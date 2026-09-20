<?php

namespace Ninthspace\Hunch\Testing;

use Closure;
use Ninthspace\Hunch\HunchManager;
use Ninthspace\Hunch\PendingClassification;
use PHPUnit\Framework\Assert;

/**
 * Stands in for Hunch in tests: classifications are answered from the fake
 * and recorded for assertions.
 */
class HunchFake extends HunchManager
{
    /**
     * @var list<array<string, bool|string>>
     */
    private array $samples = [];

    private bool $preventStrays = false;

    /**
     * @var list<RecordedClassification>
     */
    private array $recorded = [];

    /**
     * @param  array<string, float|array<string, float>>  $answers  Question key => probability of true, or option => share.
     */
    public function __construct(
        private readonly array $answers = [],
    ) {}

    public function of(string|array $state): PendingClassification
    {
        return new PendingClassification($state, new FakeDriver($this));
    }

    /**
     * Script the samples every classification takes; the real aggregator counts them.
     *
     * @param  list<array<string, bool|string>>  $samples
     */
    public function samples(array $samples): static
    {
        $this->samples = $samples;

        return $this;
    }

    public function preventStrayClassifications(bool $prevent = true): static
    {
        $this->preventStrays = $prevent;

        return $this;
    }

    /**
     * @param  Closure(RecordedClassification): bool  $matches
     */
    public function assertClassified(Closure $matches): void
    {
        $count = count(array_filter($this->recorded, $matches));

        Assert::assertGreaterThan(
            0,
            $count,
            $this->recorded === []
                ? 'Expected a matching classification, but nothing was classified.'
                : 'Expected a matching classification, but none of the '.count($this->recorded).' classifications matched.',
        );
    }

    public function assertSampledTimes(int $times): void
    {
        $taken = array_sum(array_map(fn (RecordedClassification $c) => $c->result->samples->requested, $this->recorded));

        Assert::assertSame($times, $taken, "Expected {$times} samples to be taken, but {$taken} were.");
    }

    /**
     * @return array<string, float|array<string, float>>
     */
    public function answers(): array
    {
        return $this->answers;
    }

    /**
     * @return list<array<string, bool|string>>
     */
    public function scriptedSamples(): array
    {
        return $this->samples;
    }

    public function preventsStrays(): bool
    {
        return $this->preventStrays;
    }

    public function record(RecordedClassification $classification): void
    {
        $this->recorded[] = $classification;
    }

    /**
     * @return list<RecordedClassification>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
