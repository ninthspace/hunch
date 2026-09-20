<?php

namespace Ninthspace\Hunch\Questions;

use InvalidArgumentException;
use Ninthspace\Hunch\Support\Settings;

final readonly class Choice extends Question
{
    /**
     * Option key => description, in declared order.
     *
     * @var non-empty-array<string, string>
     */
    public array $options;

    /**
     * @param  array<int|string, string>  $options  Option key => description, in declared order.
     * @param  string|null  $tieBreak  The option that wins a tie between leading answers, when it is among them.
     */
    public function __construct(string $question, array $options, public ?string $tieBreak = null, ?Condition $condition = null)
    {
        parent::__construct($question, $condition);

        $max = self::maxOptions();
        $count = count($options);

        if ($count < 2 || $count > $max) {
            throw new InvalidArgumentException("A Choice takes from 2 to {$max} options, not {$count}.");
        }

        foreach (array_keys($options) as $key) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Every Choice option key must be a non-empty string.');
            }
        }

        if ($tieBreak !== null && ! array_key_exists($tieBreak, $options)) {
            throw new InvalidArgumentException("The tie-break `{$tieBreak}` is not one of this Choice's options.");
        }

        /** @var non-empty-array<string, string> $options */
        $this->options = $options;
    }

    /**
     * A copy of this Choice where `$key` wins a tie between leading answers, when it is among them.
     */
    public function tieBreak(string $key): self
    {
        return new self($this->question, $this->options, $key, $this->condition);
    }

    private static function maxOptions(): int
    {
        return Settings::int('choice.max_options', 50);
    }

    public function onlyWhen(string $key, string|array $values): static
    {
        return new self($this->question, $this->options, $this->tieBreak, Condition::on($key, $values));
    }

    public function canonical(): array
    {
        return [
            'type' => 'choice',
            'question' => $this->question,
            'options' => self::pairs($this->options),
        ];
    }
}
