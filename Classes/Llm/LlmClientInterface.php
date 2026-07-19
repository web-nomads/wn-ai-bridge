<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Llm;

/**
 * Provider-agnostic chat completion contract.
 *
 * Keeping the LLM behind this interface means the Anthropic implementation can
 * be swapped for another provider (or the official SDK) without touching the
 * assistant service.
 */
interface LlmClientInterface
{
    /**
     * The provider key this client handles (e.g. "anthropic").
     */
    public function getProviderKey(): string;

    /**
     * Produce a completion.
     *
     * @param string     $systemPrompt      The system instructions.
     * @param list<array{role: string, content: string}> $messages Conversation turns (user/assistant).
     * @param string     $model             Model id.
     * @param int        $maxTokens         Output token limit.
     * @param float|null $temperature       Sampling temperature (0.0–1.0); null uses the provider default.
     * @return LlmResult The answer text plus token usage.
     *
     * @throws LlmException on any failure.
     */
    public function complete(string $systemPrompt, array $messages, string $model, int $maxTokens, ?float $temperature = null): LlmResult;
}
