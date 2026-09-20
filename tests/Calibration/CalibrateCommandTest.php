<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Ninthspace\Hunch\Events\CalibrationComputed;
use Ninthspace\Hunch\Models\CalibrationCell;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\Models\Label;

require_once dirname(__DIR__).'/Persistence/helpers.php';

const SET_HASH = 'v1:calibration-fixture';

beforeEach(function () {
    persistenceOn($this);
});

/**
 * One recorded classification of `intent` with the given winning answer and
 * shares, labelled with `$label` unless it is null.
 *
 * @param  array<string, float>  $probabilities
 */
function classified(string $driver, string $model, string $answer, array $probabilities, string $bucket, ?string $label): Classification
{
    $classification = Classification::query()->create([
        'id' => (string) Str::ulid(),
        'state_hash' => str_repeat('0', 64),
        'question_set_hash' => SET_HASH,
        'driver' => $driver,
        'provider' => 'anthropic',
        'model_requested' => $model,
        'model_reported' => $model,
        'sampling' => 'fixed:5',
        'status' => Classification::COMPLETED,
        'samples_requested' => 5,
        'samples_valid' => 5,
        'samples_invalid' => 0,
        'usage' => ['input' => 0, 'cached_input_read' => 0, 'cached_input_write' => 0, 'output' => 0],
        'answers' => ['intent' => [
            'answer' => $answer,
            'probabilities' => $probabilities,
            'votes' => [],
            'tied' => false,
            'applies' => true,
            'bucket' => $bucket,
        ]],
    ]);

    if ($label !== null) {
        Label::query()->create(['classification_id' => $classification->id, 'question' => 'intent', 'answer' => $label]);
    }

    return $classification;
}

/**
 * 40 labelled classifications across two drivers, plus unlabelled and
 * relabelled ones that must not change the counts.
 *
 * Expected cells:
 * - sampling / m1 / refund / 1.0          n 18, correct 16
 * - sampling / m3 / refund / 1.0          n 2,  correct 2   (same driver, other model)
 * - typesafe / m2 / refund / [0.8, 1.0)   n 10, correct 7
 * - typesafe / m2 / other  / [0.6, 0.8)   n 10, correct 5
 */
function calibrationFixture(): void
{
    $sure = ['refund' => 1.0, 'other' => 0.0];

    foreach (range(1, 16) as $i) {
        classified('sampling', 'm1', 'refund', $sure, '1.0', 'refund');
    }

    foreach (range(1, 2) as $i) {
        classified('sampling', 'm1', 'refund', $sure, '1.0', 'other');
    }

    foreach (range(1, 2) as $i) {
        classified('sampling', 'm3', 'refund', $sure, '1.0', 'refund');
    }

    foreach (range(1, 10) as $i) {
        classified('typesafe', 'm2', 'refund', ['refund' => 0.8, 'other' => 0.2], '[0.8, 1.0)', $i <= 7 ? 'refund' : 'other');
    }

    foreach (range(1, 10) as $i) {
        classified('typesafe', 'm2', 'other', ['refund' => 0.4, 'other' => 0.6], '[0.6, 0.8)', $i <= 5 ? 'other' : 'refund');
    }

    // Unlabelled: never counted.
    foreach (range(1, 3) as $i) {
        classified('sampling', 'm1', 'refund', $sure, '1.0', null);
    }

    // A superseded, wrong label on a correctly labelled classification: counting
    // it would add a 19th, incorrect outcome to the sampling/m1 cell.
    $relabelled = Classification::query()->where('driver', 'sampling')->where('model_reported', 'm1')->firstOrFail();
    Label::query()->create([
        'classification_id' => $relabelled->id,
        'question' => 'intent',
        'answer' => 'other',
        'superseded_at' => now(),
    ]);
}

/**
 * @return array<string, array{n: int, correct: int, accuracy: float, lower_bound: float}>
 */
