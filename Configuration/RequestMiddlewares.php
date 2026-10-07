<?php

declare(strict_types=1);

use WebNomads\WnAiBridge\Middleware\AgentVisitMiddleware;
use WebNomads\WnAiBridge\Middleware\AssistantRequestMiddleware;
use WebNomads\WnAiBridge\Middleware\LinkRelationMiddleware;
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
 *
 * The link relations run after site resolution too, since the llms.txt they
 * point at is the one of the resolved language, and wrap the rest of the stack
 * so they can be added to whatever response comes back.
 */
return [
    'frontend' => [
        // Adds the llms.txt v2 "Link:" response header. Never blocks.
        'web-nomads/wn-ai-bridge/link-relations' => [
            'target' => LinkRelationMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'web-nomads/wn-ai-bridge/agent-visits',
            ],
        ],
        // Records AI crawler and AI referral visits. Runs after site resolution and
        // wraps the rest of the stack so it can read the final response. It never blocks.
        'web-nomads/wn-ai-bridge/agent-visits' => [
            'target' => AgentVisitMiddleware::class,
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
