<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Http;

/**
 * Guzzle request options for HTTP requests the extension makes to its own
 * site, carrying the Basic Authentication credentials from the extension
 * configuration (for sites behind a password-protected staging setup).
 */
final class BasicAuthOptions
{
    /**
     * @param array<string, mixed>|null $extensionConfiguration Defaults to the wn_ai_bridge extension configuration
     * @return array{auth?: array{0: string, 1: string}}
     */
    public static function forOwnSite(?array $extensionConfiguration = null): array
    {
        $extensionConfiguration ??= $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];

        $username = trim((string)($extensionConfiguration['basicAuthUsername'] ?? ''));
        if ($username === '') {
            return [];
        }

        // password not trimmed, may contain significant spaces
        return ['auth' => [$username, (string)($extensionConfiguration['basicAuthPassword'] ?? '')]];
    }
}
