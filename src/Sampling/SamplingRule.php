<?php

namespace Ninthspace\Hunch\Sampling;

use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Support\Settings;

/**
 * How many samples a classification takes: a fixed count, or an adaptive
 * range that stops early once the answers are settled.
 */
final readonly class SamplingRule
{
    private function __construct(
        public bool $adaptive,
        public int $min,
        public int $max,
    ) {}

    public static function fixed(int $samples): self
    {
        if ($samples < 1) {
            throw new ConfigurationException("A fixed sampling rule takes at least 1 sample, not {$samples}.");
        }

        return new self(false, $samples, $samples);
    }

    public static function adaptive(int $min, int $max): self
    {
        if ($min < 1 || $max < $min) {
            throw new ConfigurationException("An adaptive sampling rule needs 1 <= min <= max, not [{$min}, {$max}].");
        }

        return new self(true, $min, $max);
    }

    /**
     * The rule `hunch.sampling` configures, parsed when first asked for so a
     * malformed value fails at the classification that uses it.
     */
    public static function configured(): self
    {
        $rule = Settings::get('sampling', 'fixed:5');

        return self::parse(is_string($rule) ? $rule : '');
    }

    /**
     * Parse `fixed:N` or `adaptive:MIN-MAX`.
     */
    public static function parse(string $rule): self
    {
        if (preg_match('/^fixed:(\d+)$/', $rule, $m) === 1) {
            return self::fixed((int) $m[1]);
        }

        if (preg_match('/^adaptive:(\d+)-(\d+)$/', $rule, $m) === 1) {
            return self::adaptive((int) $m[1], (int) $m[2]);
        }

        throw new ConfigurationException("`{$rule}` is not a sampling rule. Use `fixed:N` or `adaptive:MIN-MAX`.");
    }

    public function __toString(): string
    {
        return $this->adaptive ? "adaptive:{$this->min}-{$this->max}" : "fixed:{$this->min}";
    }
}
