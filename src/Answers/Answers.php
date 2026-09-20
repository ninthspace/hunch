<?php

namespace Ninthspace\Hunch\Answers;

use ArrayAccess;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * A classification's answers, keyed by question key and in canonical order.
 *
 * An object rather than an array, so that asking for a question that was never
 * asked fails the same way however it is reached, and so that a conditional
 * question's outcomes — an answer that did not apply, or one too few samples
 * qualified for — have methods of their own.
 *
 * @implements ArrayAccess<string, BooleanAnswer|ChoiceAnswer|ScoreAnswer|null>
 * @implements IteratorAggregate<string, BooleanAnswer|ChoiceAnswer|ScoreAnswer|null>
 */
final readonly class Answers implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param  array<string, BooleanAnswer|ChoiceAnswer|ScoreAnswer|null>  $answers  Null where too few samples qualified for a conditional question.
     */
    public function __construct(
        private array $answers = [],
    ) {}

    /**
     * The answer to `$question`, or null where too few samples qualified.
     *
     * @throws InvalidArgumentException When the classification never asked it.
     */
    public function get(string $question): BooleanAnswer|ChoiceAnswer|ScoreAnswer|null
    {
        if (! $this->has($question)) {
            throw new InvalidArgumentException("No answer for question `{$question}`.");
        }

        return $this->answers[$question];
    }

    /**
     * Whether the classification asked this question at all, answered or not.
     */
    public function has(string $question): bool
    {
        return array_key_exists($question, $this->answers);
    }

    /**
     * Every question asked, in canonical order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->answers);
    }

    /**
     * The answers that stand: neither null, nor a conditional question whose
     * condition the winning answer did not match.
     */
    public function applicable(): self
    {
        return new self(array_filter(
            $this->answers,
            fn (BooleanAnswer|ChoiceAnswer|ScoreAnswer|null $answer) => $answer?->applies === true,
        ));
    }

    /**
     * The questions with no answer, because too few samples qualified.
     *
     * @return list<string>
     */
    public function unanswered(): array
    {
        return array_keys(array_filter(
            $this->answers,
            fn (BooleanAnswer|ChoiceAnswer|ScoreAnswer|null $answer) => $answer === null,
        ));
    }

    /**
     * @return array<string, BooleanAnswer|ChoiceAnswer|ScoreAnswer|null>
     */
    public function all(): array
    {
        return $this->answers;
    }

    public function count(): int
    {
        return count($this->answers);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->answers);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): BooleanAnswer|ChoiceAnswer|ScoreAnswer|null
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new LogicException('Answers are read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new LogicException('Answers are read-only.');
    }
}
