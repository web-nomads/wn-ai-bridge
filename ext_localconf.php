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
    '@import "EXT:wn_ai_bridge/Configuration/TypoScript/llmsfull.typoscript"'
);

ExtensionManagementUtility::addTypoScript(
    'wn_ai_bridge',
    'setup',
    '@import "EXT:wn_ai_bridge/Configuration/TypoScript/markdown.typoscript"'
);

ExtensionManagementUtility::addTypoScript(
    'wn_ai_bridge',
    'setup',
    '@import "EXT:wn_ai_bridge/Configuration/TypoScript/assistant.typoscript"'
);

// Register cache for markdown content
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_markdown'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_markdown'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
        'groups' => ['pages'],
    ];
}

// Register cache for resolved visitor information (hostname / country) shown in
// the assistant log backend module. Uses the database backend so lookups are
// shared across requests and survive a page cache flush.
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_visitorinfo'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_visitorinfo'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'groups' => ['system'],
    ];
}

// Register cache for the daily subscription status check. Uses the database
// backend so the verdict the backend (or the CLI command) fetched is shared with
// every frontend worker, and is deliberately not part of the 'pages' group so a
// page cache flush does not trigger a new request to the issuing server.
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_subscription'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_subscription'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'groups' => ['system'],
    ];
}

// Register cache for the rate limiter counters.
// Uses the database backend so counters survive across requests and are shared
// between PHP workers. It is intentionally not part of the 'pages' group so a
// page cache flush does not reset the rate limit windows.
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_ratelimit'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['wn_ai_bridge_ratelimit'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'groups' => ['system'],
    ];
}
