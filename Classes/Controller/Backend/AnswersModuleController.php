<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Domain\Model\AssistantLearning;
use WebNomads\WnAiBridge\Domain\Model\AssistantLogEntry;
use WebNomads\WnAiBridge\Domain\Repository\AssistantLearningRepository;
use WebNomads\WnAiBridge\Domain\Repository\AssistantLogRepository;
use WebNomads\WnAiBridge\Service\LearningService;
use WebNomads\WnAiBridge\Service\SiteListService;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;

/**
 * Backend module "Answers": what the assistant says for questions it recognises.
 *
 * An active answer replaces whatever the assistant would have produced on its
 * own, and is played back whenever a question matches it in meaning. Entries
 * come from three places — written here, taken over from a logged answer in the
 * "Enquiries" module, or captured from a correction a visitor made in the
 * chat, which arrives as "pending" and is only used once approved.
 *
 * Formerly registered as "wn_ai_bridge_corrections"; the module keeps that
 * identifier as an alias so existing backend group permissions still apply.
 */
final class AnswersModuleController
{
    private const MODULE_NAME = 'wn_ai_bridge_answers';

    private const LIST_LIMIT = 200;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BackendUriBuilder $uriBuilder,
        private readonly AssistantLearningRepository $repository,
        private readonly AssistantLogRepository $logRepository,
        private readonly SubscriptionService $subscriptionService,
        private readonly SiteListService $siteListService,
    ) {}

    /**
     * The logged answer this entry is meant to replace, when the editor started
     * from the assistant log. Null whenever there is none, or it has since been
     * deleted from the log.
     *
     * @param array<string, mixed> $params
     */
    private function findLogEntry(array $params): ?AssistantLogEntry
    {
        $logUid = max(0, (int)($params['logUid'] ?? 0));
        if ($logUid === 0) {
            return null;
        }

        try {
            return $this->logRepository->findByUid($logUid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        // The module route is already blocked without a subscription; this is the
        // second line of defence so the data is never reachable by accident.
        if (!$this->subscriptionService->hasFeature(SubscriptionService::FEATURE_CORRECTIONS)) {
            return $this->renderSubscriptionNotice($request);
        }

        $parsedBody = $request->getParsedBody();
        $params = array_merge($request->getQueryParams(), is_array($parsedBody) ? $parsedBody : []);

        if ($request->getMethod() === 'POST') {
            return $this->handleAction($request, $params);
        }

        if (($params['edit'] ?? '') !== '' || ($params['new'] ?? '') !== '') {
            return $this->renderForm($request, $params);
        }

        return $this->renderList($request, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleAction(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $action = (string)($params['action'] ?? '');
        $uid = (int)($params['learningUid'] ?? 0);

        if ($action === 'save') {
            return $this->save($request, $params);
        }

        if ($uid > 0) {
            match ($action) {
                'approve' => $this->repository->setStatus($uid, AssistantLearning::STATUS_APPROVED),
                'reject', 'revoke', 'delete' => $this->repository->delete($uid),
                default => null,
            };
        }

        return $this->redirectToList(
            $action === 'approve' ? 'approved' : ($action !== '' ? 'deleted' : ''),
            (string)($params['site'] ?? ''),
        );
    }

    /**
     * Create or update an entry from the edit form.
     *
     * @param array<string, mixed> $params
     */
    private function save(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $uid = (int)($params['learningUid'] ?? 0);
        $topic = $this->text($params['topic'] ?? '');
        $answer = $this->text($params['correction'] ?? '');
        $keywords = trim((string)($params['keywords'] ?? ''));

        if ($answer === '') {
            // Re-render the form with the input the editor already typed, rather
            // than silently dropping it.
            return $this->renderForm($request, $params, 'answerRequired');
        }

        $data = [
            'tstamp' => time(),
            'site_identifier' => trim((string)($params['siteIdentifier'] ?? '')),
            'language_uid' => max(0, (int)($params['languageUid'] ?? 0)),
            'status' => ($params['status'] ?? '') === AssistantLearning::STATUS_PENDING
                ? AssistantLearning::STATUS_PENDING
                : AssistantLearning::STATUS_APPROVED,
            'source' => AssistantLearning::SOURCE_MANUAL,
            'topic' => $topic,
            'correction' => $answer,
            'keywords' => $keywords !== ''
                ? mb_substr($keywords, 0, 255)
                : LearningService::deriveKeywords($topic, $answer),
        ];

        if ($uid > 0) {
            $this->repository->update($uid, $data);
        } else {
            $this->repository->add($data + [
                'pid' => 0,
                'crdate' => time(),
                // Carried over when the entry was started from a logged answer,
                // so it stays visible what this replaces.
                'wrong_answer' => mb_substr($this->findLogEntry($params)?->answer ?? '', 0, 2000),
                'conversation_id' => '',
                'ip_address' => '',
            ]);
        }

        // Back to the list of the site the answer was written for, so it is
        // visible where it landed.
        return $this->redirectToList('saved', (string)$data['site_identifier']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderList(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $sites = $this->siteListService->getFilterOptions();

        // Anything that is not a site of this installation is treated as "all",
        // so a hand-written URL cannot narrow the list to something that does
        // not exist and make it look empty.
        $site = trim((string)($params['site'] ?? ''));
        if (!$this->siteListService->isKnownIdentifier($site)) {
            $site = '';
        }

        $tableError = false;
        $pending = [];
        $approved = [];
        try {
            $pending = $this->repository->findByStatus(AssistantLearning::STATUS_PENDING, self::LIST_LIMIT, $site);
            $approved = $this->repository->findByStatus(AssistantLearning::STATUS_APPROVED, self::LIST_LIMIT, $site);
        } catch (\Throwable $e) {
            $tableError = true;
        }

        $moduleTemplate = $this->createModuleTemplate($request);
        $moduleTemplate->assignMultiple([
            'tableError' => $tableError,
            'pending' => $pending,
            'approved' => $approved,
            'notice' => (string)($params['notice'] ?? ''),
            'subscription' => $this->subscriptionService->getStatus(),
            // Empty on a single-site installation — the template renders the
            // site filter only when there is a choice to make.
            'sites' => $sites,
            'site' => $site,
            'moduleUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            'newUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, ['new' => 1]),
        ]);

        return $moduleTemplate->renderResponse('Answers/Index');
    }

    /**
     * Render the edit form, either from a stored entry or from the submitted
     * values when validation failed.
     *
     * @param array<string, mixed> $params
     */
    private function renderForm(ServerRequestInterface $request, array $params, string $error = ''): ResponseInterface
    {
        $uid = (int)($params['edit'] ?? $params['learningUid'] ?? 0);
        $entry = $uid > 0 ? $this->repository->findByUid($uid) : null;

        if ($uid > 0 && $entry === null) {
            return $this->redirectToList();
        }

        // Three sources, and they never mix: what was just submitted (so a
        // validation error costs no typing), the stored entry, or — for a new one
        // started from a logged answer — the question and answer carried over
        // from the assistant log.
        if ($error !== '') {
            $formValues = [
                'uid' => $uid,
                'status' => (string)($params['status'] ?? AssistantLearning::STATUS_APPROVED),
                'siteIdentifier' => (string)($params['siteIdentifier'] ?? ''),
                'languageUid' => (int)($params['languageUid'] ?? 0),
                'topic' => (string)($params['topic'] ?? ''),
                'correction' => (string)($params['correction'] ?? ''),
                'keywords' => (string)($params['keywords'] ?? ''),
            ];
        } elseif ($entry !== null) {
            $formValues = [
                'uid' => $entry->uid,
                'status' => $entry->status,
                'siteIdentifier' => $entry->siteIdentifier,
                'languageUid' => $entry->languageUid,
                'topic' => $entry->topic,
                'correction' => $entry->correction,
                'keywords' => $entry->keywords,
            ];
        } else {
            $logEntry = $this->findLogEntry($params);

            $formValues = [
                'uid' => 0,
                'status' => AssistantLearning::STATUS_APPROVED,
                'siteIdentifier' => $logEntry?->siteIdentifier ?? '',
                'languageUid' => $logEntry?->languageUid ?? 0,
                'topic' => $logEntry?->question ?? '',
                // Left empty on purpose: the answer from the log is what is being
                // replaced, not a starting point to edit.
                'correction' => '',
                'keywords' => '',
            ];
        }

        $moduleTemplate = $this->createModuleTemplate($request);
        $moduleTemplate->assignMultiple([
            'form' => $formValues,
            'entry' => $entry,
            'error' => $error,
            // The answer the assistant actually gave, when this was started from
            // the log — shown so it is clear what is being replaced.
            'previousAnswer' => $entry === null ? ($this->findLogEntry($params)?->answer ?? '') : '',
            'logUid' => $entry === null ? max(0, (int)($params['logUid'] ?? 0)) : 0,
            'siteIdentifiers' => $this->safeSiteIdentifiers(),
            'moduleUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ]);

        return $moduleTemplate->renderResponse('Answers/Edit');
    }

    private function renderSubscriptionNotice(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);
        $moduleTemplate->assign('subscription', $this->subscriptionService->getStatus());

        return $moduleTemplate->renderResponse('Answers/SubscriptionRequired');
    }

    private function createModuleTemplate(ServerRequestInterface $request): \TYPO3\CMS\Backend\Template\ModuleTemplate
    {
        GeneralUtility::makeInstance(PageRenderer::class)
            ->addCssFile('EXT:wn_ai_bridge/Resources/Public/Css/backend.css');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('AI Assistant Answers');

        return $moduleTemplate;
    }

    /**
     * @param string $site Kept across the redirect so an editor working through
     *        one website's answers is not thrown back to the full list after
     *        every approval.
     */
    private function redirectToList(string $notice = '', string $site = ''): ResponseInterface
    {
        $parameters = [];
        if ($notice !== '') {
            $parameters['notice'] = $notice;
        }
        if ($this->siteListService->isKnownIdentifier($site)) {
            $parameters['site'] = $site;
        }

        return new RedirectResponse(
            (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, $parameters)
        );
    }

    /**
     * @return list<string>
     */
    private function safeSiteIdentifiers(): array
    {
        try {
            return $this->repository->findDistinctSiteIdentifiers();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function text(mixed $value): string
    {
        return mb_substr(trim((string)$value), 0, 2000);
    }
}
