<?php

namespace Ninthspace\Hunch\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Answers\ScoreAnswer;
use Ninthspace\Hunch\Calibration\Scoring;
use Ninthspace\Hunch\Drivers\ClassificationOptions;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\Models\StoredQuestionSet;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Result;
use Ninthspace\Hunch\Sampling\Sample;
use Ninthspace\Hunch\Usage;

/**
 * Writes a classification, its samples and its question set, whichever
 * driver produced it. Only the state's hash is written, never the state:
 * a stored reason has any passage it quotes from the state scrubbed out.
 */
final class Recorder
{
    /**
     * The shortest quoted passage a stored reason has scrubbed.
     */
    private const QUOTE_MIN = 8;

    /**
     * @param  string  $sentState  The rendered state that was sent, used only to scrub reasons and never stored.
     */
    public static function completed(Model $subject, QuestionSet $set, Result $result, string $sentState): Classification
    {
        return MissingTables::guard(fn () => DB::transaction(function () use ($subject, $set, $result, $sentState) {
            $classification = self::classification($subject, $set, [
                'id' => $result->id,
                'state_hash' => $result->meta->stateHash,
                'driver' => $result->meta->driver,
                'provider' => $result->meta->provider,
                'model_requested' => $result->meta->modelRequested,
                'model_reported' => $result->meta->modelReported,
                'sampling' => $result->meta->sampling,
                'status' => Classification::COMPLETED,
                'samples_requested' => $result->samples->requested,
                'samples_valid' => $result->samples->valid,
                'samples_invalid' => $result->samples->invalid,
                'usage' => self::usage($result->usage),
                'answers' => array_map(self::answer(...), $result->answers->all()),
            ]);

            self::samples($classification, $result->taken, $sentState);

            return $classification;
        }));
    }

    /**
     * A classification that could not produce answers: recorded as failed,
     * with the samples it took and the error, which never quotes the state.
     */
    public static function failed(Model $subject, QuestionSet $set, ClassificationOptions $options, string $driver, string $sentState, ClassificationFailed $e): Classification
    {
        return MissingTables::guard(fn () => DB::transaction(function () use ($subject, $set, $options, $driver, $sentState, $e) {
            $classification = self::classification($subject, $set, [
                'id' => $options->id,
                'state_hash' => hash('sha256', $sentState),
                'driver' => $driver,
                'provider' => $options->provider,
                'model_requested' => $options->model,
                'sampling' => (string) $options->sampling,
                'status' => Classification::FAILED,
                'error' => $e->getMessage(),
                'samples_requested' => count($e->samples),
                'samples_valid' => count($e->validSamples()),
                'samples_invalid' => count($e->invalidSamples()),
                'usage' => self::usage($e->usage),
            ]);

            self::samples($classification, $e->samples, $sentState);

            return $classification;
        }));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function classification(Model $subject, QuestionSet $set, array $attributes): Classification
    {
        $hash = $set->hash();

        StoredQuestionSet::query()->firstOrCreate(
            ['hash' => $hash],
            ['version' => $set->version, 'canonical' => $set->toCanonicalJson(), 'definition' => $set->definition()],
        );

        return Classification::query()->create([
            ...$attributes,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => self::subjectKey($subject),
            'question_set_hash' => $hash,
            'version' => $set->version,
        ]);
    }

    /**
     * The subject's key as stored. A subject must be saved, with a string or integer key.
     */
    public static function subjectKey(Model $subject): string
    {
        $key = $subject->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('A recorded subject must be saved first, with a string or integer key.');
        }

        return (string) $key;
    }

    /**
     * @param  list<Sample>  $samples
     */
    private static function samples(Classification $classification, array $samples, string $sentState): void
    {
        foreach ($samples as $sample) {
            $classification->samples()->create([
                'number' => $sample->number,
                'valid' => $sample->isValid(),
                'answers' => $sample->answers,
                'invalid_reason' => $sample->invalidReason,
                'reason' => self::scrub($sample->reason, $sentState),
                'band' => $sample->bands,
                'seed' => $sample->seed,
                'request_id' => $sample->requestId,
                'model' => $sample->model,
                'usage' => self::usage($sample->usage),
            ]);
        }
    }

    /**
     * The reason with every passage of at least QUOTE_MIN characters that
     * appears in the state, case-insensitively, replaced by `[state]`. A
     * passage starts on a non-space character, so spacing around it survives.
     */
    private static function scrub(?string $reason, string $state): ?string
    {
        if ($reason === null) {
            return null;
        }

        $scrubbed = '';
        $length = mb_strlen($reason);

        for ($i = 0; $i < $length;) {
            $quoted = 0;
            $startsWord = trim(mb_substr($reason, $i, 1)) !== '';

            for ($n = self::QUOTE_MIN; $startsWord && $i + $n <= $length && mb_stripos($state, mb_substr($reason, $i, $n)) !== false; $n++) {
                $quoted = $n;
            }

            if ($quoted > 0) {
                $scrubbed .= '[state]';
                $i += $quoted;
            } else {
                $scrubbed .= mb_substr($reason, $i, 1);
                $i++;
            }
        }

        return $scrubbed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function answer(BooleanAnswer|ChoiceAnswer|ScoreAnswer|null $answer): ?array
    {
        return $answer === null ? null : Scoring::fromAnswer($answer);
    }

    /**
     * @return array{input: int, cached_input_read: int, cached_input_write: int, output: int}
     */
    private static function usage(Usage $usage): array
    {
        return [
            'input' => $usage->input,
            'cached_input_read' => $usage->cachedInputRead,
            'cached_input_write' => $usage->cachedInputWrite,
            'output' => $usage->output,
        ];
    }
}
