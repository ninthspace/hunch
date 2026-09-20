<?php

namespace Ninthspace\Hunch\Events;

/**
 * A classification could not produce answers; dispatched just before the
 * exception is thrown. It carries counts and the cause's class, not the
 * exception message, which can quote a provider's response.
 */
final readonly class ClassificationFailed extends ClassificationEvent
{
    public function __construct(
        string $classificationId,
        string $questionSetHash,
        string $stateHash,
        public int $samplesTaken,
        public int $validSamples,
        public ?string $cause,
    ) {
        parent::__construct($classificationId, $questionSetHash, $stateHash);
    }
}
