<?php

use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Sampling\Sample;
use Ninthspace\Hunch\Sampling\SamplingRule;
use Ninthspace\Hunch\Sampling\Stopping;

function adaptive(): PendingClassification
{
    return Hunch::of('Which one?')
        ->using('anthropic', 'm')
        ->question('pick', new Choice('Which?', ['A' => 'First', 'B' => 'Second']));
}

/**
 * A fake that answers `pick` with each letter in turn, then keeps answering the last.
 *
 * @param  list<string>  $letters
 */
function answering(array $letters): Closure
{
    $call = 0;

    return function () use (&$call, $letters) {
        $letter = $letters[min($call++, count($letters) - 1)];

        return $letter === '!' ? ['pick' => 'not-an-option'] : ['pick' => $letter];
    };
}

it('stops after min samples when they are unanimous', function () {
    ClassifierAgent::fake(answering(['A', 'A', 'A', 'B', 'B', 'B', 'B']));

    $result = adaptive()->sampling(adaptive: [3, 7])->classify();

    ClassifierAgent::assertPromptedTimes(3);
    expect($result->samples->requested)->toBe(3);
});

it('adds two at a time, then stops once the leader cannot be caught', function () {
    ClassifierAgent::fake(answering(['A', 'A', 'B', 'A', 'A', 'B', 'B']));

    $result = adaptive()->sampling(adaptive: [3, 7])->classify();

    ClassifierAgent::assertPromptedTimes(5);
    expect($result['pick']->votes)->toBe(['A' => 4, 'B' => 1]);
});

it('reaches max and stops while the votes still split', function () {
    ClassifierAgent::fake(answering(['A', 'B', 'A', 'B', 'A', 'B', 'A', 'B', 'A']));

    adaptive()->sampling(adaptive: [3, 7])->classify();

    ClassifierAgent::assertPromptedTimes(7);
});

it('never steps past max', function () {
    $set = new QuestionSet(['pick' => new Choice('Which?', ['A' => 'First', 'B' => 'Second'])]);
    $split = [Sample::valid(1, ['pick' => 'A']), Sample::valid(2, ['pick' => 'B']), Sample::valid(3, ['pick' => 'A'])];

    expect(Stopping::nextBatch(SamplingRule::adaptive(3, 4), $set, $split))->toBe(1)
        ->and(Stopping::nextBatch(SamplingRule::adaptive(3, 7), $set, $split))->toBe(2)
        ->and(Stopping::nextBatch(SamplingRule::adaptive(3, 4), $set, [...$split, Sample::valid(4, ['pick' => 'B'])]))->toBe(0);
});

it('ignores invalid samples when judging unanimity, and reaches min valid ones first', function () {
    ClassifierAgent::fake(answering(['A', '!', 'A', 'A']));

    $result = adaptive()->sampling(adaptive: [3, 7])->classify();

    // `min` counts valid samples, so the invalid second one is replaced before
    // anything is judged; the three valid A's are then unanimous and stop the
    // run, which they could not be if the invalid sample voted for anything.
    ClassifierAgent::assertPromptedTimes(4);
    expect([$result->samples->valid, $result->samples->invalid])->toBe([3, 1])
        ->and($result['pick']->votes)->toBe(['A' => 3, 'B' => 0]);
});

it('never makes more model calls than max plus the resampling cap', function () {
    config()->set('hunch.min_valid_samples', 2);
    ClassifierAgent::fake(answering(['A', '!', 'B', '!', '!', '!', '!', '!', '!', '!']));

    $result = adaptive()->sampling(adaptive: [3, 5])->classify();

    // Five the rule allows plus two replacements, and no more however many
    // of them come back invalid.
    ClassifierAgent::assertPromptedTimes(7);
    expect([$result->samples->requested, $result->samples->invalid])->toBe([7, 5]);
});

it('keeps sampling while a trailing answer could still tie the leader', function () {
    $set = new QuestionSet(['pick' => new Choice('Which?', ['A' => 'First', 'B' => 'Second'])]);
    $leadByTwo = [
        Sample::valid(1, ['pick' => 'A']), Sample::valid(2, ['pick' => 'A']), Sample::valid(3, ['pick' => 'A']),
        Sample::valid(4, ['pick' => 'B']), Sample::valid(5, ['pick' => 'A']),
    ];

    // 4-1 with 2 left cannot be caught; 3-1 with 2 left can still be tied, so sampling goes on.
    expect(Stopping::nextBatch(SamplingRule::adaptive(3, 7), $set, $leadByTwo))->toBe(0)
        ->and(Stopping::nextBatch(SamplingRule::adaptive(3, 6), $set, array_slice($leadByTwo, 0, 4)))->toBe(2);
});
