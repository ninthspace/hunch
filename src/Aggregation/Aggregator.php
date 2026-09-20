<?php

namespace Ninthspace\Hunch\Aggregation;

use InvalidArgumentException;
use Ninthspace\Hunch\Answers\Answers;
use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Answers\ScoreAnswer;
use Ninthspace\Hunch\Calibration\CalibrationScope;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Condition;
use Ninthspace\Hunch\Questions\Question;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Sampling\Sample;

/**
 * Turns the valid samples of one question into its answer. Every driver
 * calls this, so answer shapes cannot drift between them. Invalid samples
 * are excluded before anything is counted.
 */
final class Aggregator
{
    /**
     * @param  list<Sample>  $samples
     */
    public static function aggregate(string $key, Question $question, array $samples, bool $applies = true, ?CalibrationScope $scope = null): BooleanAnswer|ChoiceAnswer|ScoreAnswer
    {
        $answers = self::counted($key, $samples);

        return match (true) {
            $question instanceof Boolean => self::boolean($answers, $applies, $scope),
            $question instanceof Choice => self::choice($answers, array_keys($question->options), $question->tieBreak, $applies, $scope),
            $question instanceof Score => self::score($answers, $question->labels(), $question->tieBreak, $applies, $scope),
            default => throw new InvalidArgumentException("Question `{$key}` has no aggregation."),
        };
    }

    /**
     * Every question's answer, in canonical order. A conditional question is
     * counted only over the samples whose controlling Choice matched; it
     * applies when the controlling answer's winner matches, and it is null
     * when fewer than two samples qualify. With a scope, each answer can read
     * its calibration back.
     *
     * @param  list<Sample>  $samples
     */
    public static function answers(QuestionSet $set, array $samples, ?CalibrationScope $scope = null): Answers
    {
        $answers = [];

        foreach ($set->inCanonicalOrder() as $key => $question) {
            $condition = $question->condition;
            $questionScope = $scope?->forQuestion($key);

            if ($condition === null) {
                $answers[$key] = self::aggregate($key, $question, $samples, scope: $questionScope);

                continue;
            }

            $qualifying = self::qualifying($condition, $samples);

            if (count($qualifying) < 2) {
                $answers[$key] = null;

                continue;
            }

            $controlling = self::aggregate($condition->key, $set->questions[$condition->key], $samples);
            $applies = $controlling instanceof ChoiceAnswer && $condition->matches($controlling->choice);

            $answers[$key] = self::aggregate($key, $question, $qualifying, $applies, $questionScope);
        }

        return new Answers($answers);
    }

    /**
     * The valid samples whose controlling answer matches the condition.
     *
     * @param  list<Sample>  $samples
     * @return list<Sample>
     */
    public static function qualifying(Condition $condition, array $samples): array
    {
        return array_values(array_filter(
            $samples,
            fn (Sample $sample) => $condition->matches($sample->answerTo($condition->key)),
        ));
    }

    /**
     * An answer built directly from vote shares, for fakes and drivers that
     * report probabilities rather than votes. The same answer classes, the
     * same tie rule and the same entropy as aggregated votes; vote counts are
     * zero because no votes were cast.
     *
     * @param  float|array<string, float>  $shares  A Boolean's probability of true, or option/level => share.
     */
    public static function fromShares(string $key, Question $question, float|array $shares, ?CalibrationScope $scope = null): BooleanAnswer|ChoiceAnswer|ScoreAnswer
    {
        if ($question instanceof Boolean) {
            if (! is_float($shares)) {
                throw new InvalidArgumentException("`{$key}` is a Boolean: give its probability of true.");
            }

            return new BooleanAnswer($shares, ['true' => 0, 'false' => 0], scope: $scope);
        }

        if (! is_array($shares) || ! ($question instanceof Choice || $question instanceof Score)) {
            throw new InvalidArgumentException("`{$key}` needs a share per option or level.");
        }

        $keys = $question instanceof Choice ? array_keys($question->options) : $question->labels();
        $probabilities = array_fill_keys($keys, 0.0);

        foreach ($shares as $option => $share) {
            if (! array_key_exists($option, $probabilities)) {
                throw new InvalidArgumentException("`{$option}` is not one of `{$key}`'s options.");
            }

            $probabilities[$option] = (float) $share;
        }

        $votes = array_fill_keys($keys, 0);
        [$leader, $tied] = self::leading($probabilities, $question->tieBreak);

        if ($question instanceof Choice) {
            return new ChoiceAnswer(
                choice: $leader,
                probabilities: $probabilities,
                confidence: $probabilities[$leader],
                entropy: self::entropy($probabilities),
                votes: $votes,
                tied: $tied,
                scope: $scope,
            );
        }

        return new ScoreAnswer(
            score: (float) self::weightedLevels($probabilities, $keys),
            probabilities: $probabilities,
            votes: $votes,
            mode: $leader,
            tied: $tied,
            scope: $scope,
        );
    }

