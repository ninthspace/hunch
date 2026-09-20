<?php

namespace Ninthspace\Hunch;

use Laravel\Ai\Responses\Data\Usage as SdkUsage;

/**
 * Tokens spent, summed across samples.
 */
final readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $cachedInputRead = 0,
        public int $cachedInputWrite = 0,
        public int $output = 0,
    ) {}

    public static function fromSdk(SdkUsage $usage): self
    {
        return new self(
            input: $usage->promptTokens,
            cachedInputRead: $usage->cacheReadInputTokens,
            cachedInputWrite: $usage->cacheWriteInputTokens,
            output: $usage->completionTokens,
        );
    }

    public function add(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->cachedInputRead + $other->cachedInputRead,
            $this->cachedInputWrite + $other->cachedInputWrite,
            $this->output + $other->output,
        );
    }

    /**
     * @param  iterable<self>  $usages
     */
    public static function sum(iterable $usages): self
    {
        $total = new self;

        foreach ($usages as $usage) {
            $total = $total->add($usage);
        }

        return $total;
    }
}
