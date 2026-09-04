<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Upgrades;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * Moves the subscription key, the temperature and the agent instructions out of
 * the extension configuration and into every site's own configuration.
 *
 * They were installation-wide, which was wrong in the one place it matters: a
 * TYPO3 serving several websites licensed them all through one key and had them
 * all speak with one voice. Each is now answered per site.
 *
 * Nothing is taken away by running this. A site that already sets a value keeps
 * it — the wizard fills in the blanks, it does not overwrite decisions. And the
 * extension configuration is only cleared once every site has been written, so a
 * failure halfway through leaves the installation exactly as it was, still
 * reading the old values through the fallback in {@see ConfigurationService}.
 */
final class AssistantSettingsToSiteConfigurationUpdate implements UpgradeWizardInterface
{
    public const IDENTIFIER = 'wnAiBridgeAssistantSettingsToSiteConfiguration';

    private const EXTENSION_KEY = 'wn_ai_bridge';

    /**
     * Extension configuration setting => the site configuration field it becomes.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'subscriptionKey' => 'aiAssistantSubscriptionKey',
        'assistantTemperature' => 'aiAssistantTemperature',
        'assistantInstructions' => 'aiAssistantInstructions',
    ];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SiteConfiguration $siteConfiguration,
        private readonly SiteWriter $siteWriter,
    ) {}

    public function getTitle(): string
    {
        return 'AI Bridge: move the subscription key, temperature and instructions into the site configuration';
    }

    public function getDescription(): string
    {
        return 'The subscription key, the temperature and the agent instructions used to be set once for the whole '
            . 'installation. They are now maintained per site, under Site Management > Sites > "AI Assistant" — so '
            . 'two websites in one TYPO3 can be licensed separately and address their visitors differently. '
            . 'This copies the current values into every site that does not set them itself and then removes them '
            . 'from the extension configuration. A temperature is rounded to one decimal, which is what the new '
            . 'field offers. Until this has run, the old values keep being used.';
    }

    public function updateNecessary(): bool
    {
        $configuration = $this->extensionConfiguration();

        foreach (array_keys(self::FIELDS) as $setting) {
            if (array_key_exists($setting, $configuration)) {
                return true;
            }
        }

        return false;
    }

    public function executeUpdate(): bool
    {
        $configuration = $this->extensionConfiguration();

        foreach ($this->siteIdentifiers() as $identifier) {
            if (!$this->writeSite($identifier, $configuration)) {
                // Leave the extension configuration alone, so the installation
                // keeps working on it and the wizard can simply be run again.
                return false;
            }
        }

        foreach (array_keys(self::FIELDS) as $setting) {
            unset($configuration[$setting]);
        }

        try {
            $this->extensionConfiguration->set(self::EXTENSION_KEY, $configuration);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function getPrerequisites(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $extensionConfiguration
     */
    private function writeSite(string $identifier, array $extensionConfiguration): bool
    {
        try {
            $siteConfiguration = $this->siteConfiguration->load($identifier);
        } catch (\Throwable $e) {
            return false;
        }

        $changed = false;
        foreach (self::FIELDS as $setting => $field) {
            $value = trim((string)($extensionConfiguration[$setting] ?? ''));
            if ($value === '') {
                continue;
            }
            // A site that has already answered this for itself is left alone.
            if (trim((string)($siteConfiguration[$field] ?? '')) !== '') {
                continue;
            }

            $siteConfiguration[$field] = $setting === 'assistantTemperature'
                ? self::temperature($value)
                : $value;
            $changed = true;
        }

        if (!$changed) {
            return true;
        }

        try {
            $this->siteWriter->write($identifier, $siteConfiguration);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * The temperature as the new field can offer it: one decimal between 0.0 and
     * 1.0. Anything finer was written into a free text field that nothing
     * validated, and would leave the select showing nothing at all.
     */
    private static function temperature(string $raw): string
    {
        return number_format(round(ConfigurationService::normaliseTemperature($raw), 1), 1, '.', '');
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
     * @return array<string, mixed>
     */
    private function extensionConfiguration(): array
    {
        try {
            $configuration = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable $e) {
            // Nothing configured at all — there is nothing to move either.
            return [];
        }

        return is_array($configuration) ? $configuration : [];
    }
}
