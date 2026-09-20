<?php

namespace Ninthspace\Hunch\Answers;

use Ninthspace\Hunch\Calibration\CalibrationScope;

final readonly class BooleanAnswer extends Answer
{
    /**
     * @param  float  $probability  Share of counted samples that answered true: a vote-share estimate, not a calibrated probability. See calibration() for measured accuracy.
     * @param  array{true: int, false: int}  $votes
     */
    public function __construct(
        public float $probability,
        public array $votes,
        bool $applies = true,
        ?CalibrationScope $scope = null,
    ) {
        parent::__construct(max($probability, 1.0 - $probability), $applies, $scope);
    }

    public function calibrationAnswer(): string
    {
        return $this->isTrue() ? 'true' : 'false';
    }

    public function isTrue(float $threshold = 0.5): bool
    {
        return $this->probability >= $threshold;
    }

    /**
     * True if any counted sample answered true, for questions where one "yes" matters.
     */
    public function anyTrue(): bool
    {
        return $this->votes['true'] > 0;
    }

    public function unanimous(): bool
    {
        return $this->votes['true'] === 0 || $this->votes['false'] === 0;
    }
}
