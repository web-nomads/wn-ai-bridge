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

// Register cache for markdown content
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_markdown'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_markdown'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
        'groups' => ['pages']
    ];
}
