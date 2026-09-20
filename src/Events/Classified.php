<?php

namespace Ninthspace\Hunch\Events;

use Ninthspace\Hunch\SampleCounts;
use Ninthspace\Hunch\Usage;

/**
 * A classification produced its answers.
 */
final readonly class Classified extends ClassificationEvent
{
    public function __construct(
        string $classificationId,
        string $questionSetHash,
        string $stateHash,
        public SampleCounts $samples,
        public Usage $usage,
    ) {
        parent::__construct($classificationId, $questionSetHash, $stateHash);
    }
}
