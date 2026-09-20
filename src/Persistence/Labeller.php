<?php

namespace Ninthspace\Hunch\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ninthspace\Hunch\Events\Labelled;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\Models\Label;
use Ninthspace\Hunch\Models\StoredQuestionSet;
use Ninthspace\Hunch\Support\Settings;

/**
 * Records human-confirmed answers against a recorded classification. Every
 * answer is checked against the question set as it was asked before anything
 * is written; a later label for a question supersedes the earlier one, which
 * is kept.
 */
final class Labeller
{
    /**
     * @param  array<string, bool|string>  $answers  Question key => the confirmed answer.
     * @return list<Label>
     */
    public static function label(string $classificationId, array $answers, ?Model $by = null): array
    {
        if (! Settings::get('persistence', false)) {
            throw new ConfigurationException('Labelling needs `hunch.persistence` to be on.');
        }

        if ($answers === []) {
            throw new InvalidArgumentException('A label needs at least one answer.');
        }

        $classification = MissingTables::guard(fn () => Classification::query()->find($classificationId))
            ?? throw new InvalidArgumentException("No recorded classification `{$classificationId}`.");

        $questions = self::questions($classification->question_set_hash);

        foreach ($answers as $key => $answer) {
            self::check((string) $key, $answer, $questions);
        }

        $labels = DB::transaction(function () use ($classification, $answers, $by) {
            $labels = [];

            foreach ($answers as $key => $answer) {
                $classification->labels()
                    ->where('question', (string) $key)
                    ->whereNull('superseded_at')
                    ->update(['superseded_at' => now()]);

                $labels[] = $classification->labels()->create([
                    'question' => (string) $key,
                    'answer' => $answer,
                    'labelled_by_type' => $by?->getMorphClass(),
                    'labelled_by_id' => $by === null ? null : Recorder::subjectKey($by),
                ]);
            }

            return $labels;
        });

        Labelled::dispatch(
            $classification->id,
            $classification->question_set_hash,
            $classification->state_hash,
            questions: array_map(strval(...), array_keys($answers)),
        );

        return $labels;
    }

    /**
     * Question key => the answers it allows, read from the stored question
     * set: `true` for a Boolean, otherwise its option or level keys.
     *
     * @return array<string, list<string>|true>
     */
    private static function questions(string $hash): array
    {
        $json = StoredQuestionSet::query()->where('hash', $hash)->value('canonical');
        $canonical = is_string($json) ? json_decode($json, true) : null;
        $questions = is_array($canonical) && is_array($canonical['questions'] ?? null) ? $canonical['questions'] : [];
        $allowed = [];

        foreach ($questions as $key => $question) {
            if (! is_array($question)) {
                continue;
            }

            $entries = $question['options'] ?? $question['levels'] ?? null;

            $allowed[(string) $key] = is_array($entries) ? self::keys($entries) : true;
        }

        return $allowed;
    }

    /**
     * The keys of a canonical list of [key, description] pairs.
     *
     * @param  array<mixed>  $pairs
     * @return list<string>
     */
    private static function keys(array $pairs): array
    {
        $keys = [];

        foreach ($pairs as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null)) {
                $keys[] = $pair[0];
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, list<string>|true>  $questions
     */
    private static function check(string $key, mixed $answer, array $questions): void
    {
        if (! array_key_exists($key, $questions)) {
            throw new InvalidArgumentException("`{$key}` is not a question of this classification.");
        }

        $allowed = $questions[$key];

        $valid = $allowed === true
            ? is_bool($answer)
            : is_string($answer) && in_array($answer, $allowed, true);

        if (! $valid) {
            throw new InvalidArgumentException('`'.var_export($answer, true)."` is not an answer `{$key}` allows.");
        }
    }
}
