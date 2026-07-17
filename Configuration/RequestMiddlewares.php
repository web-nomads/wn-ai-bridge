<?php

declare(strict_types=1);

use WebNomads\WnAiBridge\Middleware\RateLimiterMiddleware;

/**
 * Register the AI-Bridge rate limiter in the frontend middleware stack.
 *
 * It runs after site resolution (so normalizedParams / the resolved reverse
 * proxy IP are available) but before the page is actually resolved and
 * rendered, so throttled requests are rejected as cheaply as possible.
 */
return [
    'frontend' => [
        'web-nomads/wn-ai-bridge/rate-limiter' => [
            'target' => RateLimiterMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
