<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\CostCalculator;
use WebNomads\WnAiBridge\Subscription\SubscriptionOnlineCheck;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\TamperReporter;

/**
 * What the "Enquiries" module says an answer cost.
 *
 * The figure used to be labelled "CHF" whatever the conversion rate had been set
 * to, and the rate itself was named after that one currency. Both are now
 * settings that say what they do, and the label follows the configuration
 * instead of the code.
 */
final class CostCalculatorTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']);

        parent::tearDown();
    }

    #[Test]
    public function anAmountCarriesTheConfiguredCurrency(): void
    {
        $calculator = new CostCalculator($this->configuration('1.0', 'EUR'));

        self::assertSame('EUR 12.5000', $calculator->format(12.5));
    }

    #[Test]
    public function withoutAConfiguredCurrencyTheFiguresReadAsTheyAlwaysDid(): void
    {
        $calculator = new CostCalculator($this->configuration('0.90', ''));

        self::assertSame('CHF 12.5000', $calculator->format(12.5));
    }

    #[Test]
    public function theRenamedRateIsUsed(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = ['assistantUsdConversionRate' => '2.0'];

        // claude-haiku-4-5: USD 1.00 per 1M input tokens, USD 5.00 per 1M output.
        $calculator = new CostCalculator($this->realConfiguration());

        self::assertEqualsWithDelta(4.0, $calculator->cost('claude-haiku-4-5', 1_000_000, 200_000), 0.0001);
    }

    /**
     * An installation that has not run the upgrade wizard yet keeps converting
     * with the rate it always had.
     */
    #[Test]
    public function theFormerRateIsStillHonoured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = ['assistantUsdToChfRate' => '2.0'];

        $calculator = new CostCalculator($this->realConfiguration());

        self::assertEqualsWithDelta(2.0, $calculator->cost('claude-haiku-4-5', 1_000_000, 0), 0.0001);
    }

    #[Test]
    public function theNewNameWinsOverTheOldOne(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = [
            'assistantUsdConversionRate' => '3.0',
            'assistantUsdToChfRate' => '2.0',
        ];

        $calculator = new CostCalculator($this->realConfiguration());

        self::assertEqualsWithDelta(3.0, $calculator->cost('claude-haiku-4-5', 1_000_000, 0), 0.0001);
    }

    #[Test]
    public function perModelTotalsAreSummed(): void
    {
        $calculator = new CostCalculator($this->configuration('1.0', 'CHF'));

        $total = $calculator->totalCost([
            ['model' => 'claude-haiku-4-5', 'inputTokens' => 1_000_000, 'outputTokens' => 0],
            ['model' => 'claude-haiku-4-5', 'inputTokens' => 0, 'outputTokens' => 1_000_000],
        ]);

        self::assertEqualsWithDelta(6.0, $total, 0.0001);
    }

    /**
     * A real configuration service, so the reading of the extension
     * configuration is what is under test rather than a mock of it. The
     * subscription service is final and cannot be doubled; nothing here asks it
     * anything.
     */
    private function realConfiguration(): ConfigurationService
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

    private function configuration(string $rate, string $currency): ConfigurationService
    {
        $configuration = $this->createMock(ConfigurationService::class);
        $configuration->method('getAssistantUsdConversionRate')->willReturn((float)$rate);
        $configuration->method('getAssistantCurrency')->willReturn($currency !== '' ? $currency : 'CHF');

        return $configuration;
    }
}
