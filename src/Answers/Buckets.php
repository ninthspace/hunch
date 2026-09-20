<?php

namespace Ninthspace\Hunch\Answers;

use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Support\Settings;

/**
 * Probability buckets, the unit calibration is measured in. The edges come
 * from `hunch.buckets`, highest first; each bucket runs from its edge up to
 * the next edge above it, and an edge of exactly 1.0 is a bucket of its own.
 */
final class Buckets
{
    /**
     * @var non-empty-list<float>
     */
    public const DEFAULT = [1.0, 0.8, 0.6];

    public static function for(float $probability): string
    {
        $edges = self::edges();
        $upper = null;

        foreach ($edges as $edge) {
            if ($edge === 1.0) {
                if ($probability >= 1.0) {
                    return '1.0';
                }
            } elseif ($probability >= $edge) {
                return '['.self::format($edge).', '.self::format($upper ?? 1.0).')';
            }

            $upper = $edge;
        }

        return '[0, '.self::format($upper).')';
    }

    /**
     * The configured edges, checked on use: a non-empty list, strictly
     * descending, every edge in (0, 1].
     *
     * @return non-empty-list<float>
     */
    private static function edges(): array
    {
        $configured = Settings::get('buckets', self::DEFAULT);

        if (! is_array($configured) || $configured === []) {
            throw new ConfigurationException('`hunch.buckets` must be a non-empty list of edges.');
        }

        $edges = [];
        $previous = null;

        foreach ($configured as $edge) {
            if (! is_numeric($edge) || (float) $edge <= 0.0 || (float) $edge > 1.0) {
                throw new ConfigurationException('Every `hunch.buckets` edge must be in (0, 1], not `'.var_export($edge, true).'`.');
            }

            if ($previous !== null && (float) $edge >= $previous) {
                throw new ConfigurationException('`hunch.buckets` must be strictly descending.');
            }

            $edges[] = $previous = (float) $edge;
        }

        return $edges;
    }

    private static function format(float $edge): string
    {
        return $edge === 1.0 ? '1.0' : rtrim(rtrim(number_format($edge, 6, '.', ''), '0'), '.');
    }
}
