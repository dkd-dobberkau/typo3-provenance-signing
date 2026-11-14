<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Backend\Controller;

use CmsProvenance\Provenance\Backend\Controller\PagesModuleController;
use CmsProvenance\ProvenanceSigning\Domain\Repository\RevisionRepository;
use CmsProvenance\ProvenanceSigning\Revision\RevisionChanges;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\DiffUtility;

/**
 * Provenance → Revisions: the revisions of the signed record of the selected
 * page, and one revision with its record and a diff of its canonical text.
 * Read-only; only pages and languages the user may access.
 */
#[AsController]
final readonly class RevisionsModuleController
{
    private const PER_PAGE = 50;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private RevisionRepository $revisionRepository,
        private RevisionChanges $revisionChanges,
        private DiffUtility $diffUtility,
        private SiteFinder $siteFinder,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->backendUser();
        $pageId = (int)($request->getQueryParams()['id'] ?? 0);
        $pageRecord = $this->pageRecord($pageId, $user);
        $languages = $pageRecord !== [] ? $this->allowedLanguages($pageId, $user) : [];
        $total = $this->revisionRepository->countForPage($pageId, $languages);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min(max(1, (int)($request->getQueryParams()['page'] ?? 1)), $pages);

        $rows = [];
        foreach ($this->revisionRepository->findForPage($pageId, $languages, ($page - 1) * self::PER_PAGE, self::PER_PAGE) as $revision) {
            $previous = $this->revisionRepository->previousOf($revision);
            $rows[] = [
                'revision' => $revision,
                'hash' => $this->shortHash((string)$revision['content_hash']),
                'language' => $this->languageTitle($pageId, (int)$revision['language_uid']),
                'first' => $previous === null,
                'changes' => implode(', ', $this->revisionChanges->between($previous, $revision)),
                'detailUrl' => $this->url('provenance_revisions.detail', ['id' => $pageId, 'revision' => $revision['uid']]),
            ];
        }

        $view = $this->view($request, $pageRecord);
        $view->assignMultiple([
            'pageSelected' => $pageId > 0,
            'mayView' => $pageRecord !== [],
            'inWorkspace' => $user->workspace !== 0,
            'rows' => $rows,
            'previousUrl' => $page > 1 ? $this->url('provenance_revisions', ['id' => $pageId, 'page' => $page - 1]) : '',
            'nextUrl' => $page < $pages ? $this->url('provenance_revisions', ['id' => $pageId, 'page' => $page + 1]) : '',
        ]);

        return $view->renderResponse('RevisionsModule');
    }

    public function detailAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->backendUser();
        $pageId = (int)($request->getQueryParams()['id'] ?? 0);
        $revision = $this->revisionRepository->findByUid((int)($request->getQueryParams()['revision'] ?? 0));
        // The revision must belong to a page and a language the user may see.
        $pageRecord = $revision !== null ? $this->pageRecord((int)$revision['page_uid'], $user) : [];
        $mayView = $pageRecord !== [] && $user->checkLanguageAccess((int)$revision['language_uid']);

        $view = $this->view($request, $pageRecord);
        if (!$mayView) {
            $view->assign('mayView', false);
            return $view->renderResponse('RevisionDetail');
        }

        $previous = $this->revisionRepository->previousOf($revision);
        $record = json_decode((string)$revision['signed_record'], true);
        $view->assignMultiple([
            'mayView' => true,
            'revision' => $revision,
            'hash' => $this->shortHash((string)$revision['content_hash']),
            'language' => $this->languageTitle((int)$revision['page_uid'], (int)$revision['language_uid']),
            'changes' => implode(', ', $this->revisionChanges->between($previous, $revision)),
            // DiffUtility escapes both texts itself; the template outputs its markup raw.
            'textDiff' => $previous !== null ? $this->diffUtility->diff((string)$previous['canonical_text'], (string)$revision['canonical_text']) : '',
            'recordJson' => json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'backUrl' => $this->url('provenance_revisions', ['id' => $pageId > 0 ? $pageId : $revision['page_uid']]),
        ]);

        return $view->renderResponse('RevisionDetail');
    }

    /**
     * @param array<string, mixed> $pageRecord
     */
    private function view(ServerRequestInterface $request, array $pageRecord): ModuleTemplate
    {
        $module = $request->getAttribute('module');
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($GLOBALS['LANG']->sL($module->getTitle()), (string)($pageRecord['title'] ?? ''));
        $view->makeDocHeaderModuleMenu(['id' => (int)($request->getQueryParams()['id'] ?? 0)]);
        PagesModuleController::setPageInformation($view, $pageRecord);

        return $view;
    }

    /**
     * @return array<string, mixed> empty when the user may not see the page
     */
    private function pageRecord(int $pageId, BackendUserAuthentication $user): array
    {
        return $pageId > 0 ? (BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) ?: []) : [];
    }

    /**
     * The languages the page has revisions in, as far as the user may access them.
     *
     * @return list<int>
     */
    private function allowedLanguages(int $pageId, BackendUserAuthentication $user): array
    {
        return array_values(array_filter(
            $this->revisionRepository->languagesForPage($pageId),
            static fn(int $id): bool => $user->checkLanguageAccess($id)
        ));
    }

    private function languageTitle(int $pageId, int $languageId): string
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId)->getLanguageById($languageId)->getTitle();
        } catch (\Throwable) {
            return (string)$languageId;
        }
    }

    private function shortHash(string $contentHash): string
    {
        return substr(str_replace('sha256:', '', $contentHash), 0, 8);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function url(string $route, array $parameters): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
    }

    private function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
