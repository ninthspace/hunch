<?php

use Ninthspace\Hunch\Aggregation\Aggregator;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\Sampling\Sample;

/**
 * @param  list<bool|string>  $answers
 * @return list<Sample>
 */
function tieVotes(array $answers): array
{
    return array_map(fn (bool|string $answer, int $i) => Sample::valid($i + 1, ['q' => $answer]), $answers, array_keys($answers));
}

it('lets the tieBreak value win when it is among the tied answers', function () {
    $answer = Aggregator::aggregate('q', new Choice('Which?', ['a' => 'A', 'b' => 'B', 'c' => 'C'])->tieBreak('c'), tieVotes(['b', 'b', 'c', 'c']));

    expect($answer->choice)->toBe('c')
        ->and($answer->tied)->toBeTrue();
});

it('falls back to the earliest tied answer when the tieBreak value is not tied', function () {
    $answer = Aggregator::aggregate('q', new Choice('Which?', ['a' => 'A', 'b' => 'B', 'c' => 'C'])->tieBreak('a'), tieVotes(['b', 'b', 'c', 'c']));

    expect($answer->choice)->toBe('b')
        ->and($answer->tied)->toBeTrue();
});

it('resolves a Score tie by the same rule, leaving score and level() on the mean', function () {
    $score = new Score('How much?', ['L', 'M', 'H']);
    $samples = tieVotes(['L', 'L', 'H', 'H']);

    $byOrder = Aggregator::aggregate('q', $score, $samples);
    $byTieBreak = Aggregator::aggregate('q', $score->tieBreak('H'), $samples);

    expect($byOrder->mode)->toBe('L')
        ->and($byOrder->tied)->toBeTrue()
        ->and($byTieBreak->mode)->toBe('H')
        ->and($byTieBreak->tied)->toBeTrue()
        ->and($byOrder->score)->toBe(1.0)
        ->and($byTieBreak->score)->toBe(1.0)
        ->and($byOrder->level())->toBe('M')
        ->and($byTieBreak->level())->toBe('M');
});

it('gives a Boolean tie a probability of 0.5 with no tie rule', function () {
    $answer = Aggregator::aggregate('q', new Boolean('Is it?'), tieVotes([true, false]));

    expect($answer->probability)->toBe(0.5)
        ->and(property_exists($answer, 'tied'))->toBeFalse();
});

it('does not mark a single leading answer as tied', function (Choice|Score $question, array $answers) {
    expect(Aggregator::aggregate('q', $question, tieVotes($answers))->tied)->toBeFalse();
})->with([
    'choice' => [fn () => new Choice('q', ['a' => 'A', 'b' => 'B', 'c' => 'C'])->tieBreak('c'), ['b', 'b', 'c']],
    'unanimous choice' => [fn () => new Choice('q', ['a' => 'A', 'b' => 'B']), ['a', 'a']],
    'score' => [fn () => new Score('q', ['L', 'M', 'H'])->tieBreak('L'), ['M', 'M', 'L']],
]);

it('rejects a tieBreak value that is not an option or level', function (Choice|Score $question) {
    $question->tieBreak('z');
})->with([
    'choice' => [fn () => new Choice('q', ['a' => 'A', 'b' => 'B'])],
    'score' => [fn () => new Score('q', ['L', 'H'])],
])->throws(InvalidArgumentException::class);
