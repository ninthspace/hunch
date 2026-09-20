<?php

namespace Ninthspace\Hunch\Drivers;

use Ninthspace\Hunch\Exceptions\ConfigurationException;

/**
 * Picks the driver from the provider: `typesafe` is the TypeSafe driver, and
 * every other provider is sampled.
 */
final class DriverResolver
{
    public static function for(string $provider): ClassificationDriver
    {
        if ($provider === 'typesafe') {
            throw new ConfigurationException('The TypeSafe driver is not available yet.');
        }

        return new SamplingDriver;
    }
}