function cells(): array
{
    $cells = [];

    foreach (CalibrationCell::query()->get() as $cell) {
        $cells["{$cell->driver}/{$cell->model}/{$cell->answer}/{$cell->bucket}"] = [
            'n' => $cell->n,
            'correct' => $cell->correct,
            'accuracy' => $cell->accuracy,
            'lower_bound' => $cell->lower_bound,
        ];
    }

    ksort($cells);

    return $cells;
}

it('writes one row per cell with n, correct, accuracy and lower bound', function () {
    calibrationFixture();

    $this->artisan('hunch:calibrate')->assertSuccessful();

    $cells = cells();

    expect(array_keys($cells))->toBe([
        'sampling/m1/refund/1.0',
        'sampling/m3/refund/1.0',
        'typesafe/m2/other/[0.6, 0.8)',
        'typesafe/m2/refund/[0.8, 1.0)',
    ])
        ->and(array_map(fn (array $cell) => [$cell['n'], $cell['correct']], $cells))->toBe([
            'sampling/m1/refund/1.0' => [18, 16],
            'sampling/m3/refund/1.0' => [2, 2],
            'typesafe/m2/other/[0.6, 0.8)' => [10, 5],
            'typesafe/m2/refund/[0.8, 1.0)' => [10, 7],
        ])
        ->and($cells['sampling/m1/refund/1.0']['accuracy'])->toEqualWithDelta(16 / 18, 1e-9)
        ->and($cells['typesafe/m2/refund/[0.8, 1.0)']['accuracy'])->toEqualWithDelta(0.7, 1e-9)
        // Reference lower bounds: Python statistics.NormalDist Wilson interval.
        ->and($cells['sampling/m1/refund/1.0']['lower_bound'])->toEqualWithDelta(0.6720023486982121, 1e-6)
        ->and($cells['typesafe/m2/other/[0.6, 0.8)']['lower_bound'])->toEqualWithDelta(0.23659309051256408, 1e-6);

    $row = CalibrationCell::query()->where('driver', 'typesafe')->where('answer', 'refund')->sole();

    expect([$row->question_set_hash, $row->provider, $row->sampling, $row->question])
        ->toBe([SET_HASH, 'anthropic', 'fixed:5', 'intent']);
});

it('counts only classifications with a current label for the question', function () {
    calibrationFixture();

    $this->artisan('hunch:calibrate')->assertSuccessful();

    expect(array_sum(array_column(cells(), 'n')))->toBe(40)
        ->and(Label::query()->whereNotNull('superseded_at')->count())->toBe(1);
});

it('replaces its previous values when run again', function () {
    $this->freezeTime();
    calibrationFixture();

    $this->artisan('hunch:calibrate')->assertSuccessful();
    $first = CalibrationCell::query()->orderBy('id')->get()->toArray();
    CalibrationCell::query()->first()?->update(['n' => 999, 'correct' => 0]);
    CalibrationCell::query()->create([
        'question_set_hash' => 'stale', 'driver' => 'x', 'provider' => 'x', 'model' => 'x', 'sampling' => 'x',
        'question' => 'x', 'answer' => 'x', 'bucket' => 'x', 'n' => 1, 'correct' => 1, 'accuracy' => 1.0,
        'lower_bound' => 0.0, 'computed_at' => now(),
    ]);
    $this->artisan('hunch:calibrate')->assertSuccessful();

    expect(CalibrationCell::query()->orderBy('id')->get()->toArray())->toBe($first);
});

it('keeps drivers and models in separate cells', function () {
    calibrationFixture();

    $this->artisan('hunch:calibrate')->assertSuccessful();

    expect(CalibrationCell::query()->where('answer', 'refund')->pluck('model', 'driver')->all())->toHaveCount(2)
        ->and(CalibrationCell::query()->where('driver', 'sampling')->where('answer', 'refund')->pluck('n', 'model')->all())->toBe(['m1' => 18, 'm3' => 2]);
});

