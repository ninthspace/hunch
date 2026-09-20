<?php

namespace Ninthspace\Hunch\Sampling;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The seed that fixes one sample's shuffles.
 *
 * It is derived from the classification ID and the sample number, so a
 * classification can be reproduced, and it drives an object-scoped engine,
 * so the application's global RNG is never touched.
 */
final readonly class SampleSeed
{
    private function __construct(
        private string $bytes,
    ) {}

    public static function for(string $classificationId, int $sampleNumber): self
    {
        return new self(hash('sha256', $classificationId.':'.$sampleNumber, binary: true));
    }

    public function hex(): string
    {
        return bin2hex($this->bytes);
    }

    /**
     * A fresh randomizer positioned at the start of this seed's sequence.
     */
    public function randomizer(): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar($this->bytes));
    }
}
