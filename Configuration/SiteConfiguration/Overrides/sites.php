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

$GLOBALS['SiteConfiguration']['site']['columns']['llmsTxtOnePager'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtOnePager',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtOnePager.description',
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

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantAvatar'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantAvatar',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantAvatar.description',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
        'placeholder' => 'fileadmin/logo.png',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantCustomCss'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantCustomCss',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantCustomCss.description',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
        'placeholder' => 'fileadmin/assistant.css',
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

// Additional per-site widget colours. Each is an optional HEX value; unset ones
// keep the widget stylesheet defaults (including dark-mode).
$aiAssistantColorFields = [
    'aiAssistantBgColor' => 'site.aiAssistantBgColor',
    'aiAssistantTextColor' => 'site.aiAssistantTextColor',
    'aiAssistantUserBgColor' => 'site.aiAssistantUserBgColor',
    'aiAssistantUserTextColor' => 'site.aiAssistantUserTextColor',
    'aiAssistantUserLinkColor' => 'site.aiAssistantUserLinkColor',
    'aiAssistantAssistantBgColor' => 'site.aiAssistantAssistantBgColor',
    'aiAssistantAssistantTextColor' => 'site.aiAssistantAssistantTextColor',
    'aiAssistantAssistantLinkColor' => 'site.aiAssistantAssistantLinkColor',
    'aiAssistantSourcesBgColor' => 'site.aiAssistantSourcesBgColor',
    'aiAssistantSourcesTextColor' => 'site.aiAssistantSourcesTextColor',
    'aiAssistantSourcesLinkColor' => 'site.aiAssistantSourcesLinkColor',
];
foreach ($aiAssistantColorFields as $aiAssistantColorField => $aiAssistantColorLabel) {
    $GLOBALS['SiteConfiguration']['site']['columns'][$aiAssistantColorField] = [
        'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:' . $aiAssistantColorLabel,
        'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:' . $aiAssistantColorLabel . '.description',
        'config' => [
            'type' => 'color',
            'size' => 10,
        ],
    ];
}

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

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantLearning'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantLearning',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantLearning.description',
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
        llmsTxtOnePager,
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
        aiAssistantAvatar,
        aiAssistantAutoOpen,
        aiAssistantAutoOpenDelay,
        aiAssistantSystemPrompt,
        aiAssistantOnePager,
        aiAssistantLearning,
        aiAssistantSearchPid,
    --div--;LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.tab.assistantColors,
        aiAssistantAccentColor,
        aiAssistantBgColor,
        aiAssistantTextColor,
        aiAssistantUserBgColor,
        aiAssistantUserTextColor,
        aiAssistantUserLinkColor,
        aiAssistantAssistantBgColor,
        aiAssistantAssistantTextColor,
        aiAssistantAssistantLinkColor,
        aiAssistantSourcesBgColor,
        aiAssistantSourcesTextColor,
        aiAssistantSourcesLinkColor,
        aiAssistantCustomCss
';

// Per-language overrides for the assistant's visitor-facing texts, so they can
// be maintained per language variation directly on each site language. When a
// language leaves a field empty, the site-level value (and finally the bundled
// translation) is used.
$GLOBALS['SiteConfiguration']['site_language']['columns']['aiAssistantTitle'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantTitle',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.language.assistantText',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site_language']['columns']['aiAssistantWelcome'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantWelcome',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.language.assistantText',
    'config' => [
        'type' => 'text',
        'rows' => 3,
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site_language']['columns']['aiAssistantPlaceholder'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantPlaceholder',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.language.assistantText',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site_language']['columns']['aiAssistantSystemPrompt'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantSystemPrompt',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.language.assistantText',
    'config' => [
        'type' => 'text',
        'rows' => 6,
        'eval' => 'trim',
    ],
];

if (!isset($GLOBALS['SiteConfiguration']['site_language']['types']['1']['showitem'])) {
    $GLOBALS['SiteConfiguration']['site_language']['types']['1']['showitem'] = '';
}

// Per-language overrides for the llms.txt texts, so they can be maintained per
// language variation directly on each site language. When a language leaves a
// field empty, the site-level value is used.
$GLOBALS['SiteConfiguration']['site_language']['columns']['llmsTxtTitle'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtTitle',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.language.llmsText',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site_language']['columns']['llmsTxtDescription'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtDescription',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.language.llmsText',
    'config' => [
        'type' => 'text',
        'rows' => 3,
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site_language']['columns']['llmsTxtAdditionalInfo'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtAdditionalInfo',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.language.llmsText',
    'config' => [
        'type' => 'text',
        'rows' => 10,
        'renderType' => $version >= '13' ? 'codeEditor' : 'text',
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site_language']['columns']['llmsTxtKeywords'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.llmsTxtKeywords',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.language.llmsText',
    'config' => [
        'type' => 'input',
        'eval' => 'trim',
    ],
];

$GLOBALS['SiteConfiguration']['site_language']['types']['1']['showitem'] .= ',
    --div--;LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.tab.llmstxt,
        llmsTxtTitle,
        llmsTxtDescription,
        llmsTxtAdditionalInfo,
        llmsTxtKeywords,
    --div--;LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.tab.assistant,
        aiAssistantTitle,
        aiAssistantWelcome,
        aiAssistantPlaceholder,
        aiAssistantSystemPrompt
';
