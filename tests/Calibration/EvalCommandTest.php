<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Events\PromptingAgent;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Models\CalibrationCell;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Tests\Fixtures\Enquiry;
use Ninthspace\Hunch\Tests\Fixtures\Note;

require_once dirname(__DIR__).'/Persistence/helpers.php';

const EVAL_CONTEXT = 'A support inbox for a clothing shop.';

beforeEach(function () {
    persistenceOn($this);
    config()->set('ai.providers.fake', ['driver' => 'anthropic', 'key' => 'test-key']);
});

/**
 * 20 recorded, labelled classifications of one Choice question: every answer
 * a unanimous refund, 16 of them labelled refund.
 */
function evalFixture(): string
{
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund'];
    });
    $hash = null;

    foreach (range(1, 20) as $i) {
        $enquiry = Enquiry::query()->create(['body' => "Enquiry {$i}: refund please."]);

        $result = Hunch::of($enquiry->hunchState())
            ->using('anthropic', 'm')
            ->context(EVAL_CONTEXT)
            ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
            ->sampling(3)
            ->record($enquiry)
            ->classify();

        if ($i === 17) {
            // Superseded below: counting it would add a 21st, correct outcome.
            Hunch::label($result->id, ['intent' => 'refund']);
        }

        Hunch::label($result->id, ['intent' => $i <= 16 ? 'refund' : 'other']);
        $hash = $result->meta->questionSetHash;
    }

    return (string) $hash;
}

/**
 * A fake that answers `refund` for the first `$refunds` calls, then `other`.
 */
function evalAnswers(int $refunds): Closure
{
    $call = 0;

    return function () use (&$call, $refunds) {
        return ['intent' => $call++ < $refunds ? 'refund' : 'other'];
    };
}

/**
 * Counts the prompts sent from here on, so the fixture's own are not counted.
 */
function promptCount(): object
{
    $counter = new class
    {
        public int $count = 0;
    };

    Event::listen(PromptingAgent::class, function () use ($counter) {
        $counter->count++;
    });

    return $counter;
}

it('re-classifies every labelled item with each driver and reports one row per driver', function () {
    $hash = evalFixture();
    // 60 prompts per driver: the first always answers refund, the second always other.
    ClassifierAgent::fake(evalAnswers(60));
    $prompts = promptCount();
    $instructions = [];
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$instructions) {
        $instructions[] = (string) $event->prompt->agent->instructions();
    });

    $code = Artisan::call('hunch:eval', ['set' => $hash, '--driver' => ['sampling:fake:m1', 'sampling:fake:m2']]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($instructions[0] ?? '')->toContain(EVAL_CONTEXT)
        ->and($output)->toContain('20 labelled items')
        // m1 answers refund: 16 of 20 right; Brier 4 x 2 / 20; ECE |1.0 - 0.8|.
        ->and($output)->toContain('| sampling:fake:m1 | 0.8000   | 0.4000 | 0.2000 | 3.00         | 0     | 0            | 0      |')
        // m2 answers other: 4 of 20 right; Brier 16 x 2 / 20; ECE |1.0 - 0.2|.
        ->and($output)->toContain('| sampling:fake:m2 | 0.2000   | 1.6000 | 0.8000 | 3.00         | 0     | 0            | 0      |')
        ->and($prompts->count)->toBe(120);
});

it('records nothing that hunch:calibrate would count', function () {
    $hash = evalFixture();
    $before = Classification::query()->count();
    ClassifierAgent::fake(evalAnswers(60));

    expect(Artisan::call('hunch:eval', ['set' => $hash, '--driver' => ['sampling:fake:m1']]))->toBe(0);
    $this->artisan('hunch:calibrate')->assertSuccessful();

    expect(Classification::query()->count())->toBe($before)
        ->and($before)->toBe(20)
        ->and(CalibrationCell::query()->sum('n'))->toBe(20)
        ->and(CalibrationCell::query()->pluck('model')->all())->toBe(['m']);
});

it('exits non-zero without a model call when nothing is labelled for the hash', function () {
    evalFixture();
    $prompts = promptCount();

    $code = Artisan::call('hunch:eval', ['set' => 'v1:never-recorded', '--driver' => ['sampling:fake:m1']]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('v1:never-recorded')
        ->and($prompts->count)->toBe(0);
});

it('exits non-zero without a model call for a --driver that does not parse', function (string $spec) {
    $hash = evalFixture();
    $prompts = promptCount();

    $code = Artisan::call('hunch:eval', ['set' => $hash, '--driver' => [$spec]]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('driver:provider:model')
        ->and($prompts->count)->toBe(0);
})->with(['sampling:fake', 'sampling', 'sampling:fake:m1:extra', 'sampling::m1', '']);

it('exits non-zero for a driver name Hunch does not have', function () {
    $hash = evalFixture();
    $prompts = promptCount();

    $code = Artisan::call('hunch:eval', ['set' => $hash, '--driver' => ['guesswork:fake:m1']]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('guesswork')
        ->and($prompts->count)->toBe(0);
});

it('skips items whose subject cannot give its state back', function () {
    $hash = evalFixture();
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    $note = Note::query()->create();
    Classification::query()->first()?->update(['subject_type' => $note->getMorphClass(), 'subject_id' => (string) $note->getKey()]);
    ClassifierAgent::fake(evalAnswers(57));
    $prompts = promptCount();

    $code = Artisan::call('hunch:eval', ['set' => $hash, '--driver' => ['sampling:fake:m1']]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('1 item(s) skipped')
        ->and($output)->toContain('19 labelled items')
        ->and($prompts->count)->toBe(57);
});

it('says so when every labelled item was skipped', function () {
    $hash = evalFixture();
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    $note = Note::query()->create();
    Classification::query()->update(['subject_type' => $note->getMorphClass(), 'subject_id' => (string) $note->getKey()]);
    $prompts = promptCount();

    $code = Artisan::call('hunch:eval', ['set' => $hash, '--driver' => ['sampling:fake:m1']]);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('was skipped')
        ->and($output)->not->toContain('No labelled classifications')
        ->and($prompts->count)->toBe(0);
});
