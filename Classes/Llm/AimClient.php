<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Llm;

use B13\Aim\Ai;
use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Request\Message\AbstractMessage;
use B13\Aim\Request\Message\AssistantMessage;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Response\TextResponse;

/**
 * Sends the assistant's conversation through AiM.
 *
 * Which provider and model answer, the API key, budgets, the request log and
 * the cost all live in AiM. This class only translates between the assistant's
 * message format and AiM's.
 */
final class AimClient implements LlmClientInterface
{
    public const EXTENSION_KEY = 'wn_ai_bridge';

    public function __construct(
        private readonly Ai $ai,
        private readonly ProviderResolver $providerResolver,
        private readonly AimResponseTracker $responseTracker,
    ) {}

    public function isAvailable(): bool
    {
        try {
            $this->providerResolver->resolveForCapability(ConversationCapableInterface::class);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function complete(string $systemPrompt, array $messages, int $maxTokens, ?float $temperature = null, ?int $pageId = null): LlmResult
    {
        $aimMessages = self::toAimMessages($messages);

        try {
            $response = $this->ai->conversation(
                messages: $aimMessages,
                systemPrompt: $systemPrompt,
                pageId: $pageId !== null && $pageId > 0 ? $pageId : null,
                maxTokens: $maxTokens,
                temperature: $temperature !== null ? max(0.0, min(1.0, $temperature)) : 0.2,
                extensionKey: self::EXTENSION_KEY,
            );
        } catch (\Throwable $e) {
            throw new LlmException('AiM request failed: ' . $e->getMessage(), 1765400002, $e);
        }

        return $this->toResult($response);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @return list<AbstractMessage>
     */
    public static function toAimMessages(array $messages): array
    {
        $aimMessages = [];
        foreach ($messages as $message) {
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $aimMessages[] = ($message['role'] ?? 'user') === 'assistant'
                ? new AssistantMessage($content)
                : new UserMessage($content);
        }

        // providers reject a conversation opened by the assistant
        if ($aimMessages === [] || !$aimMessages[0] instanceof UserMessage) {
            throw new LlmException('Conversation must start with a user message.', 1765400004);
        }

        return $aimMessages;
    }

    private function toResult(TextResponse $response): LlmResult
    {
        if ($response->errors !== []) {
            throw new LlmException('AiM returned an error: ' . implode('; ', $response->errors), 1765400003);
        }

        $text = trim($response->content);
        if ($text === '') {
            throw new LlmException('AiM returned an empty answer.', 1765400006);
        }

        $configuration = $this->responseTracker->configurationFor($response);

        return new LlmResult(
            $text,
            $response->usage->promptTokens,
            $response->usage->completionTokens,
            $configuration?->providerIdentifier ?? '',
            $response->usage->modelUsed !== '' ? $response->usage->modelUsed : ($configuration?->model ?? ''),
            $response->usage->cost,
            $configuration?->costCurrency ?? '',
        );
    }
}
