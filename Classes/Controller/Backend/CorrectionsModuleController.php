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
use WebNomads\WnAiBridge\Domain\Repository\AssistantLearningRepository;

/**
 * Backend module: moderate visitor corrections captured for owner-approved
 * learning. Pending corrections can be approved (used in future answers) or
 * rejected (deleted); approved ones are listed and can be revoked.
 */
final class CorrectionsModuleController
{
    private const MODULE_NAME = 'wn_ai_bridge_corrections';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BackendUriBuilder $uriBuilder,
        private readonly AssistantLearningRepository $repository,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $params = array_merge($request->getQueryParams(), is_array($parsedBody) ? $parsedBody : []);

        if ($request->getMethod() === 'POST') {
            $action = (string)($params['action'] ?? '');
            $uid = (int)($params['learningUid'] ?? 0);
            if ($uid > 0) {
                if ($action === 'approve') {
                    $this->repository->setStatus($uid, AssistantLearning::STATUS_APPROVED);
                } elseif ($action === 'reject' || $action === 'revoke') {
                    $this->repository->delete($uid);
                }
            }
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME));
        }

        $tableError = false;
        $pending = [];
        $approved = [];
        try {
            $pending = $this->repository->findByStatus(AssistantLearning::STATUS_PENDING, 200);
            $approved = $this->repository->findByStatus(AssistantLearning::STATUS_APPROVED, 200);
        } catch (\Throwable $e) {
            $tableError = true;
        }

        GeneralUtility::makeInstance(PageRenderer::class)
            ->addCssFile('EXT:wn_ai_bridge/Resources/Public/Css/backend.css');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('AI Assistant Corrections');
        $moduleTemplate->assignMultiple([
            'tableError' => $tableError,
            'pending' => $pending,
            'approved' => $approved,
            'moduleUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ]);

        return $moduleTemplate->renderResponse('Corrections/Index');
    }
}
