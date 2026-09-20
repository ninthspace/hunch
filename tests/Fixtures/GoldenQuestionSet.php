<?php

namespace Ninthspace\Hunch\Tests\Fixtures;

use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;

final class GoldenQuestionSet
{
    public static function make(): QuestionSet
    {
        return new QuestionSet([
            'intent' => new Choice('What does the customer want?', [
                'refund' => 'Money back for an order',
                'exchange' => 'A different size or colour',
                'other' => 'Anything else',
            ]),
            'urgency' => new Score('How urgent is it?', ['Low', 'Medium', 'High']),
            'complaint' => new Boolean('Is this a complaint?', ['true' => 'The customer is unhappy']),
        ], context: 'A support inbox for a clothing shop. Café prices are in €.');
    }
}
