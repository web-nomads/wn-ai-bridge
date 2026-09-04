<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Domain\Model\BotAccessFilter;

/**
 * Reading the "Bot Access Log" filter form, and echoing it back.
 *
 * The site is what this was extended for: on an installation serving several
 * websites the log is one list of everything, and "which crawlers visit this
 * site" cannot be answered from it. Every link the module builds — pagination,
 * the AJAX reload — has to carry the chosen site, or the list silently reverts
 * to all of them on the second page.
 */
final class BotAccessFilterTest extends TestCase
{
    #[Test]
    public function theChosenSiteIsRead(): void
    {
        self::assertSame('web-nomads', BotAccessFilter::fromQueryParams(['site' => ' web-nomads '])->site);
    }

    #[Test]
    public function noSiteMeansAllOfThem(): void
    {
        self::assertSame('', BotAccessFilter::fromQueryParams([])->site);
        self::assertSame('', BotAccessFilter::fromQueryParams(['site' => '   '])->site);
    }

    #[Test]
    public function theChosenSiteSurvivesIntoEveryLinkTheModuleBuilds(): void
    {
        $values = BotAccessFilter::fromQueryParams([
            'site' => 'web-nomads',
            'requestType' => 'llmstxt',
            'aiOnly' => '1',
        ])->toFormValues();

        self::assertSame('web-nomads', $values['site']);
        self::assertSame('llmstxt', $values['requestType']);
        self::assertSame('1', $values['aiOnly']);
    }

    #[Test]
    public function theOtherCriteriaAreUnaffected(): void
    {
        $filter = BotAccessFilter::fromQueryParams([
            'dateFrom' => '2026-01-01',
            'dateTo' => '2026-01-31',
            'botName' => 'GPTBot',
            'ip' => '10.0.0.1',
            'search' => 'llms',
            'page' => '3',
        ]);

        self::assertSame('GPTBot', $filter->botName);
        self::assertSame('10.0.0.1', $filter->ip);
        self::assertSame('llms', $filter->search);
        self::assertSame(3, $filter->page);
        self::assertSame(100, $filter->getOffset());
        self::assertNotNull($filter->dateFrom);
        self::assertNotNull($filter->dateTo);
    }
}
