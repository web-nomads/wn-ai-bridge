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

/*
 * Per-site widget colours.
 *
 * The widget has two colour schemes and the visitor's browser chooses between
 * them, so every role is configured twice: once for a light page and once for a
 * dark one. A colour mixed to be read on white cannot be read on near-black, so
 * a single value for both never worked — it only meant that whichever scheme
 * had not been thought about came out wrong.
 *
 * The light field is the one that matters. Where a dark field is left empty the
 * light value is used for both, which is how this extension behaved before the
 * split and therefore what an existing site keeps doing. Leave both empty and
 * the stylesheet's own defaults apply, and those are stated per scheme (see
 * Resources/Public/Css/assistant.css).
 *
 * The array keys are the CSS custom property suffixes, so `bg` arrives as
 * --wn-ai-bg, and the order is the order of the rows in the palette. The two
 * placeholders are the stylesheet defaults for that scheme, so a picker opens
 * showing what the field would otherwise fall back to.
 */
$aiAssistantColorRoles = [
    'accent' => ['field' => 'aiAssistantAccentColor', 'light' => '#2563eb', 'dark' => '#8ab4ff'],
    'accent-contrast' => ['field' => 'aiAssistantAccentContrastColor', 'light' => '#ffffff', 'dark' => '#0b1220'],
    'bg' => ['field' => 'aiAssistantBgColor', 'light' => '#ffffff', 'dark' => '#1f2430'],
    'fg' => ['field' => 'aiAssistantTextColor', 'light' => '#1f2933', 'dark' => '#e5e7eb'],
    'user-bg' => ['field' => 'aiAssistantUserBgColor', 'light' => '#2563eb', 'dark' => '#8ab4ff'],
    'user-fg' => ['field' => 'aiAssistantUserTextColor', 'light' => '#ffffff', 'dark' => '#0b1220'],
    'user-link' => ['field' => 'aiAssistantUserLinkColor', 'light' => '#ffffff', 'dark' => '#0b1220'],
    'assistant-bg' => ['field' => 'aiAssistantAssistantBgColor', 'light' => '#f3f4f6', 'dark' => '#2d3340'],
    'assistant-fg' => ['field' => 'aiAssistantAssistantTextColor', 'light' => '#1f2933', 'dark' => '#e5e7eb'],
    'assistant-link' => ['field' => 'aiAssistantAssistantLinkColor', 'light' => '#2563eb', 'dark' => '#8ab4ff'],
    'sources-bg' => ['field' => 'aiAssistantSourcesBgColor', 'light' => '#ffffff', 'dark' => '#1f2430'],
    'sources-fg' => ['field' => 'aiAssistantSourcesTextColor', 'light' => '#6b7280', 'dark' => '#9ca3af'],
    'sources-link' => ['field' => 'aiAssistantSourcesLinkColor', 'light' => '#2563eb', 'dark' => '#8ab4ff'],
];

$aiAssistantColorRows = [];
foreach ($aiAssistantColorRoles as $aiAssistantColorRole) {
    $aiAssistantLightField = $aiAssistantColorRole['field'];
    // AssistantColors::DARK_FIELD_SUFFIX — spelled out here because a site
    // configuration override is loaded before the class map is available.
    $aiAssistantDarkField = $aiAssistantLightField . 'Dark';

    $aiAssistantLabel = 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.' . $aiAssistantLightField;

    // Each field names its own scheme rather than relying on the column it
    // happens to stand in. A palette wraps on a narrow screen, and a heading
    // row would then sit above a column that is no longer there - which is
    // worse than no heading at all, because it would still be read as one.
    $GLOBALS['SiteConfiguration']['site']['columns'][$aiAssistantLightField] = [
        'label' => $aiAssistantLabel . '.light',
        'description' => $aiAssistantLabel . '.description',
        'config' => [
            'type' => 'color',
            'size' => 10,
            'placeholder' => $aiAssistantColorRole['light'],
        ],
    ];

    $GLOBALS['SiteConfiguration']['site']['columns'][$aiAssistantDarkField] = [
        'label' => $aiAssistantLabel . '.dark',
        'description' => $aiAssistantLabel . '.description',
        'config' => [
            'type' => 'color',
            'size' => 10,
            'placeholder' => $aiAssistantColorRole['dark'],
        ],
    ];

    // Light on the left, dark on the right, one role per row.
    $aiAssistantColorRows[] = $aiAssistantLightField . ', ' . $aiAssistantDarkField;
}

$GLOBALS['SiteConfiguration']['site']['palettes']['aiAssistantColors'] = [
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.assistantColors.description',
    'showitem' => implode(', --linebreak--, ', $aiAssistantColorRows),
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

// The licence this site runs on. It lives here rather than in the extension
// configuration because a licence covers domains, and a domain belongs to a
// site: two websites in one TYPO3 can be licensed separately, each with the key
// it was sold. Left empty, the installation-wide key is used.
$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantSubscriptionKey'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantSubscriptionKey',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantSubscriptionKey.description',
    'config' => [
        'type' => 'text',
        'rows' => 4,
        'eval' => 'trim',
        'placeholder' => 'WNAI1.…',
    ],
];

// Two settings that decide what the assistant costs and how it speaks. Both are
// answers a site gives, not an installation: two websites address their
// visitors differently.

// A select rather than a free text field: the value is a decimal between 0 and
// 1, and everything outside that range was silently clamped anyway — which left
// a number in the configuration that had nothing to do with what was in effect.
$aiAssistantTemperatureItems = [
    [
        'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantTemperature.default',
        'value' => '',
    ],
];
for ($aiAssistantTemperature = 0; $aiAssistantTemperature <= 10; $aiAssistantTemperature++) {
    $aiAssistantTemperatureValue = number_format($aiAssistantTemperature / 10, 1, '.', '');
    $aiAssistantTemperatureItems[] = [
        'label' => $aiAssistantTemperatureValue,
        'value' => $aiAssistantTemperatureValue,
    ];
}

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantTemperature'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantTemperature',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantTemperature.description',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'default' => '',
        'items' => $aiAssistantTemperatureItems,
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['aiAssistantInstructions'] = [
    'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantInstructions',
    'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:site.aiAssistantInstructions.description',
    'config' => [
        'type' => 'text',
        'rows' => 12,
        'eval' => 'trim',
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
        aiAssistantSubscriptionKey,
        aiAssistantTemperature,
        aiAssistantInstructions,
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
        --palette--;;aiAssistantColors,
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
