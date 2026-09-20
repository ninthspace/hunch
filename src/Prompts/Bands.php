<?php

namespace Ninthspace\Hunch\Prompts;

/**
 * The confidence bands a sample can state for a question under
 * `withSelfReport()`. A band is recorded for comparison only: it never
 * changes a vote, a probability, a bucket or an answer.
 */
final class Bands
{
    /**
     * Band => what it means, highest confidence first.
     */
    public const DEFINITIONS = [
        'high' => 'You are almost certain this answer is right.',
        'medium' => 'You think this answer is right, but it could be wrong.',
        'low' => 'You are unsure; this is close to a guess.',
    ];

    /**
     * The probability each band stands for, used to measure stated bands
     * against measured accuracy.
     */
    private const PROBABILITIES = ['high' => 0.95, 'medium' => 0.75, 'low' => 0.4];

    /**
     * @return non-empty-list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function isBand(mixed $band): bool
    {
        return is_string($band) && array_key_exists($band, self::DEFINITIONS);
    }

    public static function probability(string $band): float
    {
        return self::PROBABILITIES[$band] ?? 0.0;
    }

    /**
     * The band a set of samples stated most often. A tie goes to the lower
     * confidence, so a split never reads as more certain than it was.
     *
     * @param  list<string>  $bands
     */
    public static function modal(array $bands): ?string
    {
        $counts = array_fill_keys(self::keys(), 0);

        foreach ($bands as $band) {
            if (self::isBand($band)) {
                $counts[$band]++;
            }
        }

        $most = max($counts);

        if ($most === 0) {
            return null;
        }

        return (string) array_key_last(array_filter($counts, fn (int $count) => $count === $most));
    }
}
