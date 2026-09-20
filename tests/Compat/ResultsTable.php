<?php

namespace Ninthspace\Hunch\Tests\Compat;

use RuntimeException;

/**
 * Writes the compatibility results into the README, between its markers and
 * nowhere else. The run is manual and local: this changes one file and never
 * commits, pushes or opens anything.
 */
final class ResultsTable
{
    public const START = '<!-- compat:results -->';

    public const END = '<!-- compat:results:end -->';

    public const README = __DIR__.'/../../README.md';

    /**
     * Replace the table between the markers, leaving every other byte of the
     * README as it was.
     *
     * @param  list<array{provider: string, model: string, items: int, samples: int, invalid: float, accuracy: float, input: int, cached: int, output: int}>  $rows
     */
    public static function write(array $rows, string $ranAt, string $readme = self::README): void
    {
        $current = (string) file_get_contents($readme);
        $start = strpos($current, self::START);
        $end = strpos($current, self::END);

        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('The README has no compat:results markers to write between.');
        }

        $replacement = self::START."\n".self::render($rows, $ranAt)."\n";

        file_put_contents($readme, substr($current, 0, $start).$replacement.substr($current, $end));
    }

    /**
     * @param  list<array{provider: string, model: string, items: int, samples: int, invalid: float, accuracy: float, input: int, cached: int, output: int}>  $rows
     */
    public static function render(array $rows, string $ranAt): string
    {
        $lines = [
            '| Provider | Model | Items | Samples | Invalid samples | Accuracy | Input | Cached input | Output |',
            '|---|---|---:|---:|---:|---:|---:|---:|---:|',
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| %s | %s | %d | %d | %.1f%% | %.1f%% | %s | %s | %s |',
                $row['provider'],
                $row['model'],
                $row['items'],
                $row['samples'],
                $row['invalid'] * 100,
                $row['accuracy'] * 100,
                number_format($row['input']),
                number_format($row['cached']),
                number_format($row['output']),
            );
        }

        $lines[] = '';
        $lines[] = "Last run {$ranAt} by `composer compat`, over ".count($rows).' provider'.(count($rows) === 1 ? '' : 's').'.';
        $lines[] = 'Accuracy is measured over a small synthetic fixture set and is a compatibility check, not a benchmark: see `calibration()` for measured accuracy in an application.';

        return implode("\n", $lines);
    }
}
