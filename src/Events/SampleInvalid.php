<?php

namespace Ninthspace\Hunch\Events;

/**
 * A sample's output did not validate and is excluded from every vote.
 */
final readonly class SampleInvalid extends ClassificationEvent
{
    public function __construct(
        string $classificationId,
        string $questionSetHash,
        string $stateHash,
        public int $sampleNumber,
        public string $problem,
        public ?string $requestId,
    ) {
        parent::__construct($classificationId, $questionSetHash, $stateHash);
    }
}
