<?php

namespace Ninthspace\Hunch\Sampling;

use Ninthspace\Hunch\Aggregation\Aggregator;
use Ninthspace\Hunch\Questions\Condition;
use Ninthspace\Hunch\QuestionSet;

/**
 * Decides how many more samples a classification takes.
 *
 * A fixed rule takes all of its samples. An adaptive rule takes `min`, then
 * two at a time up to `max`, stopping as soon as every question is settled:
 * its valid answers are unanimous, or the leader is ahead by more than the
 * samples still to come, so it can be neither overtaken nor tied.
 *
 * A rule's `min` and `max` count valid samples, so an invalid sample is
 * replaced. `$resamples` caps how many calls beyond `max` that may cost, and
 * bounds every batch, so a run makes at most `max` plus `$resamples` calls.
 */
final class Stopping
{
    public const STEP = 2;

    /**
     * @param  list<Sample>  $taken
     * @param  int  $resamples  Calls allowed beyond the rule's own maximum.
     */
    public static function nextBatch(SamplingRule $rule, QuestionSet $set, array $taken, int $resamples = 0): int
    {
        $valid = count(array_filter($taken, fn (Sample $sample) => $sample->isValid()));

        // Calls left: the rule's maximum plus the resampling allowance, less
        // what has been spent.
        $headroom = $rule->max + max(0, $resamples) - count($taken);

        if ($headroom <= 0) {
            return 0;
        }

        // Below `min` there is nothing to judge yet, for either kind of rule.
        if ($valid < $rule->min) {
            return min($rule->min - $valid, $headroom);
        }

        // Samples that could still arrive: what the rule wants, or what the
        // headroom allows, whichever is smaller.
        $remaining = min($rule->max - $valid, $headroom);

        if ($remaining <= 0) {
            return 0;
        }

        if (! $rule->adaptive) {
            return $remaining;
        }

        if (self::settled($set, $taken, $remaining)) {
            return 0;
        }

        return min(self::STEP, $remaining);
    }

    /**
     * @param  list<Sample>  $taken
     */
    private static function settled(QuestionSet $set, array $taken, int $remaining): bool
    {
        foreach ($set->questions as $key => $question) {
            $samples = $taken;

            if ($question->condition !== null) {
                if (! self::applies($question->condition, $taken)) {
                    continue;
                }

                $samples = Aggregator::qualifying($question->condition, $taken);
            }

            $votes = self::votes($key, $samples);

            if (count($votes) === 0) {
                return false;
            }

            if (count($votes) === 1) {
                continue;
            }

            rsort($votes);

            if ($remaining >= $votes[0] - $votes[1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a conditional question applies to the samples so far: its
     * controlling answer's current leader is one of the condition's values.
     *
     * @param  list<Sample>  $taken
     */
    private static function applies(Condition $condition, array $taken): bool
    {
        $votes = self::votes($condition->key, $taken);

        if ($votes === []) {
            return false;
        }

        arsort($votes);

        return $condition->matches(unserialize((string) array_key_first($votes)));
    }

    /**
     * Valid answers to `$key`, counted by answer.
     *
     * @param  list<Sample>  $samples
     * @return array<string, int>
     */
    private static function votes(string $key, array $samples): array
    {
        $votes = [];

        foreach ($samples as $sample) {
            $answer = $sample->answerTo($key);

            if ($answer !== null) {
                $vote = serialize($answer);
                $votes[$vote] = ($votes[$vote] ?? 0) + 1;
            }
        }

        return $votes;
    }
}
