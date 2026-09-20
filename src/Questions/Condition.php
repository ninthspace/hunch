<?php

namespace Ninthspace\Hunch\Questions;

/**
 * A question asked only when another question, a Choice, is answered with
 * one of the given values.
 */
final readonly class Condition
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $key,
        public array $values,
    ) {}

    /**
     * @param  string|list<string>  $values
     */
    public static function on(string $key, string|array $values): self
    {
        return new self($key, is_string($values) ? [$values] : $values);
    }

    public function matches(mixed $answer): bool
    {
        return is_string($answer) && in_array($answer, $this->values, true);
    }
}
