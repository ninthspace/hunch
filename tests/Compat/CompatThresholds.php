<?php

namespace Ninthspace\Hunch\Tests\Compat;

/**
 * The decisions the compatibility run fails on, kept apart from the run so
 * they can be checked without calling a provider.
 */
final class CompatThresholds
{
    /**
     * The share of samples a provider may return outside the schema before
     * the run is a failure.
     */
    public const INVALID_LIMIT = 0.10;

    public static function invalidRate(int $invalid, int $samples): float
    {
        return $samples === 0 ? 0.0 : $invalid / $samples;
    }

    /**
     * Whether this run failed the schema-validity check.
     */
    public static function exceedsInvalidLimit(int $invalid, int $samples, float $limit = self::INVALID_LIMIT): bool
    {
        return self::invalidRate($invalid, $samples) > $limit;
    }
}
