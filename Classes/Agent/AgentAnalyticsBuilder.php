<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Agent;

/**
 * Crawler and referral visits of a period, prepared for the module "Agent Analytics"
 */
final class AgentAnalyticsBuilder
{
    public const DAY = 'day';
    public const WEEK = 'week';

    public const TOP_AGENTS_IN_CHART = 8;
    public const TOP_PAGES = 50;

    public const OWN_COLOR = '#2f6fde';
    public const PALETTE = ['#e8590c', '#2b8a3e', '#ae3ec9', '#f08c00', '#0c8599', '#c92a2a', '#5f3dc4', '#868e96', '#d6336c', '#1971c2'];

    public function __construct(
        private readonly VisitRepository $visits,
    ) {}

    /**
     * @param string $site site identifier, empty = all sites
     * @return array<string, mixed>
     */
    public function build(\DateTimeImmutable $start, \DateTimeImmutable $end, string $site = '', string $granularity = self::DAY): array
    {
        $visits = $this->visits->findInPeriod($start->getTimestamp(), $end->setTime(23, 59, 59)->getTimestamp(), $site);
        $crawlerVisits = array_values(array_filter($visits, static fn(array $v): bool => $v['kind'] === AgentDetector::CRAWLER));
        $referrals = array_values(array_filter($visits, static fn(array $v): bool => $v['kind'] === AgentDetector::REFERRAL));
        $buckets = self::buckets($start, $end, $granularity);

        return [
            'hasData' => $visits !== [],
            'totals' => [
                'crawlerVisits' => count($crawlerVisits),
                'crawlers' => count(array_unique(array_column($crawlerVisits, 'agent'))),
                'pages' => count(array_unique(array_map(self::page(...), array_column($crawlerVisits, 'path')))),
                'types' => self::typeCounts($crawlerVisits),
                'referrals' => count($referrals),
                'platforms' => count(array_unique(array_column($referrals, 'agent'))),
            ],
            'crawlers' => self::crawlers($crawlerVisits),
            'topPages' => self::topPages($crawlerVisits),
            'platforms' => self::platforms($referrals),
            'landingPages' => self::topPages($referrals),
            'crawlerSeries' => self::json(self::series($crawlerVisits, $buckets, $granularity)),
            'referralSeries' => self::json(self::series($referrals, $buckets, $granularity)),
        ];
    }

    /**
     * One row per crawler: visits per request type, pages, last visit, verified share and median recrawl interval
     *
     * @param list<array{time: int, path: string, type: string, agent: string, purpose: string, verified: int}> $visits
     * @return list<array{agent: string, purpose: string, visits: int, types: array<string, int>, pages: int, last: int, recrawlHours: float|null, verified: int, notVerified: int}>
     */
    public static function crawlers(array $visits): array
    {
        $byAgent = [];
        foreach ($visits as $visit) {
            $byAgent[$visit['agent']][] = $visit;
        }
        $rows = [];
        foreach ($byAgent as $agent => $list) {
            $byPath = [];
            foreach ($list as $visit) {
                $byPath[self::page($visit['path'])][] = $visit['time'];
            }
            $intervals = [];
            foreach ($byPath as $times) {
                sort($times);
                for ($i = 1, $n = count($times); $i < $n; $i++) {
                    $intervals[] = $times[$i] - $times[$i - 1];
                }
            }
            $rows[] = [
                'agent' => (string)$agent,
                'purpose' => $list[0]['purpose'],
                'visits' => count($list),
                'types' => self::typeCounts($list),
                'pages' => count($byPath),
                'last' => max(array_column($list, 'time')),
                'recrawlHours' => $intervals === [] ? null : round(self::median($intervals) / 3600, 1),
                'verified' => count(array_filter($list, static fn(array $v): bool => $v['verified'] === BotVerifier::VERIFIED)),
                'notVerified' => count(array_filter($list, static fn(array $v): bool => $v['verified'] === BotVerifier::NOT_VERIFIED)),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$b['visits'], $a['agent']] <=> [$a['visits'], $b['agent']]);

        return $rows;
    }

