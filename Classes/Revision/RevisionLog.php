<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Revision;

use CmsProvenance\ProvenanceSigning\Canonicalization\JsonCanonicalizer;
use CmsProvenance\ProvenanceSigning\Domain\Repository\RevisionRepository;
use CmsProvenance\ProvenanceSigning\Signing\SignedPage;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Keeps a revision whenever a page and language are signed with a record they
 * never had before, so it can be traced later what was signed when, from
 * which text and through which request. Internal only; nothing is published.
 */
final readonly class RevisionLog
{
    // Set by b13/ai-bots-love-markdown on requests for the Markdown version.
    private const MARKDOWN_REQUEST_ATTRIBUTE = 'ai-bots-love-markdown.requested';

    public function __construct(
        private RevisionRepository $revisionRepository,
        private JsonCanonicalizer $jsonCanonicalizer,
    ) {}

    /**
     * @return bool whether a revision was written
     */
    public function record(ServerRequestInterface $request, int $pageUid, int $languageUid, SignedPage $signedPage): bool
    {
        $record = $signedPage->record;
        $recordHash = $this->recordHash($record);
        if ($this->revisionRepository->hasRecord($pageUid, $languageUid, $recordHash)) {
            return false;
        }

        $this->revisionRepository->add([
            'page_uid' => $pageUid,
            'language_uid' => $languageUid,
            'document_uri' => (string)($record->{'@id'} ?? ''),
            'content_hash' => (string)($record->contentHash ?? ''),
            'record_hash' => $recordHash,
            'key_id' => (string)($record->signature->keyId ?? ''),
            'cps_conformance' => (int)($record->cpsConformance ?? 0),
            'signed_record' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'canonical_text' => $signedPage->canonicalText,
            'request_kind' => $request->getAttribute(self::MARKDOWN_REQUEST_ATTRIBUTE) ? 'markdown' : 'html',
            'request_url' => $this->requestUrl($request),
            'signed_at' => time(),
        ]);

        return true;
    }

    /**
     * SHA-256 over the Annex A.4 bytes, without the signature; Ed25519 is
     * deterministic, so equal hashes mean an equal signed record.
     */
    public function recordHash(\stdClass $record): string
    {
        return hash('sha256', $this->jsonCanonicalizer->canonicalizeRecord($record));
    }

    /**
     * The URL as requested: b13/ai-bots-love-markdown rewrites the request URI
     * (.md suffix, type parameter) but leaves normalizedParams alone.
     */
    private function requestUrl(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');

        return $normalizedParams instanceof NormalizedParams
            ? $normalizedParams->getRequestUrl()
            : (string)$request->getUri();
    }
}
