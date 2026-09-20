<?php

use Ninthspace\Hunch\PromptOptions;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Tests\Fixtures\GoldenQuestionSet;
use Symfony\Component\Process\Process;

/**
 * @param  array{intent?: array<string, string>, levels?: array<string, string>, question?: string, context?: string}  $overrides
 */
function questionSet(array $overrides = [], ?string $version = null, PromptOptions $options = new PromptOptions): QuestionSet
{
    return new QuestionSet([
        'intent' => new Choice($overrides['question'] ?? 'What does the customer want?', $overrides['intent'] ?? [
            'refund' => 'Money back',
            'exchange' => 'A different item',
        ]),
        'urgency' => new Score('How urgent is it?', $overrides['levels'] ?? [
            'Low' => 'Can wait',
            'High' => 'Needs action today',
        ]),
    ], $overrides['context'] ?? 'A support inbox.', $options, $version);
}

it('gives the same hash for the same content in a separate process', function () {
    $script = sprintf(
        'require %s; echo %s::make()->hash();',
        var_export(dirname(__DIR__).'/vendor/autoload.php', true),
        GoldenQuestionSet::class,
    );

    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->mustRun();

    expect($process->getOutput())->toBe(GoldenQuestionSet::make()->hash());
});

it('changes the hash when any single character changes', function (array $overrides) {
    expect(questionSet($overrides)->hash())->not->toBe(questionSet()->hash());
})->with([
    'question' => [['question' => 'What does the customer want!']],
    'option key' => [['intent' => ['refunds' => 'Money back', 'exchange' => 'A different item']]],
    'option description' => [['intent' => ['refund' => 'Money back.', 'exchange' => 'A different item']]],
    'level label' => [['levels' => ['low' => 'Can wait', 'High' => 'Needs action today']]],
    'level description' => [['levels' => ['Low' => 'Can wait', 'High' => 'Needs action now']]],
    'context' => [['context' => 'A support inbox!']],
]);

it('changes the hash when Choice options are reordered', function () {
    $reordered = questionSet(['intent' => ['exchange' => 'A different item', 'refund' => 'Money back']]);

    expect($reordered->hash())->not->toBe(questionSet()->hash());
});

it('changes the hash when Score levels are reordered', function () {
    $reordered = questionSet(['levels' => ['High' => 'Needs action today', 'Low' => 'Can wait']]);

    expect($reordered->hash())->not->toBe(questionSet()->hash());
});

it('keeps the hash when the question keys are passed in a different order', function () {
    $set = questionSet();
    $reversed = new QuestionSet(array_reverse($set->questions, preserve_keys: true), $set->context);

    expect(array_keys($reversed->questions))->toBe(['urgency', 'intent'])
        ->and($reversed->hash())->toBe($set->hash());
});

it('matches the golden hash, including its format prefix', function () {
    expect(GoldenQuestionSet::make()->hash())
        ->toBe('v1:85f093967968299a2c22feb54ebb63840b22230ee54a80eac75f9c28bf25482d')
        ->toStartWith(QuestionSet::HASH_FORMAT.':');
});

it('ignores the version name', function () {
    expect(questionSet(version: '2026-09')->hash())
        ->toBe(questionSet()->hash())
        ->toBe(questionSet(version: 'other')->hash());
});

it('hashes only the context, the prompt options and the questions', function () {
    expect(array_keys(json_decode(questionSet(version: '2026-09')->toCanonicalJson(), true)))
        ->toBe(['context', 'options', 'questions']);
});

it('changes the hash when reasons or self-report are enabled', function (PromptOptions $options) {
    expect(questionSet(options: $options)->hash())->not->toBe(questionSet()->hash());
})->with([
    'reasons' => [new PromptOptions(reasons: 200)],
    'self-report' => [new PromptOptions(selfReport: true)],
]);

it('includes Boolean descriptions in the hash', function () {
    $plain = new QuestionSet(['complaint' => new Boolean('Is this a complaint?')]);
    $described = new QuestionSet(['complaint' => new Boolean('Is this a complaint?', ['false' => 'A question'])]);

    expect($described->hash())->not->toBe($plain->hash());
});

it('rejects question keys that are not non-empty strings', function (array $questions) {
    new QuestionSet($questions);
})->with([
    'a list' => [[new Boolean('q')]],
    'an empty key' => [['' => new Boolean('q')]],
])->throws(InvalidArgumentException::class);

it('rejects values that are not questions', function () {
    new QuestionSet(['intent' => 'What does the customer want?']);
})->throws(InvalidArgumentException::class);
