<?php

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\AiException;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\Models\Label;
use Ninthspace\Hunch\Models\StoredQuestionSet;
use Ninthspace\Hunch\Models\StoredSample;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Tests\Fixtures\Enquiry;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    persistenceOn($this);
});

it('writes a classification row, a row per sample and the question set once', function () {
    ClassifierAgent::fake(recordedAnswers(['refund', 'refund', 'other', 'refund', 'refund', 'refund']));
    $messages = [];
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$messages) {
        $messages[] = $event->prompt->prompt;
    });
    $enquiry = enquiry();

    $result = recording($enquiry)->classify();
    recording($enquiry)->classify();

    $row = Classification::query()->findOrFail($result->id);

    expect(Classification::query()->count())->toBe(2)
        ->and($row->subject_type)->toBe($enquiry->getMorphClass())
        ->and($row->subject_id)->toBe((string) $enquiry->getKey())
        ->and($row->subject?->is($enquiry))->toBeTrue()
        ->and($row->state_hash)->toBe(hash('sha256', sentState($messages[0])))
        ->and($row->status)->toBe(Classification::COMPLETED)
        ->and($row->question_set_hash)->toBe($result->meta->questionSetHash)
        ->and($row->answers['intent']['answer'] ?? null)->toBe('refund')
        ->and($row->samples()->count())->toBe(3)
        ->and($row->samples()->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])
        ->and(StoredQuestionSet::query()->count())->toBe(1)
        ->and(StoredQuestionSet::query()->value('hash'))->toBe($result->meta->questionSetHash);
});

it('adds a question set row for a new question set', function () {
    $enquiry = enquiry();

    ClassifierAgent::fake(recordedAnswers(['refund']));
    recording($enquiry)->classify();
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund', 'urgent' => true];
    });
    recording($enquiry)->question('urgent', new Boolean('Is it urgent?'))->classify();

    expect(StoredQuestionSet::query()->count())->toBe(2);
});

it('stores a reason only when reasons were requested', function () {
    $enquiry = enquiry();

    ClassifierAgent::fake(recordedAnswers(['refund']));
    $plain = recording($enquiry)->classify();
    ClassifierAgent::fake(recordedAnswers(['refund'], reasons: true));
    $reasoned = recording($enquiry)->withReasons(12)->classify();

    expect(StoredSample::query()->where('classification_id', $plain->id)->pluck('reason')->all())->toBe([null, null, null])
        ->and(StoredSample::query()->where('classification_id', $reasoned->id)->pluck('reason')->all())->toBe(['The text say', 'The text say', 'The text say']);
});

it('prunes classifications older than retention_days with their samples, and keeps newer ones', function () {
    ClassifierAgent::fake(recordedAnswers(['refund']));
    $enquiry = enquiry();

    $old = recording($enquiry)->classify();
    $this->travel(20)->days();
    $recent = recording($enquiry)->classify();
    $this->travel(15)->days();

    $this->artisan('model:prune', ['--model' => [Classification::class]])->assertSuccessful();

    expect(Classification::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(StoredSample::query()->where('classification_id', $old->id)->count())->toBe(0)
        ->and(StoredSample::query()->where('classification_id', $recent->id)->count())->toBe(3);
});

it('never prunes a label, or a classification that has one', function () {
    ClassifierAgent::fake(recordedAnswers(['refund']));
    $enquiry = enquiry();

    $labelled = recording($enquiry)->classify();
    $unlabelled = recording($enquiry)->classify();
    Label::query()->create(['classification_id' => $labelled->id, 'question' => 'intent', 'answer' => 'refund']);
    $this->travel(90)->days();

    $this->artisan('model:prune', ['--model' => [Classification::class]])->assertSuccessful();

    expect(Classification::query()->pluck('id')->all())->toBe([$labelled->id])
        ->and(Classification::query()->find($unlabelled->id))->toBeNull()
        ->and(Label::query()->count())->toBe(1)
        ->and(StoredSample::query()->where('classification_id', $labelled->id)->count())->toBe(3);
});

it('records a failed classification with its error and the samples taken', function (string $failure, int $taken) {
    ClassifierAgent::fake($failure === 'provider'
        ? function () {
            throw new AiException('The provider rejected: '.MARKER);
        }
        : recordedAnswers(['!']));
    $enquiry = enquiry();

    try {
        recording($enquiry)->classify();

        $this->fail('The classification should have failed.');
    } catch (ClassificationFailed $e) {
        $row = Classification::query()->sole();

        expect($row->status)->toBe(Classification::FAILED)
            ->and($row->error)->toBe($e->getMessage())
            ->and($row->samples_requested)->toBe($taken)
            ->and($row->samples()->count())->toBe($taken)
            ->and($row->answers)->toBeNull();
    }
})->with([
    'provider error' => ['provider', 0],
    // Three the rule allows plus the two replacements the cap permits, every
    // one of them invalid and every one of them recorded.
    'too few valid samples' => ['invalid', 5],
]);

it('keeps the state out of the ClassificationFailed message and the error column', function () {
    ClassifierAgent::fake(function () {
        throw new AiException('400 Bad Request: could not parse "Refund please. '.MARKER.'"');
    });

    try {
        recording(enquiry())->classify();

        $this->fail('The classification should have failed.');
    } catch (ClassificationFailed $e) {
        expect($e->getMessage())->not->toContain(MARKER)
            ->and(Classification::query()->sole()->error)->not->toContain(MARKER)
            ->and($e->getPrevious()?->getMessage())->toContain(MARKER);
    }
});

it('writes the state text to no column of any Hunch table', function () {
    $enquiry = enquiry();

    ClassifierAgent::fake(recordedAnswers(['refund', '!', 'refund', 'refund']));
    recording($enquiry, 'Refund please. '.MARKER)->sampling(4)->classify();
    ClassifierAgent::fake(recordedAnswers(['refund', '!', 'refund', 'refund'], reasons: true));
    recording($enquiry, ['subject' => 'Refund', 'message' => MARKER])->withReasons(200)->sampling(4)->classify();
    Label::query()->create(['classification_id' => Classification::query()->value('id'), 'question' => 'intent', 'answer' => 'refund']);

    expect(Classification::query()->count())->toBe(2)
        // Four valid samples, the invalid one having been replaced, each with
        // the reason it was asked for.
        ->and(StoredSample::query()->whereNotNull('reason')->count())->toBe(4)
        ->and(hunchRows())->toContain('The text says')
        ->and(hunchRows())->not->toContain('Refund please')
        ->and(hunchRows())->not->toContain('Q7X2');
});

it('writes the marker to no row, log entry or cache entry', function () {
    config()->set('cache.default', 'array');
    ClassifierAgent::fake(recordedAnswers(['refund'], reasons: true));
    $logged = [];
    $cached = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$cached) {
        $cached[] = $event->key.serialize($event->value);
    });
    logger()->info('control entry '.MARKER);
    cache()->put('control', MARKER);

    recording(enquiry())->withReasons(200)->classify();

    expect(array_filter($logged, fn (string $entry) => str_contains($entry, MARKER)))->toHaveCount(1)
        ->and(array_filter($cached, fn (string $entry) => str_contains($entry, MARKER)))->toHaveCount(1)
        ->and(hunchRows())->not->toContain('Q7X2');
});

