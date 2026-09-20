<?php

namespace Ninthspace\Hunch;

/**
 * The prompt options that change the instructions, and so the question set hash.
 */
final readonly class PromptOptions
{
    /**
     * @param  int|null  $reasons  Maximum characters per reason, or null when reasons are off.
     */
    public function __construct(
        public ?int $reasons = null,
        public bool $selfReport = false,
    ) {}
}
