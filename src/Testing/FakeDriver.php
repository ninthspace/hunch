<?php

namespace Ninthspace\Hunch\Testing;

use Ninthspace\Hunch\Aggregation\Aggregator;
use Ninthspace\Hunch\Answers\Answers;
use Ninthspace\Hunch\Calibration\CalibrationScope;
use Ninthspace\Hunch\ClassificationMeta;
use Ninthspace\Hunch\Drivers\ClassificationDriver;
use Ninthspace\Hunch\Drivers\ClassificationOptions;
use Ninthspace\Hunch\Drivers\DriverResolver;
use Ninthspace\Hunch\Prompts\UserMessage;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Result;
use Ninthspace\Hunch\SampleCounts;
use Ninthspace\Hunch\Sampling\Sample;
use Ninthspace\Hunch\Sampling\SampleSeed;
use Ninthspace\Hunch\Sampling\SampleValidator;
use Ninthspace\Hunch\Usage;

/**
 * Answers classifications from the fake instead of a model. Direct answers
 * are turned into answer objects; scripted samples go through the real
 * aggregator. The classification ID is fixed, so seeds and shuffles repeat.
 */
final class FakeDriver implements ClassificationDriver
{
    public const ID = '01J0000000000000000000FAKE';

    public function __construct(
        private readonly HunchFake $fake,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function classify(string|array $state, QuestionSet $set, ClassificationOptions $options): Result
    {
        $samples = $this->fake->scriptedSamples();
        $answers = $this->fake->answers();
        $unanswered = array_diff(array_keys($set->questions), array_keys($answers));

        if ($samples === [] && $unanswered !== []) {
            if ($this->fake->preventsStrays()) {
                throw new StrayClassification('No fake answers the questions '.implode(', ', $unanswered).'.');
            }

            return DriverResolver::for($options->provider)->classify($state, $set, $options);
        }

        $messages = [];
        $taken = [];
        $seeds = [];

        foreach ($samples as $index => $answersBySample) {
            $number = $index + 1;
            $seed = SampleSeed::for(self::ID, $number);
            $messages[] = UserMessage::render($set, $state, $seed);
            [$answersBySample, $reason, $bands] = SampleValidator::split($set, $answersBySample);
            $taken[] = Sample::valid($number, $answersBySample, seed: $seed->hex(), reason: $reason, bands: $bands);
            $seeds[$number] = $seed->hex();
        }

        $result = new Result(
            id: self::ID,
            answers: $this->answer($set, $answers, $taken, new CalibrationScope($set->hash(), $this->name(), $options->provider, CalibrationScope::model(null, $options->model), (string) $options->sampling)),
            samples: new SampleCounts(count($taken), count($taken), 0),
            usage: new Usage,
            meta: new ClassificationMeta(
                driver: $this->name(),
                provider: $options->provider,
                modelRequested: $options->model,
                modelReported: null,
                sampling: (string) $options->sampling,
                questionSetHash: $set->hash(),
                stateHash: UserMessage::stateHash($state),
                version: $set->version,
                seeds: $seeds,
                requestIds: [],
            ),
            reasons: self::reasons($set, $taken),
            taken: $taken,
        );

        $this->fake->record(new RecordedClassification($state, $set, $options->provider, $result, $messages));

        return $result;
    }

    /**
     * Each scripted sample's reason, or null when none were asked for.
     *
     * @param  list<Sample>  $samples
     * @return list<string>|null
     */
    private static function reasons(QuestionSet $set, array $samples): ?array
    {
        return $set->options->reasons === null ? null : array_map(fn (Sample $sample) => (string) $sample->reason, $samples);
    }

    /**
     * @param  array<string, float|array<string, float>>  $answers
     * @param  list<Sample>  $samples
     */
    private function answer(QuestionSet $set, array $answers, array $samples, CalibrationScope $scope): Answers
    {
        if ($samples !== []) {
            return Aggregator::answers($set, $samples, $scope);
        }

        $result = [];

        foreach ($set->inCanonicalOrder() as $key => $question) {
            $result[$key] = Aggregator::fromShares($key, $question, $answers[$key], $scope->forQuestion($key));
        }

        return new Answers($result);
    }
}
