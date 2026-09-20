<?php

use Ninthspace\Hunch\Calibration\Statistics;

it('matches the reference Wilson 95% lower bound for 27 correct out of 30', function () {
    // Reference: Python statistics.NormalDist, z = inv_cdf(0.975).
    expect(Statistics::wilsonLowerBound(27, 30))->toEqualWithDelta(0.7437891742081593, 1e-6);
});

it('bounds the Wilson lower bound at the edges', function () {
    expect(Statistics::wilsonLowerBound(0, 30))->toEqualWithDelta(0.0, 1e-12)
        ->and(Statistics::wilsonLowerBound(30, 30))->toBeLessThan(1.0)
        ->and(Statistics::wilsonLowerBound(30, 30))->toBeGreaterThan(0.88)
        ->and(Statistics::wilsonLowerBound(0, 0))->toBe(0.0);
});

it('scores Boolean probabilities with the Brier score', function () {
    expect(Statistics::booleanBrier([1.0, 0.8, 0.6], [true, false, true]))
        ->toEqualWithDelta((0 + 0.64 + 0.16) / 3, 1e-9);
});

it('scores Choice probabilities with the multi-class Brier score', function () {
    $probabilities = [
        ['a' => 0.7, 'b' => 0.2, 'c' => 0.1],
        ['a' => 0.2, 'b' => 0.5, 'c' => 0.3],
    ];

    // (0.09 + 0.04 + 0.01) and (0.04 + 0.25 + 0.49), averaged.
    expect(Statistics::multiclassBrier($probabilities, ['a', 'c']))->toEqualWithDelta((0.14 + 0.78) / 2, 1e-9);
});

it('computes the expected calibration error as the count-weighted gap per bucket', function () {
    $outcomes = [
        ['bucket' => '1.0', 'stated' => 1.0, 'correct' => true],
        ['bucket' => '1.0', 'stated' => 1.0, 'correct' => true],
        ['bucket' => '0.8', 'stated' => 0.9, 'correct' => true],
        ['bucket' => '0.8', 'stated' => 0.8, 'correct' => false],
        ['bucket' => '0.8', 'stated' => 0.85, 'correct' => true],
        ['bucket' => '0', 'stated' => 0.5, 'correct' => false],
        ['bucket' => '0', 'stated' => 0.6, 'correct' => true],
        ['bucket' => '0', 'stated' => 0.55, 'correct' => false],
        ['bucket' => '0', 'stated' => 0.55, 'correct' => false],
        ['bucket' => '0', 'stated' => 0.5, 'correct' => true],
    ];

    // Gaps: |1.0 - 1.0| = 0 (n 2), |0.85 - 2/3| (n 3), |0.54 - 0.4| = 0.14 (n 5).
    expect(Statistics::expectedCalibrationError($outcomes))
        ->toEqualWithDelta((2 * 0 + 3 * abs(0.85 - 2 / 3) + 5 * 0.14) / 10, 1e-9);
});

it('builds a reliability table of mean stated probability against accuracy per bucket', function () {
    $table = Statistics::reliability([
        ['bucket' => '0.8', 'stated' => 0.9, 'correct' => true],
        ['bucket' => '0.8', 'stated' => 0.8, 'correct' => false],
        ['bucket' => '1.0', 'stated' => 1.0, 'correct' => true],
    ]);

    expect(array_keys($table))->toBe(['0.8', '1.0'])
        ->and($table['0.8']['n'])->toBe(2)
        ->and($table['0.8']['meanStated'])->toEqualWithDelta(0.85, 1e-9)
        ->and($table['0.8']['accuracy'])->toEqualWithDelta(0.5, 1e-9)
        ->and($table['1.0'])->toBe(['n' => 1, 'meanStated' => 1.0, 'accuracy' => 1.0]);
});
