<?php

use Illuminate\Support\Facades\Artisan;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Models\CalibrationCell;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\Models\Label;
use Ninthspace\Hunch\Models\StoredSample;
use Ninthspace\Hunch\Tests\Fixtures\Enquiry;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    persistenceOn($this);
});

/**
 * Records `$count` classifications against their own enquiries, every one a
 * unanimous refund, labelling the first `$correct` of them refund.
 *
 * @return list<string>
 */
function journey(int $count, int $correct): array
{
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund'];
    });
    $ids = [];

    foreach (range(1, $count) as $i) {
        $enquiry = Enquiry::query()->create(['body' => "Enquiry {$i}: refund please."]);
        $id = recording($enquiry)->classify()->id;
        Hunch::label($id, ['intent' => $i <= $correct ? 'refund' : 'other']);
        $ids[] = $id;
    }

    return $ids;
}

it('carries 30 recorded, labelled classifications through hunch:calibrate into calibration()', function () {
    $enquiry = enquiry();
    journey(30, 27);

    Artisan::call('hunch:calibrate');
    $later = recording($enquiry)->classify();

    $cell = CalibrationCell::query()->sole();

    expect(Classification::query()->count())->toBe(31)
        ->and(StoredSample::query()->count())->toBe(93)
        ->and(Label::query()->whereNull('superseded_at')->count())->toBe(30)
        ->and([$cell->n, $cell->correct])->toBe([30, 27])
        ->and($later['intent']->calibration())->toBe([
            'n' => 30,
            'accuracy' => 0.9,
            'lowerBound' => $cell->lower_bound,
        ])
        ->and($cell->lower_bound)->toEqualWithDelta(0.7437891742081593, 1e-6)
        // The 31st classification is unlabelled, so it is not counted itself.
        ->and($cell->n)->toBe(30);
});

it('changes the cell when a classification is relabelled and calibrated again', function () {
    $ids = journey(30, 27);

    Artisan::call('hunch:calibrate');
    $before = CalibrationCell::query()->sole();

    // The 28th was labelled other; calling it refund makes it a 28th correct answer.
    Hunch::label($ids[27], ['intent' => 'refund']);
    Artisan::call('hunch:calibrate');
    $after = CalibrationCell::query()->sole();

    expect([$before->n, $before->correct])->toBe([30, 27])
        ->and([$after->n, $after->correct])->toBe([30, 28])
        ->and($after->id)->toBe($before->id)
        ->and($after->accuracy)->toEqualWithDelta(28 / 30, 1e-9)
        ->and($after->lower_bound)->toBeGreaterThan($before->lower_bound)
        ->and(Label::query()->where('classification_id', $ids[27])->count())->toBe(2)
        ->and(Label::query()->where('classification_id', $ids[27])->whereNull('superseded_at')->count())->toBe(1);
});
