<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\HunchServiceProvider;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Tests\Fixtures\Enquiry;
use Ninthspace\Hunch\Tests\TestCase;

/*
 * Shared by the persistence tests: persistence on, migrated, and an
 * application model to record classifications against.
 */

const MARKER = 'Marker-Q7X2-Hunch';

const HUNCH_TABLES = ['hunch_question_sets', 'hunch_classifications', 'hunch_samples', 'hunch_labels', 'hunch_calibration'];

function persistenceOn(TestCase $test): void
{
    config()->set('hunch.persistence', true);
    config()->set('hunch.retention_days', 30);
    config()->set('hunch.retries', 0);
    app()->register(HunchServiceProvider::class, force: true);

    // Hunch never loads its own migrations: an application publishes them and
    // migrates, so the tests run them from the package's path the same way.
    $test->artisan('migrate', [
        '--path' => dirname(__DIR__, 2).'/database/migrations',
        '--realpath' => true,
    ])->assertSuccessful();

    Schema::create('enquiries', function (Blueprint $table) {
        $table->id();
        $table->text('body');
        $table->timestamps();
    });
}

function enquiry(): Enquiry
{
    return Enquiry::query()->create(['body' => 'Refund please. '.MARKER]);
}

/**
 * @param  string|array<string, string>  $state
 */
function recording(Enquiry $enquiry, string|array $state = 'Refund please. '.MARKER): PendingClassification
{
    return Hunch::of($state)
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3)
        ->record($enquiry);
}

/**
 * A fake answering `intent` in turn, `!` for an invalid sample. With reasons,
 * each reason quotes the state.
 *
 * @param  list<string>  $intents
 */
function recordedAnswers(array $intents, bool $reasons = false): Closure
{
    $call = 0;

    return function () use (&$call, $intents, $reasons) {
        $intent = $intents[min($call++, count($intents) - 1)];
        $output = ['intent' => $intent === '!' ? 'not-an-option' : $intent];

        return $reasons ? [...$output, 'reason' => 'The text says: '.MARKER] : $output;
    };
}

/**
 * Every row of every Hunch table, encoded, for scanning.
 */
function hunchRows(): string
{
    return implode("\n", array_map(fn (string $table) => (string) json_encode(DB::table($table)->get()), HUNCH_TABLES));
}

/**
 * The text between a user message's state tags.
 */
function sentState(string $message): string
{
    preg_match('~\n<state>\n(.*)\n</state>\n$~s', $message, $matches);

    return $matches[1] ?? throw new RuntimeException('No state block in the message.');
}
