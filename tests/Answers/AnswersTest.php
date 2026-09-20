<?php

use Ninthspace\Hunch\Aggregation\Aggregator;
use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Answers\Buckets;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Answers\ScoreAnswer;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\Sampling\Sample;
use Symfony\Component\Finder\Finder;

/**
 * @param  list<bool|string>  $answers
 */
function answerFor(Boolean|Choice|Score $question, array $answers): BooleanAnswer|ChoiceAnswer|ScoreAnswer
{
    return Aggregator::aggregate('q', $question, array_map(
        fn (bool|string $answer, int $i) => Sample::valid($i + 1, ['q' => $answer]),
        $answers,
        array_keys($answers),
    ));
}

const HONESTY = 'vote-share estimate, not a calibrated probability';

it('gives a BooleanAnswer thresholds, anyTrue, unanimity and votes', function () {
    $answer = answerFor(new Boolean('q'), [true, true, false]);

    expect($answer->isTrue(0.6))->toBeTrue()
        ->and($answer->isTrue(0.7))->toBeFalse()
        ->and($answer->anyTrue())->toBeTrue()
        ->and($answer->unanimous())->toBeFalse()
        ->and($answer->votes)->toBe(['true' => 2, 'false' => 1]);
});

it('reads a Choice option share, throwing for a key that is not an option', function () {
    $answer = answerFor(new Choice('q', ['a' => 'A', 'b' => 'B', 'c' => 'C']), ['a', 'b']);

    expect($answer->probabilityOf('c'))->toBe(0.0)
        ->and(fn () => $answer->probabilityOf('x'))->toThrow(InvalidArgumentException::class);
});

it('places probabilities in the default buckets', function (float $probability, string $bucket) {
    expect(Buckets::for($probability))->toBe($bucket);
})->with([
    [1.0, '1.0'],
    [0.8, '[0.8, 1.0)'],
    [0.79, '[0.6, 0.8)'],
    [0.2, '[0, 0.6)'],
]);

it('gives every answer a bucket from its leading share, and applies and calibration defaults', function () {
    $boolean = answerFor(new Boolean('q'), [false, false, false, false, true]);
    $choice = answerFor(new Choice('q', ['a' => 'A', 'b' => 'B']), ['a', 'a', 'a']);
    $score = answerFor(new Score('q', ['L', 'M', 'H']), ['L', 'M', 'H', 'H']);

    expect($boolean->bucket)->toBe('[0.8, 1.0)')
        ->and($choice->bucket)->toBe('1.0')
        ->and($score->bucket)->toBe('[0, 0.6)')
        ->and([$boolean->applies, $choice->applies, $score->applies])->toBe([true, true, true])
        ->and([$boolean->calibration(), $choice->calibration(), $score->calibration()])->toBe([null, null, null]);
});

it('cannot change an answer after construction', function (string $property, mixed $value) {
    $answer = answerFor(new Choice('q', ['a' => 'A', 'b' => 'B']), ['a', 'b', 'a']);

    $answer->{$property} = $value;
})->with([
    'choice' => ['choice', 'b'],
    'confidence' => ['confidence', 1.0],
    'bucket' => ['bucket', '1.0'],
    'applies' => ['applies', false],
])->throws(Error::class, 'readonly');

arch('answers are immutable')
    ->expect('Ninthspace\Hunch\Answers')
    ->classes()
    ->toBeReadonly()
    ->ignoring(Buckets::class);

it('counts Score votes per level and knows unanimity', function () {
    $score = answerFor(new Score('q', ['L', 'M', 'H']), ['L', 'M', 'H', 'H']);
    $choice = answerFor(new Choice('q', ['a' => 'A', 'b' => 'B']), ['a', 'a', 'a']);

    expect($score->votes)->toBe(['L' => 1, 'M' => 1, 'H' => 2])
        ->and($score->unanimous())->toBeFalse()
        ->and($choice->unanimous())->toBeTrue();
});

it('documents every probability, probabilities and confidence as a vote-share estimate', function () {
    $documented = 0;

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/src/Answers')->name('*Answer.php') as $file) {
        $class = 'Ninthspace\\Hunch\\Answers\\'.$file->getBasename('.php');

        if ((new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $constructor = new ReflectionMethod($class, '__construct');
        $docblock = (string) $constructor->getDocComment();
        $names = array_intersect(['probability', 'probabilities', 'confidence'], array_map(fn (ReflectionParameter $p) => $p->getName(), $constructor->getParameters()));

        expect($names)->not->toBeEmpty("{$class} has no probability to document");

        foreach ($names as $name) {
            preg_match('/@param\s+\S+(?:<[^>]*>)?\s+\$'.$name.'\b(.*)$/m', $docblock, $match);

            expect($match[1] ?? '')
                ->toContain(HONESTY)
                ->toContain('calibration()');

            $documented++;
        }
    }

    expect($documented)->toBe(4);
});

it('never calls vote shares calibrated, accurate or a confidence level without qualifying it', function () {
    $texts = ['README.md' => file_get_contents(dirname(__DIR__, 2).'/README.md')];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/src/Answers')->name('*.php') as $file) {
        $texts[$file->getRelativePathname()] = $file->getContents();
    }

    foreach ($texts as $name => $text) {
        $unqualified = preg_replace('/not a calibrated probability/i', '', (string) $text);

        expect(preg_match('/\bcalibrated\b|\baccurate\b|confidence level/i', (string) $unqualified))
            ->toBe(0, "{$name} describes vote shares without qualification");
    }
});
