<?php

namespace Ninthspace\Hunch\Exceptions;

use Ninthspace\Hunch\Sampling\Sample;
use Ninthspace\Hunch\Usage;
use RuntimeException;
use Throwable;

/**
 * A classification could not produce answers. Hunch never substitutes a
 * default answer; this carries whatever samples were taken and what they cost.
 */
final class ClassificationFailed extends RuntimeException
{
    /**
     * @param  list<Sample>  $samples
     */
    public function __construct(
        string $message,
        public readonly array $samples,
        public readonly Usage $usage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return list<Sample>
     */
    public function validSamples(): array
    {
        return array_values(array_filter($this->samples, fn (Sample $sample) => $sample->isValid()));
    }

    /**
     * @return list<Sample>
     */
    public function invalidSamples(): array
    {
        return array_values(array_filter($this->samples, fn (Sample $sample) => ! $sample->isValid()));
    }
}
