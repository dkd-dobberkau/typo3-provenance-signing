<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Tests\Unit\Signing;

use CmsProvenance\ProvenanceSigning\Canonicalization\CanonicalText;
use CmsProvenance\ProvenanceSigning\Canonicalization\JsonCanonicalizer;
use CmsProvenance\ProvenanceSigning\Key\KeyStore;
use CmsProvenance\ProvenanceSigning\Signing\PageSigner;
use CmsProvenance\ProvenanceSigning\Signing\RecordSigner;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageSignerTest extends UnitTestCase
{
    private const KEY_BASE = 'https://example.org/.well-known/content-provenance/keys/';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/provenance-keys-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        @rmdir($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function signedPageVerifiesAgainstItsOwnText(): void
    {
        $store = new KeyStore($this->directory);
        $key = $store->generate();

        $signed = $this->pageSigner($store)->signHtml($this->page('<p>Signed claim</p>'), self::KEY_BASE);
        $record = $this->extractRecord($signed);

        self::assertSame($key->keyId, $record->signature->keyId);
        self::assertSame(self::KEY_BASE . $key->keyId, $record->key);
        self::assertSame(3, $record->cpsConformance);
        self::assertTrue($this->recordSigner()->verify($signed, $record, $key->publicKey));
    }

    #[Test]
    public function changingTheTextAfterSigningBreaksVerification(): void
    {
        $store = new KeyStore($this->directory);
        $key = $store->generate();
        $signed = $this->pageSigner($store)->signHtml($this->page('<p>Signed claim</p>'), self::KEY_BASE);

        $tampered = str_replace('Signed claim', 'Altered claim', $signed);

        self::assertFalse($this->recordSigner()->verify($tampered, $this->extractRecord($tampered), $key->publicKey));
    }

    #[Test]
    public function pageStaysUnchangedWithoutAnActiveKey(): void
    {
        $page = $this->page('<p>Text</p>');

        self::assertSame($page, $this->pageSigner(new KeyStore($this->directory))->signHtml($page, self::KEY_BASE));
    }

    #[Test]
    public function pageWithoutProvenanceRecordStaysUnchanged(): void
    {
        $store = new KeyStore($this->directory);
        $store->generate();
        $page = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization"}</script></head><body><main><p>x</p></main></body></html>';

        self::assertSame($page, $this->pageSigner($store)->signHtml($page, self::KEY_BASE));
    }

    #[Test]
    public function higherConformanceLevelIsKept(): void
    {
        $store = new KeyStore($this->directory);
        $store->generate();

        $signed = $this->pageSigner($store)->signHtml($this->page('<p>x</p>', 4), self::KEY_BASE);

        self::assertSame(4, $this->extractRecord($signed)->cpsConformance);
    }

    private function page(string $content, int $level = 2): string
    {
        $record = json_encode([
            '@context' => ['https://schema.org', 'https://cms-provenance.org/ns/v1'],
            '@type' => 'WebPage',
            '@id' => 'urn:uuid:00000000-0000-4000-8000-000000000001',
            'headline' => 'A </script> headline',
            'cpsConformance' => $level,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return '<!DOCTYPE html><html><head><title>t</title><script type="application/ld+json">' . $record
            . '</script></head><body><main>' . $content . '</main></body></html>';
    }

    private function extractRecord(string $html): \stdClass
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
        return json_decode($matches[1], false, 512, JSON_THROW_ON_ERROR);
    }

    private function recordSigner(): RecordSigner
    {
        return new RecordSigner(new CanonicalText(), new JsonCanonicalizer());
    }

    private function pageSigner(KeyStore $store): PageSigner
    {
        return new PageSigner(new CanonicalText(), $this->recordSigner(), $store);
    }
}
