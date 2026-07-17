<?php

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$version = (string)GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion();

$GLOBALS['SiteConfiguration']['site']['columns']['llmsTxtEnabled'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtEnabled',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtEnabled.description',
    'config' => [
        'type' => 'check',
        'renderType' => 'checkboxToggle',
        'default' => 1,
        'items' => [
            [
                'label' => '',
                'labelChecked' => 'Enabled',
                'labelUnchecked' => 'Disabled',
            ],
        ],
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['llmsTxtTitle'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtTitle',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtTitle.description',
    'config' => [
        'type' => 'input',
        'placeholder' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtTitle.placeholder',
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['llmsTxtDescription'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtDescription',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtDescription.description',
    'config' => [
        'type' => 'text',
        'rows' => 3,
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['llmsTxtAdditionalInfo'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtAdditionalInfo',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtAdditionalInfo.description',
    'config' => [
        'type' => 'text',
        'rows' => 10,
        'renderType' => $version >= '13' ? 'codeEditor' : 'text',
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['llmsTxtContactEmail'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtContactEmail',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtContactEmail.description',
    'config' => [
        'type' => 'input',
        'eval' => 'trim,email',
        'placeholder' => 'contact@example.com',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['llmsTxtKeywords'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtKeywords',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtKeywords.description',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
        'placeholder' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtKeywords.placeholder',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['llmsTxtMaxDepth'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtMaxDepth',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtMaxDepth.description',
    'config' => [
        'type' => 'number',
        'default' => 2,
        'range' => [
            'lower' => 1,
            'upper' => 5,
        ],
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantEnabled'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantEnabled',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantEnabled.description',
    'config' => [
        'type' => 'check',
        'renderType' => 'checkboxToggle',
        'default' => 1,
        'items' => [
            [
                'label' => '',
                'labelChecked' => 'Enabled',
                'labelUnchecked' => 'Disabled',
            ],
        ],
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantTitle'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantTitle',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantTitle.description',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
        'placeholder' => 'Wie kann ich helfen?',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantWelcome'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantWelcome',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantWelcome.description',
    'config' => [
        'type' => 'text',
        'rows' => 3,
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantPlaceholder'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantPlaceholder',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantPlaceholder.description',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
        'placeholder' => 'Ihre Frage …',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantAutoOpen'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantAutoOpen',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantAutoOpen.description',
    'config' => [
        'type' => 'check',
        'renderType' => 'checkboxToggle',
        'default' => 0,
        'items' => [
            [
                'label' => '',
                'labelChecked' => 'Enabled',
                'labelUnchecked' => 'Disabled',
            ],
        ],
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantAutoOpenDelay'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantAutoOpenDelay',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantAutoOpenDelay.description',
    'displayCond' => 'FIELD:aiAssistantAutoOpen:REQ:true',
    'config' => [
        'type' => 'number',
        'default' => 5,
        'range' => [
            'lower' => 0,
            'upper' => 600,
        ],
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantAccentColor'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantAccentColor',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantAccentColor.description',
    'config' => [
        'type' => 'color',
        'size' => 10,
        'placeholder' => '#2563eb',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantSystemPrompt'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantSystemPrompt',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantSystemPrompt.description',
    'config' => [
        'type' => 'text',
        'rows' => 6,
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantOnePager'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantOnePager',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantOnePager.description',
    'config' => [
        'type' => 'check',
        'renderType' => 'checkboxToggle',
        'default' => 0,
        'items' => [
            [
                'label' => '',
                'labelChecked' => 'Enabled',
                'labelUnchecked' => 'Disabled',
            ],
        ],
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantSearchPid'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantSearchPid',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantSearchPid.description',
    'config' => [
        'type' => 'number',
        'default' => 0,
        'range' => [
            'lower' => 0,
        ],
    ],
];

if (!isset($GLOBALS['SiteConfiguration']['site']['types']['0']['showitem'])) {
    $GLOBALS['SiteConfiguration']['site']['types']['0']['showitem'] = '';
}

$GLOBALS['SiteConfiguration']['site']['types']['0']['showitem'] .= ',
    --div--;LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.tab.llmstxt,
        llmsTxtEnabled,
        llmsTxtTitle,
        llmsTxtDescription,
        llmsTxtAdditionalInfo,
        llmsTxtContactEmail,
        llmsTxtKeywords,
        llmsTxtMaxDepth,
    --div--;LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.tab.assistant,
        aiAssistantEnabled,
        aiAssistantTitle,
        aiAssistantWelcome,
        aiAssistantPlaceholder,
        aiAssistantAccentColor,
        aiAssistantAutoOpen,
        aiAssistantAutoOpenDelay,
        aiAssistantSystemPrompt,
        aiAssistantOnePager,
        aiAssistantSearchPid
';
