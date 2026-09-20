<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Events\Labelled;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Models\Label;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\Tests\Fixtures\Enquiry;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    persistenceOn($this);
});

/**
 * A recorded classification of three questions: a Choice, a Boolean and a Score.
 */
function labelled(): string
{
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund', 'urgent' => true, 'tone' => 'calm'];
    });

    return recording(enquiry())
        ->question('urgent', new Boolean('Is it urgent?'))
        ->question('tone', new Score('How upset?', ['calm' => 'Calm', 'annoyed' => 'Annoyed', 'furious' => 'Furious']))
        ->classify()
        ->id;
}

it('writes one label row with the labeller set, leaving the other questions unlabelled', function () {
    $id = labelled();
    $reviewer = Enquiry::query()->create(['body' => 'A reviewer']);
    Event::fake([Labelled::class]);

    $labels = Hunch::label($id, ['intent' => 'refund'], by: $reviewer);

    $row = Label::query()->sole();

    expect($labels)->toHaveCount(1)
        ->and($row->classification_id)->toBe($id)
        ->and($row->question)->toBe('intent')
        ->and($row->answer)->toBe('refund')
        ->and($row->labelled_by_type)->toBe($reviewer->getMorphClass())
        ->and($row->labelled_by_id)->toBe((string) $reviewer->getKey())
        ->and($row->labelledBy?->is($reviewer))->toBeTrue()
        ->and($row->superseded_at)->toBeNull()
        ->and(Label::query()->whereIn('question', ['urgent', 'tone'])->count())->toBe(0);
    Event::assertDispatched(Labelled::class, fn (Labelled $event) => $event->classificationId === $id && $event->questions === ['intent']);
});

it('labels a Boolean and a Score with their own answer types', function () {
    $id = labelled();

    Hunch::label($id, ['urgent' => false, 'tone' => 'annoyed']);

    expect(DB::table('hunch_labels')->where('question', 'urgent')->value('answer'))->toBe('false')
        ->and(Label::query()->where('question', 'urgent')->sole()->answer)->toBeFalse()
        ->and(Label::query()->where('question', 'tone')->sole()->answer)->toBe('annoyed');
});

it('supersedes an earlier label for the same question and keeps both', function () {
    $id = labelled();

    Hunch::label($id, ['intent' => 'refund', 'urgent' => true]);
    $this->travel(1)->minutes();
    Hunch::label($id, ['intent' => 'other']);

    $intent = Label::query()->where('question', 'intent')->orderBy('id')->get();

    expect($intent)->toHaveCount(2)
        ->and($intent[0]->answer)->toBe('refund')
        ->and($intent[0]->superseded_at)->not->toBeNull()
        ->and($intent[1]->answer)->toBe('other')
        ->and($intent[1]->superseded_at)->toBeNull()
        ->and(Label::query()->where('question', 'intent')->whereNull('superseded_at')->count())->toBe(1)
        ->and(Label::query()->where('question', 'urgent')->whereNull('superseded_at')->count())->toBe(1);
});

it('refuses an answer the question set does not allow, and writes nothing', function (string $key, bool|string $answer) {
    $id = labelled();
    Hunch::label($id, ['intent' => 'refund']);

    try {
        Hunch::label($id, ['urgent' => true, $key => $answer]);

        $this->fail('The label should have been refused.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain($key);
    }

    expect(Label::query()->count())->toBe(1)
        ->and(Label::query()->sole()->superseded_at)->toBeNull();
})->with([
    'unknown question' => ['colour', 'red'],
    'not a Choice option' => ['intent', 'Refund'],
    'not a Score level' => ['tone', 'livid'],
    'Boolean given a string' => ['urgent', 'yes'],
    'Choice given a bool' => ['intent', true],
]);

it('refuses a classification ID that was never recorded', function () {
    labelled();

    try {
        Hunch::label('01J0000000000000000000NONE', ['intent' => 'refund']);

        $this->fail('The label should have been refused.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('01J0000000000000000000NONE');
    }

    expect(Label::query()->count())->toBe(0);
});

it('refuses labelling when persistence is off', function () {
    $id = labelled();
    config()->set('hunch.persistence', false);

    expect(fn () => Hunch::label($id, ['intent' => 'refund']))->toThrow(ConfigurationException::class, 'hunch.persistence')
        ->and(Label::query()->count())->toBe(0);
});
