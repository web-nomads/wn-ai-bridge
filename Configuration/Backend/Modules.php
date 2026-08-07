<?php

declare(strict_types=1);

use WebNomads\WnAiBridge\Controller\Backend\AnswersModuleController;
use WebNomads\WnAiBridge\Controller\Backend\BotAccessModuleController;
use WebNomads\WnAiBridge\Controller\Backend\EnquiriesModuleController;

/**
 * Backend module registration.
 *
 * A dedicated top-level "AI Bridge" module group holds three submodules: the
 * visitors' enquiries, the curated answers and the bot access log.
 */
return [
    'wn_ai_bridge' => [
        'labels' => [
            'title' => 'AI Bridge',
        ],
        'iconIdentifier' => 'wn-ai-bridge-module',
        'position' => ['after' => 'web'],
    ],
    'wn_ai_bridge_enquiries' => [
        'parent' => 'wn_ai_bridge',
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'wn-ai-bridge-module',
        'path' => '/module/wn-ai-bridge/enquiries',
        // Formerly "wn_ai_bridge_log". The alias keeps backend group permissions
        // and bookmarks pointing at the old identifier working.
        'aliases' => ['wn_ai_bridge_log'],
        'labels' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang_enquiries.xlf',
        'routes' => [
            '_default' => [
                'target' => EnquiriesModuleController::class . '::handleRequest',
            ],
        ],
    ],
    'wn_ai_bridge_answers' => [
        'parent' => 'wn_ai_bridge',
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'wn-ai-bridge-module',
        'path' => '/module/wn-ai-bridge/answers',
        // Formerly "Corrections". The alias keeps backend group permissions and
        // bookmarks pointing at the old identifier working.
        'aliases' => ['wn_ai_bridge_corrections'],
        'labels' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang_answers.xlf',
        'routes' => [
            '_default' => [
                'target' => AnswersModuleController::class . '::handleRequest',
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
