<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Agent\AgentAnalyticsBuilder;
use WebNomads\WnAiBridge\Agent\AgentDetector;
use WebNomads\WnAiBridge\Agent\AgentSettings;
use WebNomads\WnAiBridge\Agent\BotVerifier;
use WebNomads\WnAiBridge\Agent\LogLineParser;
use WebNomads\WnAiBridge\Agent\VisitLogger;
use WebNomads\WnAiBridge\Controller\Backend\AgentAnalyticsModuleController;

final class AgentAnalyticsTest extends TestCase
{
    #[Test]
    public function crawlersAreRecognisedByTheirUserAgent(): void
    {
        $detector = new AgentDetector();

        self::assertSame(['token' => 'GPTBot', 'operator' => 'OpenAI', 'purpose' => 'training'], $detector->crawler('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; +https://openai.com/gptbot'));
        self::assertSame('ChatGPT-User', $detector->crawler('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot')['token'] ?? '');
        self::assertSame('Claude-User', $detector->crawler('Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)')['token'] ?? '');
        self::assertSame('Bingbot', $detector->crawler('Mozilla/5.0 (compatible; bingbot/2.0)')['token'] ?? '');
        self::assertSame('NewAiBot', $detector->crawler('Mozilla/5.0 NewAiBot/1.0', ['NewAiBot'])['token'] ?? '');
        self::assertNull($detector->crawler('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'));
        self::assertNull($detector->crawler('Mozilla/5.0 (compatible; AhrefsBot/7.0)'));
        self::assertNull($detector->crawler(''));
    }

    #[Test]
    public function platformsAreRecognisedByReferrerOrUtmSource(): void
    {
        $detector = new AgentDetector();

        self::assertSame('ChatGPT', $detector->platform('https://chatgpt.com/'));
        self::assertSame('Perplexity', $detector->platform('https://www.perplexity.ai/search?q=x'));
        self::assertSame('Gemini', $detector->platform('https://gemini.google.com/app'));
        self::assertSame('Copilot', $detector->platform('https://www.bing.com/chat?q=x'));
        self::assertSame('', $detector->platform('https://www.bing.com/search?q=x'));
        self::assertSame('Claude', $detector->platform('', 'claude.ai'));
        self::assertSame('', $detector->platform('https://www.google.com/'));
    }

    #[Test]
    public function verificationNeedsMatchingReverseAndForwardDns(): void
    {
        $verifier = new BotVerifier(
            static fn(string $ip): string => ['66.249.66.1' => 'crawl-66-249-66-1.googlebot.com', '1.2.3.4' => 'evil.example.com', '5.6.7.8' => 'fake.googlebot.com'][$ip] ?? $ip,
            static fn(string $host): array => ['crawl-66-249-66-1.googlebot.com' => ['66.249.66.1'], 'fake.googlebot.com' => ['9.9.9.9']][$host] ?? [],
        );

        self::assertSame(BotVerifier::VERIFIED, $verifier->verify('Googlebot', '66.249.66.1'));
        self::assertSame(BotVerifier::NOT_VERIFIED, $verifier->verify('Googlebot', '1.2.3.4'));
        self::assertSame(BotVerifier::NOT_VERIFIED, $verifier->verify('Googlebot', '5.6.7.8'));
        self::assertSame(BotVerifier::UNKNOWN, $verifier->verify('GPTBot', '66.249.66.1'));
    }

    #[Test]
    public function combinedLogLinesAreParsed(): void
    {
        $parser = new LogLineParser();

        $line = $parser->parse('66.249.66.1 - - [02/Oct/2026:10:12:01 +0200] "GET /llms.txt HTTP/1.1" 200 5120 "-" "Mozilla/5.0 (compatible; Googlebot/2.1)"');
        self::assertNotNull($line);
        self::assertSame('', $line['host']);
        self::assertSame('/llms.txt', $line['path']);
        self::assertSame(200, $line['status']);
        self::assertSame((new \DateTimeImmutable('2026-10-02 10:12:01 +0200'))->getTimestamp(), $line['time']);

        $vhost = $parser->parse('www.example.ch:443 85.1.2.3 - - [02/Oct/2026:10:12:01 +0000] "GET / HTTP/2.0" 301 0 "https://chatgpt.com/" "Firefox"');
        self::assertNotNull($vhost);
        self::assertSame('www.example.ch', $vhost['host']);
        self::assertSame('https://chatgpt.com/', $vhost['referrer']);
        self::assertNull($parser->parse('garbage'));
    }

    #[Test]
    public function pathsKeepTheQueryOnlyForCrawlersAndIpsAreHashed(): void
    {
        self::assertSame('/a?b=1', VisitLogger::path('/a?b=1#c', true));
        self::assertSame('/a', VisitLogger::path('/a?email=x@y.ch', false));
        self::assertSame('/', VisitLogger::path('', false));
        self::assertSame(16, strlen(VisitLogger::hashIp('1.2.3.4')));
        self::assertNotSame(VisitLogger::hashIp('1.2.3.4'), VisitLogger::hashIp('1.2.3.5'));
        self::assertSame('', VisitLogger::hashIp(''));
    }

