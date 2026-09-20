<?php

use Illuminate\Support\Str;
use Ninthspace\Hunch\Answers\Answers;
use Ninthspace\Hunch\ClassificationMeta;
use Ninthspace\Hunch\Models\StoredQuestionSet;
use Ninthspace\Hunch\Persistence\Recorder;
use Ninthspace\Hunch\PromptOptions;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Result;
use Ninthspace\Hunch\SampleCounts;
use Ninthspace\Hunch\Usage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    persistenceOn($this);
});

function asked(): QuestionSet
{
    return new QuestionSet([
        'intent' => new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else'], 'other'),
        'urgent' => new Boolean('Is it urgent?', ['true' => 'Needs a reply today'])->onlyWhen('intent', 'refund'),
        'tone' => new Score('How upset are they?', ['calm' => 'Calm', 'cross' => 'Cross', 'furious' => 'Furious'])->tieBreak('cross'),
    ], 'A support inbox.', new PromptOptions(reasons: 120), 'v2');
}

it('rebuilds a recorded question set with its conditions, tie-breaks, context and options', function () {
    $set = asked();

    $rebuilt = QuestionSet::fromStored($set->toCanonicalJson(), $set->definition(), $set->version);

    expect($rebuilt->hash())->toBe($set->hash())
        ->and($rebuilt->context)->toBe('A support inbox.')
        ->and($rebuilt->version)->toBe('v2')
        ->and($rebuilt->options->reasons)->toBe(120)
        ->and($rebuilt->definition())->toBe($set->definition())
        ->and($rebuilt->questions['urgent']->condition?->key)->toBe('intent')
        ->and($rebuilt->questions['urgent']->condition?->values)->toBe(['refund'])
        ->and($rebuilt->questions['intent']->tieBreak)->toBe('other')
        ->and($rebuilt->questions['tone']->tieBreak)->toBe('cross')
        ->and($rebuilt->questions['tone']->levels)->toBe(['calm' => 'Calm', 'cross' => 'Cross', 'furious' => 'Furious'])
        ->and($rebuilt->questions['urgent']->descriptions)->toBe(['true' => 'Needs a reply today']);
});

it('records the definition beside the canonical form, without changing the hash', function () {
    $set = asked();

    Recorder::completed(
        enquiry(),
        $set,
        new Result(
            id: (string) Str::ulid(),
            answers: new Answers,
            samples: new SampleCounts(0, 0, 0),
            usage: new Usage,
            meta: new ClassificationMeta('sampling', 'anthropic', 'm', 'm', 'fixed:3', $set->hash(), str_repeat('0', 64), 'v2', [], []),
        ),
        'Refund please.',
    );

    $stored = StoredQuestionSet::query()->sole();

    expect($stored->hash)->toBe($set->hash())
        ->and($stored->definition)->toBe($set->definition())
        ->and(QuestionSet::fromStored($stored->canonical, $stored->definition, $stored->version)->hash())->toBe($set->hash());
});
