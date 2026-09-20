<?php

namespace Ninthspace\Hunch\Testing;

use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Result;

/**
 * One classification the fake handled, for assertions.
 */
final readonly class RecordedClassification
{
    /**
     * @param  string|array<string, mixed>  $state
     * @param  list<string>  $userMessages  The per-sample user messages, in order.
     */
    public function __construct(
        public string|array $state,
        public QuestionSet $questions,
        public string $provider,
        public Result $result,
        public array $userMessages,
    ) {}
}
