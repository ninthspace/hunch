<?php

namespace Ninthspace\Hunch;

/**
 * How many samples were asked for, and how many of them could be counted.
 */
final readonly class SampleCounts
{
    public function __construct(
        public int $requested,
        public int $valid,
        public int $invalid,
    ) {}
}
