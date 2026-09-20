<?php

namespace Ninthspace\Hunch\Sampling;

use Ninthspace\Hunch\Prompts\Bands;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;

/**
 * Checks one sample's output against the canonical schema. Only output that
 * validates is counted: anything else is recorded as invalid and excluded
 * from every vote.
 */
final class SampleValidator
{
    /**
     * Why the output is invalid, or null when it validates.
     */
    public static function problem(QuestionSet $set, mixed $output): ?string
    {
        if (! is_array($output) || (array_is_list($output) && $output !== [])) {
            return 'The output is not a JSON object.';
        }

        $reasons = $set->options->reasons !== null;
        $expected = [
            ...array_keys($set->questions),
            ...($reasons ? [QuestionSet::REASON] : []),
            ...($set->options->selfReport ? [QuestionSet::BANDS] : []),
        ];
        $missing = array_diff($expected, array_keys($output));
        $extra = array_diff(array_keys($output), $expected);

        // Every field missing means the answer never arrived, or answered
        // something else. Reported separately so a truncated response can be
        // told from a wrong one.
        if (count($missing) === count($expected)) {
            return $extra === []
                ? 'The output has no structured data.'
                : 'The output answers none of the questions.';
        }

        if ($missing !== []) {
            return 'Missing '.implode(', ', $missing).'.';
        }

        // Counted, not named: the model chooses these names, so they can carry
        // text from the state, and this reason is stored on the sample row and
        // dispatched on SampleInvalid.
        if ($extra !== []) {
            return count($extra) === 1
                ? 'The output has an unexpected field.'
                : 'The output has '.count($extra).' unexpected fields.';
        }

        if ($reasons && ! is_string($output[QuestionSet::REASON])) {
            return 'The reason is not a string.';
        }

        if ($set->options->selfReport) {
            $bands = $output[QuestionSet::BANDS];

            if (! is_array($bands) || array_diff(array_keys($set->questions), array_keys($bands)) !== []) {
                return 'The bands do not cover every question.';
            }

            foreach ($bands as $key => $band) {
                if (! Bands::isBand($band)) {
                    return "`{$key}` has no confidence band.";
                }
            }
        }

        foreach ($set->questions as $key => $question) {
            $answer = $output[$key];

            $valid = match (true) {
                $question instanceof Boolean => is_bool($answer),
                $question instanceof Choice => is_string($answer) && array_key_exists($answer, $question->options),
                $question instanceof Score => is_string($answer) && array_key_exists($answer, $question->levels),
                default => false,
            };

            if (! $valid) {
                return "`{$key}` is not one of its allowed values.";
            }
        }

        return null;
    }

    /**
     * Validated output split into its answers and its reason. A reason longer
     * than the limit is cut to it; the sample stays valid either way.
     *
     * @param  array<string, mixed>  $output
     * @return array{array<string, bool|string>, string|null, array<string, string>|null}
     */
    public static function split(QuestionSet $set, array $output): array
    {
        $bands = null;
        $reason = null;

        if ($set->options->selfReport) {
            $bands = self::statedBands($output[QuestionSet::BANDS] ?? []);
            unset($output[QuestionSet::BANDS]);
        }

        $max = $set->options->reasons;

        if ($max !== null) {
            $stated = $output[QuestionSet::REASON] ?? '';
            $reason = mb_substr(is_string($stated) ? $stated : '', 0, $max);
            unset($output[QuestionSet::REASON]);
        }

        /** @var array<string, bool|string> $output */
        return [$output, $reason, $bands];
    }

    /**
     * The band stated per question, keeping only the ones given as strings.
     *
     * @return array<string, string>
     */
    private static function statedBands(mixed $stated): array
    {
        $bands = [];

        foreach (is_array($stated) ? $stated : [] as $key => $band) {
            if (is_string($band)) {
                $bands[(string) $key] = $band;
            }
        }

        return $bands;
    }
}
