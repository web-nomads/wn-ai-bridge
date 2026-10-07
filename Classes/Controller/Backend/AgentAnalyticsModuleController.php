<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use WebNomads\WnAiBridge\Agent\AgentAnalyticsBuilder;
use WebNomads\WnAiBridge\Agent\AgentSettings;
use WebNomads\WnAiBridge\Agent\VisitRepository;
use WebNomads\WnAiBridge\Backend\ModuleDocHeader;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\SiteListService;

/**
 * Which AI crawlers read llms.txt, the Markdown versions and the pages, and which AI platforms send visitors (module "Agent Analytics")
 */
final class AgentAnalyticsModuleController
{
    private const MODULE_NAME = 'wn_ai_bridge_agents';
    public const PERIODS = ['7', '30', '90', '365'];
    private const DEFAULT_PERIOD = '30';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BackendUriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly AgentAnalyticsBuilder $builder,
        private readonly VisitRepository $visits,
        private readonly AgentSettings $settings,
        private readonly ConfigurationService $configurationService,
        private readonly SiteListService $siteListService,
        private readonly SiteFinder $siteFinder,
        private readonly ViewFactoryInterface $viewFactory,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        if ($request->getMethod() === 'POST' && is_array($parsedBody) && ($parsedBody['action'] ?? '') === 'deleteAll') {
            $this->visits->deleteAll();

            return new RedirectResponse($this->moduleUrl());
        }

        $query = $request->getQueryParams();
        $sites = $this->siteListService->getFilterOptions();
        $site = is_string($query['site'] ?? null) && isset($sites[$query['site']]) ? $query['site'] : '';
        [$period, $start, $end] = self::period($query);
        $granularity = ($query['granularity'] ?? '') === AgentAnalyticsBuilder::WEEK ? AgentAnalyticsBuilder::WEEK : AgentAnalyticsBuilder::DAY;
        $filter = [
            'site' => $site,
            'period' => $period,
            'dateFrom' => $start->format('Y-m-d'),
            'dateTo' => $end->format('Y-m-d'),
        ];

        $tableError = false;
        $data = ['hasData' => false];
        try {
            $data = $this->builder->build($start, $end, $site, $granularity);
        } catch (\Throwable $e) {
            $tableError = true;
        }
        if (($query['action'] ?? '') === 'csv' && $data['hasData']) {
            return $this->csvResponse($data);
        }

        $siteConfiguration = $this->siteConfiguration($site, $sites);
        $variables = $data + [
            // for information only: this module needs no subscription
            'subscription' => $this->configurationService->getSubscriptionStatus(),
            'tableError' => $tableError,
            'logging' => $this->settings->analytics($siteConfiguration),
            'referralLogging' => $this->settings->referrals($siteConfiguration),
            'verifying' => $this->settings->verifyIp($siteConfiguration),
            'retention' => $this->settings->retentionDays(),
            'llmsFullEnabled' => $this->configurationService->isLlmsFullTxtEnabled(),
            'sites' => $sites,
            'periods' => self::PERIODS,
            'filter' => $filter,
            'granularity' => $granularity,
            'moduleUrl' => $this->moduleUrl(),
            // GET forms drop the query of their action, the token must come along
            'moduleArguments' => self::queryArguments($this->moduleUrl()),
            'dayUrl' => $this->moduleUrl($filter + ['granularity' => AgentAnalyticsBuilder::DAY]),
            'weekUrl' => $this->moduleUrl($filter + ['granularity' => AgentAnalyticsBuilder::WEEK]),
            'csvUrl' => $this->moduleUrl($filter + ['action' => 'csv']),
            // normalised filter, written back into the form after an AJAX request
            'filterState' => (string)json_encode($filter + ['granularity' => $granularity], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ];

        // AJAX filter request: only the results, the module around them stays
        if (($query['ajax'] ?? '') === '1') {
            $view = $this->viewFactory->create(new ViewFactoryData(
                templateRootPaths: ['EXT:wn_ai_bridge/Resources/Private/Templates/'],
                partialRootPaths: ['EXT:wn_ai_bridge/Resources/Private/Partials/'],
                request: $request,
            ));
            $view->assignMultiple($variables);

            return new HtmlResponse($view->render('AgentAnalytics/Results'));
        }

        $this->pageRenderer->addCssFile('EXT:wn_ai_bridge/Resources/Public/Css/backend.css');
        $this->pageRenderer->loadJavaScriptModule('@webnomads/wn-ai-bridge/backend-filter.js');
        $this->pageRenderer->loadJavaScriptModule('@webnomads/wn-ai-bridge/agent-charts.js');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        ModuleDocHeader::withoutButtonBar($moduleTemplate);
        $moduleTemplate->setTitle('Agent Analytics');
        $moduleTemplate->assignMultiple($variables);

        return $moduleTemplate->renderResponse('AgentAnalytics/Index');
    }

