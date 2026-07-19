<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WebNomads\WnAiBridge\Domain\Repository\BotAccessRepository;
use WebNomads\WnAiBridge\Security\BotDetectionService;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * Records bot/crawler accesses to llms.txt, the Markdown (.md) versions and
 * normal pages for review in the backend module. Opt-in via the extension
 * configuration and a no-op for human visitors, so regular traffic is untouched.
 */
final class BotAccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly BotDetectionService $botDetectionService,
        private readonly BotAccessRepository $repository,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        try {
            if ($this->configurationService->isBotAccessLoggingEnabled()) {
                $this->maybeLog($request, $response);
            }
        } catch (\Throwable $e) {
            // Logging must never affect the delivered response.
        }

        return $response;
    }

    private function maybeLog(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $userAgent = trim($request->getHeaderLine('User-Agent'));
        if ($userAgent === '' || !$this->botDetectionService->isBotUserAgent($userAgent)) {
            return; // Only bots are logged.
        }

        $type = $this->classify($request, $response);
        if ($type === null) {
            return; // Not a page/llms.txt/markdown response we care about.
        }

        $this->repository->add([
            'pid' => 0,
            'crdate' => time(),
            'site_identifier' => $this->resolveSiteIdentifier($request),
            'language_uid' => $this->resolveLanguageId($request),
            'request_type' => $type,
            'method' => mb_substr($request->getMethod(), 0, 10),
            'path' => $request->getUri()->getPath(),
            'query_string' => mb_substr($request->getUri()->getQuery(), 0, 2000),
            'http_status' => $response->getStatusCode(),
            'bot_name' => mb_substr((string)$this->botDetectionService->botName($userAgent), 0, 64),
            'is_ai_bot' => $this->botDetectionService->isAiBot($userAgent) ? 1 : 0,
            'user_agent' => mb_substr($userAgent, 0, 500),
            'ip_address' => $this->resolveClientIp($request),
            'referer' => mb_substr(trim($request->getHeaderLine('Referer')), 0, 500),
        ]);
    }

    /**
     * Classify the request as llms.txt, a Markdown version or a normal page.
     * Normal pages are only logged when the response is an HTML document, so
     * assets, feeds and JSON endpoints are ignored.
     */
    private function classify(ServerRequestInterface $request, ResponseInterface $response): ?string
    {
        $path = rtrim($request->getUri()->getPath(), '/');

        if (str_ends_with($path, '/llms.txt') || $path === '/llms.txt') {
            return 'llmstxt';
        }
        if (str_ends_with($path, '.md')) {
            return 'markdown';
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'text/html')) {
            return 'page';
        }

        return null;
    }

    private function resolveSiteIdentifier(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        return $site instanceof Site ? $site->getIdentifier() : '';
    }

    private function resolveLanguageId(ServerRequestInterface $request): int
    {
        $language = $request->getAttribute('language');
        return $language instanceof SiteLanguage ? $language->getLanguageId() : 0;
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams->getRemoteAddress();
        }
        return '';
    }
}
