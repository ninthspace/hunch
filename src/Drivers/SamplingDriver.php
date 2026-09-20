<?php

namespace Ninthspace\Hunch\Drivers;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Arr;
use Illuminate\Support\Sleep;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Aggregation\Aggregator;
use Ninthspace\Hunch\Calibration\CalibrationScope;
use Ninthspace\Hunch\ClassificationMeta;
use Ninthspace\Hunch\Events\ClassificationFailed as ClassificationFailedEvent;
use Ninthspace\Hunch\Events\Classified;
use Ninthspace\Hunch\Events\Classifying;
use Ninthspace\Hunch\Events\SampleInvalid;
use Ninthspace\Hunch\Events\SampleTaken;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Prompts\UserMessage;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Result;
use Ninthspace\Hunch\SampleCounts;
use Ninthspace\Hunch\Sampling\Sample;
use Ninthspace\Hunch\Sampling\SampleSeed;
use Ninthspace\Hunch\Sampling\SampleValidator;
use Ninthspace\Hunch\Sampling\Stopping;
use Ninthspace\Hunch\Support\Settings;
use Ninthspace\Hunch\Usage;
use ReflectionClass;

/**
 * Asks the same questions several times and aggregates the answers. Samples
 * run one after another, each prompt against the single requested provider.
 */
final class SamplingDriver implements ClassificationDriver
{
    public function name(): string
    {
        return 'sampling';
    }

    public function classify(string|array $state, QuestionSet $set, ClassificationOptions $options): Result
    {
        $agent = self::agent($set);
        $samples = [];
        $questionSetHash = $set->hash();
        $stateHash = UserMessage::stateHash($state);
        $ids = [$options->id, $questionSetHash, $stateHash];
        $resamples = Settings::int('max_resamples', 2);

        Classifying::dispatch(...$ids, provider: $options->provider, model: $options->model, sampling: (string) $options->sampling);

        try {
            while (($batch = Stopping::nextBatch($options->sampling, $set, $samples, $resamples)) > 0) {
                for ($i = 0; $i < $batch; $i++) {
                    $samples[] = $sample = self::sample($agent, $set, $state, $options, count($samples) + 1, $samples);

                    if ($sample->isValid()) {
                        SampleTaken::dispatch(...$ids, sampleNumber: $sample->number, requestId: $sample->requestId, model: $sample->model);
                    } else {
                        SampleInvalid::dispatch(...$ids, sampleNumber: $sample->number, problem: (string) $sample->invalidReason, requestId: $sample->requestId);
                    }
                }
            }

            $valid = count(array_filter($samples, fn (Sample $sample) => $sample->isValid()));
            $required = Settings::int('min_valid_samples', 3);

            if ($valid < $required) {
                throw new ClassificationFailed(
                    "Only {$valid} of ".count($samples)." samples were valid; at least {$required} are needed.",
                    $samples,
                    self::usage($samples),
                );
            }
        } catch (ClassificationFailed $e) {
            ClassificationFailedEvent::dispatch(
                ...$ids,
                samplesTaken: count($e->samples),
                validSamples: count($e->validSamples()),
                cause: $e->getPrevious() === null ? null : $e->getPrevious()::class,
            );

            throw $e;
        }

        $answers = Aggregator::answers($set, $samples, new CalibrationScope(
            $questionSetHash,
            $this->name(),
            $options->provider,
            CalibrationScope::model(self::reportedModel($samples), $options->model),
            (string) $options->sampling,
        ));
        $counts = new SampleCounts(count($samples), $valid, count($samples) - $valid);
        $usage = self::usage($samples);

        Classified::dispatch(...$ids, samples: $counts, usage: $usage);

        return new Result(
            id: $options->id,
            answers: $answers,
            samples: $counts,
            usage: $usage,
            meta: new ClassificationMeta(
                driver: $this->name(),
                provider: $options->provider,
                modelRequested: $options->model,
                modelReported: self::reportedModel($samples),
                sampling: (string) $options->sampling,
                questionSetHash: $questionSetHash,
                stateHash: $stateHash,
                version: $set->version,
                seeds: self::bySample($samples, fn (Sample $sample) => $sample->seed),
                requestIds: self::bySample($samples, fn (Sample $sample) => $sample->requestId),
            ),
            reasons: self::reasons($set, $samples),
            taken: $samples,
        );
    }

    /**
     * Each valid sample's reason, in sample order, or null when none were asked for.
     *
     * @param  list<Sample>  $samples
     * @return list<string>|null
     */
    private static function reasons(QuestionSet $set, array $samples): ?array
    {
        if ($set->options->reasons === null) {
            return null;
        }

        return array_values(array_map(
            fn (Sample $sample) => (string) $sample->reason,
            array_filter($samples, fn (Sample $sample) => $sample->isValid()),
        ));
    }

