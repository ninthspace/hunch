<?php

use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Testing\RecordedClassification;
use Ninthspace\Hunch\Testing\StrayClassification;
use PHPUnit\Framework\AssertionFailedError;

function customerEmail(): PendingClassification
{
    return Hunch::of('Please refund my order, I need it today.')->questions([
        'intent' => new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']),
        'reply_today' => new Boolean('Should we reply today?'),
    ]);
}

it('answers from direct probabilities without calling the agent', function () {
    ClassifierAgent::fake();
    Hunch::fake(['intent' => ['refund' => 0.8, 'other' => 0.2], 'reply_today' => 0.6]);

    $result = customerEmail()->classify();

    expect($result['intent']->probabilities)->toBe(['refund' => 0.8, 'other' => 0.2])
        ->and($result['intent']->choice)->toBe('refund')
        ->and($result['reply_today']->probability)->toBe(0.6);
    ClassifierAgent::assertNeverPrompted();
});

it('runs scripted samples through the real aggregator', function () {
    Hunch::fake()->samples([
        ['intent' => 'refund', 'reply_today' => true],
        ['intent' => 'refund', 'reply_today' => true],
        ['intent' => 'other', 'reply_today' => false],
    ]);

    $intent = customerEmail()->classify()['intent'];

    expect($intent->choice)->toBe('refund')
        ->and($intent->confidence)->toEqualWithDelta(2 / 3, 1e-9)
        ->and($intent->votes)->toBe(['refund' => 2, 'other' => 1]);
});

it('asserts a matching classification happened', function () {
    $fake = Hunch::fake(['intent' => ['refund' => 1.0], 'reply_today' => 1.0]);

    customerEmail()->classify();

    $fake->assertClassified(fn (RecordedClassification $c) => $c->result['intent']->choice === 'refund');
});

it('fails assertClassified with a readable message when nothing matched', function () {
    $fake = Hunch::fake(['intent' => ['refund' => 1.0], 'reply_today' => 1.0]);

    expect(fn () => $fake->assertClassified(fn () => true))
        ->toThrow(AssertionFailedError::class, 'nothing was classified');

    customerEmail()->classify();

    expect(fn () => $fake->assertClassified(fn (RecordedClassification $c) => $c->result['intent']->choice === 'other'))
        ->toThrow(AssertionFailedError::class, 'none of the 1 classifications matched');
});

it('asserts how many samples were taken', function () {
    $fake = Hunch::fake()->samples([
        ['intent' => 'refund', 'reply_today' => true],
        ['intent' => 'refund', 'reply_today' => true],
        ['intent' => 'other', 'reply_today' => false],
    ]);

    customerEmail()->classify();

    $fake->assertSampledTimes(3);
    expect(fn () => $fake->assertSampledTimes(2))->toThrow(AssertionFailedError::class, 'Expected 2 samples');
});

it('throws for a classification no fake answers under preventStrayClassifications()', function () {
    Hunch::fake(['intent' => ['refund' => 1.0]])->preventStrayClassifications();

    customerEmail()->classify();
})->throws(StrayClassification::class, 'reply_today');

it('produces identical user messages on two runs of the same classification', function () {
    $fake = Hunch::fake()->samples([
        ['intent' => 'refund', 'reply_today' => true],
        ['intent' => 'other', 'reply_today' => false],
        ['intent' => 'refund', 'reply_today' => true],
    ]);

    customerEmail()->classify();
    customerEmail()->classify();

    [$first, $second] = $fake->recorded();

    expect($first->userMessages)->toHaveCount(3)
        ->and($second->userMessages)->toBe($first->userMessages);
});

it('gives the sampling driver and the fake driver the same answer classes and public surface', function () {
    $scripted = [
        ['intent' => 'refund', 'reply_today' => true],
        ['intent' => 'refund', 'reply_today' => false],
        ['intent' => 'other', 'reply_today' => true],
    ];

    ClassifierAgent::fake($scripted);
    $sampled = customerEmail()->using('anthropic', 'm')->sampling(3)->classify();

    Hunch::fake()->samples($scripted);
    $faked = customerEmail()->classify();

    foreach (['intent', 'reply_today'] as $key) {
        $surface = fn (object $answer) => [
            get_class($answer),
            array_map(fn (ReflectionProperty $p) => $p->getName(), (new ReflectionObject($answer))->getProperties(ReflectionProperty::IS_PUBLIC)),
            array_map(fn (ReflectionMethod $m) => $m->getName(), (new ReflectionObject($answer))->getMethods(ReflectionMethod::IS_PUBLIC)),
        ];

        expect($surface($faked[$key]))->toBe($surface($sampled[$key]));
    }
});

it('reports the same confidence for votes a,a,a,b from both drivers', function () {
    $question = new Choice('Which?', ['a' => 'A', 'b' => 'B']);
    $votes = [['q' => 'a'], ['q' => 'a'], ['q' => 'a'], ['q' => 'b']];

    ClassifierAgent::fake($votes);
    $sampled = Hunch::of('text')->using('anthropic')->question('q', $question)->sampling(4)->classify();

    Hunch::fake()->samples($votes);
    $faked = Hunch::of('text')->question('q', $question)->classify();

    expect($sampled['q']->confidence)->toBe(0.75)
        ->and($faked['q']->confidence)->toBe(0.75);
});