    /**
     * Most visited paths with the agents visiting them
     *
     * @param list<array{time: int, path: string, type: string, agent: string}> $visits
     * @return list<array{path: string, type: string, visits: int, last: int, agents: array<string, int>}>
     */
    public static function topPages(array $visits, int $limit = self::TOP_PAGES): array
    {
        $pages = [];
        foreach ($visits as $visit) {
            $path = self::page($visit['path']);
            $pages[$path] ??= ['path' => $path, 'type' => $visit['type'], 'visits' => 0, 'last' => 0, 'agents' => []];
            $pages[$path]['visits']++;
            $pages[$path]['last'] = max($pages[$path]['last'], $visit['time']);
            $pages[$path]['agents'][$visit['agent']] = ($pages[$path]['agents'][$visit['agent']] ?? 0) + 1;
        }
        foreach ($pages as $path => $page) {
            arsort($page['agents']);
            $pages[$path]['agents'] = $page['agents'];
        }
        usort($pages, static fn(array $a, array $b): int => [$b['visits'], $a['path']] <=> [$a['visits'], $b['path']]);

        return array_slice($pages, 0, $limit);
    }

    /**
     * @param list<array{agent: string}> $visits
     * @return list<array{platform: string, visits: int}>
     */
    public static function platforms(array $visits): array
    {
        $counts = array_count_values(array_column($visits, 'agent'));
        arsort($counts);

        return array_map(static fn(string|int $platform, int $count): array => ['platform' => (string)$platform, 'visits' => $count], array_keys($counts), $counts);
    }

    /**
     * Bucket key => label, from the first to the last day
     *
     * @return array<string, string>
     */
    public static function buckets(\DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity): array
    {
        $buckets = [];
        $day = $start->setTime(0, 0);
        $last = $end->setTime(0, 0);
        while ($day <= $last) {
            $buckets[self::key($day, $granularity)] ??= $granularity === self::WEEK ? 'W' . $day->format('W') : $day->format('d.m.');
            $day = $day->modify('+1 day');
        }

        return $buckets;
    }

    /**
     * Visits per agent and bucket for the chart; the biggest agents get own lines
     *
     * @param list<array{time: int, agent: string}> $visits
     * @param array<string, string> $buckets key => label
     * @return array{labels: list<string>, series: list<array{label: string, values: list<int>, color: string}>}
     */
    public static function series(array $visits, array $buckets, string $granularity): array
    {
        $counts = array_count_values(array_column($visits, 'agent'));
        arsort($counts);
        $agents = array_map('strval', array_slice(array_keys($counts), 0, self::TOP_AGENTS_IN_CHART));
        $values = [];
        foreach ($agents as $agent) {
            $values[$agent] = array_fill_keys(array_keys($buckets), 0);
        }
        $timezone = new \DateTimeZone(date_default_timezone_get());
        foreach ($visits as $visit) {
            if (!isset($values[$visit['agent']])) {
                continue;
            }
            $key = self::key((new \DateTimeImmutable('@' . $visit['time']))->setTimezone($timezone), $granularity);
            if (isset($values[$visit['agent']][$key])) {
                $values[$visit['agent']][$key]++;
            }
        }
        $series = [];
        foreach ($agents as $index => $agent) {
            $series[] = ['label' => $agent, 'values' => array_values($values[$agent]), 'color' => $index === 0 ? self::OWN_COLOR : self::PALETTE[($index - 1) % count(self::PALETTE)]];
        }

        return ['labels' => array_values($buckets), 'series' => $series];
    }

    /**
     * @param list<array{type: string}> $visits
     * @return array<string, int>
     */
    private static function typeCounts(array $visits): array
    {
        $counts = array_fill_keys(VisitLogger::TYPES, 0);
        foreach ($visits as $visit) {
            if (isset($counts[$visit['type']])) {
                $counts[$visit['type']]++;
            }
        }

        return $counts;
    }

    private static function key(\DateTimeImmutable $date, string $granularity): string
    {
        return $granularity === self::WEEK ? $date->format('o-\WW') : $date->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    private static function page(string $path): string
    {
        return (string)strtok($path, '?');
    }

    /**
     * @param list<int> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float)$values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
