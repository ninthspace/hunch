<?php

use Ninthspace\Hunch\Aggregation\Aggregator;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\Sampling\Sample;

/**
 * One valid sample per answer, numbered from 1.
 *
 * @param  list<bool|string>  $answers
 * @return list<Sample>
 */
function votes(string $key, array $answers): array
{
    return array_map(
        fn (bool|string $answer, int $i) => Sample::valid($i + 1, [$key => $answer]),
        $answers,
        array_keys($answers),
    );
}

function abcChoice(): Choice
{
    return new Choice('Which?', ['a' => 'A', 'b' => 'B', 'c' => 'C']);
}

it('gives a Boolean probability of true votes over counted samples', function () {
    $answer = Aggregator::aggregate('q', new Boolean('Is it?'), votes('q', [true, true, true, false, false]));

    expect($answer->probability)->toBe(0.6);
});

it('gives Choice shares, the leading choice, its confidence and the normalised entropy', function () {
    $answer = Aggregator::aggregate('q', abcChoice(), votes('q', ['a', 'a', 'a', 'b']));

    expect($answer->probabilities)->toBe(['a' => 0.75, 'b' => 0.25, 'c' => 0.0])
        ->and($answer->choice)->toBe('a')
        ->and($answer->confidence)->toBe(0.75)
        ->and($answer->entropy)->toEqualWithDelta(-(0.75 * log(0.75) + 0.25 * log(0.25)) / log(3), 1e-9);
});

it('gives a unanimous Choice an entropy of 0 and an even split an entropy of 1', function () {
    expect(Aggregator::aggregate('q', abcChoice(), votes('q', ['b', 'b', 'b']))->entropy)->toEqualWithDelta(0.0, 1e-9)
        ->and(Aggregator::aggregate('q', abcChoice(), votes('q', ['a', 'b', 'c']))->entropy)->toEqualWithDelta(1.0, 1e-9);
});

it('gives a Score its mean level index and the nearest level, halves rounding up', function (array $answers, float $score, string $level) {
    $answer = Aggregator::aggregate('q', new Score('How much?', ['L', 'M', 'H']), votes('q', $answers));

    expect($answer->score)->toBe($score)
        ->and($answer->level())->toBe($level);
})->with([
    'L,M,H,H' => [['L', 'M', 'H', 'H'], 1.25, 'M'],
    'M,H' => [['M', 'H'], 1.5, 'H'],
]);

it('gives Choice and Score shares that sum to 1', function (Choice|Score $question, array $answers) {
    $answer = Aggregator::aggregate('q', $question, votes('q', $answers));

    expect(array_sum($answer->probabilities))->toEqualWithDelta(1.0, 1e-9);
})->with([
    'choice, thirds' => [fn () => abcChoice(), ['a', 'b', 'c', 'a', 'b', 'c', 'a']],
    'score, sevenths' => [fn () => new Score('q', ['L', 'M', 'H']), ['L', 'M', 'M', 'H', 'H', 'H', 'L']],
]);

it('ignores invalid samples entirely', function (Boolean|Choice|Score $question, array $answers) {
    $valid = votes('q', $answers);
    $withInvalid = [
        Sample::invalid(90, 'Did not match the schema'),
        ...$valid,
        Sample::invalid(91, 'Timed out'),
    ];

    $clean = Aggregator::aggregate('q', $question, $valid);
    $mixed = Aggregator::aggregate('q', $question, $withInvalid);

    expect($mixed->votes)->toBe($clean->votes)
        ->and($mixed)->toEqual($clean);
})->with([
    'boolean' => [fn () => new Boolean('q'), [true, false, true]],
    'choice' => [fn () => abcChoice(), ['a', 'c', 'c']],
    'score' => [fn () => new Score('q', ['L', 'M', 'H']), ['L', 'H', 'H']],
]);

it('rejects an answer that is not one of the question\'s options', function () {
    Aggregator::aggregate('q', abcChoice(), votes('q', ['a', 'z']));
})->throws(InvalidArgumentException::class);

it('refuses to aggregate when no sample counts', function () {
    Aggregator::aggregate('q', new Boolean('q'), [Sample::invalid(1, 'Timed out')]);
})->throws(InvalidArgumentException::class);
