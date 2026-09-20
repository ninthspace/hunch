<?php

namespace Ninthspace\Hunch\Events;

/**
 * A sample came back valid and will be counted.
 */
final readonly class SampleTaken extends ClassificationEvent
{
    public function __construct(
        string $classificationId,
        string $questionSetHash,
        string $stateHash,
        public int $sampleNumber,
        public ?string $requestId,
        public ?string $model,
    ) {
        parent::__construct($classificationId, $questionSetHash, $stateHash);
    }
}
