<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Llm;

/**
 * Result of an LLM completion: the answer text plus what AiM reports about it
 * (provider, model, token usage and cost), used for the enquiries log.
 */
final class LlmResult
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly float $cost = 0.0,
        public readonly string $costCurrency = '',
    ) {}

    public function getTotalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
