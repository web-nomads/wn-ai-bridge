<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Upgrades;

use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;
use WebNomads\WnAiBridge\Configuration\AssistantColorRoles;

/**
 * Moves a dark assistant palette into the dark colour fields.
 *
 * Until now a site configured one set of widget colours and that set was used
 * whatever colour scheme the visitor's browser asked for. Each role is now a
 * pair — a light field and a dark one — and the old fields became the light half
 * of it, so a site whose palette was written for a bright page needs nothing
 * done and this wizard leaves it alone.
 *
 * A palette written for a dark page is the case worth handling. Its values now
 * sit in the light column, which is where they would be shown on exactly the
 * screens they were never mixed for. Which of the two it is can be read off the
 * configuration rather than guessed: the panel background is what the whole
 * palette was chosen against, so a dark `aiAssistantBgColor` says the set
 * belongs in the dark column.
 *
 * Moves rather than copies. A near-black palette left behind in the light column
 * would be served to the first visitor on a bright screen; emptied, the widget
 * falls back to its own light defaults until someone fills the column in
 * deliberately.
 *
 * A site that already states a dark colour of its own has been through this by
 * hand and is skipped — the wizard fills in blanks, it does not overwrite
 * decisions. Same rule as {@see AssistantSettingsToSiteConfigurationUpdate}.
 */
final class AssistantColorSchemeUpdate implements UpgradeWizardInterface
{
    public const IDENTIFIER = 'wnAiBridgeAssistantColorScheme';

    /**
     * Relative luminance below which a background counts as dark. 0.25 sits
     * clear of both ends: #1F2430, the widget's own dark panel, is 0.02, and
     * #F3F4F6, its light message bubble, is 0.90.
     */
    private const DARK_THRESHOLD = 0.25;

    public function __construct(
        private readonly SiteConfiguration $siteConfiguration,
        private readonly SiteWriter $siteWriter,
    ) {}

    public function getTitle(): string
    {
        return 'AI Bridge: move a dark assistant palette into the dark colour fields';
    }

    public function getDescription(): string
    {
        return 'The assistant widget now holds one set of colours per colour scheme, under '
            . 'Site Management > Sites > "AI Assistant Colors". Sites whose palette was mixed for a dark '
            . 'page still carry those values in the light column, where they would be shown to visitors '
            . 'on a bright screen. This moves such a palette into the dark column and leaves the light one '
            . 'empty, so the widget uses its own light defaults until it is filled in. Sites with a light '
            . 'palette, and sites that already state dark colours, are left untouched.';
    }

    public function getPrerequisites(): array
    {
        return [];
    }

    public function updateNecessary(): bool
    {
        return $this->affectedSites() !== [];
    }

    public function executeUpdate(): bool
    {
        foreach ($this->affectedSites() as $identifier => $configuration) {
            try {
                $this->siteWriter->write($identifier, $this->moveToDark($configuration));
            } catch (\Throwable $e) {
                // Leave the rest alone; the wizard stays available and can
                // simply be run again once the cause is dealt with.
                return false;
            }
        }

        return true;
    }

    /**
     * Sites carrying a dark palette in the light fields that have not been given
     * a dark palette of their own yet.
     *
     * @return array<string, array<string, mixed>>
     */
    private function affectedSites(): array
    {
        $affected = [];
        foreach ($this->siteIdentifiers() as $identifier) {
            try {
                $configuration = $this->siteConfiguration->load($identifier);
            } catch (\Throwable $e) {
                continue;
            }

            $background = AssistantColorRoles::value($configuration, AssistantColorRoles::ROLES['bg']);
            if ($background === '' || !self::isDark($background)) {
                continue;
            }

            if ($this->hasDarkPalette($configuration)) {
                continue;
            }

            $affected[$identifier] = $configuration;
        }

        return $affected;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function hasDarkPalette(array $configuration): bool
    {
        foreach (AssistantColorRoles::ROLES as $lightField) {
            if (AssistantColorRoles::value($configuration, AssistantColorRoles::darkField($lightField)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function moveToDark(array $configuration): array
    {
        foreach (AssistantColorRoles::ROLES as $lightField) {
            $value = AssistantColorRoles::value($configuration, $lightField);
            if ($value === '') {
                continue;
            }

            $configuration[AssistantColorRoles::darkField($lightField)] = $value;
            $configuration[$lightField] = '';
        }

        return $configuration;
    }

    /**
     * @return list<string>
     */
    private function siteIdentifiers(): array
    {
        try {
            return array_map(strval(...), array_keys($this->siteConfiguration->getAllExistingSites()));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * WCAG relative luminance of a #rgb or #rrggbb colour.
     */
    private static function isDark(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $channel = (int)hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2] < self::DARK_THRESHOLD;
    }
}
