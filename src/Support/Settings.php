<?php

namespace Ninthspace\Hunch\Support;

use Illuminate\Container\Container;

/**
 * Reads `hunch.*` configuration, falling back to the given default when no
 * Laravel configuration is bound (a question built outside a booted app).
 */
final class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if (! Container::getInstance()->bound('config')) {
            return $default;
        }

        return config("hunch.{$key}", $default);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key, $default);

        if (is_string($value)) {
            $value = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        }

        return is_int($value) ? $value : $default;
    }
}
