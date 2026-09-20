<?php

namespace Ninthspace\Hunch\Prompts;

use InvalidArgumentException;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Sampling\SampleSeed;
use Random\Randomizer;
use Stringable;

/**
 * The per-sample user message: this sample's shuffled order, then the state.
 * It carries keys and labels only, never question text, descriptions or context.
 */
final class UserMessage
{
    /**
     * @param  string|array<int|string, mixed>  $state
     */
    public static function render(QuestionSet $set, string|array $state, SampleSeed $seed): string
    {
        return implode("\n", self::order($set, $seed))."\n\n<state>\n".self::renderedState($state)."\n</state>\n";
    }

    /**
     * sha256 of the state as rendered into every user message, after any
     * redaction: the one form of the state that is ever kept.
     *
     * @param  string|array<int|string, mixed>  $state
     */
    public static function stateHash(string|array $state): string
    {
        return hash('sha256', self::renderedState($state));
    }

    /**
     * The state as it appears between the `<state>` tags of every user message.
     *
     * @param  string|array<int|string, mixed>  $state
     */
    public static function renderedState(string|array $state): string
    {
        if (is_string($state)) {
            return self::neutralise($state);
        }

        $sections = [];

        foreach ($state as $name => $value) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('Every state section needs a non-empty string name.');
            }

            if (! is_scalar($value) && ! $value instanceof Stringable) {
                throw new InvalidArgumentException("State section `{$name}` must be text.");
            }

            $sections[] = '<section name="'.htmlspecialchars($name, ENT_QUOTES).'">'."\n"
                .self::neutralise((string) $value)."\n"
                .'</section>';
        }

        return implode("\n", $sections);
    }

    /**
     * The shuffled order lines. The randomizer is consumed in a fixed sequence
     * (question keys first, then each question's options or levels in sorted
     * key order), so a seed always gives the same message.
     *
     * @return list<string>
     */
    private static function order(QuestionSet $set, SampleSeed $seed): array
    {
        $randomizer = $seed->randomizer();
        $questions = $set->inCanonicalOrder();

        $lines = ['Answer the questions in this order: '.self::shuffled($randomizer, array_keys($questions)).'.'];

        foreach ($questions as $key => $question) {
            if ($question instanceof Choice) {
                $lines[] = "Consider the options for {$key} in this order: ".self::shuffled($randomizer, array_keys($question->options)).'.';
            }

            if ($question instanceof Score) {
                $lines[] = "Consider the levels for {$key} in this order: ".self::shuffled($randomizer, $question->labels()).'.';
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $items
     */
    private static function shuffled(Randomizer $randomizer, array $items): string
    {
        /** @var list<string> $shuffled */
        $shuffled = $randomizer->shuffleArray($items);

        return implode(', ', $shuffled);
    }

    /**
     * Disarm any state or section tag inside the text, so the state cannot
     * close its own delimiters and speak outside them.
     */
    private static function neutralise(string $text): string
    {
        return (string) preg_replace('~<(/?(?:state|section))\b~i', '&lt;$1', $text);
    }
}
