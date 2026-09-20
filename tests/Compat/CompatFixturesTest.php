<?php

use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Question;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\Redactors\ContactDetails;
use Ninthspace\Hunch\Tests\Compat\CompatFixtures;

it('holds 42 labelled items in three groups, as the epic settled', function () {
    $groups = CompatFixtures::groups();

    expect(array_keys($groups))->toBe(['core', 'large-choice', 'injection'])
        ->and(count($groups['core']['items']))->toBe(24)
        ->and(count($groups['large-choice']['items']))->toBe(12)
        ->and(count($groups['injection']['items']))->toBe(6)
        ->and(CompatFixtures::items())->toHaveCount(42);
});

it('labels every item for every question of its group', function () {
    foreach (CompatFixtures::groups() as $name => $group) {
        expect($group['context'])->not->toBeEmpty()
            ->and($group['questions'])->not->toBeEmpty();

        foreach ($group['items'] as $item) {
            expect($item['state'])->not->toBeEmpty()
                ->and(array_keys($item['labels']))->toBe(array_keys($group['questions']), "{$item['id']} is not labelled for every question");

            foreach ($group['questions'] as $key => $question) {
                expect($question)->toBeInstanceOf(Question::class)
                    ->and(labelIsAnswerable($question, $item['labels'][$key]))->toBeTrue("{$item['id']} labels `{$key}` with an answer the question does not offer");
            }
        }
    }
});

it('gives every item an id of its own', function () {
    $ids = array_column(CompatFixtures::items(), 'id');

    expect($ids)->toHaveCount(42)
        ->and(array_unique($ids))->toHaveCount(42);
});

it('asks a Choice of 40 to 50 options in the large-choice group', function () {
    $question = CompatFixtures::groups()['large-choice']['questions']['department'];

    expect($question)->toBeInstanceOf(Choice::class)
        ->and(count($question->options))->toBeGreaterThanOrEqual(40)
        ->and(count($question->options))->toBeLessThanOrEqual(50);
});

it('gives every injection item an instruction that asks for a different answer than the truth', function () {
    $items = CompatFixtures::groups()['injection']['items'];

    expect($items)->toHaveCount(6);

    foreach ($items as $item) {
        expect($item)->toHaveKey('complies')
            ->and($item['complies'])->not->toBe($item['labels'], "{$item['id']} asks for the answer that is already true, so compliance cannot be measured");
    }
});

it('carries no contact details or key material in any fixture state', function () {
    $redactor = new ContactDetails;

    foreach (CompatFixtures::items() as $item) {
        expect($redactor($item['state']))->toBe($item['state'], "{$item['id']} contains an email address or phone number")
            ->and($item['state'])->not->toContain('sk-ant-')
            ->and(preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $item['state']))->toBe(0);
    }
});

it('keeps the fixture set inside tests/Fixtures/compat', function () {
    $files = glob(CompatFixtures::DIRECTORY.'/*.php');

    expect($files)->toHaveCount(3)
        ->and(realpath(CompatFixtures::DIRECTORY))->toEndWith('tests/Fixtures/compat');
});

/**
 * Whether the question offers this answer at all.
 */
function labelIsAnswerable(Question $question, bool|string $label): bool
{
    $options = match (true) {
        $question instanceof Choice => array_keys($question->options),
        $question instanceof Score => $question->labels(),
        default => null,
    };

    return $options === null ? is_bool($label) : is_string($label) && in_array($label, $options, true);
}
