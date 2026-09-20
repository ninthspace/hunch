<?php

use Illuminate\Support\Facades\Event;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Events\SampleInvalid;
use Ninthspace\Hunch\Models\StoredSample;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Sampling\SampleValidator;

require_once __DIR__.'/../Persistence/helpers.php';

/*
 * Why a sample was rejected. The reason is stored on the sample row and
 * carried on SampleInvalid, so it says enough to act on and nothing the model
 * chose the words for.
 */

function askedSet(): QuestionSet
{
    return new QuestionSet([
        'intent' => new Choice('What do they want?', ['refund' => 'Money back', 'other' => 'Anything else']),
        'urgent' => new Boolean('Is it urgent?'),
    ]);
}

it('tells an answer that never arrived from one that answered badly', function (mixed $output, ?string $expected) {
    expect(SampleValidator::problem(askedSet(), $output))->toBe($expected);
})->with([
    'nothing at all' => [[], 'The output has no structured data.'],
    'answers something else entirely' => [['colour' => 'blue', 'size' => 'large'], 'The output answers none of the questions.'],
    'one field short' => [['intent' => 'refund'], 'Missing urgent.'],
    'not an object' => ['The customer wants a refund.', 'The output is not a JSON object.'],
    'a value outside the enum' => [['intent' => 'escalate', 'urgent' => true], '`intent` is not one of its allowed values.'],
    'complete' => [['intent' => 'refund', 'urgent' => true], null],
]);

it('counts unexpected fields rather than naming them', function () {
    $one = SampleValidator::problem(askedSet(), ['intent' => 'refund', 'urgent' => true, 'why' => 'because']);
    $two = SampleValidator::problem(askedSet(), ['intent' => 'refund', 'urgent' => true, 'why' => 'a', 'how' => 'b']);

    expect($one)->toBe('The output has an unexpected field.')
        ->and($two)->toBe('The output has 2 unexpected fields.');
});

it('never repeats a field name the model took from the state', function () {
    // The model is told to answer, and instead names a field after the secret
    // in the state. The reason must not carry it anywhere.
    persistenceOn($this);

    ClassifierAgent::fake(function () {
        return ['intent' => 'refund', MARKER => 'leaked'];
    });

    $problems = [];
    Event::listen(SampleInvalid::class, function (SampleInvalid $event) use (&$problems) {
        $problems[] = $event->problem;
    });

    try {
        recording(enquiry())->classify();
    } catch (Throwable) {
        // Every sample is invalid here; the reasons are what is under test.
    }

    $reasons = StoredSample::query()->pluck('invalid_reason')->implode("\n");

    expect($problems)->not->toBeEmpty()
        ->and(implode("\n", $problems))->not->toContain('Q7X2')
        ->and($reasons)->not->toContain('Q7X2')
        ->and($reasons)->toContain('unexpected field')
        ->and(hunchRows())->not->toContain('Q7X2');
});
