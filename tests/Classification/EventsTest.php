<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\AiException;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Events\ClassificationEvent;
use Ninthspace\Hunch\Events\ClassificationFailed as ClassificationFailedEvent;
use Ninthspace\Hunch\Events\Classified;
use Ninthspace\Hunch\Events\Classifying;
use Ninthspace\Hunch\Events\SampleInvalid;
use Ninthspace\Hunch\Events\SampleTaken;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\SampleCounts;
use Ninthspace\Hunch\Usage;

const EVENT_STATE = 'Zanzibar-7 wants a refund for order Quokka-42.';

function eventful(): PendingClassification
{
    return Hunch::of(['subject' => 'Refund', 'message' => EVENT_STATE])
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->withReasons(80);
}

/**
 * A fake that answers each sample in turn: a string is the intent, `!` an invalid output.
 *
 * @param  list<string>  $answers
 */
function answeringWith(array $answers): Closure
{
    $call = 0;

    return function () use (&$call, $answers) {
        $answer = $answers[min($call++, count($answers) - 1)];

        return $answer === '!'
            ? ['intent' => 'not-an-option', 'reason' => 'Quoting the state: '.EVENT_STATE]
            : ['intent' => $answer, 'reason' => 'Quoting the state: '.EVENT_STATE];
    };
}

/**
 * Every Hunch event dispatched, in order, as the listener saw it.
 *
 * @return ArrayObject<int, ClassificationEvent>
 */
function hunchEvents(): ArrayObject
{
    $events = new ArrayObject;

    Event::listen('Ninthspace\Hunch\Events\*', function (string $name, array $payload) use ($events) {
        $events[] = $payload[0];
    });

    return $events;
}

it('dispatches Classifying, SampleTaken three times and Classified under Event::fake()', function () {
    Event::fake();
    ClassifierAgent::fake(answeringWith(['refund']));

    eventful()->sampling(3)->classify();

    Event::assertDispatchedTimes(Classifying::class, 1);
    Event::assertDispatchedTimes(SampleTaken::class, 3);
    Event::assertDispatchedTimes(Classified::class, 1);
    Event::assertNotDispatched(SampleInvalid::class);
    Event::assertNotDispatched(ClassificationFailedEvent::class);
});

it('dispatches them in order: Classifying, each SampleTaken, then Classified', function () {
    ClassifierAgent::fake(answeringWith(['refund']));
    $events = hunchEvents();

    eventful()->sampling(3)->classify();

    expect(array_map(fn (object $event) => $event::class, $events->getArrayCopy()))
        ->toBe([Classifying::class, SampleTaken::class, SampleTaken::class, SampleTaken::class, Classified::class])
        ->and(array_map(fn (SampleTaken $event) => $event->sampleNumber, array_slice($events->getArrayCopy(), 1, 3)))->toBe([1, 2, 3]);
});

it('dispatches SampleInvalid instead of SampleTaken for an invalid sample', function () {
    ClassifierAgent::fake(answeringWith(['refund', '!', 'refund', 'refund']));
    $events = hunchEvents();

    eventful()->sampling(4)->classify();

    $invalid = array_values(array_filter($events->getArrayCopy(), fn (object $event) => $event instanceof SampleInvalid));
    $taken = array_values(array_filter($events->getArrayCopy(), fn (object $event) => $event instanceof SampleTaken));

    expect($invalid)->toHaveCount(1)
        ->and($invalid[0]->sampleNumber)->toBe(2)
        ->and($invalid[0]->problem)->toBe('`intent` is not one of its allowed values.')
        // Sample 5 replaces the invalid second one and announces itself the
        // same way, under its own number.
        ->and(array_map(fn (SampleTaken $event) => $event->sampleNumber, $taken))->toBe([1, 3, 4, 5]);
});

