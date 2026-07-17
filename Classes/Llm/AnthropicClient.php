<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Llm;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * Anthropic Claude client talking to the Messages API over HTTP.
 *
 * We deliberately use TYPO3's bundled Guzzle client (RequestFactory) and the
 * documented REST contract instead of pulling in the anthropic-ai/sdk Composer
 * package: this keeps the extension free of heavy external dependencies that
 * could clash with TYPO3's pinned versions on customer installs. The request
 * shape follows the official Messages API (POST /v1/messages,
 * anthropic-version: 2023-06-01, x-api-key auth).
 */
final class AnthropicClient implements LlmClientInterface
{
    private const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const TIMEOUT_SECONDS = 25;

    private readonly RequestFactory $requestFactory;
    private readonly ConfigurationService $configurationService;

    public function __construct(
        ?RequestFactory $requestFactory = null,
        ?ConfigurationService $configurationService = null,
    ) {
        $this->requestFactory = $requestFactory ?? GeneralUtility::makeInstance(RequestFactory::class);
        $this->configurationService = $configurationService ?? GeneralUtility::makeInstance(ConfigurationService::class);
    }

    public function getProviderKey(): string
    {
        return 'anthropic';
    }

    public function complete(string $systemPrompt, array $messages, string $model, int $maxTokens): string
    {
        $apiKey = $this->configurationService->getAssistantApiKey();
        if ($apiKey === '') {
            throw new LlmException('No Anthropic API key configured.', 1765400001);
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            // System prompt is stable across requests, so mark it for prompt
            // caching to cut cost/latency on repeated questions.
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => $this->normaliseMessages($messages),
        ];

        try {
            $response = $this->requestFactory->request(
                self::API_ENDPOINT,
                'POST',
                [
                    'headers' => [
                        'x-api-key' => $apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'content-type' => 'application/json',
                    ],
                    'body' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'timeout' => self::TIMEOUT_SECONDS,
                ]
            );
        } catch (\Throwable $e) {
            throw new LlmException('Anthropic request failed: ' . $e->getMessage(), 1765400002, $e);
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new LlmException('Anthropic API returned HTTP ' . $status . ': ' . $body, 1765400003);
        }

        return $this->extractText($body);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    private function normaliseMessages(array $messages): array
    {
        $normalised = [];
        foreach ($messages as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $normalised[] = ['role' => $role, 'content' => $content];
        }

        // The Messages API requires the first message to be from the user.
        if ($normalised === [] || $normalised[0]['role'] !== 'user') {
            throw new LlmException('Conversation must start with a user message.', 1765400004);
        }

        return $normalised;
    }

    private function extractText(string $body): string
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException('Anthropic returned invalid JSON.', 1765400005, $e);
        }

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $text = trim($text);
        if ($text === '') {
            throw new LlmException('Anthropic returned an empty answer.', 1765400006);
        }

        return $text;
    }
}
