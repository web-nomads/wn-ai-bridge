<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\LinkRelationService;

/**
 * Adds the llms.txt v2 link relations as an HTTP ``Link:`` response header.
 *
 * The page head carries the same pair, but only for HTML. A ``.md`` document,
 * llms-full.txt and every HEAD request have no head to read, and the header is
 * what v2 offers for exactly that case.
 *
 * Never blocks and never replaces a Link header someone else set — its own
 * relations are appended to it.
 */
final class LinkRelationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly LinkRelationService $linkRelationService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        try {
            return $this->withLinkRelations($request, $response);
        } catch (\Throwable $e) {
            // A discoverability hint must never cost the response.
            return $response;
        }
    }

    private function withLinkRelations(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // Redirects, 404s and error pages describe nothing.
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $this->configurationService->setRequest($request);
        if (!$this->configurationService->isEnabled()) {
            return $response;
        }

        $value = $this->linkRelationService->headerValue($request);
        if ($value === '') {
            return $response;
        }

        $existing = $response->getHeaderLine('Link');

        return $response->withHeader('Link', $existing !== '' ? $existing . ', ' . $value : $value);
    }
}
