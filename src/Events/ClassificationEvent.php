<?php

namespace Ninthspace\Hunch\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * What every Hunch event carries: which classification, which question set,
 * and the hash of the state that was sent. Never the state itself.
 */
abstract readonly class ClassificationEvent
{
    use Dispatchable;

    public function __construct(
        public string $classificationId,
        public string $questionSetHash,
        public string $stateHash,
    ) {}
}
