<?php

namespace Ninthspace\Hunch\Events;

/**
 * Human-confirmed answers were recorded for a classification.
 */
final readonly class Labelled extends ClassificationEvent
{
    /**
     * @param  list<string>  $questions  The question keys labelled.
     */
    public function __construct(
        string $classificationId,
        string $questionSetHash,
        string $stateHash,
        public array $questions,
    ) {
        parent::__construct($classificationId, $questionSetHash, $stateHash);
    }
}
