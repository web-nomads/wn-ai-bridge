<?php

declare(strict_types=1);

/*
 * Coding standards for this extension: the official TYPO3 rule set, applied to
 * the source that actually ships. Everything generated or vendored stays out.
 */

$config = \TYPO3\CodingStandards\CsFixerConfig::create();

$config->getFinder()
    ->in(__DIR__)
    ->exclude(['.Build', 'var']);

return $config;
