<?php

namespace Ninthspace\Hunch\Calibration;

/**
 * Everything that identifies an answer's calibration cell except the answer
 * and its bucket: question set hash, driver, provider, model, sampling rule
 * and question. Cells never merge across any of these.
 */
final readonly class CalibrationScope
{
    public function __construct(
        public string $questionSetHash,
        public string $driver,
        public string $provider,
        public string $model,
        public string $sampling,
        public string $question = '',
    ) {}

    /**
     * The model a cell is kept under: the one the provider reported, else
     * the one requested.
     */
    public static function model(?string $reported, ?string $requested): string
    {
        return $reported ?? $requested ?? '';
    }

    public function forQuestion(string $question): self
    {
        return new self($this->questionSetHash, $this->driver, $this->provider, $this->model, $this->sampling, $question);
    }

    /**
     * The scope as calibration-row columns.
     *
     * @return array{question_set_hash: string, driver: string, provider: string, model: string, sampling: string, question: string}
     */
    public function columns(): array
    {
        return [
            'question_set_hash' => $this->questionSetHash,
            'driver' => $this->driver,
            'provider' => $this->provider,
            'model' => $this->model,
            'sampling' => $this->sampling,
            'question' => $this->question,
        ];
    }
}
