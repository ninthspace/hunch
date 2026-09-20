<?php

namespace Ninthspace\Hunch\Exceptions;

use InvalidArgumentException;

/**
 * Hunch has been configured in a way it cannot run with.
 */
final class ConfigurationException extends InvalidArgumentException {}
