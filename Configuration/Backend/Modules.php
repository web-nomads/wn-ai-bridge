<?php

declare(strict_types=1);

use WebNomads\WnAiBridge\Controller\Backend\BotAccessModuleController;
use WebNomads\WnAiBridge\Controller\Backend\CorrectionsModuleController;
use WebNomads\WnAiBridge\Controller\Backend\LogModuleController;

/**
 * Backend module registration.
 *
 * A dedicated top-level "AI Bridge" module group holds three submodules:
 * the assistant log, the visitor-correction moderation and the bot access log.
 */
return [
    'wn_ai_bridge' => [
        'labels' => [
            'title' => 'AI Bridge',
        ],
        'iconIdentifier' => 'wn-ai-bridge-module',
        'position' => ['after' => 'web'],
    ],
    'wn_ai_bridge_log' => [
        'parent' => 'wn_ai_bridge',
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'wn-ai-bridge-module',
        'path' => '/module/wn-ai-bridge/log',
        'labels' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang_module.xlf',
        'routes' => [
            '_default' => [
                'target' => LogModuleController::class . '::handleRequest',
            ],
        ],
    ],
    'wn_ai_bridge_corrections' => [
        'parent' => 'wn_ai_bridge',
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'wn-ai-bridge-module',
        'path' => '/module/wn-ai-bridge/corrections',
        'labels' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang_corrections.xlf',
        'routes' => [
            '_default' => [
                'target' => CorrectionsModuleController::class . '::handleRequest',
            ],
        ],
    ],
    'wn_ai_bridge_botaccess' => [
        'parent' => 'wn_ai_bridge',
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'wn-ai-bridge-module',
        'path' => '/module/wn-ai-bridge/bot-access',
        'labels' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang_botaccess.xlf',
        'routes' => [
            '_default' => [
                'target' => BotAccessModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
