<?php

namespace Ninthspace\Hunch\Questions;

abstract readonly class Question
{
    /**
     * @param  Condition|null  $condition  Set through onlyWhen(): the question is only counted when it applies.
     */
    public function __construct(
        public string $question,
        public ?Condition $condition = null,
    ) {}

    /**
     * A copy of this question that is only counted in samples where the
     * Choice `$key` was answered with one of `$values`.
     *
     * @param  string|list<string>  $values
     */
    abstract public function onlyWhen(string $key, string|array $values): static;

    /**
     * The question's content in canonical form, for the question set hash.
     *
     * @return array<string, mixed>
     */
    abstract public function canonical(): array;

    /**
     * Key => description as a list of pairs, so declared order is explicit.
     *
     * @param  array<string, string>  $entries
     * @return list<array{string, string}>
     */
    protected static function pairs(array $entries): array
    {
        return array_map(
            fn (string $key, string $description) => [$key, $description],
            array_keys($entries),
            array_values($entries),
        );
    }
}
