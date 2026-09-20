<?php

namespace Ninthspace\Hunch\Questions;

use InvalidArgumentException;

final readonly class Score extends Question
{
    /**
     * Level label => description, ordered from lowest to highest.
     *
     * @var non-empty-array<string, string>
     */
    public array $levels;

    /**
     * @param  array<int|string, string>  $levels  A list of labels, or label => description, lowest first.
     * @param  string|null  $tieBreak  The level that wins a tie between modal answers, when it is among them.
     */
    public function __construct(string $question, array $levels, public ?string $tieBreak = null, ?Condition $condition = null)
    {
        parent::__construct($question, $condition);

        $normalised = array_is_list($levels)
            ? array_fill_keys($levels, '')
            : $levels;

        if (count($normalised) < 2 || count($normalised) !== count($levels)) {
            throw new InvalidArgumentException('A Score takes at least 2 distinct levels.');
        }

        foreach (array_keys($normalised) as $label) {
            if (! is_string($label) || $label === '') {
                throw new InvalidArgumentException('Every Score level label must be a non-empty string.');
            }
        }

        if ($tieBreak !== null && ! array_key_exists($tieBreak, $normalised)) {
            throw new InvalidArgumentException("The tie-break `{$tieBreak}` is not one of this Score's levels.");
        }

        /** @var non-empty-array<string, string> $normalised */
        $this->levels = $normalised;
    }

    /**
     * A copy of this Score where `$label` wins a tie between modal answers, when it is among them.
     */
    public function tieBreak(string $label): self
    {
        return new self($this->question, $this->levels, $label, $this->condition);
    }

    /**
     * The level labels, index 0 the lowest.
     *
     * @return non-empty-list<string>
     */
    public function labels(): array
    {
        return array_keys($this->levels);
    }

    public function onlyWhen(string $key, string|array $values): static
    {
        return new self($this->question, $this->levels, $this->tieBreak, Condition::on($key, $values));
    }

    public function canonical(): array
    {
        return [
            'type' => 'score',
            'question' => $this->question,
            'levels' => self::pairs($this->levels),
        ];
    }
}
