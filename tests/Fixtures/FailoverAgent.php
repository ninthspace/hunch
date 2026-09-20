<?php

namespace Ninthspace\Hunch\Tests\Fixtures;

use Ninthspace\Hunch\Agents\ClassifierAgent;

/**
 * An agent that declares a fallback provider through the SDK's own mechanism.
 */
class FailoverAgent extends ClassifierAgent
{
    /**
     * @return list<string>
     */
    public function provider(): array
    {
        return ['openai'];
    }
}
