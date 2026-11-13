<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\EventListener;

use CmsProvenance\Provenance\Service\AbsoluteUrlResolver;
use CmsProvenance\ProvenanceSigning\Domain\Repository\SignedRecordRepository;
use CmsProvenance\ProvenanceSigning\Signing\PageSigner;
use CmsProvenance\ProvenanceSigning\WellKnown\KeysHandler;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Signs the provenance record once the page HTML is complete and before it is
 * cached, so every delivery of the cached page carries a matching signature.
 * The signed record is also stored for the audit endpoint.
 *
 * Non-cacheable (USER_INT) output is inserted after this point. It must stay
 * outside the content roots or be marked with data-cps-ignore, otherwise the
 * delivered text no longer matches the signed hash.
 */
#[AsEventListener(identifier: 'provenance-signing/sign-page-content')]
final readonly class SignPageContent
{
    public function __construct(
        private PageSigner $pageSigner,
        private SignedRecordRepository $signedRecordRepository,
        private LoggerInterface $logger,
        private AbsoluteUrlResolver $urlResolver,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        $request = $event->getRequest();
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return;
        }
        $keyBaseUrl = $this->urlResolver->origin($site, $request) . KeysHandler::PATH . '/';

        try {
            $signedPage = $this->pageSigner->signPage($this->getContent($event), $keyBaseUrl);
            if ($signedPage === null) {
                return;
            }
            $this->setContent($event, $signedPage->html);
            $this->storeForAudit($event, $signedPage->record);
        } catch (\Throwable $exception) {
            // An unsigned page is still a valid L2 page; never break delivery over a signature.
            $this->logger->error('Provenance signing failed: ' . $exception->getMessage(), ['exception' => $exception]);
        }
    }

    private function storeForAudit(AfterCacheableContentIsGeneratedEvent $event, \stdClass $signedRecord): void
    {
        $request = $event->getRequest();
        $pageInformation = $request->getAttribute('frontend.page.information');
        $language = $request->getAttribute('language');
        if ($pageInformation instanceof PageInformation) {
            $this->signedRecordRepository->save(
                $pageInformation->getId(),
                $language instanceof SiteLanguage ? $language->getLanguageId() : 0,
                $signedRecord
            );
        }
    }

    // TYPO3 14 passes the content on the event, TYPO3 13 on the frontend controller.
    private function getContent(AfterCacheableContentIsGeneratedEvent $event): string
    {
        return method_exists($event, 'getContent') ? $event->getContent() : (string)$event->getController()->content;
    }

    private function setContent(AfterCacheableContentIsGeneratedEvent $event, string $content): void
    {
        if (method_exists($event, 'setContent')) {
            $event->setContent($content);
            return;
        }
        $event->getController()->content = $content;
    }
}