    #[Test]
    public function crawlerRowsCountVisitsPerTypePagesAndTheMedianRecrawlInterval(): void
    {
        $day = 86400;
        $visit = static fn(int $time, string $path, string $type, string $agent, int $verified = 0): array => ['time' => $time, 'path' => $path, 'type' => $type, 'agent' => $agent, 'purpose' => 'training', 'verified' => $verified];
        $visits = [
            $visit(0, '/llms.txt', 'llmstxt', 'ClaudeBot'),
            $visit(2 * $day, '/llms.txt', 'llmstxt', 'ClaudeBot'),
            $visit(6 * $day, '/llms.txt', 'llmstxt', 'ClaudeBot', BotVerifier::VERIFIED),
            $visit(7 * $day, '/a.md', 'markdown', 'ClaudeBot'),
            $visit($day, '/b', 'page', 'GPTBot', BotVerifier::NOT_VERIFIED),
        ];

        $rows = AgentAnalyticsBuilder::crawlers($visits);

        self::assertSame('ClaudeBot', $rows[0]['agent']);
        self::assertSame(4, $rows[0]['visits']);
        self::assertSame(['llmstxt' => 3, 'llmsfull' => 0, 'markdown' => 1, 'page' => 0], $rows[0]['types']);
        self::assertSame(2, $rows[0]['pages']);
        // intervals of 2 and 4 days on /llms.txt
        self::assertSame(72.0, $rows[0]['recrawlHours']);
        self::assertSame(1, $rows[0]['verified']);
        self::assertNull($rows[1]['recrawlHours']);
        self::assertSame(1, $rows[1]['notVerified']);

        $pages = AgentAnalyticsBuilder::topPages($visits);
        self::assertSame(['path' => '/llms.txt', 'type' => 'llmstxt', 'visits' => 3, 'last' => 6 * $day, 'agents' => ['ClaudeBot' => 3]], $pages[0]);
    }

    #[Test]
    public function seriesCountVisitsPerAgentAndDay(): void
    {
        $buckets = AgentAnalyticsBuilder::buckets(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'), AgentAnalyticsBuilder::DAY);
        $visits = [
            ['time' => (new \DateTimeImmutable('2026-10-01 10:00'))->getTimestamp(), 'agent' => 'GPTBot'],
            ['time' => (new \DateTimeImmutable('2026-10-03 10:00'))->getTimestamp(), 'agent' => 'GPTBot'],
            ['time' => (new \DateTimeImmutable('2026-10-03 11:00'))->getTimestamp(), 'agent' => 'ClaudeBot'],
        ];

        $series = AgentAnalyticsBuilder::series($visits, $buckets, AgentAnalyticsBuilder::DAY);

        self::assertSame(['01.10.', '02.10.', '03.10.'], $series['labels']);
        self::assertSame(['GPTBot', 'ClaudeBot'], array_column($series['series'], 'label'));
        self::assertSame([1, 0, 1], $series['series'][0]['values']);
        self::assertSame([0, 0, 1], $series['series'][1]['values']);
    }

    #[Test]
    public function siteSwitchesOverrideTheExtensionConfiguration(): void
    {
        $settings = new AgentSettings(null, ['agentAnalytics' => '1', 'agentReferrals' => '0', 'agentExtraBots' => 'OneBot, x', 'agentRetentionDays' => '30']);

        self::assertTrue($settings->analytics());
        self::assertFalse($settings->analytics([AgentSettings::SITE_ANALYTICS => '0']));
        self::assertTrue($settings->analytics([AgentSettings::SITE_ANALYTICS => '']));
        self::assertFalse($settings->referrals());
        self::assertTrue($settings->referrals([AgentSettings::SITE_REFERRALS => '1']));
        self::assertFalse($settings->verifyIp());
        self::assertSame(['OneBot', 'TwoBot'], $settings->extraBots([AgentSettings::SITE_EXTRA_BOTS => 'TwoBot']));
        self::assertSame(30, $settings->retentionDays());

        $defaults = new AgentSettings(null, []);
        self::assertTrue($defaults->analytics());
        self::assertTrue($defaults->referrals());
        self::assertSame(90, $defaults->retentionDays());
    }

    #[Test]
    public function thePeriodIsAPresetOrACustomRangeUpToToday(): void
    {
        $today = new \DateTimeImmutable('2026-10-07');

        [$period, $start, $end] = AgentAnalyticsModuleController::period([], $today);
        self::assertSame(['30', '2026-09-08', '2026-10-07'], [$period, $start->format('Y-m-d'), $end->format('Y-m-d')]);

        [$period, $start] = AgentAnalyticsModuleController::period(['period' => '7'], $today);
        self::assertSame(['7', '2026-10-01'], [$period, $start->format('Y-m-d')]);

        [$period, $start, $end] = AgentAnalyticsModuleController::period(['period' => 'custom', 'dateFrom' => '2026-10-05', 'dateTo' => '2026-12-31'], $today);
        self::assertSame(['custom', '2026-10-05', '2026-10-07'], [$period, $start->format('Y-m-d'), $end->format('Y-m-d')]);

        [$period] = AgentAnalyticsModuleController::period(['period' => '13'], $today);
        self::assertSame('30', $period);
    }

    #[Test]
    public function theFilterFormCarriesTheArgumentsOfTheModuleUrl(): void
    {
        self::assertSame(['token' => 'abc', 'site' => 'main'], AgentAnalyticsModuleController::queryArguments('/typo3/module/wn-ai-bridge/agents?token=abc&site=main&x[]=1'));
        self::assertSame([], AgentAnalyticsModuleController::queryArguments('/typo3/module/wn-ai-bridge/agents'));
    }

    #[Test]
    public function csvUsesSemicolonsABomAndNeutralisesFormulas(): void
    {
        $csv = AgentAnalyticsModuleController::csv([['page', 'visits'], ['=HYPERLINK("x")', 3], ['-1', 2]]);

        self::assertStringStartsWith("\xEF\xBB\xBFpage;visits\n", $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringContainsString("-1;2\n", $csv);
    }
}
