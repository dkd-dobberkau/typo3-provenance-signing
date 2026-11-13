<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Tests\Functional;

use CmsProvenance\ProvenanceSigning\Canonicalization\CanonicalText;
use CmsProvenance\ProvenanceSigning\Canonicalization\JsonCanonicalizer;
use CmsProvenance\ProvenanceSigning\Key\KeyStore;
use CmsProvenance\ProvenanceSigning\Signing\RecordSigner;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SignedPageTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['cms-provenance/provenance', 'cms-provenance/provenance-signing'];

    // The test instance is set up once per class, so the directory must not change between tests.
    private string $keyDirectory;

    protected function setUp(): void
    {
        $this->keyDirectory = sys_get_temp_dir() . '/provenance-signing-functional-test';
        $this->removeKeys();
        $this->configurationToUseInTestInstance = [
            'EXTENSIONS' => ['provenance_signing' => ['keyDirectory' => $this->keyDirectory]],
        ];
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SignedPage.csv');
        $this->setUpFrontendRootPage(1, ['EXT:provenance_signing/Tests/Functional/Fixtures/page.typoscript']);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'http://localhost/');
    }

    protected function tearDown(): void
    {
        $this->removeKeys();
        parent::tearDown();
    }

    private function removeKeys(): void
    {
        array_map('unlink', glob($this->keyDirectory . '/*') ?: []);
        @rmdir($this->keyDirectory);
    }

    #[Test]
    public function deliveredPageVerifiesAgainstThePublishedKey(): void
    {
        $key = (new KeyStore($this->keyDirectory))->generate();

        $html = $this->fetch('/');
        $record = $this->extractRecord($html);

        self::assertSame('Ed25519', $record->signature->algorithm);
        self::assertSame($key->keyId, $record->signature->keyId);
        self::assertSame('http://localhost/.well-known/content-provenance/keys/' . $key->keyId, $record->key);
        self::assertSame(3, $record->cpsConformance);
        self::assertTrue((new RecordSigner(new CanonicalText(), new JsonCanonicalizer()))->verify($html, $record, $key->publicKey));
    }

    #[Test]
    public function keyDocumentPublishesThePublicKeyAsJwk(): void
    {
        $key = (new KeyStore($this->keyDirectory))->generate();

        $document = json_decode($this->fetch('/.well-known/content-provenance/keys/' . $key->keyId), false, 512, JSON_THROW_ON_ERROR);

        self::assertSame('OKP', $document->publicKeyJwk->kty);
        self::assertSame('Ed25519', $document->publicKeyJwk->crv);
        self::assertSame($key->publicKey, sodium_base642bin($document->publicKeyJwk->x, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
        self::assertNull($document->retiredAt);
    }

    #[Test]
    public function pageStaysUnsignedWithoutKey(): void
    {
        $record = $this->extractRecord($this->fetch('/'));

        self::assertFalse(isset($record->signature));
        self::assertFalse(isset($record->contentHash));
        self::assertSame(2, $record->cpsConformance);
    }

    private function fetch(string $path): string
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost' . $path));
        self::assertSame(200, $response->getStatusCode(), substr((string)$response->getBody(), 0, 300));

        return (string)$response->getBody();
    }

    private function extractRecord(string $html): \stdClass
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches));

        return json_decode($matches[1], false, 512, JSON_THROW_ON_ERROR);
    }
}
