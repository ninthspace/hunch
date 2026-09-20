<?php

use Illuminate\Support\Facades\DB;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Answers\Buckets;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Models\CalibrationCell;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Result;

require_once dirname(__DIR__).'/Persistence/helpers.php';

beforeEach(function () {
    persistenceOn($this);
});

/**
 * A sampling-driver classification whose `intent` answer is a unanimous refund.
 */
function unanimousRefund(): Result
{
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund'];
    });

    return Hunch::of('Refund please.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3)
        ->classify();
}

/**
 * A calibration row for the refund answer's cell, with `$overrides` changing any column.
 *
 * @param  array<string, mixed>  $overrides
 */
function refundCell(Result $result, int $n, array $overrides = []): CalibrationCell
{
    return CalibrationCell::query()->create([
        'question_set_hash' => $result->meta->questionSetHash,
        'driver' => 'sampling',
        'provider' => 'anthropic',
        'model' => $result->meta->modelReported ?? 'm',
        'sampling' => 'fixed:3',
        'question' => 'intent',
        'answer' => 'refund',
        'bucket' => '1.0',
        'n' => $n,
        'correct' => $n - 3,
        'accuracy' => ($n - 3) / $n,
        'lower_bound' => 0.75,
        'computed_at' => now(),
        ...$overrides,
    ]);
}

it('places probabilities in configured buckets', function () {
    config()->set('hunch.buckets', [1.0, 0.9, 0.5]);

    expect(Buckets::for(0.95))->toBe('[0.9, 1.0)')
        ->and(Buckets::for(0.4))->toBe('[0, 0.5)')
        ->and(Buckets::for(0.5))->toBe('[0.5, 0.9)')
        ->and(Buckets::for(1.0))->toBe('1.0');
});

it("returns the answer's calibration row once n reaches min_n", function () {
    $result = unanimousRefund();
    $cell = refundCell($result, 30);

    expect($result['intent'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($result['intent']->calibration())->toBe(['n' => 30, 'accuracy' => $cell->accuracy, 'lowerBound' => 0.75]);
});

it('returns null below min_n', function () {
    $result = unanimousRefund();
    refundCell($result, 29);

    expect($result['intent']->calibration())->toBeNull();

    config()->set('hunch.calibration.min_n', 29);

    expect($result['intent']->calibration())->not->toBeNull();
});

it('returns null with no query when persistence is off', function () {
    $result = unanimousRefund();
    refundCell($result, 30);
    config()->set('hunch.persistence', false);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    expect($result['intent']->calibration())->toBeNull()
        ->and($queries)->toBe(0);
});

it('never reads a row kept for another driver, model or sampling rule', function (string $column, string $value) {
    $result = unanimousRefund();
    refundCell($result, 30, [$column => $value]);

    expect($result['intent']->calibration())->toBeNull();

    refundCell($result, 40);

    expect($result['intent']->calibration()['n'] ?? null)->toBe(40);
})->with([
    'another driver' => ['driver', 'typesafe'],
    'another model' => ['model', 'other-model'],
    'another sampling rule' => ['sampling', 'adaptive:3-7'],
    'another provider' => ['provider', 'openai'],
    'another question set' => ['question_set_hash', 'v1:other'],
    'another bucket' => ['bucket', '[0.8, 1.0)'],
    'another answer' => ['answer', 'other'],
]);

it('refuses a bucket configuration that is not strictly descending within (0, 1]', function (array $buckets) {
    config()->set('hunch.buckets', $buckets);

    try {
        Buckets::for(0.7);

        $this->fail('The bucket configuration should have been refused.');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain('hunch.buckets');
    }
})->with([
    'ascending' => [[0.6, 0.8, 1.0]],
    'repeated edge' => [[1.0, 0.8, 0.8]],
    'edge above 1' => [[1.2, 0.8]],
    'edge of 0' => [[1.0, 0.0]],
    'negative edge' => [[1.0, -0.5]],
    'empty' => [[]],
    'not a number' => [[1.0, 'high']],
]);

it('reads back the cell hunch:calibrate wrote from recorded, labelled classifications', function () {
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund'];
    });
    $enquiry = enquiry();

    foreach (range(1, 30) as $i) {
        $id = recording($enquiry)->classify()->id;
        Hunch::label($id, ['intent' => $i <= 27 ? 'refund' : 'other']);
    }

    $this->artisan('hunch:calibrate')->assertSuccessful();
    $fresh = recording($enquiry)->classify();

    expect($fresh['intent']->calibration())->toMatchArray(['n' => 30])
        ->and($fresh['intent']->calibration()['accuracy'] ?? null)->toEqualWithDelta(0.9, 1e-9)
        ->and($fresh['intent']->calibration()['lowerBound'] ?? null)->toEqualWithDelta(0.7437891742081593, 1e-6);
});
