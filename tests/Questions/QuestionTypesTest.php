<?php

use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;

function options(int $count): array
{
    $options = [];

    for ($i = 1; $i <= $count; $i++) {
        $options["option_{$i}"] = "Option {$i}";
    }

    return $options;
}

describe('Choice', function () {
    it('constructs with 2 or 50 options', function (int $count) {
        expect(new Choice('q', options($count))->options)->toHaveCount($count);
    })->with([2, 50]);

    it('rejects 1 or 51 options', function (int $count) {
        new Choice('q', options($count));
    })->with([1, 51])->throws(InvalidArgumentException::class);

    it('reads its upper bound from hunch.choice.max_options', function () {
        config()->set('hunch.choice.max_options', 20);

        expect(new Choice('q', options(20))->options)->toHaveCount(20)
            ->and(fn () => new Choice('q', options(21)))->toThrow(InvalidArgumentException::class);
    });

    it('rejects option keys that are not non-empty strings', function (array $options) {
        new Choice('q', $options);
    })->with([
        'a list' => [['refund', 'exchange']],
        'an integer key' => [[1 => 'One', 'two' => 'Two']],
        'an empty key' => [['' => 'Blank', 'two' => 'Two']],
    ])->throws(InvalidArgumentException::class);
});

describe('Score', function () {
    it('keeps levels in declared order, lowest first', function (array $levels) {
        $score = new Score('q', $levels);

        expect($score->labels())->toBe(['Low', 'Mid', 'High'])
            ->and($score->labels()[0])->toBe('Low');
    })->with([
        'a list of labels' => [['Low', 'Mid', 'High']],
        'labels with descriptions' => [['Low' => 'd', 'Mid' => 'd', 'High' => 'd']],
    ]);

    it('keeps level descriptions', function () {
        expect(new Score('q', ['Low' => 'Barely', 'High' => 'Very'])->levels)
            ->toBe(['Low' => 'Barely', 'High' => 'Very']);
    });

    it('rejects fewer than 2 levels', function (array $levels) {
        new Score('q', $levels);
    })->with([
        'none' => [[]],
        'one label' => [['Only']],
        'one described level' => [['Only' => 'd']],
    ])->throws(InvalidArgumentException::class);
});

describe('Boolean', function () {
    it('is valid with no descriptions', function () {
        expect(new Boolean('q')->descriptions)->toBe([]);
    });

    it('accepts descriptions of true and false', function () {
        expect(new Boolean('q', ['true' => 'Yes', 'false' => 'No'])->descriptions)
            ->toBe(['true' => 'Yes', 'false' => 'No']);
    });

    it('rejects any other description key', function (array $descriptions) {
        new Boolean('q', $descriptions);
    })->with([
        'yes' => [['yes' => 'Yes']],
        'a list' => [['Yes', 'No']],
        'mixed' => [['true' => 'Yes', 'maybe' => 'Unsure']],
    ])->throws(InvalidArgumentException::class);
});
