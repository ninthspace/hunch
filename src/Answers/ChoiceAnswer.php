<?php

namespace Ninthspace\Hunch\Answers;

use InvalidArgumentException;
use Ninthspace\Hunch\Calibration\CalibrationScope;

final readonly class ChoiceAnswer extends Answer
{
    /**
     * @param  array<string, float>  $probabilities  Option key => share of counted samples, in canonical order. Each share is a vote-share estimate, not a calibrated probability. See calibration() for measured accuracy.
     * @param  float  $confidence  The leading option's share of counted samples, the same measure for every driver: a vote-share estimate, not a calibrated probability. See calibration() for measured accuracy.
     * @param  array<string, int>  $votes  Option key => vote count, in canonical order.
     */
    public function __construct(
        public string $choice,
        public array $probabilities,
        public float $confidence,
        public float $entropy,
        public array $votes,
        public bool $tied,
        bool $applies = true,
        ?CalibrationScope $scope = null,
    ) {
        parent::__construct($confidence, $applies, $scope);
    }

    public function calibrationAnswer(): string
    {
        return $this->choice;
    }

    /**
     * The share of counted samples that chose `$key`.
     */
    public function probabilityOf(string $key): float
    {
        if (! array_key_exists($key, $this->probabilities)) {
            throw new InvalidArgumentException("`{$key}` is not one of this Choice's options.");
        }

        return $this->probabilities[$key];
    }

    public function unanimous(): bool
    {
        return count(array_filter($this->votes)) === 1;
    }
}
