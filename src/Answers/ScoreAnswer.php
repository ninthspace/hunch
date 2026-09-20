<?php

namespace Ninthspace\Hunch\Answers;

use Ninthspace\Hunch\Calibration\CalibrationScope;

final readonly class ScoreAnswer extends Answer
{
    /**
     * @param  float  $score  The mean level index over counted samples, 0 being the lowest level.
     * @param  array<string, float>  $probabilities  Level label => share of counted samples, lowest level first. Each share is a vote-share estimate, not a calibrated probability. See calibration() for measured accuracy.
     * @param  array<string, int>  $votes  Level label => vote count, lowest level first.
     * @param  string  $mode  The most-voted level, after the tie rule.
     */
    public function __construct(
        public float $score,
        public array $probabilities,
        public array $votes,
        public string $mode,
        public bool $tied,
        bool $applies = true,
        ?CalibrationScope $scope = null,
    ) {
        parent::__construct($probabilities[$mode] ?? 0.0, $applies, $scope);
    }

    /**
     * The modal level: the one the bucket's share belongs to.
     */
    public function calibrationAnswer(): string
    {
        return $this->mode;
    }

    /**
     * The label of the level nearest the mean score, halves rounding up.
     */
    public function level(): string
    {
        $labels = array_keys($this->votes);

        return $labels[(int) floor($this->score + 0.5)];
    }

    public function unanimous(): bool
    {
        return count(array_filter($this->votes)) === 1;
    }
}
