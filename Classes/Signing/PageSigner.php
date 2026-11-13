<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Signing;

use CmsProvenance\Provenance\Service\ConformanceLevel;
use CmsProvenance\ProvenanceSigning\Canonicalization\CanonicalText;
use CmsProvenance\ProvenanceSigning\Key\KeyStore;

/**
 * Signs the provenance JSON-LD block of a rendered page (Annex A.5.1).
 *
 * The content hash is computed from the same HTML that is delivered, so a
 * verifier fetching the page recomputes exactly what was signed. Pages
 * without a provenance block, with more than one, or without an active key
 * are returned unchanged.
 */
final readonly class PageSigner
{
    private const VOCABULARY = 'https://cms-provenance.org/ns/v1';
    private const SCRIPT_PATTERN = '#(<script type="application/ld\+json">)(.*?)(</script>)#s';

    public function __construct(
        private CanonicalText $canonicalText,
        private RecordSigner $recordSigner,
        private KeyStore $keyStore,
        private ConformanceLevel $conformanceLevel,
    ) {}

    /**
     * @param string $keyBaseUrl URL prefix under which a key document is published, ending with "/"
     */
    public function signHtml(string $html, string $keyBaseUrl, bool $auditEndpoint = false): string
    {
        return $this->signPage($html, $keyBaseUrl, $auditEndpoint)?->html ?? $html;
    }

    /**
     * @param string $keyBaseUrl URL prefix under which a key document is published, ending with "/"
     * @return SignedPage|null null when the page cannot or need not be signed
     */
    /**
     * @param bool $auditEndpoint whether the site serves the Audit Endpoint, needed for L4
     */
    public function signPage(string $html, string $keyBaseUrl, bool $auditEndpoint = false): ?SignedPage
    {
        $records = $this->findProvenanceRecords($html);
        $key = $this->keyStore->activeKey();
        if (count($records) !== 1 || $key === null) {
            return null;
        }

        [$fullMatch, $record] = $records[0];
        unset($record->signature);
        $record->key = $keyBaseUrl . $key->keyId;
        $record->cpsConformance = $this->conformanceLevel->of(
            json_decode(json_encode($record, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
            true,
            $auditEndpoint
        );
        $record->contentHash = $this->canonicalText->contentHash($html);

        $signed = $this->recordSigner->sign($record, $key->keyId, $this->keyStore->secretKey($key->keyId));
        $json = json_encode(
            $signed,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
        );

        return new SignedPage(
            str_replace($fullMatch, '<script type="application/ld+json">' . $json . '</script>', $html),
            $signed
        );
    }

    /**
     * @return list<array{0: string, 1: \stdClass}> the matched script element and its decoded record
     */
    private function findProvenanceRecords(string $html): array
    {
        preg_match_all(self::SCRIPT_PATTERN, $html, $matches, PREG_SET_ORDER);
        $records = [];
        foreach ($matches as $match) {
            $data = json_decode($match[2], false);
            if (!$data instanceof \stdClass) {
                continue;
            }
            $context = $data->{'@context'} ?? null;
            if (in_array(self::VOCABULARY, is_array($context) ? $context : [$context], true)) {
                $records[] = [$match[0], $data];
            }
        }

        return $records;
    }
}
