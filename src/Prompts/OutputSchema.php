<?php

namespace Ninthspace\Hunch\Prompts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Question;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;

/**
 * The canonical output schema: one required field per question, with Choice
 * and Score answers as enums of their keys in declared order. It never sees a
 * sample's shuffle, so it is the same for every sample.
 */
final class OutputSchema
{
    /**
     * @return array<string, Type>
     */
    public static function for(QuestionSet $set, JsonSchema $schema): array
    {
        $questions = $set->inCanonicalOrder();

        $fields = array_map(
            fn (Question $question) => self::field($question, $schema)->required(),
            $questions,
        );

        if ($set->options->reasons !== null) {
            $fields[QuestionSet::REASON] = $schema->string()->required();
        }

        if ($set->options->selfReport) {
            $fields[QuestionSet::BANDS] = $schema->object(array_map(
                fn () => $schema->string()->enum(Bands::keys())->required(),
                $questions,
            ))->required();
        }

        return $fields;
    }

    private static function field(Question $question, JsonSchema $schema): Type
    {
        return match (true) {
            $question instanceof Choice => $schema->string()->enum(array_keys($question->options)),
            $question instanceof Score => $schema->string()->enum($question->labels()),
            $question instanceof Boolean => $schema->boolean(),
            default => $schema->string(),
        };
    }
}
