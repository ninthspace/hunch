<?php

namespace Ninthspace\Hunch;

use ArrayAccess;
use InvalidArgumentException;
use LogicException;
use Ninthspace\Hunch\Answers\Answers;
use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Answers\ScoreAnswer;
use Ninthspace\Hunch\Sampling\Sample;

/**
 * A classification's answers, keyed by question key.
 *
 * @implements ArrayAccess<string, BooleanAnswer|ChoiceAnswer|ScoreAnswer|null>
 */
final readonly class Result implements ArrayAccess
{
    /**
     * @param  string  $id  The classification's ULID, whether or not it is recorded.
     * @param  Answers  $answers  Every question's answer, null where too few samples qualified for a conditional question.
     * @param  Usage  $usage  Tokens summed across every sample, cache reads and writes included.
     * @param  list<string>|null  $reasons  Each valid sample's reason, when reasons were requested.
     * @param  list<Sample>  $taken  Every sample taken, valid or not, in order.
     */
    public function __construct(
        public string $id,
        public Answers $answers,
        public SampleCounts $samples,
        public Usage $usage,
        public ClassificationMeta $meta,
        public ?array $reasons = null,
        public array $taken = [],
    ) {}

    /**
     * The answer to `$question`, or null where too few samples qualified for
     * a conditional question.
     *
     * @throws InvalidArgumentException When the classification never asked it.
     */
    public function answer(string $question): BooleanAnswer|ChoiceAnswer|ScoreAnswer|null
    {
        return $this->answers->get($question);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->answers->offsetExists($offset);
    }

    public function offsetGet(mixed $offset): BooleanAnswer|ChoiceAnswer|ScoreAnswer|null
    {
        return $this->answers->offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new LogicException('A Result is read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new LogicException('A Result is read-only.');
    }
}