    /**
     * Take one sample. A provider error ends the classification, carrying the
     * samples taken so far.
     *
     * @param  string|array<string, mixed>  $state
     * @param  list<Sample>  $taken
     */
    private static function sample(ClassifierAgent $agent, QuestionSet $set, string|array $state, ClassificationOptions $options, int $number, array $taken): Sample
    {
        $seed = SampleSeed::for($options->id, $number);
        $message = UserMessage::render($set, $state, $seed);
        $retries = max(0, Settings::int('retries', 2));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $agent->prompt(
                    $message,
                    provider: self::providers($agent, $options),
                    model: $options->model,
                    timeout: $options->timeout,
                );

                break;
            } catch (RateLimitedException|ProviderOverloadedException $e) {
                if ($attempt <= $retries) {
                    Sleep::for(self::backoff($attempt))->milliseconds();

                    continue;
                }

                throw self::failed($number, $taken, $e);
            } catch (AiException|HttpClientException $e) {
                throw self::failed($number, $taken, $e);
            }
        }

        $usage = Usage::fromSdk($response->usage);
        $output = $response instanceof StructuredAgentResponse ? $response->structured : $response->text;
        $problem = SampleValidator::problem($set, $output);

        if ($problem !== null) {
            return Sample::invalid($number, $problem, $usage, $seed->hex(), $response->invocationId, $response->meta->model);
        }

        /** @var array<string, bool|string> $output */
        [$answers, $reason, $bands] = SampleValidator::split($set, $output);

        return Sample::valid($number, $answers, $usage, $seed->hex(), $response->invocationId, $response->meta->model, $reason, $bands);
    }

    /**
     * The provider to prompt. Without `hunch.failover` it is the requested
     * provider alone, so the SDK cannot fail over. With it, the requested
     * provider leads, followed by the providers the agent declares.
     *
     * @return string|array<string, string|null>
     */
    private static function providers(ClassifierAgent $agent, ClassificationOptions $options): string|array
    {
        if (Settings::get('failover', false) !== true) {
            return $options->provider;
        }

        $providers = [$options->provider => $options->model];

        foreach (Arr::wrap(self::declaredProviders($agent)) as $key => $value) {
            $name = is_string($key) ? $key : $value;
            $model = is_string($key) && is_string($value) ? $value : null;

            if (is_string($name) && ! array_key_exists($name, $providers)) {
                $providers[$name] = $model;
            }
        }

        return $providers;
    }

    /**
     * The failover providers the agent declares, through the SDK's own
     * `provider()` method or else its `#[Provider]` attribute.
     */
    private static function declaredProviders(ClassifierAgent $agent): mixed
    {
        if (method_exists($agent, 'provider')) {
            return $agent->provider();
        }

        $attribute = (new ReflectionClass($agent))->getAttributes(ProviderAttribute::class)[0] ?? null;

        return $attribute?->newInstance()->value;
    }

    /**
     * Milliseconds to wait before retry `$attempt`: 500, 1000, 2000, …
     */
    private static function backoff(int $attempt): int
    {
        return 500 * 2 ** ($attempt - 1);
    }

    /**
     * @param  list<Sample>  $taken
     */
    private static function failed(int $number, array $taken, AiException|HttpClientException $e): ClassificationFailed
    {
        return new ClassificationFailed(
            'Sample '.$number.' failed at the provider ('.$e::class.').',
            $taken,
            self::usage($taken),
            $e,
        );
    }

    /**
     * Tokens summed across the samples.
     *
     * @param  list<Sample>  $samples
     */
    private static function usage(array $samples): Usage
    {
        return Usage::sum(array_map(fn (Sample $sample) => $sample->usage, $samples));
    }

    /**
     * The model the provider reported: one name when every sample agrees,
     * every distinct name comma-separated when they don't.
     *
     * @param  list<Sample>  $samples
     */
    private static function reportedModel(array $samples): ?string
    {
        $models = array_values(array_unique(array_filter(array_map(fn (Sample $sample) => $sample->model, $samples))));

        return $models === [] ? null : implode(', ', $models);
    }

    /**
     * @param  list<Sample>  $samples
     * @param  callable(Sample): ?string  $value
     * @return array<int, string>
     */
    private static function bySample(array $samples, callable $value): array
    {
        $values = [];

        foreach ($samples as $sample) {
            $values[$sample->number] = (string) $value($sample);
        }

        return $values;
    }

    private static function agent(QuestionSet $set): ClassifierAgent
    {
        $class = Settings::get('agent', ClassifierAgent::class);

        if (! is_string($class) || ! is_a($class, ClassifierAgent::class, true)) {
            throw new ConfigurationException('`hunch.agent` must be ClassifierAgent or a subclass of it.');
        }

        return new $class($set);
    }
}
