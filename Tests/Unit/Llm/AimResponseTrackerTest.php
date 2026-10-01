<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Llm;

use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Event\AfterAiResponseEvent;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use WebNomads\WnAiBridge\Llm\AimResponseTracker;

/**
 * AiM's response names the model and the cost, but not the provider and the
 * currency; those are read off the configuration the response event reports.
 */
final class AimResponseTrackerTest extends TestCase
{
    #[Test]
    public function theConfigurationThatAnsweredIsRememberedPerResponse(): void
    {
        $tracker = new AimResponseTracker();
        $first = new TextResponse('a');
        $second = new TextResponse('b');

        $tracker($this->event($first, 'anthropic', 'CHF'));
        $tracker($this->event($second, 'openai', 'USD'));

        $firstConfiguration = $tracker->configurationFor($first);
        $secondConfiguration = $tracker->configurationFor($second);

        self::assertNotNull($firstConfiguration);
        self::assertNotNull($secondConfiguration);
        self::assertSame('anthropic', $firstConfiguration->providerIdentifier);
        self::assertSame('CHF', $firstConfiguration->costCurrency);
        self::assertSame('openai', $secondConfiguration->providerIdentifier);
    }

    #[Test]
    public function aResponseTheEventNeverSawFallsBackToTheLastConfiguration(): void
    {
        $tracker = new AimResponseTracker();
        $tracker($this->event(new TextResponse('a'), 'anthropic', 'EUR'));

        self::assertSame('EUR', $tracker->configurationFor(new TextResponse('other'))?->costCurrency);
    }

    #[Test]
    public function withoutAnyEventNothingIsKnown(): void
    {
        self::assertNull((new AimResponseTracker())->configurationFor(new TextResponse('a')));
    }

    private function event(TextResponse $response, string $provider, string $currency): AfterAiResponseEvent
    {
        return new AfterAiResponseEvent(
            $response,
            $this->createMock(AiRequestInterface::class),
            new AiProviderManifest($provider, $provider, '', '', [], [], $provider, $this->createMock(ContainerInterface::class)),
            new ProviderConfiguration(['uid' => 1, 'ai_provider' => $provider, 'model' => 'm', 'cost_currency' => $currency]),
        );
    }
}
