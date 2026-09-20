<?php

namespace Ninthspace\Hunch;

use InvalidArgumentException;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Question;
use Ninthspace\Hunch\Questions\Score;

/**
 * A set of questions and the context they are asked in, identified by a hash of its content.
 */
final readonly class QuestionSet
{
    /**
     * Bumped whenever the canonical form changes, so the change is visible in every hash.
     */
    public const HASH_FORMAT = 'v1';

    /**
     * The output field that carries a sample's reason when reasons are on.
     */
    public const REASON = 'reason';

    /**
     * The output field that carries a sample's stated confidence bands.
     */
    public const BANDS = 'bands';

    /**
     * @var array<string, Question>
     */
    public array $questions;

    /**
     * @param  array<int|string, mixed>  $questions  Question key => question.
     * @param  string|null  $version  A human-readable name, carried alongside the hash and never part of it.
     */
    public function __construct(
        array $questions,
        public string $context = '',
        public PromptOptions $options = new PromptOptions,
        public ?string $version = null,
    ) {
        foreach ($questions as $key => $question) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Every question key must be a non-empty string.');
            }

            if (! $question instanceof Question) {
                throw new InvalidArgumentException("Question `{$key}` is not a Boolean, Choice or Score.");
            }

            if ($key === self::REASON && $options->reasons !== null) {
                throw new InvalidArgumentException('`'.self::REASON.'` is not a question key while reasons are on: the reason is carried under it.');
            }
        }

        /** @var array<string, Question> $questions */
        foreach ($questions as $key => $question) {
            self::checkCondition($key, $question, $questions);
        }

        $this->questions = $questions;
    }

    /**
     * A condition must name a Choice in this set, and only values it offers.
     *
     * @param  array<string, Question>  $questions
     */
    private static function checkCondition(string $key, Question $question, array $questions): void
    {
        $condition = $question->condition;

        if ($condition === null) {
            return;
        }

        $controlling = $questions[$condition->key] ?? null;

        if (! $controlling instanceof Choice) {
            throw new InvalidArgumentException("`{$key}` is conditional on `{$condition->key}`, which is not a Choice in this set.");
        }

        foreach ($condition->values as $value) {
            if (! array_key_exists($value, $controlling->options)) {
                throw new InvalidArgumentException("`{$key}` is conditional on `{$condition->key}` being `{$value}`, which is not one of its options.");
            }
        }
    }

    /**
     * The set's identity: sha256 over the canonical JSON, prefixed with the hash format.
     */
    public function hash(): string
    {
        return self::HASH_FORMAT.':'.hash('sha256', $this->toCanonicalJson());
    }

    /**
     * The set's content as canonical JSON: question keys sorted, options and
     * levels in declared order, and neither the version name nor any state.
     */
    public function toCanonicalJson(): string
    {
        return json_encode([
            'context' => $this->context,
            'options' => [
                'reasons' => $this->options->reasons,
                'self_report' => $this->options->selfReport,
            ],
            'questions' => array_map(fn (Question $question) => $question->canonical(), $this->inCanonicalOrder()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * What the set holds beyond its canonical form: the conditions and
     * tie-breaks, which are deliberately outside the hash (FR14) and so
     * cannot be read back from the canonical JSON.
     *
     * @return array{conditions: array<string, array{string, list<string>}>, tie_breaks: array<string, string>}
     */
    public function definition(): array
    {
        $conditions = [];
        $tieBreaks = [];

        foreach ($this->inCanonicalOrder() as $key => $question) {
            if ($question->condition !== null) {
                $conditions[$key] = [$question->condition->key, $question->condition->values];
            }

            if (($question instanceof Choice || $question instanceof Score) && $question->tieBreak !== null) {
                $tieBreaks[$key] = $question->tieBreak;
            }
        }

        return ['conditions' => $conditions, 'tie_breaks' => $tieBreaks];
    }

    /**
     * The set as it was asked, rebuilt from a recorded row: the canonical
     * JSON plus the conditions and tie-breaks the definition carries.
     *
     * @param  array{conditions?: array<string, array{string, list<string>}>, tie_breaks?: array<string, string>}|null  $definition
     */
    public static function fromStored(string $canonicalJson, ?array $definition = null, ?string $version = null): self
    {
        $canonical = json_decode($canonicalJson, true);

        if (! is_array($canonical) || ! is_array($canonical['questions'] ?? null)) {
            throw new InvalidArgumentException('A recorded question set needs canonical JSON with questions.');
        }

        $questions = [];

        foreach ($canonical['questions'] as $key => $question) {
            if (! is_array($question)) {
                throw new InvalidArgumentException("Recorded question `{$key}` is not a question.");
            }

            $questions[(string) $key] = self::rebuild((string) $key, $question, $definition ?? []);
        }

        $options = is_array($canonical['options'] ?? null) ? $canonical['options'] : [];
        $reasons = $options['reasons'] ?? null;

        return new self(
            $questions,
            is_string($canonical['context'] ?? null) ? $canonical['context'] : '',
            new PromptOptions(is_int($reasons) ? $reasons : null, ($options['self_report'] ?? false) === true),
            $version,
        );
    }

    /**
     * @param  array<mixed>  $canonical
     * @param  array{conditions?: array<string, array{string, list<string>}>, tie_breaks?: array<string, string>}  $definition
     */
    private static function rebuild(string $key, array $canonical, array $definition): Question
    {
        $entries = self::entries($canonical['options'] ?? $canonical['levels'] ?? []);
        $tieBreak = $definition['tie_breaks'][$key] ?? null;
        $text = is_string($canonical['question'] ?? null) ? $canonical['question'] : '';

        $question = match ($canonical['type'] ?? null) {
            'choice' => new Choice($text, $entries, $tieBreak),
            'score' => new Score($text, $entries, $tieBreak),
            'boolean' => new Boolean($text, array_filter(self::entries($canonical['descriptions'] ?? []), fn (string $description) => $description !== '')),
            default => throw new InvalidArgumentException("Recorded question `{$key}` has no known type."),
        };

        [$on, $values] = $definition['conditions'][$key] ?? [null, null];

        return is_string($on) && is_array($values) ? $question->onlyWhen($on, $values) : $question;
    }

    /**
     * Canonical pairs, or a Boolean's description map, as key => description.
     *
     * @return array<string, string>
     */
    private static function entries(mixed $entries): array
    {
        $map = [];

        foreach (is_array($entries) ? $entries : [] as $key => $entry) {
            if (is_array($entry) && is_string($entry[0] ?? null)) {
                $map[$entry[0]] = is_string($entry[1] ?? null) ? $entry[1] : '';
            } elseif (is_string($key)) {
                $map[$key] = is_string($entry) ? $entry : '';
            }
        }

        return $map;
    }

    /**
     * The questions sorted by key, the order every rendering of the set uses.
     *
     * @return array<string, Question>
     */
    public function inCanonicalOrder(): array
    {
        $questions = $this->questions;
        ksort($questions, SORT_STRING);

        return $questions;
    }
}
