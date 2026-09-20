<?php

namespace Ninthspace\Hunch;

/**
 * Where a classification's answers came from, so it can be traced and replayed.
 */
final readonly class ClassificationMeta
{
    /**
     * @param  string  $stateHash  sha256 of the redacted, rendered state that was sent.
     * @param  array<int, string>  $seeds  Sample number => seed (hex).
     * @param  array<int, string>  $requestIds  Sample number => the SDK's request ID.
     */
    public function __construct(
        public string $driver,
        public string $provider,
        public ?string $modelRequested,
        public ?string $modelReported,
        public string $sampling,
        public string $questionSetHash,
        public string $stateHash,
        public ?string $version,
        public array $seeds,
        public array $requestIds,
    ) {}
}
