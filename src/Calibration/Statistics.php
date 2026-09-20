<?php

namespace Ninthspace\Hunch\Calibration;

/**
 * The measures calibration and evaluation report. Pure functions over
 * numbers, shared by `hunch:calibrate` and `hunch:eval`.
 */
final class Statistics
{
    /**
     * z for a two-sided 95% interval.
     */
    private const Z95 = 1.959963984540054;

    /**
     * The lower bound of the 95% Wilson score interval for `$correct` out of `$n`.
     */
    public static function wilsonLowerBound(int $correct, int $n): float
    {
        if ($n === 0) {
            return 0.0;
        }

        $p = $correct / $n;
        $z2 = self::Z95 ** 2;

        $centre = $p + $z2 / (2 * $n);
        $margin = self::Z95 * sqrt($p * (1 - $p) / $n + $z2 / (4 * $n * $n));

        return max(0.0, ($centre - $margin) / (1 + $z2 / $n));
    }

    /**
     * Mean squared error of each probability of true against its label.
     *
     * @param  list<float>  $probabilities
     * @param  list<bool>  $labels
     */
    public static function booleanBrier(array $probabilities, array $labels): float
    {
        if ($probabilities === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($probabilities as $i => $p) {
            $total += ($p - ($labels[$i] ? 1.0 : 0.0)) ** 2;
        }

        return $total / count($probabilities);
    }

    /**
     * The multi-class Brier score: for each item, the squared error summed
     * over every option against the one-hot label, then averaged.
     *
     * @param  list<array<string, float>>  $probabilities  Option => probability, per item.
     * @param  list<string>  $labels
     */
    public static function multiclassBrier(array $probabilities, array $labels): float
    {
        if ($probabilities === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($probabilities as $i => $options) {
            foreach ($options as $option => $p) {
                $total += ($p - ($option === $labels[$i] ? 1.0 : 0.0)) ** 2;
            }
        }

        return $total / count($probabilities);
    }

    /**
     * The count-weighted mean, across buckets, of |mean stated probability − accuracy|.
     *
     * @param  list<array{bucket: string, stated: float, correct: bool}>  $outcomes
     */
    public static function expectedCalibrationError(array $outcomes): float
    {
        if ($outcomes === []) {
            return 0.0;
        }

        $error = 0.0;

        foreach (self::reliability($outcomes) as $bucket) {
            $error += $bucket['n'] * abs($bucket['meanStated'] - $bucket['accuracy']);
        }

        return $error / count($outcomes);
    }

    /**
     * Per bucket, in first-seen order: how many outcomes, their mean stated
     * probability, and the share that were correct.
     *
     * @param  list<array{bucket: string, stated: float, correct: bool}>  $outcomes
     * @return array<string, array{n: int, meanStated: float, accuracy: float}>
     */
    public static function reliability(array $outcomes): array
    {
        $buckets = [];

        foreach ($outcomes as $outcome) {
            $key = $outcome['bucket'];

            $buckets[$key] ??= ['n' => 0, 'stated' => 0.0, 'correct' => 0];
            $buckets[$key]['n']++;
            $buckets[$key]['stated'] += $outcome['stated'];
            $buckets[$key]['correct'] += $outcome['correct'] ? 1 : 0;
        }

        return array_map(fn (array $bucket) => [
            'n' => $bucket['n'],
            'meanStated' => $bucket['stated'] / $bucket['n'],
            'accuracy' => (float) $bucket['correct'] / $bucket['n'],
        ], $buckets);
    }
}
