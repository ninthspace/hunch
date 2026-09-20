<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\PromptingAgent;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Choice;

const REDACTION_SAMPLES = [['intent' => 'refund'], ['intent' => 'refund'], ['intent' => 'refund']];

/**
 * @param  string|array<string, string>  $state
 */
function redacting(string|array $state): PendingClassification
{
    return Hunch::of($state)
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3);
}

/**
 * Every user message sent, in order, and a log of what happened when.
 *
 * @return object{messages: list<string>, log: list<string>}
 */
function sentMessages(): object
{
    $sent = (object) ['messages' => [], 'log' => []];

    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use ($sent) {
        $sent->messages[] = $event->prompt->prompt;
        $sent->log[] = 'prompt';
    });

    return $sent;
}

/**
 * The text between a message's state tags.
 */
function stateBlock(string $message): string
{
    preg_match('~\n<state>\n(.*)\n</state>\n$~s', $message, $matches);

    return $matches[1] ?? throw new RuntimeException('No state block in the message.');
}

it('sends only the redacted text in every user message', function () {
    ClassifierAgent::fake(REDACTION_SAMPLES);
    $sent = sentMessages();

    redacting('Alice wants her money back. Regards, Alice')
        ->redactUsing(fn (string $s) => str_replace('Alice', '[name]', $s))
        ->classify();

    expect($sent->messages)->toHaveCount(3);

    foreach ($sent->messages as $message) {
        expect($message)->toContain('[name] wants her money back. Regards, [name]')
            ->not->toContain('Alice');
    }
});

it('hands the redactor each named part of array state and keeps the section names', function () {
    ClassifierAgent::fake(REDACTION_SAMPLES);
    $sent = sentMessages();
    $received = [];

    redacting(['subject' => 'Refund for Alice', 'message' => 'Alice here, please refund me.'])
        ->redactUsing(function (string $part) use (&$received) {
            $received[] = $part;

            return str_replace('Alice', '[name]', $part);
        })
        ->classify();

    expect($received)->toBe(['Refund for Alice', 'Alice here, please refund me.'])
        ->and(stateBlock($sent->messages[0]))->toBe(
            "<section name=\"subject\">\nRefund for [name]\n</section>\n<section name=\"message\">\n[name] here, please refund me.\n</section>"
        );
});

it('redacts once per classification, before the first sample, and sends every sample the same text', function () {
    ClassifierAgent::fake(REDACTION_SAMPLES);
    $sent = sentMessages();
    $calls = 0;

    redacting('Alice wants a refund.')
        ->redactUsing(function (string $s) use (&$calls, $sent) {
            $calls++;
            $sent->log[] = 'redact';

            return "Redaction {$calls}: ".str_replace('Alice', '[name]', $s);
        })
        ->classify();

    expect($calls)->toBe(1)
        ->and($sent->log)->toBe(['redact', 'prompt', 'prompt', 'prompt'])
        ->and(array_unique(array_map(stateBlock(...), $sent->messages)))->toBe(['Redaction 1: [name] wants a refund.']);
});

it('redacts again for each classification', function () {
    ClassifierAgent::fake([...REDACTION_SAMPLES, ...REDACTION_SAMPLES]);
    $calls = 0;
    $redactor = function (string $s) use (&$calls) {
        $calls++;

        return $s;
    };

    redacting('One.')->redactUsing($redactor)->classify();
    redacting('Two.')->redactUsing($redactor)->classify();

    expect($calls)->toBe(2);
});

it('sets state_hash to sha256 of the redacted, rendered state that was sent', function (string|array $state) {
    ClassifierAgent::fake(REDACTION_SAMPLES);
    $sent = sentMessages();

    $result = redacting($state)->redactUsing(fn (string $s) => str_replace('Alice', '[name]', $s))->classify();

    expect($result->meta->stateHash)->toBe(hash('sha256', stateBlock($sent->messages[0])))
        ->and($result->meta->stateHash)->not->toBe(hash('sha256', stateBlock(str_replace('[name]', 'Alice', $sent->messages[0]))));
})->with([
    'text' => ['Alice wants a refund.'],
    'sections' => [['subject' => 'Refund', 'message' => 'Alice wants a refund.']],
]);

it('hashes the state as sent when no redactor is set', function () {
    ClassifierAgent::fake(REDACTION_SAMPLES);
    $sent = sentMessages();

    $result = redacting('Alice wants a refund.')->classify();

    expect(stateBlock($sent->messages[0]))->toBe('Alice wants a refund.')
        ->and($result->meta->stateHash)->toBe(hash('sha256', 'Alice wants a refund.'));
});

it('refuses a redactor that does not return a string, before any call', function () {
    ClassifierAgent::fake();

    expect(fn () => redacting('Alice')->redactUsing(fn (string $s) => null)->classify())
        ->toThrow(ConfigurationException::class, 'must return a string');
    ClassifierAgent::assertNeverPrompted();
});

it('refuses a class name that is not an invokable redactor, before any call', function () {
    ClassifierAgent::fake();

    expect(fn () => redacting('Alice')->redactUsing(stdClass::class)->classify())
        ->toThrow(ConfigurationException::class, 'must be callable');
    ClassifierAgent::assertNeverPrompted();
});
