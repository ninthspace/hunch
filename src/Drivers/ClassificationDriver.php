<?php

namespace Ninthspace\Hunch\Drivers;

use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Result;

/**
 * How a classification is carried out. Every driver hands its samples to the
 * shared aggregator, so answer shapes cannot drift between drivers.
 */
interface ClassificationDriver
{
    /**
     * The driver's name, as recorded in `meta` and in persisted rows.
     */
    public function name(): string;

    /**
     * @param  string|array<string, mixed>  $state
     */
    public function classify(string|array $state, QuestionSet $set, ClassificationOptions $options): Result;
}
