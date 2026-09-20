<?php

namespace Ninthspace\Hunch\Questions;

use InvalidArgumentException;

final readonly class Boolean extends Question
{
    /**
     * Optional descriptions of `true` and `false`.
     *
     * @var array<'true'|'false', string>
     */
    public array $descriptions;

    /**
     * @param  array<int|string, string>  $descriptions  Optional descriptions of `true` and `false`.
     */
    public function __construct(string $question, array $descriptions = [], ?Condition $condition = null)
    {
        parent::__construct($question, $condition);

        foreach ($descriptions as $key => $description) {
            if (! in_array($key, ['true', 'false'], true)) {
                throw new InvalidArgumentException("A Boolean can only describe `true` and `false`, not `{$key}`.");
            }
        }

        /** @var array<'true'|'false', string> $descriptions */
        $this->descriptions = $descriptions;
    }

    public function onlyWhen(string $key, string|array $values): static
    {
        return new self($this->question, $this->descriptions, Condition::on($key, $values));
    }

    public function canonical(): array
    {
        return [
            'type' => 'boolean',
            'question' => $this->question,
            'descriptions' => [
                'true' => $this->descriptions['true'] ?? null,
                'false' => $this->descriptions['false'] ?? null,
            ],
        ];
    }
}
