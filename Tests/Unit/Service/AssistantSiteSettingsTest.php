<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Subscription\SubscriptionOnlineCheck;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\TamperReporter;

/**
 * The settings that moved from the extension configuration into the site
 * configuration: the temperature and the agent instructions.
 *
 * Both are answers a website gives and not an installation — two sites in one
 * TYPO3 address their visitors differently. The extension configuration is still
 * read where a site names none, so an installation that has not run the upgrade
 * wizard yet keeps working on exactly what it had.
 *
 * The subscription key moved the same way; it is exercised in
 * SiteSubscriptionKeyTest, which needs a signed key to say anything at all.
 */
final class AssistantSiteSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']);

        parent::tearDown();
    }

    /**
     * The LLM API key stays installation-wide: it is the account the provider
     * bills, not something a website answers for.
     */
    #[Test]
    public function theApiKeyIsReadFromTheExtensionConfiguration(): void
    {
        $configuration = $this->service([], ['assistantApiKey' => 'sk-installation']);

        self::assertSame('sk-installation', $configuration->getAssistantApiKey());
        self::assertTrue($configuration->isAssistantLlmConfigured());
    }

    #[Test]
    public function noApiKeyMeansSearchOnly(): void
    {
        $configuration = $this->service([], []);

        self::assertSame('', $configuration->getAssistantApiKey());
        self::assertFalse($configuration->isAssistantLlmConfigured());
    }

    #[Test]
    public function theSiteDecidesHowMuchItsAnswersMayVary(): void
    {
        $configuration = $this->service(
            ['aiAssistantTemperature' => '0.7'],
            ['assistantTemperature' => '0.1'],
        );

        self::assertSame(0.7, $configuration->getAssistantTemperature());
    }

    #[Test]
    public function aSiteThatNamesNoTemperatureFallsBackAndThenToTheDefault(): void
    {
        self::assertSame(0.1, $this->service([], ['assistantTemperature' => '0.1'])->getAssistantTemperature());
        self::assertSame(
            ConfigurationService::DEFAULT_TEMPERATURE,
            $this->service([], [])->getAssistantTemperature(),
        );
    }

    /**
     * The value used to be typed into a free text field that nothing validated.
     */
    #[DataProvider('temperatures')]
    #[Test]
    public function aTemperatureIsReadAsItMayHaveBeenWritten(string $raw, float $expected): void
    {
        self::assertSame($expected, ConfigurationService::normaliseTemperature($raw));
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function temperatures(): array
    {
        return [
            'plain' => ['0.4', 0.4],
            'comma' => ['0,4', 0.4],
            'padded' => ['  0.4  ', 0.4],
            'zero' => ['0.0', 0.0],
            'one' => ['1.0', 1.0],
            'above the range is clamped' => ['5', 1.0],
            'below the range is clamped' => ['-2', 0.0],
            'not a number at all' => ['heiss', ConfigurationService::DEFAULT_TEMPERATURE],
            'empty' => ['', ConfigurationService::DEFAULT_TEMPERATURE],
        ];
    }

    #[Test]
    public function theSiteDecidesHowTheAssistantSpeaks(): void
    {
        $configuration = $this->service(
            ['aiAssistantInstructions' => 'Answer in the language of the question.'],
            ['assistantInstructions' => 'Be brief.'],
        );

        self::assertSame('Answer in the language of the question.', $configuration->getAssistantInstructions());
    }

    #[Test]
    public function withoutInstructionsOfItsOwnTheInstallationOnesStillApply(): void
    {
        $configuration = $this->service([], ['assistantInstructions' => 'Be brief.']);

        self::assertSame('Be brief.', $configuration->getAssistantInstructions());
    }

    /**
     * Read from the command line and from a backend module too, where there is
     * no site to resolve. An exception there would take down whatever is running.
     */
    #[Test]
    public function withoutARequestNothingBreaksAndTheInstallationValuesStand(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = [
            'assistantTemperature' => '0.3',
            'assistantInstructions' => 'Be brief.',
        ];

        $configuration = $this->configurationService();

        self::assertSame(0.3, $configuration->getAssistantTemperature());
        self::assertSame('Be brief.', $configuration->getAssistantInstructions());
    }

    /**
     * The search root used to be 0 when a site configured none, and every
     * provider read that as "no restriction" — which on an installation with
     * more than one site meant the whole page tree.
     */
    #[Test]
    public function withoutAConfiguredSearchRootTheSitesOwnRootPageIsTheBoundary(): void
    {
        $configuration = $this->service([], []);

        self::assertSame(1, $configuration->getAssistantSearchRootPageId());
        self::assertSame(1, $configuration->getCurrentSiteRootPageId());
        self::assertSame('ours', $configuration->getCurrentSiteIdentifier());
    }

    #[Test]
    public function aConfiguredSearchRootStillNarrowsTheSearchFurther(): void
    {
        $configuration = $this->service(['aiAssistantSearchPid' => 42], []);

        self::assertSame(42, $configuration->getAssistantSearchRootPageId());
    }

    /**
     * @param array<string, mixed> $siteConfiguration
     * @param array<string, mixed> $extensionConfiguration
     */
    private function service(array $siteConfiguration, array $extensionConfiguration): ConfigurationService
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = $extensionConfiguration;

        $site = new Site('ours', 1, ['base' => 'https://a.example/'] + $siteConfiguration);

        $configuration = $this->configurationService();
        $configuration->setRequest(
            (new ServerRequest('https://a.example/'))->withAttribute('site', $site)
        );

        return $configuration;
    }

    /**
     * The subscription service is final and cannot be doubled, so it is built
     * for real. Nothing here asks it anything — it never resolves a key.
     */
    private function configurationService(): ConfigurationService
    {
        $requestFactory = $this->createMock(RequestFactory::class);

        return new ConfigurationService(
            $this->createMock(SiteFinder::class),
            new SubscriptionService(
                new SubscriptionOnlineCheck($requestFactory),
                new TamperReporter($requestFactory),
            ),
        );
    }
}
