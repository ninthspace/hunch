<?php

namespace Ninthspace\Hunch\Sampling;

use Ninthspace\Hunch\Usage;

/**
 * One sample's outcome: the answers it gave, or why it could not be counted,
 * with what it cost and where it came from.
 */
final readonly class Sample
{
    /**
     * @param  array<string, bool|string>|null  $answers  Question key => answer, or null when invalid.
     * @param  array<string, string>|null  $bands  Question key => the band this sample stated.
     */
    private function __construct(
        public int $number,
        public ?array $answers,
        public ?string $invalidReason,
        public Usage $usage,
        public ?string $seed,
        public ?string $requestId,
        public ?string $model,
        public ?string $reason = null,
        public ?array $bands = null,
    ) {}

    /**
     * @param  array<string, bool|string>  $answers
     * @param  string|null  $reason  Display text only, kept apart from the answers so nothing counts it.
     * @param  array<string, string>|null  $bands  Question key => stated band, recorded for comparison only.
     */
    public static function valid(int $number, array $answers, Usage $usage = new Usage, ?string $seed = null, ?string $requestId = null, ?string $model = null, ?string $reason = null, ?array $bands = null): self
    {
        return new self($number, $answers, null, $usage, $seed, $requestId, $model, $reason, $bands);
    }

    public static function invalid(int $number, string $reason, Usage $usage = new Usage, ?string $seed = null, ?string $requestId = null, ?string $model = null): self
    {
        return new self($number, null, $reason, $usage, $seed, $requestId, $model);
    }

    public function isValid(): bool
    {
        return $this->answers !== null;
    }

    /**
     * This sample's answer to `$key`, or null when it is invalid or did not answer it.
     */
    public function answerTo(string $key): bool|string|null
    {
        return $this->answers[$key] ?? null;
    }
}
