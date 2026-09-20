<?php

namespace Ninthspace\Hunch\Calibration;

use Illuminate\Support\Facades\DB;
use Ninthspace\Hunch\Events\CalibrationComputed;
use Ninthspace\Hunch\Models\CalibrationCell;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\Models\Label;
use Ninthspace\Hunch\Persistence\MissingTables;
use Ninthspace\Hunch\Prompts\Bands;

/**
 * Joins current labels to recorded answers and measures accuracy per
 * calibration cell: question set hash, driver, provider, model, sampling
 * rule, question, answer and bucket. Cells are never merged across drivers
 * or models, and superseded labels are never counted.
 *
 * @phpstan-type Outcome array{cell: array<string, string>, scope: string, correct: bool, stated: float, bucket: string, brier: float, band: string|null}
 * @phpstan-type Measures array{brier: float, ece: float, reliability: array<string, array{n: int, meanStated: float, accuracy: float}>, bands: array<string, array{n: int, accuracy: float, brier: float}>, bandEce: float}
 */
final class Calibrator
{
    /**
     * Recompute every cell, replacing what was there, and report the
     * per-question measures.
     *
     * @return array<string, Measures> Question scope => measures.
     */
    public static function run(): array
    {
        $outcomes = MissingTables::guard(self::outcomes(...));
        $cells = [];
        $questions = [];

        foreach ($outcomes as $outcome) {
            $cell = $outcome['cell'];
            $key = implode("\0", $cell);

            $cells[$key] ??= ['cell' => $cell, 'n' => 0, 'correct' => 0];
            $cells[$key]['n']++;
            $cells[$key]['correct'] += $outcome['correct'] ? 1 : 0;

            $questions[$outcome['scope']][] = $outcome;
        }

        self::store($cells);

        CalibrationComputed::dispatch(count($cells), count($outcomes));

        return array_map(self::measures(...), $questions);
    }

    /**
     * One outcome per current label whose question has a recorded answer
     * that applied.
     *
     * @return list<Outcome>
     */
    private static function outcomes(): array
    {
        $outcomes = [];

        $labels = Label::query()
            ->whereNull('superseded_at')
            ->with('classification.samples')
            ->orderBy('id')
            ->get();

        foreach ($labels as $label) {
            $classification = $label->classification;

            if ($classification?->status !== Classification::COMPLETED) {
                continue;
            }

            $answer = $classification->answers[$label->question] ?? null;

            if (! is_array($answer) || ($answer['applies'] ?? true) !== true) {
                continue;
            }

            $outcome = self::outcome($classification, $label, $answer, self::statedBand($classification, $label->question));

            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $answer  The recorded answer.
     * @return Outcome|null
     */
    private static function outcome(Classification $classification, Label $label, array $answer, ?string $band): ?array
    {
        $scored = Scoring::of($answer, $label->answer);

        if ($scored === null) {
            return null;
        }

        $scope = new CalibrationScope(
            $classification->question_set_hash,
            $classification->driver,
            $classification->provider,
            CalibrationScope::model($classification->model_reported, $classification->model_requested),
            $classification->sampling,
            $label->question,
        );

        return [
            'cell' => [...$scope->columns(), 'answer' => $scored['answer'], 'bucket' => $scored['bucket']],
            'scope' => implode(' / ', $scope->columns()),
            'correct' => $scored['correct'],
            'stated' => $scored['stated'],
            'bucket' => $scored['bucket'],
            'brier' => $scored['brier'],
            'band' => $band,
        ];
    }

    /**
     * The band this classification's samples stated most often for the
     * question, or null when none did.
     */
    private static function statedBand(Classification $classification, string $question): ?string
    {
        $stated = [];

        foreach ($classification->samples as $sample) {
            $band = ($sample->band ?? [])[$question] ?? null;

            if (is_string($band)) {
                $stated[] = $band;
            }
        }

        return Bands::modal($stated);
    }

    /**
     * @param  list<Outcome>  $outcomes
     * @return Measures
     */
    private static function measures(array $outcomes): array
    {
        $buckets = array_map(fn (array $outcome) => [
            'bucket' => $outcome['bucket'],
            'stated' => $outcome['stated'],
            'correct' => $outcome['correct'],
        ], $outcomes);

        $bandBuckets = [];
        $bands = [];

        foreach ($outcomes as $outcome) {
            $band = $outcome['band'];

            if ($band === null) {
                continue;
            }

            $bandBuckets[] = ['bucket' => $band, 'stated' => Bands::probability($band), 'correct' => $outcome['correct']];
            $bands[$band][] = $outcome;
        }

        return [
            'brier' => array_sum(array_column($outcomes, 'brier')) / count($outcomes),
            'ece' => Statistics::expectedCalibrationError($buckets),
            'reliability' => Statistics::reliability($buckets),
            'bands' => array_map(fn (array $band) => [
                'n' => count($band),
                'accuracy' => (float) count(array_filter(array_column($band, 'correct'))) / count($band),
                'brier' => array_sum(array_column($band, 'brier')) / count($band),
            ], $bands),
            'bandEce' => Statistics::expectedCalibrationError($bandBuckets),
        ];
    }

    /**
     * Replace the stored cells with these, keeping each surviving cell's row.
     *
     * @param  array<string, array{cell: array<string, string>, n: int, correct: int}>  $cells
     */
    private static function store(array $cells): void
    {
        DB::transaction(function () use ($cells) {
            $kept = [];

            foreach ($cells as ['cell' => $cell, 'n' => $n, 'correct' => $correct]) {
                $kept[] = CalibrationCell::query()->updateOrCreate(
                    $cell,
                    [
                        'n' => $n,
                        'correct' => $correct,
                        'accuracy' => $correct / $n,
                        'lower_bound' => Statistics::wilsonLowerBound($correct, $n),
                        'computed_at' => now(),
                    ],
                )->id;
            }

            CalibrationCell::query()->whereNotIn('id', $kept)->delete();
        });
    }
}
