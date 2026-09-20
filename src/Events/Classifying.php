<?php

namespace Ninthspace\Hunch\Events;

/**
 * A classification is about to take its first sample.
 */
final readonly class Classifying extends ClassificationEvent
{
    public function __construct(
        string $classificationId,
        string $questionSetHash,
        string $stateHash,
        public string $provider,
        public ?string $model,
        public string $sampling,
    ) {
        parent::__construct($classificationId, $questionSetHash, $stateHash);
    }
}
