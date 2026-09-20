<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\PromptingAgent;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Redactors\ContactDetails;
use Random\Engine\Mt19937;
use Random\Randomizer;

const CONTACT_SAMPLES = [['intent' => 'refund'], ['intent' => 'refund'], ['intent' => 'refund']];

const CONTACT_STATE = 'Write to alice@example.com, or call +44 7700 900123, 07700 900123 or (555) 123-4567.';

function contact(): PendingClassification
{
    return Hunch::of(CONTACT_STATE)
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3);
}

$fixture = require dirname(__DIR__).'/Fixtures/contact-details.php';

it('replaces email addresses and phone numbers when named in redactUsing()', function () {
    $messages = [];
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$messages) {
        $messages[] = $event->prompt->prompt;
    });
    ClassifierAgent::fake(CONTACT_SAMPLES);

    contact()->redactUsing(ContactDetails::class)->classify();

    expect($messages)->toHaveCount(3);

    foreach ($messages as $message) {
        expect($message)->toContain('Write to [email], or call [phone], [phone] or [phone].')
            ->not->toContain('alice@example.com')
            ->not->toContain('7700')
            ->not->toContain('555');
    }
});

it('sends the state unchanged when no redactor is set', function () {
    $messages = [];
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$messages) {
        $messages[] = $event->prompt->prompt;
    });
    ClassifierAgent::fake(CONTACT_SAMPLES);

    contact()->classify();

    foreach ($messages as $message) {
        expect($message)->toContain(CONTACT_STATE)->not->toContain('[email]')->not->toContain('[phone]');
    }
});

it('holds ten positives and ten negatives in the fixture', function () use ($fixture) {
    expect($fixture['positives'])->toHaveCount(10)
        ->and($fixture['negatives'])->toHaveCount(10);
});

it('redacts every contact detail in the fixture', function (string $text, string $expected) {
    expect((new ContactDetails)($text))->toBe($expected);
})->with($fixture['positives']);

it('leaves text with no email address or phone number unchanged', function (string $text) {
    expect((new ContactDetails)($text))->toBe($text);
})->with($fixture['negatives']);

it('leaves generated text with no email address or phone number unchanged', function () {
    $randomizer = new Randomizer(new Mt19937(2026));
    $words = ['refund', 'order', 'BK-2026-0415', '£12.50', '14:30', '2026-04-15', '15/04/2026', 'room', '#4', 'at', '@desk', 'v2.1', '(ok)', '-', 'x3'];

    for ($i = 0; $i < 300; $i++) {
        $parts = [];

        for ($n = $randomizer->getInt(1, 12); $n > 0; $n--) {
            $parts[] = $randomizer->getInt(0, 2) === 0
                ? (string) $randomizer->getInt(0, 9999)
                : $words[$randomizer->getInt(0, count($words) - 1)];
        }

        $text = implode(' ', $parts);
        $digitRuns = preg_match('~\d(?:[ .-]?\d){9}~', $text) === 1;

        if (! $digitRuns) {
            expect((new ContactDetails)($text))->toBe($text, "Changed: {$text}");
        }
    }
});
