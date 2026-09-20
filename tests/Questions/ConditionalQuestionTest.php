<?php

use Ninthspace\Hunch\Aggregation\Aggregator;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Sampling\Sample;
use Ninthspace\Hunch\Sampling\SamplingRule;
use Ninthspace\Hunch\Sampling\Stopping;

function bookingSet(string|array $values = 'group'): QuestionSet
{
    return new QuestionSet([
        'intent' => new Choice('What is it about?', ['group' => 'A group booking', 'refund' => 'A refund', 'other' => 'Anything else']),
        'group_date' => (new Boolean('Is a date given?'))->onlyWhen('intent', $values),
    ]);
}

/**
 * @param  list<array{string, bool}>  $rows  intent, group_date per sample
 * @return list<Sample>
 */
function bookingSamples(array $rows): array
{
    return array_map(
        fn (array $row, int $i) => Sample::valid($i + 1, ['intent' => $row[0], 'group_date' => $row[1]]),
        $rows,
        array_keys($rows),
    );
}

it('computes a conditional answer over the matching samples only', function () {
    $answers = Aggregator::answers(bookingSet(), bookingSamples([
        ['group', true], ['group', true], ['group', false], ['refund', false], ['group', true],
    ]));

    expect($answers['group_date']->votes)->toBe(['true' => 3, 'false' => 1])
        ->and($answers['group_date']->probability)->toBe(0.75);
});

it('applies only when the winning controlling answer matches', function () {
    $applies = Aggregator::answers(bookingSet(), bookingSamples([
        ['group', true], ['group', true], ['refund', false],
    ]));
    $doesNot = Aggregator::answers(bookingSet(), bookingSamples([
        ['refund', true], ['refund', true], ['group', true], ['group', false], ['refund', false],
    ]));

    expect($applies['group_date']->applies)->toBeTrue()
        ->and($doesNot['group_date']->applies)->toBeFalse()
        ->and($doesNot['intent']->applies)->toBeTrue();
});

it('gives a null answer when fewer than two samples qualify', function () {
    $answers = Aggregator::answers(bookingSet(), bookingSamples([
        ['group', true], ['refund', false], ['refund', true],
    ]));

    expect($answers['group_date'])->toBeNull()
        ->and($answers['intent'])->not->toBeNull();
});

it('counts samples matching any listed value', function () {
    $answers = Aggregator::answers(bookingSet(['group', 'refund']), bookingSamples([
        ['group', true], ['refund', false], ['other', true], ['refund', true],
    ]));

    expect(array_sum($answers['group_date']->votes))->toBe(3);
});

it('refuses a condition on a missing key, a non-Choice question, or an unknown value', function (string $key, string $value) {
    new QuestionSet([
        'intent' => new Choice('What is it about?', ['group' => 'A group booking', 'refund' => 'A refund']),
        'urgency' => new Score('How urgent?', ['Low', 'High']),
        'group_date' => (new Boolean('Is a date given?'))->onlyWhen($key, $value),
    ]);
})->with([
    'missing key' => ['nope', 'group'],
    'not a Choice' => ['urgency', 'High'],
    'unknown value' => ['intent', 'party'],
])->throws(InvalidArgumentException::class);

it('does not wait for unanimity on a conditional question that does not apply', function () {
    $taken = bookingSamples([
        ['refund', true], ['refund', false], ['refund', true],
    ]);

    // intent is unanimous (refund); group_date splits but does not apply, so sampling stops.
    expect(Stopping::nextBatch(SamplingRule::adaptive(3, 7), bookingSet(), $taken))->toBe(0);
});

it('keeps the question set hash unchanged by a condition', function () {
    $plain = new QuestionSet([
        'intent' => new Choice('What is it about?', ['group' => 'A group booking', 'refund' => 'A refund', 'other' => 'Anything else']),
        'group_date' => new Boolean('Is a date given?'),
    ]);

    expect(bookingSet()->hash())->toBe($plain->hash());
});