it('dispatches ClassificationFailed before the exception is thrown', function (string $failure) {
    config()->set('hunch.retries', 0);
    ClassifierAgent::fake($failure === 'provider'
        ? function () {
            throw new AiException('Provider said: '.EVENT_STATE);
        }
        : answeringWith(['!']));
    $dispatched = null;
    $classified = false;
    Event::listen(ClassificationFailedEvent::class, function (ClassificationFailedEvent $event) use (&$dispatched) {
        $dispatched = $event;
    });
    Event::listen(Classified::class, function () use (&$classified) {
        $classified = true;
    });

    try {
        eventful()->sampling(3)->classify();

        $this->fail('The classification should have failed.');
    } catch (ClassificationFailed $e) {
        expect($dispatched)->toBeInstanceOf(ClassificationFailedEvent::class)
            ->and($dispatched->samplesTaken)->toBe(count($e->samples))
            ->and($dispatched->validSamples)->toBe(0)
            ->and($dispatched->cause)->toBe($failure === 'provider' ? AiException::class : null);
    }

    expect($classified)->toBeFalse();
})->with(['provider', 'invalid']);

it('fires the SDK prompt events once per sample', function (int $min, int $max, int $expected) {
    Event::fake();
    ClassifierAgent::fake(answeringWith($expected === 3 ? ['refund'] : ['refund', 'refund', 'other', 'refund', 'refund']));

    eventful()->sampling(adaptive: [$min, $max])->classify();

    Event::assertDispatchedTimes(PromptingAgent::class, $expected);
    Event::assertDispatchedTimes(AgentPrompted::class, $expected);
})->with([
    'stops at min' => [3, 7, 3],
    'adds samples' => [3, 7, 5],
]);

it('puts the classification ID, question set hash and state hash on every event', function () {
    ClassifierAgent::fake(answeringWith(['refund', '!', 'refund', 'refund']));
    $events = hunchEvents();

    $result = eventful()->sampling(4)->classify();

    // Classifying, four taken, one invalid, one replacement, Classified.
    expect($events)->toHaveCount(7);

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(ClassificationEvent::class)
            ->and($event->classificationId)->toBe($result->id)
            ->and($event->questionSetHash)->toBe($result->meta->questionSetHash)
            ->and($event->stateHash)->toBe($result->meta->stateHash);
    }
});

it('carries no state text on any event, even when a reason or provider error quotes it', function () {
    config()->set('hunch.retries', 0);
    $events = hunchEvents();
    ClassifierAgent::fake(answeringWith(['refund', '!', 'refund', 'refund']));
    eventful()->sampling(4)->classify();
    ClassifierAgent::fake(function () {
        throw new AiException('Provider said: '.EVENT_STATE);
    });

    try {
        eventful()->sampling(3)->classify();
    } catch (ClassificationFailed) {
    }

    $kinds = array_unique(array_map(fn (object $event) => $event::class, $events->getArrayCopy()));
    $payload = serialize($events->getArrayCopy());

    expect($kinds)->toHaveCount(5)
        ->and($payload)->not->toContain('Zanzibar')
        ->and($payload)->not->toContain('Quokka')
        ->and($payload)->not->toContain('Refund');
});

it('declares no event property that could hold the state', function () {
    $allowed = ['int', 'string', '?string', SampleCounts::class, Usage::class];
    // Question keys, checked against the question set before dispatch.
    $lists = ['Ninthspace\\Hunch\\Events\\Labelled::$questions'];

    foreach (glob(dirname(__DIR__, 2).'/src/Events/*.php') as $file) {
        $class = 'Ninthspace\\Hunch\\Events\\'.basename($file, '.php');

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $name = "{$class}::\${$property->getName()}";

            expect((string) $property->getType())->toBeIn(in_array($name, $lists, true) ? ['array'] : $allowed, $name);

            if ($property->getName() !== 'stateHash') {
                expect(strtolower($property->getName()))->not->toContain('state', $name);
            }
        }
    }
});