    /**
     * Preset of the last n days or a custom range; never in the future, start before end
     *
     * @param array<string, mixed> $query
     * @return array{0: string, 1: \DateTimeImmutable, 2: \DateTimeImmutable}
     */
    public static function period(array $query, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $period = is_string($query['period'] ?? null) ? $query['period'] : self::DEFAULT_PERIOD;
        if ($period === 'custom') {
            $from = self::date($query['dateFrom'] ?? null);
            $to = self::date($query['dateTo'] ?? null) ?? $today;
            $to = min($to, $today);
            if ($from !== null) {
                return ['custom', min($from, $to), max($from, $to)];
            }
            $period = self::DEFAULT_PERIOD;
        }
        if (!in_array($period, self::PERIODS, true)) {
            $period = self::DEFAULT_PERIOD;
        }

        return [$period, $today->modify('-' . ((int)$period - 1) . ' days'), $today];
    }

    /**
     * Scalar query arguments of a URL, e.g. the backend token
     *
     * @return array<string, string>
     */
    public static function queryArguments(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $arguments);

        return array_map('strval', array_filter($arguments, 'is_scalar'));
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }

    /**
     * Configuration of the selected site, or of the only one; empty = extension configuration
     *
     * @param array<string, string> $sites
     * @return array<string, mixed>
     */
    private function siteConfiguration(string $site, array $sites): array
    {
        try {
            if ($site !== '') {
                return $this->siteFinder->getSiteByIdentifier($site)->getConfiguration();
            }
            $all = $this->siteFinder->getAllSites();

            return $sites === [] && count($all) === 1 ? reset($all)->getConfiguration() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function csvResponse(array $data): ResponseInterface
    {
        $rows = [['crawler', 'purpose', 'visits', 'llms_txt', 'llms_full_txt', 'markdown', 'pages_html', 'distinct_pages', 'last_visit', 'recrawl_hours', 'verified', 'not_verified']];
        foreach ($data['crawlers'] as $crawler) {
            $rows[] = [
                $crawler['agent'], $crawler['purpose'], $crawler['visits'],
                $crawler['types']['llmstxt'], $crawler['types']['llmsfull'], $crawler['types']['markdown'], $crawler['types']['page'],
                $crawler['pages'], date('Y-m-d H:i', $crawler['last']), $crawler['recrawlHours'], $crawler['verified'], $crawler['notVerified'],
            ];
        }
        $rows[] = [];
        $rows[] = ['page', 'crawler_visits', 'last_visit', 'crawlers'];
        foreach ($data['topPages'] as $page) {
            $rows[] = [$page['path'], $page['visits'], date('Y-m-d H:i', $page['last']), implode(', ', array_keys($page['agents']))];
        }
        $rows[] = [];
        $rows[] = ['platform', 'referred_visits'];
        foreach ($data['platforms'] as $platform) {
            $rows[] = [$platform['platform'], $platform['visits']];
        }
        $rows[] = [];
        $rows[] = ['landing_page', 'referred_visits', 'platforms'];
        foreach ($data['landingPages'] as $page) {
            $rows[] = [$page['path'], $page['visits'], implode(', ', array_keys($page['agents']))];
        }

        $response = new Response();
        $response->getBody()->write(self::csv($rows));

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="ai-bridge-agents-' . date('Y-m-d') . '.csv"');
    }

    /**
     * Semicolons, UTF-8 with BOM, formulas neutralised
     *
     * @param list<list<string|int|float|null>> $rows
     */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static function (string|int|float|null $value): string {
                $value = (string)$value;

                return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($value) ? "'" . $value : $value;
            }, $row), ';', '"', '');
        }
        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function moduleUrl(array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, array_filter($parameters, static fn(string $value): bool => $value !== ''));
    }
}
