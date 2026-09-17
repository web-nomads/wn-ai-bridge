<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Configuration;

/**
 * The colour roles of the assistant widget, and how they are named in a site
 * configuration.
 *
 * Three places need the same list and must not drift apart: the site
 * configuration form that offers the fields, the service that reads them, and
 * the upgrade wizard that moved the values of the single palette this extension
 * used to have. The form builds its own copy because a SiteConfiguration
 * override is loaded before the class map exists — the field names there are
 * spelled out, which is why the test suite compares the two.
 */
final class AssistantColorRoles
{
    /**
     * A dark field carries the name of its light counterpart plus this suffix.
     * The light field is the one an existing site already has, so the light set
     * keeps the original names and the split costs no migration of its own.
     */
    public const DARK_FIELD_SUFFIX = 'Dark';

    /**
     * CSS custom property suffix => site configuration field of the light set.
     * `bg` reaches the stylesheet as --wn-ai-bg.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'accent' => 'aiAssistantAccentColor',
        'accent-contrast' => 'aiAssistantAccentContrastColor',
        'bg' => 'aiAssistantBgColor',
        'fg' => 'aiAssistantTextColor',
        'user-bg' => 'aiAssistantUserBgColor',
        'user-fg' => 'aiAssistantUserTextColor',
        'user-link' => 'aiAssistantUserLinkColor',
        'assistant-bg' => 'aiAssistantAssistantBgColor',
        'assistant-fg' => 'aiAssistantAssistantTextColor',
        'assistant-link' => 'aiAssistantAssistantLinkColor',
        'sources-bg' => 'aiAssistantSourcesBgColor',
        'sources-fg' => 'aiAssistantSourcesTextColor',
        'sources-link' => 'aiAssistantSourcesLinkColor',
    ];

    /**
     * #rgb and #rrggbb only. Everything else is dropped rather than escaped:
     * these values are written into a stylesheet, so anything that is not
     * certainly a colour has no business being there.
     */
    public const HEX_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    public static function darkField(string $lightField): string
    {
        return $lightField . self::DARK_FIELD_SUFFIX;
    }

    /**
     * The value of a colour field, or an empty string where it is unset or not
     * a hex colour.
     *
     * @param array<string, mixed> $configuration
     */
    public static function value(array $configuration, string $field): string
    {
        $value = trim((string)($configuration[$field] ?? ''));

        return preg_match(self::HEX_PATTERN, $value) === 1 ? $value : '';
    }
}
