<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\PromptingAgent;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Result;

const SAMPLED = [
    ['intent' => 'refund', 'reply_today' => true],
    ['intent' => 'refund', 'reply_today' => false],
    ['intent' => 'other', 'reply_today' => true],
];

/**
 * @param  string|array<string, string>  $state
 */
function pipeline(string|array $state): PendingClassification
{
    return Hunch::of($state)
        ->using('anthropic', 'm')
        ->context('A support inbox for a clothing shop.')
        ->questions([
            'intent' => new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']),
            'reply_today' => new Boolean('Should we reply today?'),
        ])
        ->sampling(3);
}

it('drives the sampling driver end to end: builder, prompts, aggregation, Result', function () {
    ClassifierAgent::fake(SAMPLED);

    $result = pipeline(['subject' => 'Wrong size', 'message' => 'Refund please'])->classify();

    ClassifierAgent::assertPromptedTimes(3);
    expect($result)->toBeInstanceOf(Result::class)
        ->and($result['intent'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($result['intent']->votes)->toBe(['refund' => 2, 'other' => 1])
        ->and($result['reply_today'])->toBeInstanceOf(BooleanAnswer::class)
        ->and($result['reply_today']->probability)->toEqualWithDelta(2 / 3, 1e-9)
        ->and($result->samples->valid)->toBe(3)
        ->and($result->meta->driver)->toBe('sampling');
});

it('sends byte-identical instructions for the same question set whatever the state', function () {
    ClassifierAgent::fake([...SAMPLED, ...SAMPLED]);
    $hashes = [];
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$hashes) {
        $hashes[] = hash('sha256', (string) $event->prompt->agent->instructions());
    });

    $first = pipeline('Please refund my order.')->classify();
    $second = pipeline(['subject' => 'Late', 'message' => 'Where is my parcel?'])->classify();

    expect($first->meta->questionSetHash)->toBe($second->meta->questionSetHash)
        ->and($hashes)->toHaveCount(6)
        ->and(array_unique($hashes))->toHaveCount(1);
});

it('runs no queries with persistence off, calibration() included', function () {
    config()->set('hunch.persistence', false);
    ClassifierAgent::fake(SAMPLED);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $result = pipeline('Please refund my order.')->classify();

    foreach ($result->answers as $answer) {
        $answer->calibration();
    }

    expect($queries)->toBe(0);
});

it('classifies with persistence off even when the database driver does not exist', function () {
    config()->set('hunch.persistence', false);
    config()->set('database.connections.broken', ['driver' => 'no-such-driver']);
    config()->set('database.default', 'broken');
    ClassifierAgent::fake(SAMPLED);

    $databaseWorks = true;

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $databaseWorks = false;
    }

    expect($databaseWorks)->toBeFalse()
        ->and(pipeline('Please refund my order.')->classify()['intent']->choice)->toBe('refund');
});
