<?php

namespace Ninthspace\Hunch;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ninthspace\Hunch\Drivers\ClassificationDriver;
use Ninthspace\Hunch\Drivers\ClassificationOptions;
use Ninthspace\Hunch\Drivers\DriverResolver;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Persistence\Recorder;
use Ninthspace\Hunch\Prompts\UserMessage;
use Ninthspace\Hunch\Questions\Question;
use Ninthspace\Hunch\Sampling\SamplingRule;
use Ninthspace\Hunch\Support\Settings;
use Stringable;

/**
 * A classification being described. Values set here override configuration;
 * everything is checked before the first model call.
 */
class PendingClassification
{
    private string $context = '';

    /**
     * @var array<string, Question>
     */
    private array $questions = [];

    private ?string $provider = null;

    private ?string $model = null;

    private ?SamplingRule $sampling = null;

    private ?int $timeout = null;

    private PromptOptions $promptOptions;

    /**
     * @var (callable(string): string)|class-string|null
     */
    private mixed $redactor = null;

    private ?Model $subject = null;

    /**
     * @param  string|array<string, mixed>  $state
     */
    public function __construct(
        private readonly string|array $state,
        private readonly ?ClassificationDriver $driver = null,
    ) {
        $this->promptOptions = new PromptOptions;
    }

    public function context(string $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function question(string $key, Question $question): static
    {
        $this->questions[$key] = $question;

        return $this;
    }

    /**
     * @param  array<string, Question>  $questions
     */
    public function questions(array $questions): static
    {
        foreach ($questions as $key => $question) {
            $this->question($key, $question);
        }

        return $this;
    }

    public function using(string $provider, ?string $model = null): static
    {
        $this->provider = $provider;
        $this->model = $model;

        return $this;
    }

    /**
     * Take exactly `$samples` samples, or with `adaptive: [min, max]` take
     * `min` and stop early once every answer is settled.
     *
     * @param  array{int, int}|null  $adaptive
     */
    public function sampling(?int $samples = null, ?array $adaptive = null): static
    {
        $this->sampling = $adaptive !== null
            ? SamplingRule::adaptive($adaptive[0], $adaptive[1])
            : SamplingRule::fixed($samples ?? 0);

        return $this;
    }

    /**
     * Ask each sample for a short reason, at most `$maxChars` characters.
     * Reasons are display text only: nothing reads them back.
     */
    public function withReasons(int $maxChars = 200): static
    {
        if ($maxChars < 1) {
            throw new InvalidArgumentException("A reason needs at least one character, not {$maxChars}.");
        }

        $this->promptOptions = new PromptOptions($maxChars, $this->promptOptions->selfReport);

        return $this;
    }

    /**
     * Ask each sample to state a confidence band per question. Bands are
     * recorded for comparison only: nothing reads them back into an answer.
     */
    public function withSelfReport(bool $selfReport = true): static
    {
        $this->promptOptions = new PromptOptions($this->promptOptions->reasons, $selfReport);

        return $this;
    }

    /**
     * Seconds allowed for each model call.
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Transform the state before it is sent: once per classification, before
     * the first model call, and once per named part of array state. Takes a
     * callable, or the class name of an invokable redactor.
     *
     * @param  callable(string): string|class-string  $redactor
     */
    public function redactUsing(callable|string $redactor): static
    {
        $this->redactor = $redactor;

        return $this;
    }

    /**
     * Persist this classification against `$subject`: the classification,
     * its samples and its question set, with the state's hash but never the
     * state. Needs `hunch.persistence` on.
     */
    public function record(Model $subject): static
    {
        if (! Settings::get('persistence', false)) {
            throw new ConfigurationException('record() needs `hunch.persistence` to be on.');
        }

        Recorder::subjectKey($subject);

        $this->subject = $subject;

        return $this;
    }

    public function classify(): Result
    {
        $set = $this->questionSet();
        $options = $this->options();
        $state = $this->redacted();
        $driver = $this->driver ?? DriverResolver::for($options->provider);

        if ($this->subject === null) {
            return $driver->classify($state, $set, $options);
        }

        try {
            $result = $driver->classify($state, $set, $options);
        } catch (ClassificationFailed $e) {
            Recorder::failed($this->subject, $set, $options, $driver->name(), UserMessage::renderedState($state), $e);

            throw $e;
        }

        Recorder::completed($this->subject, $set, $result, UserMessage::renderedState($state));

        return $result;
    }

    /**
     * The state every sample receives: redacted once, when a redactor is set.
     *
     * @return string|array<string, mixed>
     */
    private function redacted(): string|array
    {
        if ($this->redactor === null) {
            return $this->state;
        }

        $redactor = is_callable($this->redactor) ? $this->redactor : app($this->redactor);

        if (! is_callable($redactor)) {
            throw new ConfigurationException('A redactor must be callable, or the class name of an invokable class.');
        }

        $redact = function (string $text) use ($redactor): string {
            $redacted = $redactor($text);

            if (! is_string($redacted)) {
                throw new ConfigurationException('A redactor must return a string.');
            }

            return $redacted;
        };

        if (is_string($this->state)) {
            return $redact($this->state);
        }

        return array_map(
            fn (mixed $part) => is_scalar($part) || $part instanceof Stringable ? $redact((string) $part) : $part,
            $this->state,
        );
    }

    private function questionSet(): QuestionSet
    {
        if ($this->questions === []) {
            throw new InvalidArgumentException('A classification needs at least one question.');
        }

        return new QuestionSet($this->questions, $this->context, $this->promptOptions);
    }

    private function options(): ClassificationOptions
    {
        $provider = $this->provider ?? Settings::get('provider') ?? ($this->driver !== null ? 'fake' : null);

        if (! is_string($provider) || $provider === '') {
            throw new ConfigurationException('No provider: call using() or set `hunch.provider`.');
        }

        $model = $this->provider !== null ? $this->model : Settings::get('model');
        $sampling = $this->sampling ?? SamplingRule::configured();
        $required = Settings::int('min_valid_samples', 3);

        if ($required > $sampling->min) {
            throw new ConfigurationException(
                "`hunch.min_valid_samples` is {$required}, but {$sampling} can take as few as {$sampling->min} samples. Raise the sample count; it is never scaled down."
            );
        }

        return new ClassificationOptions(
            id: (string) Str::ulid(),
            provider: $provider,
            model: is_string($model) ? $model : null,
            sampling: $sampling,
            timeout: $this->timeout ?? Settings::int('timeout', 20),
        );
    }
}
