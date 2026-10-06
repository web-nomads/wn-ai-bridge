<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_wnaibridge_llms_include' => [
        'exclude' => true,
        'label' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:pages.llms_include',
        'description' => 'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang.xlf:pages.llms_include.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
]);
ExtensionManagementUtility::addFieldsToPalette('pages', 'visibility', 'tx_wnaibridge_llms_include', 'after:nav_hide');
