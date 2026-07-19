<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Llm;

/**
 * Result of an LLM completion: the answer text plus the token usage reported by
 * the provider (used for logging/cost tracking).
 */
final class LlmResult
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public function getTotalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
