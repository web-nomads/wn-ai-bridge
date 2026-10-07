<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebNomads\WnAiBridge\Agent\VisitLogger;

/**
 * Logs AI crawler requests to llms.txt, Markdown versions and pages, and visits referred by AI platforms; never changes the response
 */
final class AgentVisitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly VisitLogger $visitLogger,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        // error pages rendered by TYPO3 itself are part of the visit already logged
        if ($request->getAttribute('originalRequest') !== null || in_array('TYPO3 Error Handler', $request->getHeader('Requested-By'), true)) {
            return $response;
        }
        try {
            $uri = $request->getUri();
            $type = VisitLogger::type($uri->getPath(), $response->getHeaderLine('Content-Type'));
            if ($type === null) {
                return $response;
            }
            $params = $request->getAttribute('normalizedParams');
            $site = $request->getAttribute('site');
            $this->visitLogger->log(
                $site instanceof Site ? $site->getIdentifier() : '',
                $uri->getHost(),
                $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : ''),
                $type,
                $response->getStatusCode(),
                $request->getHeaderLine('User-Agent'),
                $request->getHeaderLine('Referer'),
                $params instanceof NormalizedParams ? $params->getRemoteAddress() : (string)($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                time(),
                $site instanceof Site ? $site->getConfiguration() : [],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('AI Bridge could not log a visit', ['error' => $e->getMessage()]);
        }

        return $response;
    }
}
