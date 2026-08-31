<?php

declare(strict_types=1);

use WebNomads\WnAiBridge\Middleware\AssistantRequestMiddleware;
use WebNomads\WnAiBridge\Middleware\BotAccessLogMiddleware;
use WebNomads\WnAiBridge\Middleware\RateLimiterMiddleware;

/**
 * Register the AI-Bridge frontend middlewares.
 *
 * The rate limiter runs after site resolution (so normalizedParams / the
 * resolved reverse proxy IP are available) but before the page is actually
 * resolved, so throttled requests are rejected as cheaply as possible.
 *
 * The assistant endpoint runs after the rate limiter (so its requests are
 * throttled too) and after the frontend authentication, because whether a hit
 * may be shown depends on the visitor's frontend groups. It stays before the
 * page resolver, since it answers directly with JSON and must not go through
 * page rendering.
 */
return [
    'frontend' => [
        // Records bot accesses. Runs after site resolution and wraps the rest of
        // the stack so it can read the final response status. It never blocks.
        'web-nomads/wn-ai-bridge/bot-access-log' => [
            'target' => BotAccessLogMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'web-nomads/wn-ai-bridge/rate-limiter',
            ],
        ],
        'web-nomads/wn-ai-bridge/rate-limiter' => [
            'target' => RateLimiterMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        'web-nomads/wn-ai-bridge/assistant' => [
            'target' => AssistantRequestMiddleware::class,
            'after' => [
                'web-nomads/wn-ai-bridge/rate-limiter',
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
