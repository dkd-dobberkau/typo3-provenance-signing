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

/**
 * A site may use a relative base such as "/"; the record must still point to
 * the key with an absolute URL, taking scheme and host from the request.
 */
final class SignedPageWithRelativeBaseTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['cms-provenance/provenance', 'cms-provenance/provenance-signing'];

    // The test instance is set up once per class, so the directory must not change between tests.
    private string $keyDirectory;

    protected function setUp(): void
    {
        $this->keyDirectory = sys_get_temp_dir() . '/provenance-signing-relative-base-test';
        $this->removeKeys();
        $this->configurationToUseInTestInstance = [
            'EXTENSIONS' => ['provenance_signing' => ['keyDirectory' => $this->keyDirectory]],
        ];
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SignedPage.csv');
        $this->setUpFrontendRootPage(1, ['EXT:provenance_signing/Tests/Functional/Fixtures/page.typoscript']);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, '/');
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
    public function keyUrlIsAbsoluteAndThePageStillVerifies(): void
    {
        $key = (new KeyStore($this->keyDirectory))->generate();

        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost/'));
        self::assertSame(200, $response->getStatusCode(), substr((string)$response->getBody(), 0, 300));
        $html = (string)$response->getBody();
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches));
        $record = json_decode($matches[1], false, 512, JSON_THROW_ON_ERROR);

        self::assertSame('http://localhost/.well-known/content-provenance/keys/' . $key->keyId, $record->key);
        self::assertTrue((new RecordSigner(new CanonicalText(), new JsonCanonicalizer()))->verify($html, $record, $key->publicKey));
    }
}
