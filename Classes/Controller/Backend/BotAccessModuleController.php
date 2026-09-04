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
use WebNomads\WnAiBridge\Domain\Model\BotAccessFilter;
use WebNomads\WnAiBridge\Domain\Repository\BotAccessRepository;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\SiteListService;

/**
 * Backend module: a filterable list of bot/crawler accesses to llms.txt, the
 * Markdown versions and normal pages.
 */
final class BotAccessModuleController
{
    private const MODULE_NAME = 'wn_ai_bridge_botaccess';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BotAccessRepository $repository,
        private readonly ConfigurationService $configurationService,
        private readonly BackendUriBuilder $uriBuilder,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly SiteListService $siteListService,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $params = array_merge($request->getQueryParams(), is_array($parsedBody) ? $parsedBody : []);

        if ($request->getMethod() === 'POST' && ($params['action'] ?? '') === 'deleteAll') {
            $this->repository->deleteAll();
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME));
        }

        $filter = BotAccessFilter::fromQueryParams($params);

        $tableError = false;
        $entries = [];
        $total = 0;
        $stats = ['total' => 0, 'llmstxt' => 0, 'llmsfull' => 0, 'markdown' => 0, 'page' => 0, 'ai' => 0];
        $botNames = [];
        try {
            $entries = $this->repository->findByFilter($filter);
            $total = $this->repository->countByFilter($filter);
            $stats = $this->repository->getStatistics($filter);
            $botNames = $this->repository->findDistinctBotNames();
        } catch (\Throwable $e) {
            $tableError = true;
        }

        $variables = [
            // Shown for information only: this module is not part of the
            // subscription and keeps working without one.
            'subscription' => $this->configurationService->getSubscriptionStatus(),
            'loggingEnabled' => $this->configurationService->isBotAccessLoggingEnabled(),
            // Accesses to llms-full.txt are always recorded, but a site that
            // does not serve the document has nothing to show for it — the
            // count and the filter option would only be noise.
            'llmsFullEnabled' => $this->configurationService->isLlmsFullTxtEnabled(),
            'tableError' => $tableError,
            'entries' => $entries,
            'total' => $total,
            'stats' => $stats,
            'botNames' => $botNames,
            // Empty on a single-site installation — the template renders the
            // site filter only when there is a choice to make.
            'sites' => $this->siteListService->getFilterOptions(),
            'filter' => $filter->toFormValues(),
            'pagination' => $this->buildPagination($filter, $total),
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
        $moduleTemplate->setTitle('Bot Access Log');
        $moduleTemplate->assignMultiple($variables);

        return $moduleTemplate->renderResponse('BotAccess/Index');
    }

    /**
     * Render only the filter-dependent results fragment (statistics, result
     * table and pagination) as a bare HTML response for AJAX requests.
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

        return new HtmlResponse($view->render('BotAccess/Results'));
    }

    /**
     * @return array{current: int, totalPages: int, pages: list<array{num: int, url: string, active: bool}>}
     */
    private function buildPagination(BotAccessFilter $filter, int $total): array
    {
        $totalPages = (int)max(1, ceil($total / $filter->perPage));
        $current = min($filter->page, $totalPages);

        $pages = [];
        $from = max(1, $current - 3);
        $to = min($totalPages, $current + 3);
        for ($i = $from; $i <= $to; $i++) {
            $query = array_merge($filter->toFormValues(), ['page' => $i]);
            $pages[] = [
                'num' => $i,
                'url' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, $query),
                'active' => $i === $current,
            ];
        }

        return ['current' => $current, 'totalPages' => $totalPages, 'pages' => $pages];
    }
}
