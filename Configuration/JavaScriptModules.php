<?php

declare(strict_types=1);

/**
 * Registers the extension's backend ES6 modules in the import map so they can
 * be loaded via PageRenderer::loadJavaScriptModule().
 */
return [
    'dependencies' => ['backend'],
    'imports' => [
        '@webnomads/wn-ai-bridge/' => 'EXT:wn_ai_bridge/Resources/Public/JavaScript/',
    ],
];
