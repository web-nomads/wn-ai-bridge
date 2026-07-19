<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WebNomads\WnAiBridge\Security\BotDetectionService;
use WebNomads\WnAiBridge\Service\AssistantLogWriter;
use WebNomads\WnAiBridge\Service\AssistantService;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\LearningService;

/**
 * Frontend JSON endpoint for the site assistant.
 *
 * Intercepts POST requests to ".../wn-ai-bridge/ask" and returns the assistant's
 * answer plus its source hits as JSON. Every other request passes through
 * untouched. Because it is a PSR-15 middleware it needs no page, route enhancer
 * or Extbase plugin and works on every site out of the box.
 */
final class AssistantRequestMiddleware implements MiddlewareInterface
{
    /**
     * Endpoint path segment matched at the end of the request path (so it also
     * works for sites served from a sub-directory).
     */
    public const ENDPOINT_PATH = '/wn-ai-bridge/ask';

    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly AssistantService $assistantService,
        private readonly BotDetectionService $botDetectionService,
        private readonly AssistantLogWriter $logWriter,
        private readonly LearningService $learningService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isAssistantRequest($request)) {
            return $handler->handle($request);
        }

        // Give the configuration service the current request so site-level
        // settings (title, welcome text, search root) resolve correctly.
        $this->configurationService->setRequest($request);

        if ($request->getMethod() !== 'POST') {
            return $this->error('Method not allowed.', 405);
        }

        if (!$this->configurationService->isAssistantEnabledForCurrentSite()) {
            return $this->error('The assistant is not available.', 404);
        }

        // Reject automated (bot/crawler/script) access when bot protection is on.
        if ($this->configurationService->isAssistantBotProtectionEnabled()
            && $this->botDetectionService->isBot($request)
        ) {
            return $this->error('Bots are not allowed. This assistant is for human visitors only.', 403);
        }

        $payload = $this->parseBody($request);
        $question = trim((string)($payload['question'] ?? ''));

        if ($question === '') {
            return $this->error('Please provide a question.', 400);
        }
        if (mb_strlen($question) > 1000) {
            $question = mb_substr($question, 0, 1000);
        }

        $history = $this->sanitiseHistory($payload['history'] ?? []);
        $languageId = $this->resolveLanguageId($request);
        $conversationId = $this->sanitiseConversationId($payload['conversationId'] ?? '');

        try {
            $response = $this->assistantService->ask($question, $history, $languageId);
        } catch (\Throwable $e) {
            return $this->error('The assistant is temporarily unavailable.', 503);
        }

        // Persist the interaction when logging is enabled (best-effort).
        $this->logWriter->log($request, $question, $response, $languageId, $conversationId);

        // Capture a visitor correction for owner-moderated learning (best-effort).
        $this->learningService->captureCorrection(
            $question,
            $history,
            $languageId,
            $this->resolveSiteIdentifier($request),
            $conversationId,
            $this->resolveClientIp($request),
        );

        return new JsonResponse([
            'answer' => $response->answer,
            'sources' => $response->sources,
            'mode' => $response->mode,
        ]);
    }

    private function isAssistantRequest(ServerRequestInterface $request): bool
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        return str_ends_with($path, self::ENDPOINT_PATH);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $raw = (string)$request->getBody();
        if ($raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * @param mixed $history
     * @return list<array{role: string, content: string}>
     */
    private function sanitiseHistory(mixed $history): array
    {
        if (!is_array($history)) {
            return [];
        }

        $clean = [];
        foreach ($history as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string)($turn['content'] ?? ''));
            if ($content !== '') {
                $clean[] = ['role' => $role, 'content' => $content];
            }
        }

        return $clean;
    }

    private function resolveLanguageId(ServerRequestInterface $request): int
    {
        $language = $request->getAttribute('language');
        return $language instanceof SiteLanguage ? $language->getLanguageId() : 0;
    }

    private function resolveSiteIdentifier(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        return $site instanceof Site ? $site->getIdentifier() : '';
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams->getRemoteAddress();
        }
        return '';
    }

    /**
     * Keep only a safe, bounded conversation identifier (client-generated).
     */
    private function sanitiseConversationId(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';
        return substr($value, 0, 40);
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
