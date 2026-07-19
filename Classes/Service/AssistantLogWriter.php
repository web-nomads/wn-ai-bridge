<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebNomads\WnAiBridge\Domain\Repository\AssistantLogRepository;
use WebNomads\WnAiBridge\Dto\AssistantResponse;

/**
 * Persists assistant questions/answers to the log when logging is enabled.
 *
 * Logging must never affect the visitor response, so every failure here is
 * swallowed. It is called from the request middleware, which has access to the
 * client IP, user agent and resolved site.
 */
final class AssistantLogWriter
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly AssistantLogRepository $repository,
    ) {}

    public function log(
        ServerRequestInterface $request,
        string $question,
        AssistantResponse $response,
        int $languageId,
        string $conversationId = '',
    ): void {
        if (!$this->configurationService->isAssistantLoggingEnabled()) {
            return;
        }

        // Ensure every entry has a conversation id so the backend can group turns
        // into threads. When the client did not send one, fall back to a unique
        // per-request id (the entry becomes a single-turn thread).
        if ($conversationId === '') {
            $conversationId = 'srv-' . bin2hex(random_bytes(8));
        }

        try {
            $this->repository->add([
                'pid' => 0,
                'crdate' => time(),
                'conversation_id' => $conversationId,
                'question' => $question,
                'answer' => $response->answer,
                'mode' => $response->mode,
                'provider' => (string)($response->provider ?? ''),
                'model' => (string)($response->model ?? ''),
                'input_tokens' => (int)($response->inputTokens ?? 0),
                'output_tokens' => (int)($response->outputTokens ?? 0),
                'total_tokens' => (int)(($response->inputTokens ?? 0) + ($response->outputTokens ?? 0)),
                'source_count' => count($response->sources),
                'sources' => $this->encodeSources($response->sources),
                'ip_address' => $this->resolveClientIp($request),
                'user_agent' => mb_substr(trim($request->getHeaderLine('User-Agent')), 0, 255),
                'language_uid' => $languageId,
                'site_identifier' => $this->resolveSiteIdentifier($request),
                'page_id' => 0,
            ]);
        } catch (\Throwable $e) {
            // Logging is best-effort — never break the assistant response.
        }
    }

    /**
     * Store the suggested pages (title + URL) as compact JSON so the backend log
     * module can show the actual links instead of just the "[n]" markers.
     *
     * @param list<\WebNomads\WnAiBridge\Dto\SearchResultItem> $sources
     */
    private function encodeSources(array $sources): string
    {
        $compact = array_map(
            static fn($source): array => ['title' => $source->title, 'url' => $source->url],
            $sources,
        );

        return json_encode($compact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            $remoteAddress = $normalizedParams->getRemoteAddress();
            if ($remoteAddress !== '') {
                return $remoteAddress;
            }
        }
        return '';
    }

    private function resolveSiteIdentifier(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        return $site instanceof Site ? $site->getIdentifier() : '';
    }
}