    /**
     * The answers this question received from valid samples.
     *
     * @param  list<Sample>  $samples
     * @return non-empty-list<bool|string>
     */
    private static function counted(string $key, array $samples): array
    {
        $answers = [];

        foreach ($samples as $sample) {
            $answer = $sample->answerTo($key);

            if ($answer !== null) {
                $answers[] = $answer;
            }
        }

        if ($answers === []) {
            throw new InvalidArgumentException("No valid sample answered `{$key}`.");
        }

        return $answers;
    }

    /**
     * @param  non-empty-list<bool|string>  $answers
     */
    private static function boolean(array $answers, bool $applies, ?CalibrationScope $scope): BooleanAnswer
    {
        $true = count(array_filter($answers, fn (bool|string $answer) => $answer === true));
        $false = count(array_filter($answers, fn (bool|string $answer) => $answer === false));

        if ($true + $false !== count($answers)) {
            throw new InvalidArgumentException('A Boolean answer must be true or false.');
        }

        return new BooleanAnswer((float) $true / count($answers), ['true' => $true, 'false' => $false], $applies, $scope);
    }

    /**
     * @param  non-empty-list<bool|string>  $answers
     * @param  non-empty-list<string>  $options
     */
    private static function choice(array $answers, array $options, ?string $tieBreak, bool $applies, ?CalibrationScope $scope): ChoiceAnswer
    {
        $votes = self::tally($answers, $options);
        $probabilities = self::shares($votes, count($answers));
        [$choice, $tied] = self::leading($votes, $tieBreak);

        return new ChoiceAnswer(
            choice: $choice,
            probabilities: $probabilities,
            confidence: $probabilities[$choice],
            entropy: self::entropy($probabilities),
            votes: $votes,
            tied: $tied,
            applies: $applies,
            scope: $scope,
        );
    }

    /**
     * @param  non-empty-list<bool|string>  $answers
     * @param  non-empty-list<string>  $levels
     */
    private static function score(array $answers, array $levels, ?string $tieBreak, bool $applies, ?CalibrationScope $scope): ScoreAnswer
    {
        $votes = self::tally($answers, $levels);
        [$mode, $tied] = self::leading($votes, $tieBreak);

        return new ScoreAnswer(
            score: (float) self::weightedLevels($votes, $levels) / count($answers),
            probabilities: self::shares($votes, count($answers)),
            votes: $votes,
            mode: $mode,
            tied: $tied,
            applies: $applies,
            scope: $scope,
        );
    }

    /**
     * Each level's position on the scale, weighted by its votes or share and
     * summed: divided by the votes cast, or taken over shares, it is the score.
     *
     * @param  array<string, int|float>  $weights  Level => votes or share.
     * @param  list<string>  $levels
     */
    private static function weightedLevels(array $weights, array $levels): int|float
    {
        $index = array_flip($levels);
        $total = 0;

        foreach ($weights as $level => $weight) {
            $total += $index[$level] * $weight;
        }

        return $total;
    }

    /**
     * Votes per key, every key present and in canonical order.
     *
     * @param  non-empty-list<bool|string>  $answers
     * @param  non-empty-list<string>  $keys
     * @return non-empty-array<string, int>
     */
    private static function tally(array $answers, array $keys): array
    {
        $votes = array_fill_keys($keys, 0);

        foreach ($answers as $answer) {
            if (! is_string($answer) || ! array_key_exists($answer, $votes)) {
                throw new InvalidArgumentException('Answer `'.var_export($answer, true).'` is not one of the question\'s options.');
            }

            $votes[$answer]++;
        }

        return $votes;
    }

    /**
     * @param  array<string, int>  $votes
     * @return array<string, float>
     */
    private static function shares(array $votes, int $counted): array
    {
        return array_map(fn (int $count) => (float) $count / $counted, $votes);
    }

    /**
     * The key with the most votes, and whether it had to win a tie. Among
     * tied keys, the tie-break wins when it is one of them, otherwise the
     * earliest in canonical order.
     *
     * @param  non-empty-array<string, int|float>  $votes
     * @return array{string, bool}
     */
    private static function leading(array $votes, ?string $tieBreak): array
    {
        $leaders = array_keys($votes, max($votes), true);

        if (count($leaders) === 1) {
            return [$leaders[0], false];
        }

        return [in_array($tieBreak, $leaders, true) ? (string) $tieBreak : $leaders[0], true];
    }

    /**
     * Shannon entropy of the shares, normalised by the number of options so
     * a unanimous answer is 0 and an even split is 1.
     *
     * @param  array<string, float>  $probabilities
     */
    private static function entropy(array $probabilities): float
    {
        $entropy = 0.0;

        foreach ($probabilities as $p) {
            if ($p > 0.0) {
                $entropy -= $p * log($p);
            }
        }

        return $entropy / log(count($probabilities));
    }
}
