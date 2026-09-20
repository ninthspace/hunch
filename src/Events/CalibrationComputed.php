<?php

namespace Ninthspace\Hunch\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * `hunch:calibrate` finished: how many cells it wrote, from how many
 * labelled answers.
 */
final readonly class CalibrationComputed
{
    use Dispatchable;

    public function __construct(
        public int $cells,
        public int $labelled,
    ) {}
}
