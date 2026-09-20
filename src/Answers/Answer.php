<?php

namespace Ninthspace\Hunch\Answers;

use Ninthspace\Hunch\Calibration\CalibrationScope;
use Ninthspace\Hunch\Models\CalibrationCell;
use Ninthspace\Hunch\Persistence\MissingTables;
use Ninthspace\Hunch\Support\Settings;

/**
 * What every answer carries, whichever driver produced it.
 */
abstract readonly class Answer
{
    /**
     * The probability bucket this answer's calibration is measured in.
     */
    public string $bucket;

    /**
     * @param  bool  $applies  Whether the question applied to this state: false for a conditional question whose controlling answer did not match.
     */
    public function __construct(
        float $share,
        public bool $applies = true,
        public ?CalibrationScope $scope = null,
    ) {
        $this->bucket = Buckets::for($share);
    }

    /**
     * Measured accuracy for this answer's calibration cell, once enough
     * labelled classifications exist. Null until persistence records them.
     *
     * @return array{n: int, accuracy: float, lowerBound: float}|null
     */
    public function calibration(): ?array
    {
        if ($this->scope === null || ! Settings::get('persistence', false)) {
            return null;
        }

        $cell = MissingTables::guard(fn () => CalibrationCell::query()
            ->where($this->scope->columns())
            ->where('answer', $this->calibrationAnswer())
            ->where('bucket', $this->bucket)
            ->first());

        if ($cell === null || $cell->n < Settings::int('calibration.min_n', 30)) {
            return null;
        }

        return ['n' => $cell->n, 'accuracy' => $cell->accuracy, 'lowerBound' => $cell->lower_bound];
    }

    /**
     * The winning answer as calibration cells record it.
     */
    abstract public function calibrationAnswer(): string;
}
