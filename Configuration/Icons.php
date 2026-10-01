<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'wn-ai-bridge-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:wn_ai_bridge/Resources/Public/Icons/Extension.svg',
    ],
    'wn-ai-bridge-module-enquiries' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:wn_ai_bridge/Resources/Public/Icons/module-enquiries.svg',
    ],
    'wn-ai-bridge-module-answers' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:wn_ai_bridge/Resources/Public/Icons/module-answers.svg',
    ],
    'wn-ai-bridge-module-bot-access' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:wn_ai_bridge/Resources/Public/Icons/module-bot-access.svg',
    ],
];
