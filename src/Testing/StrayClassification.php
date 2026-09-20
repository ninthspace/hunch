<?php

namespace Ninthspace\Hunch\Testing;

use RuntimeException;

/**
 * A classification ran under `preventStrayClassifications()` with no fake to answer it.
 */
final class StrayClassification extends RuntimeException {}
