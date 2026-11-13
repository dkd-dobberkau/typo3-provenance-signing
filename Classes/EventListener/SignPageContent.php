<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\EventListener;

use CmsProvenance\ProvenanceSigning\Signing\PageSigner;
use CmsProvenance\ProvenanceSigning\Middleware\PublicKeyEndpoint;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Signs the provenance record once the page HTML is complete and before it is
 * cached, so every delivery of the cached page carries a matching signature.
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
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        $site = $event->getRequest()->getAttribute('site');
        if (!$site instanceof Site) {
            return;
        }
        $keyBaseUrl = rtrim((string)$site->getBase()->withPath(''), '/') . PublicKeyEndpoint::PATH . '/';

        try {
            $this->setContent($event, $this->pageSigner->signHtml($this->getContent($event), $keyBaseUrl));
        } catch (\Throwable $exception) {
            // An unsigned page is still a valid L2 page; never break delivery over a signature.
            $this->logger->error('Provenance signing failed: ' . $exception->getMessage(), ['exception' => $exception]);
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