it('reports Brier score, expected calibration error and the reliability table per question', function () {
    calibrationFixture();

    // typesafe/m2: Brier (7 x 0.08 + 3 x 1.28 + 5 x 0.32 + 5 x 0.72) / 20 = 0.48;
    // ECE: both buckets are 0.1 over-confident (0.8 vs 0.7, 0.6 vs 0.5).
    $this->artisan('hunch:calibrate')
        ->expectsOutputToContain(SET_HASH.' / typesafe / anthropic / m2 / fixed:5 / intent')
        ->expectsOutputToContain('Brier 0.4800')
        ->expectsOutputToContain('ECE 0.1000')
        ->assertSuccessful();
});

it('dispatches CalibrationComputed once per run', function () {
    calibrationFixture();
    Event::fake([CalibrationComputed::class]);

    $this->artisan('hunch:calibrate')->assertSuccessful();

    Event::assertDispatchedTimes(CalibrationComputed::class, 1);
    Event::assertDispatched(CalibrationComputed::class, fn (CalibrationComputed $event) => $event->cells === 4 && $event->labelled === 40);
});

/**
 * A recorded classification with one answer under `$question`, labelled `$label`.
 *
 * @param  array<string, mixed>  $answer
 */
function answered(string $question, array $answer, bool|string $label): void
{
    $classification = classified('sampling', 'm1', 'refund', ['refund' => 1.0, 'other' => 0.0], '1.0', null);
    $classification->update(['answers' => [$question => [...$answer, 'applies' => true]]]);
    Label::query()->create(['classification_id' => $classification->id, 'question' => $question, 'answer' => $label]);
}

it('judges a Boolean at probability 0.5 or more, and a Score by its modal level', function () {
    answered('urgent', ['answer' => true, 'probability' => 0.8, 'bucket' => '[0.8, 1.0)'], true);
    answered('urgent', ['answer' => true, 'probability' => 0.8, 'bucket' => '[0.8, 1.0)'], false);
    answered('urgent', ['answer' => false, 'probability' => 0.3, 'bucket' => '[0.6, 0.8)'], false);
    answered('tone', ['answer' => 'calm', 'level' => 'annoyed', 'probabilities' => ['calm' => 0.6, 'annoyed' => 0.0, 'furious' => 0.4], 'bucket' => '[0.6, 0.8)'], 'calm');

    // urgent: Brier (0.04 + 0.64 + 0.09) / 3; ECE: [0.8, 1.0) stated 0.8 vs 0.5 (n 2), [0.6, 0.8) stated 0.7 vs 1.0 (n 1).
    $this->artisan('hunch:calibrate')
        ->expectsOutputToContain(SET_HASH.' / sampling / anthropic / m1 / fixed:5 / urgent')
        ->expectsOutputToContain('Brier 0.2567')
        ->expectsOutputToContain('ECE 0.3000')
        ->assertSuccessful();

    expect(CalibrationCell::query()->where('question', 'urgent')->orderBy('answer')->get(['answer', 'bucket', 'n', 'correct'])->toArray())->toBe([
        ['answer' => 'false', 'bucket' => '[0.6, 0.8)', 'n' => 1, 'correct' => 1],
        ['answer' => 'true', 'bucket' => '[0.8, 1.0)', 'n' => 2, 'correct' => 1],
    ])
        ->and(CalibrationCell::query()->where('question', 'tone')->sole()->only(['answer', 'n', 'correct']))->toBe(['answer' => 'calm', 'n' => 1, 'correct' => 1]);
});

it('refuses to calibrate when persistence is off', function () {
    config()->set('hunch.persistence', false);

    $this->artisan('hunch:calibrate')->expectsOutputToContain('hunch.persistence')->assertFailed();
});

it('skips a labelled answer to a conditional question that did not apply', function () {
    answered('group_date', ['answer' => 'weekend', 'probabilities' => ['weekend' => 1.0, 'weekday' => 0.0], 'bucket' => '1.0'], 'weekend');
    Classification::query()->sole()->update(['answers' => ['group_date' => [
        'answer' => 'weekend', 'probabilities' => ['weekend' => 1.0, 'weekday' => 0.0], 'bucket' => '1.0', 'applies' => false,
    ]]]);

    $this->artisan('hunch:calibrate')->assertSuccessful();

    expect(CalibrationCell::query()->count())->toBe(0);
});
