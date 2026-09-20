<?php

namespace Ninthspace\Hunch\Commands;

use Illuminate\Console\Command;
use Ninthspace\Hunch\Calibration\Calibrator;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Support\Settings;

/**
 * Measures accuracy against human labels, per calibration cell, and reports
 * each question's Brier score, expected calibration error and reliability.
 */
class CalibrateCommand extends Command
{
    protected $signature = 'hunch:calibrate';

    protected $description = 'Measure Hunch answers against human labels, per calibration cell';

    public function handle(): int
    {
        if (! Settings::get('persistence', false)) {
            $this->error('hunch:calibrate needs `hunch.persistence` to be on.');

            return self::FAILURE;
        }

        try {
            $questions = Calibrator::run();
        } catch (ConfigurationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($questions as $scope => $measures) {
            $this->line($scope);
            $this->line(sprintf('  Brier %.4f', $measures['brier']));
            $this->line(sprintf('  ECE %.4f', $measures['ece']));
            $this->table(['Bucket', 'n', 'Mean stated', 'Accuracy'], $this->reliabilityRows($measures['reliability']));

            if ($measures['bands'] !== []) {
                $this->line(sprintf('  Band ECE %.4f', $measures['bandEce']));
                $this->table(['Band', 'n', 'Accuracy', 'Brier'], $this->bandRows($measures['bands']));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Stated confidence bands, when `withSelfReport()` recorded any.
     *
     * @param  array<string, array{n: int, accuracy: float, brier: float}>  $bands
     * @return list<array{string, int, string, string}>
     */
    private function bandRows(array $bands): array
    {
        $rows = [];

        foreach ($bands as $band => $row) {
            $rows[] = [(string) $band, $row['n'], sprintf('%.3f', $row['accuracy']), sprintf('%.4f', $row['brier'])];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{n: int, meanStated: float, accuracy: float}>  $reliability
     * @return list<array{string, int, string, string}>
     */
    private function reliabilityRows(array $reliability): array
    {
        $rows = [];

        foreach ($reliability as $bucket => $row) {
            $rows[] = [(string) $bucket, $row['n'], sprintf('%.3f', $row['meanStated']), sprintf('%.3f', $row['accuracy'])];
        }

        return $rows;
    }
}
