<?php

namespace Ninthspace\Hunch\Drivers;

use Ninthspace\Hunch\Sampling\SamplingRule;

/**
 * Everything a driver needs besides the state and the questions.
 */
final readonly class ClassificationOptions
{
    public function __construct(
        public string $id,
        public string $provider,
        public ?string $model,
        public SamplingRule $sampling,
        public int $timeout,
    ) {}
}
