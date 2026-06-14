<?php

declare(strict_types=1);

defined('TYPO3') or die();

$autoloader = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('wn_ai_bridge') . 'vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addTypoScript(
    'wn_ai_bridge',
    'setup',
    '@import "EXT:wn_ai_bridge/Configuration/TypoScript/setup.typoscript"'
);

ExtensionManagementUtility::addTypoScript(
    'wn_ai_bridge',
    'setup',
    '@import "EXT:wn_ai_bridge/Configuration/TypoScript/markdown.typoscript"'
);