it('sends and records only the redacted text when a redactor is set', function () {
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund'];
    });
    $seen = [];
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$seen) {
        $seen[] = $event->prompt->prompt.$event->prompt->agent->instructions();
    });
    Event::listen('Ninthspace\Hunch\Events\*', function (string $name, array $payload) use (&$seen) {
        $seen[] = serialize($payload);
    });

    $result = recording(enquiry())->redactUsing(fn (string $s) => str_replace(MARKER, '[redacted]', $s))->classify();

    expect($seen)->not->toBeEmpty()
        ->and(implode("\n", $seen))->not->toContain('Q7X2')
        ->and(implode("\n", $seen))->toContain('[redacted]')
        ->and(hunchRows())->not->toContain('Q7X2')
        ->and(Classification::query()->sole()->state_hash)->toBe(hash('sha256', 'Refund please. [redacted]'))
        ->and($result->meta->stateHash)->toBe(hash('sha256', 'Refund please. [redacted]'));
});

it('refuses record() when persistence is off, before any model call', function () {
    config()->set('hunch.persistence', false);
    ClassifierAgent::fake();
    $enquiry = enquiry();

    try {
        recording($enquiry)->classify();

        $this->fail('record() should have thrown.');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain('hunch.persistence');
    }

    ClassifierAgent::assertNeverPrompted();
    expect(Classification::query()->count())->toBe(0);
});

it('refuses an unsaved subject before any model call', function () {
    ClassifierAgent::fake();

    expect(fn () => recording(new Enquiry(['body' => 'x'])))->toThrow(InvalidArgumentException::class, 'saved');
    ClassifierAgent::assertNeverPrompted();
});

it('runs the persistence tests on SQLite in memory, as phpunit.xml sets', function () {
    $xml = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
    $env = [];

    foreach ($xml->php->env as $entry) {
        $env[(string) $entry['name']] = (string) $entry['value'];
    }

    expect($env)->toMatchArray(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'])
        ->and(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:')
        ->and(collect(HUNCH_TABLES)->every(fn (string $table) => Schema::hasTable($table)))->toBeTrue();
});

it('scrubs passages quoted from the state out of stored reasons, and leaves Result->reasons alone', function () {
    $reasons = ['The text says: Marker-Q7X2-Hunch', 'Customer wants a refund', 'Quotes "refund please" directly'];
    $call = 0;
    ClassifierAgent::fake(function () use (&$call, $reasons) {
        return ['intent' => 'refund', 'reason' => $reasons[$call++]];
    });

    $result = recording(enquiry())->withReasons(200)->classify();

    expect($result->reasons)->toBe($reasons)
        ->and(StoredSample::query()->orderBy('number')->pluck('reason')->all())->toBe([
            'The text says: [state]',
            'Customer wants a refund',
            'Quotes "[state]" directly',
        ]);
});

it('prunes nothing when retention_days is not set', function (mixed $retention) {
    ClassifierAgent::fake(recordedAnswers(['refund']));
    recording(enquiry())->classify();
    config()->set('hunch.retention_days', $retention);
    $this->travel(400)->days();

    $this->artisan('model:prune', ['--model' => [Classification::class]])->assertSuccessful();

    expect(Classification::query()->count())->toBe(1);
})->with([null, 0, 'thirty']);

it('reads a retention_days set from the environment as a string', function () {
    ClassifierAgent::fake(recordedAnswers(['refund']));
    recording(enquiry())->classify();
    config()->set('hunch.retention_days', '30');
    $this->travel(31)->days();

    $this->artisan('model:prune', ['--model' => [Classification::class]])->assertSuccessful();

    expect(Classification::query()->count())->toBe(0);
});
