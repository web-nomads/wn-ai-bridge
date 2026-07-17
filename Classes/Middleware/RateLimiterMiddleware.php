<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\RateLimiter\RateLimiterService;
use WebNomads\WnAiBridge\RateLimiter\RateLimitStatus;

/**
 * Throttles AI-Bridge requests (llms.txt and Markdown endpoints) to prevent
 * bots and crawlers from overloading the server.
 *
 * The middleware only acts on AI-Bridge URLs; every other frontend request
 * passes through untouched. Throttling can be switched off entirely via the
 * "rateLimiterEnabled" extension configuration.
 */
final class RateLimiterMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiterService $rateLimiterService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Bail out early for everything that is not an AI-Bridge request or when
        // the limiter is globally disabled - no overhead for regular traffic.
        if (!$this->isAiBridgeRequest($request) || !$this->rateLimiterService->isEnabled()) {
            return $handler->handle($request);
        }

        $clientIp = $this->resolveClientIp($request);
        $status = $this->rateLimiterService->consumeForIp($clientIp);

        // The per-key / per-bot limit is an independent, second gate.
        if ($status->isAllowed()) {
            $key = $this->resolveApiKey($request);
            if ($key !== null) {
                $keyStatus = $this->rateLimiterService->consumeForKey($key);
                // Report the stricter (already tripped) status to the client.
                if (!$keyStatus->isAllowed()) {
                    $status = $keyStatus;
                }
            }
        }

        if (!$status->isAllowed()) {
            return $this->tooManyRequestsResponse($status);
        }

        return $handler->handle($request);
    }

    /**
     * Detects requests routed to the llms.txt or Markdown endpoints based on
     * the URL suffixes configured in the PageType route enhancer.
     */
    private function isAiBridgeRequest(ServerRequestInterface $request): bool
    {
        $path = rtrim($request->getUri()->getPath(), '/');

        return str_ends_with($path, '/llms.txt')
            || $path === '/llms.txt'
            || str_ends_with($path, '.md')
            || str_ends_with($path, AssistantRequestMiddleware::ENDPOINT_PATH);
    }

    /**
     * Resolves the real client IP, honouring TYPO3's reverse proxy configuration.
     */
    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            $remoteAddress = $normalizedParams->getRemoteAddress();
            if ($remoteAddress !== '') {
                return $remoteAddress;
            }
        }

        $remoteAddress = (string)GeneralUtility::getIndpEnv('REMOTE_ADDR');

        return $remoteAddress !== '' ? $remoteAddress : 'unknown';
    }

    /**
     * Determines an API key or bot identifier for the second rate limit scope.
     *
     * Precedence: Bearer token / Authorization header, then X-Api-Key header,
     * then an "api_key" query parameter, and finally the User-Agent as a coarse
     * bot identifier. Returns null when the request cannot be identified at all.
     */
    private function resolveApiKey(ServerRequestInterface $request): ?string
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if ($authorization !== '') {
            if (stripos($authorization, 'Bearer ') === 0) {
                return 'token:' . substr($authorization, 7);
            }
            return 'auth:' . $authorization;
        }

        $apiKeyHeader = trim($request->getHeaderLine('X-Api-Key'));
        if ($apiKeyHeader !== '') {
            return 'apikey:' . $apiKeyHeader;
        }

        $queryApiKey = $request->getQueryParams()['api_key'] ?? '';
        if (is_string($queryApiKey) && trim($queryApiKey) !== '') {
            return 'apikey:' . trim($queryApiKey);
        }

        $userAgent = trim($request->getHeaderLine('User-Agent'));
        if ($userAgent !== '') {
            return 'ua:' . $userAgent;
        }

        return null;
    }

    /**
     * Builds a RFC 6585 compliant "429 Too Many Requests" response with a
     * Retry-After header indicating when the current window resets.
     */
    private function tooManyRequestsResponse(RateLimitStatus $status): ResponseInterface
    {
        $response = new Response(
            'php://temp',
            429,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Retry-After' => (string)$status->retryAfter,
                'X-RateLimit-Limit' => (string)$status->limit,
                'X-RateLimit-Remaining' => (string)$status->remaining,
            ]
        );

        $response->getBody()->write(
            "429 Too Many Requests\n\n"
            . "Rate limit exceeded. Please retry after " . $status->retryAfter . " seconds.\n"
        );

        return $response;
    }
}
