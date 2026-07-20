<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use WebNomads\WnAiBridge\Domain\Model\AssistantLogEntry;
use WebNomads\WnAiBridge\Domain\Model\LogFilter;
use WebNomads\WnAiBridge\Domain\Repository\AssistantLogRepository;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\CostCalculator;
use WebNomads\WnAiBridge\Service\VisitorInfoService;

/**
 * Backend module: a filterable list of logged assistant questions/answers with
 * a provider and token-usage overview.
 */
final class LogModuleController
{
    private const MODULE_NAME = 'wn_ai_bridge_log';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AssistantLogRepository $repository,
        private readonly ConfigurationService $configurationService,
        private readonly BackendUriBuilder $uriBuilder,
        private readonly VisitorInfoService $visitorInfoService,
        private readonly CostCalculator $costCalculator,
        private readonly ViewFactoryInterface $viewFactory,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $params = array_merge($request->getQueryParams(), is_array($parsedBody) ? $parsedBody : []);

        // Destructive "clear log" action (POST only).
        if ($request->getMethod() === 'POST' && ($params['action'] ?? '') === 'deleteAll') {
            $this->repository->deleteAll();
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME));
        }

        $filter = LogFilter::fromQueryParams($params);

        $tableError = false;
        $threads = [];
        $totalThreads = 0;
        $stats = $this->emptyStatistics();
        $providers = [];
        $totalCostChf = $this->costCalculator->format(0.0);
        try {
            $stats = $this->repository->getStatistics($filter);
            $providers = $this->repository->findDistinctProviders();
            $totalThreads = $this->repository->countThreads($filter);
            $totalCostChf = $this->costCalculator->format(
                $this->costCalculator->totalCostChf($this->repository->getModelTokenTotals($filter))
            );
            $conversationIds = $this->repository->findThreadConversationIds($filter);
            $threads = $this->buildThreads($conversationIds);
        } catch (\Throwable $e) {
            // Most likely the database schema has not been updated yet.
            $tableError = true;
        }

        $variables = [
            'loggingEnabled' => $this->configurationService->isAssistantLoggingEnabled(),
            'tableError' => $tableError,
            'threads' => $threads,
            'total' => $totalThreads,
            'stats' => $stats,
            'providers' => $providers,
            'totalCostChf' => $totalCostChf,
            'filter' => $filter->toFormValues(),
            'pagination' => $this->buildPagination($filter, $totalThreads),
            'moduleUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ];

        // AJAX filter request: return just the filter-dependent results fragment
        // so the module (and its doc-header) is never re-rendered.
        if (($params['ajax'] ?? '') === '1') {
            return $this->renderResultsFragment($request, $variables);
        }

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('EXT:wn_ai_bridge/Resources/Public/Css/backend.css');
        $pageRenderer->loadJavaScriptModule('@webnomads/wn-ai-bridge/backend-filter.js');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('AI Assistant Log');
        $moduleTemplate->assignMultiple($variables);

        return $moduleTemplate->renderResponse('Log/Index');
    }

    /**
     * Render only the filter-dependent results fragment (statistics, thread
     * list and pagination) as a bare HTML response for AJAX requests.
     *
     * @param array<string, mixed> $variables
     */
    private function renderResultsFragment(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:wn_ai_bridge/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:wn_ai_bridge/Resources/Private/Partials/'],
            request: $request,
        ));
        $view->assignMultiple($variables);

        return new HtmlResponse($view->render('Log/Results'));
    }

    /**
     * Assemble display-ready threads for the given (page-ordered) conversation
     * ids: each thread carries its first turn (representative), all turns and
     * the aggregated token usage.
     *
     * @param list<string> $conversationIds
     * @return list<array<string, mixed>>
     */
    private function buildThreads(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $byConversation = [];
        foreach ($this->repository->findByConversations($conversationIds) as $entry) {
            $byConversation[$entry->conversationId][] = $entry;
        }

        $threads = [];
        foreach ($conversationIds as $conversationId) {
            $entries = $byConversation[$conversationId] ?? [];
            if ($entries === []) {
                continue;
            }

            $inputTokens = 0;
            $outputTokens = 0;
            $totalTokens = 0;
            $costChf = 0.0;
            $turns = [];
            foreach ($entries as $entry) {
                $inputTokens += $entry->inputTokens;
                $outputTokens += $entry->outputTokens;
                $totalTokens += $entry->totalTokens;

                $turnCost = $this->costCalculator->costChf($entry->model, $entry->inputTokens, $entry->outputTokens);
                $costChf += $turnCost;

                // Wrap each turn so the template can show its per-turn cost and
                // the answer with the "[n]" citations turned into real links.
                $turns[] = [
                    'entry' => $entry,
                    'costChf' => $this->costCalculator->format($turnCost),
                    'answerHtml' => $this->renderAnswerHtml($entry->answer, $entry->sources),
                ];
            }

            $threads[] = [
                'conversationId' => $conversationId,
                'first' => $entries[0],
                'turns' => $turns,
                'turnCount' => count($turns),
                'inputTokens' => $inputTokens,
                'outputTokens' => $outputTokens,
                'totalTokens' => $totalTokens,
                'costChf' => $this->costCalculator->format($costChf),
                'visitor' => $this->visitorInfoService->resolve($entries[0]->ipAddress),
            ];
        }

        return $threads;
    }

    /**
     * Render the logged answer as safe HTML for the backend: escape everything,
     * then turn the "[n]" citation markers (and an optional bold page title the
     * model wrote right before one) into links pointing at the suggested page,
     * with the page title as the link text. Mirrors the frontend widget so the
     * log shows the same clean links instead of raw "[1]" markers.
     *
     * @param list<array{title: string, url: string}> $sources
     */
    private function renderAnswerHtml(string $answer, array $sources): string
    {
        $html = htmlspecialchars($answer, ENT_QUOTES, 'UTF-8');

        $link = static function (int $number) use ($sources): ?string {
            $source = $sources[$number - 1] ?? null;
            if ($source === null || ($source['url'] ?? '') === '') {
                return null;
            }
            $title = $source['title'] !== '' ? $source['title'] : $source['url'];

            return '<a href="' . htmlspecialchars($source['url'], ENT_QUOTES, 'UTF-8')
                . '" target="_blank" rel="noopener noreferrer">'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a>';
        };

        // "**Title** [n]" — replace the whole thing with the linked page title.
        $html = preg_replace_callback('/\*\*([^*]+)\*\*\s*\[(\d+)\]/', static function (array $m) use ($link): string {
            return $link((int)$m[2]) ?? $m[0];
        }, $html) ?? $html;

        // Any remaining bare "[n]" marker → the linked page title.
        $html = preg_replace_callback('/\[(\d+)\]/', static function (array $m) use ($link): string {
            return $link((int)$m[1]) ?? $m[0];
        }, $html) ?? $html;

        // Remaining **bold** not tied to a citation.
        $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html) ?? $html;

        return nl2br($html);
    }

    /**
     * Build a compact pagination model (windowed page links) preserving the
     * active filter in every link.
     *
     * @return array{current: int, totalPages: int, perPage: int, pages: list<array{num: int, url: string, active: bool}>}
     */
    private function buildPagination(LogFilter $filter, int $total): array
    {
        $totalPages = (int)max(1, ceil($total / $filter->perPage));
        $current = min($filter->page, $totalPages);

        $baseParams = array_filter(
            $filter->toFormValues(),
            static fn(string $value): bool => $value !== ''
        );

        $window = 3;
        $start = max(1, $current - $window);
        $end = min($totalPages, $current + $window);

        $pages = [];
        for ($p = $start; $p <= $end; $p++) {
            $pages[] = [
                'num' => $p,
                'url' => (string)$this->uriBuilder->buildUriFromRoute(
                    self::MODULE_NAME,
                    array_merge($baseParams, ['page' => $p])
                ),
                'active' => $p === $current,
            ];
        }

        return [
            'current' => $current,
            'totalPages' => $totalPages,
            'perPage' => $filter->perPage,
            'pages' => $pages,
        ];
    }

    /**
     * @return array{total: int, llm: int, search: int, inputTokens: int, outputTokens: int, totalTokens: int, providers: list<mixed>}
     */
    private function emptyStatistics(): array
    {
        return [
            'total' => 0,
            'llm' => 0,
            'search' => 0,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'totalTokens' => 0,
            'providers' => [],
        ];
    }
}
