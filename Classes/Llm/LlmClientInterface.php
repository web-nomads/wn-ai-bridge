<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Llm;

/**
 * Chat completion contract of the assistant.
 *
 * Provider, model, API key and pricing are not part of it: they are configured
 * centrally in the AiM extension, which this is implemented against.
 */
interface LlmClientInterface
{
    /**
     * Whether a provider is configured that can hold a conversation. Without one
     * the assistant runs in search-only mode.
     */
    public function isAvailable(): bool;

    /**
     * Produce a completion.
     *
     * @param string     $systemPrompt The system instructions.
     * @param list<array{role: string, content: string}> $messages Conversation turns (user/assistant).
     * @param int        $maxTokens    Output token limit.
     * @param float|null $temperature  Sampling temperature (0.0–1.0); null uses the default.
     * @param int|null   $pageId       Page the conversation belongs to, for page-tree prompt fragments.
     * @return LlmResult The answer text plus provider, model, token usage and cost.
     *
     * @throws LlmException on any failure.
     */
    public function complete(string $systemPrompt, array $messages, int $maxTokens, ?float $temperature = null, ?int $pageId = null): LlmResult;
}
