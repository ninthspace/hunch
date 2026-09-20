<?php

use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Answers\Answers;
use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Result;

/**
 * A classification of three questions: a plain Choice, a Boolean, and a
 * conditional Choice that only applies to bookings.
 *
 * @param  list<array<string, bool|string>>  $samples
 */
function answersFrom(array $samples): Answers
{
    ClassifierAgent::fake($samples);

    return Hunch::of('Can we book a table for six on Saturday?')
        ->using('anthropic', 'm')
        ->questions([
            'intent' => new Choice('What do they want?', ['booking' => 'To book', 'refund' => 'Money back']),
            'urgent' => new Boolean('Is it urgent?'),
            'group_date' => (new Choice('Which date suits?', ['saturday' => 'Saturday', 'sunday' => 'Sunday']))->onlyWhen('intent', 'booking'),
        ])
        ->sampling(count($samples))
        ->classify()
        ->answers;
}

/**
 * @return list<array<string, bool|string>>
 */
function bookedSamples(): array
{
    return [
        ['intent' => 'booking', 'urgent' => false, 'group_date' => 'saturday'],
        ['intent' => 'booking', 'urgent' => false, 'group_date' => 'saturday'],
        ['intent' => 'booking', 'urgent' => true, 'group_date' => 'sunday'],
    ];
}

it('reads an answer by question key, however it is reached', function () {
    $answers = answersFrom(bookedSamples());

    expect($answers->get('intent'))->toBeInstanceOf(ChoiceAnswer::class)
        ->and($answers['intent'])->toBe($answers->get('intent'))
        ->and($answers->get('urgent'))->toBeInstanceOf(BooleanAnswer::class)
        ->and($answers->get('intent')->choice)->toBe('booking');
});

it('throws for a question the classification never asked, by either route', function () {
    $answers = answersFrom(bookedSamples());

    expect(fn () => $answers->get('colour'))->toThrow(InvalidArgumentException::class, 'colour')
        ->and(fn () => $answers['colour'])->toThrow(InvalidArgumentException::class, 'colour')
        ->and($answers->has('colour'))->toBeFalse()
        ->and(isset($answers['colour']))->toBeFalse()
        ->and($answers->has('intent'))->toBeTrue();
});

it('is countable and iterable in canonical order', function () {
    $answers = answersFrom(bookedSamples());
    $seen = [];

    foreach ($answers as $question => $answer) {
        $seen[$question] = $answer === null ? null : $answer::class;
    }

    expect($answers)->toHaveCount(3)
        ->and(count($answers))->toBe(3)
        ->and(array_keys($seen))->toBe(['group_date', 'intent', 'urgent'])
        ->and($answers->keys())->toBe(['group_date', 'intent', 'urgent'])
        ->and($answers->all())->toBe($seen === [] ? [] : $answers->all());
});

it('sets applicable aside from the answers that did not apply', function () {
    // Two samples qualify for the booking-only question, so it has an answer,
    // but the winning intent is refund, so that answer does not apply.
    $answers = answersFrom([
        ['intent' => 'booking', 'urgent' => false, 'group_date' => 'saturday'],
        ['intent' => 'booking', 'urgent' => false, 'group_date' => 'saturday'],
        ['intent' => 'refund', 'urgent' => false, 'group_date' => 'sunday'],
        ['intent' => 'refund', 'urgent' => false, 'group_date' => 'sunday'],
        ['intent' => 'refund', 'urgent' => false, 'group_date' => 'sunday'],
    ]);

    expect($answers->get('intent')->choice)->toBe('refund')
        ->and($answers->get('group_date'))->not->toBeNull()
        ->and($answers->get('group_date')->applies)->toBeFalse()
        ->and($answers->applicable()->keys())->toBe(['intent', 'urgent'])
        ->and($answers->applicable())->toBeInstanceOf(Answers::class)
        ->and($answers->applicable())->toHaveCount(2)
        ->and($answers)->toHaveCount(3);
});

it('names the questions too few samples qualified to answer', function () {
    // Only one sample is a booking, and a conditional question needs two.
    $answers = answersFrom([
        ['intent' => 'booking', 'urgent' => false, 'group_date' => 'saturday'],
        ['intent' => 'refund', 'urgent' => false, 'group_date' => 'saturday'],
        ['intent' => 'refund', 'urgent' => false, 'group_date' => 'sunday'],
    ]);

    expect($answers->get('group_date'))->toBeNull()
        ->and($answers->unanswered())->toBe(['group_date'])
        ->and($answers->applicable()->keys())->toBe(['intent', 'urgent'])
        ->and($answers->has('group_date'))->toBeTrue();
});

it('reports nothing unanswered when every question has an answer', function () {
    $empty = new Answers;

    expect(answersFrom(bookedSamples())->unanswered())->toBe([])
        ->and($empty->unanswered())->toBe([])
        ->and($empty)->toHaveCount(0)
        ->and($empty->keys())->toBe([])
        ->and($empty->all())->toBe([]);
});

it('is read-only', function () {
    $answers = answersFrom(bookedSamples());

    expect(fn () => $answers['intent'] = null)->toThrow(LogicException::class, 'read-only')
        ->and(function () use ($answers) {
            unset($answers['intent']);
        })->toThrow(LogicException::class, 'read-only');
});

/**
 * A classification of two questions, so every route can be compared.
 */
function twoQuestions(): Result
{
    ClassifierAgent::fake([
        ['intent' => 'booking', 'urgent' => false],
        ['intent' => 'booking', 'urgent' => false],
        ['intent' => 'refund', 'urgent' => true],
    ]);

    return Hunch::of('Can we book a table for six on Saturday?')
        ->using('anthropic', 'm')
        ->questions([
            'intent' => new Choice('What do they want?', ['booking' => 'To book', 'refund' => 'Money back']),
            'urgent' => new Boolean('Is it urgent?'),
        ])
        ->sampling(3)
        ->classify();
}

it('reaches the same answer by method, collection and shorthand', function () {

    $result = twoQuestions();

    expect($result->answer('intent'))->toBe($result->answers->get('intent'))
        ->and($result->answer('intent'))->toBe($result['intent'])
        ->and($result->answer('intent')->choice)->toBe('booking');
});

it('throws for a question never asked, by method as well', function () {
    $result = twoQuestions();

    expect(fn () => $result->answer('colour'))->toThrow(InvalidArgumentException::class, 'colour')
        ->and(fn () => $result['colour'])->toThrow(InvalidArgumentException::class, 'colour')
        ->and(fn () => $result->answers->get('colour'))->toThrow(InvalidArgumentException::class, 'colour');
});
