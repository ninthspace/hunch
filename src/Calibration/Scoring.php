<?php

namespace Ninthspace\Hunch\Calibration;

use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Answers\ScoreAnswer;

/**
 * Scores one recorded answer against its label: whether it was right, the
 * probability it stated for its winning answer, and its Brier term. Both
 * `hunch:calibrate` and `hunch:eval` measure through here, so they cannot
 * drift apart.
 *
 * @phpstan-type Scored array{answer: string, correct: bool, stated: float, bucket: string, brier: float}
 */
final class Scoring
{
    /**
     * @param  array<string, mixed>  $answer  A recorded answer, as `hunch_classifications.answers` holds it.
     * @return Scored|null Null when the answer is not one this can score.
     */
    public static function of(array $answer, bool|string $label): ?array
    {
        $winner = $answer['answer'] ?? null;
        $bucket = $answer['bucket'] ?? null;

        if ((! is_bool($winner) && ! is_string($winner)) || ! is_string($bucket)) {
            return null;
        }

        [$stated, $brier] = self::probabilities($answer, $winner, $label);

        return [
            'answer' => is_bool($winner) ? ($winner ? 'true' : 'false') : $winner,
            'correct' => $winner === $label,
            'stated' => $stated,
            'bucket' => $bucket,
            'brier' => $brier,
        ];
    }

    /**
     * An answer object in the same shape the database holds.
     *
     * @return array<string, mixed>
     */
    public static function fromAnswer(BooleanAnswer|ChoiceAnswer|ScoreAnswer $answer): array
    {
        $shared = ['applies' => $answer->applies, 'bucket' => $answer->bucket];

        return match (true) {
            $answer instanceof BooleanAnswer => [
                ...$shared,
                'answer' => $answer->isTrue(),
                'probability' => $answer->probability,
                'votes' => $answer->votes,
            ],
            $answer instanceof ChoiceAnswer => [
                ...$shared,
                'answer' => $answer->choice,
                'probabilities' => $answer->probabilities,
                'votes' => $answer->votes,
                'tied' => $answer->tied,
            ],
            $answer instanceof ScoreAnswer => [
                ...$shared,
                'answer' => $answer->mode,
                'score' => $answer->score,
                'level' => $answer->level(),
                'probabilities' => $answer->probabilities,
                'votes' => $answer->votes,
                'tied' => $answer->tied,
            ],
        };
    }

    /**
     * The probability stated for the winning answer, and this answer's Brier
     * term: over the probability of true for a Boolean, otherwise
     * multi-class over every option or level.
     *
     * @param  array<string, mixed>  $answer
     * @return array{float, float}
     */
    private static function probabilities(array $answer, bool|string $winner, bool|string $label): array
    {
        if (is_bool($winner)) {
            $true = is_numeric($answer['probability'] ?? null) ? (float) $answer['probability'] : 0.0;

            return [
                $winner ? $true : 1.0 - $true,
                Statistics::booleanBrier([$true], [$label === true]),
            ];
        }

        $shares = [];

        foreach (is_array($answer['probabilities'] ?? null) ? $answer['probabilities'] : [] as $option => $share) {
            $shares[(string) $option] = is_numeric($share) ? (float) $share : 0.0;
        }

        return [
            $shares[$winner] ?? 0.0,
            Statistics::multiclassBrier([$shares], [is_string($label) ? $label : '']),
        ];
    }
}
