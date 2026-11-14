<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Tests\Functional;

use CmsProvenance\ProvenanceSigning\Key\KeyStore;
use CmsProvenance\ProvenanceSigning\Revision\RevisionLog;
use CmsProvenance\ProvenanceSigning\Signing\PageSigner;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RevisionLogTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['cms-provenance/provenance', 'cms-provenance/provenance-signing'];

    // The test instance is set up once per class, so the directory must not change between tests.
    private string $keyDirectory;

    protected function setUp(): void
    {
        $this->keyDirectory = sys_get_temp_dir() . '/provenance-signing-revision-test';
        $this->removeKeys();
        $this->configurationToUseInTestInstance = [
            'EXTENSIONS' => ['provenance_signing' => ['keyDirectory' => $this->keyDirectory]],
        ];
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SignedPage.csv');
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'http://localhost/');
        (new KeyStore($this->keyDirectory))->generate();
    }

    protected function tearDown(): void
    {
        $this->removeKeys();
        parent::tearDown();
    }

    #[Test]
    public function firstRenderWritesARevision(): void
    {
        $this->useTypoScript('page.typoscript');
        $html = $this->fetch('/');

        $revisions = $this->revisions();
        self::assertCount(1, $revisions);
        self::assertSame(1, (int)$revisions[0]['page_uid']);
        self::assertSame(0, (int)$revisions[0]['language_uid']);
        self::assertSame('html', $revisions[0]['request_kind']);
        // Simulated frontend requests report /index.php in normalizedParams; the demo shows real URLs.
        self::assertStringStartsWith('http://localhost/', $revisions[0]['request_url']);
        self::assertSame($this->deliveredRecord($html)->contentHash, $revisions[0]['content_hash']);
        self::assertStringContainsString('Signed claim & more', $revisions[0]['canonical_text']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $revisions[0]['record_hash']);
    }

    #[Test]
    public function identicalReRenderWritesNothing(): void
    {
        // The page cache is disabled in functional tests, so every request renders and signs anew.
        $this->useTypoScript('page.typoscript');
        $this->fetch('/');
        $this->fetch('/');

        self::assertCount(1, $this->revisions());
    }

    #[Test]
    public function changedRecordWritesASecondRevision(): void
    {
        $this->useTypoScript('page.typoscript');
        $this->fetch('/');
        $this->setPageTitle('Root, revised');
        $this->fetch('/');

        self::assertCount(2, $this->revisions());
    }

    #[Test]
    public function returningToAnEarlierRecordWritesNothing(): void
    {
        $this->useTypoScript('page.typoscript');
        $this->fetch('/');
        $this->setPageTitle('Root, revised');
        $this->fetch('/');
        $this->setPageTitle('Root');
        $this->fetch('/');

        self::assertCount(2, $this->revisions());
    }

    #[Test]
    public function uncachedRenderIsNotLogged(): void
    {
        $this->useTypoScript('page-uncached.typoscript');
        $this->fetch('/');

        self::assertCount(0, $this->revisions());
        self::assertCount(1, $this->getConnectionPool()->getConnectionForTable('tx_provenancesigning_record')
            ->select(['uid'], 'tx_provenancesigning_record')->fetchAllAssociative());
    }

    #[Test]
    public function renderForALoggedInFrontendUserIsNotLogged(): void
    {
        $this->getConnectionPool()->getConnectionForTable('fe_users')
            ->insert('fe_users', ['uid' => 1, 'pid' => 1, 'username' => 'member', 'password' => 'x', 'usergroup' => '']);
        $this->useTypoScript('page.typoscript');

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/'),
            (new InternalRequestContext())->withFrontendUserId(1)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $this->revisions());
    }

    #[Test]
    public function markdownRequestIsMarked(): void
    {
        $html = '<html><head><script type="application/ld+json">'
            . '{"@context":["https://schema.org","https://cms-provenance.org/ns/v1"],"@type":"WebPage","@id":"urn:uuid:1"}'
            . '</script></head><body><main><p>Text</p></main></body></html>';
        $signedPage = $this->get(PageSigner::class)->signPage($html, 'http://localhost/keys/');
        $request = (new ServerRequest('http://localhost/index.md', 'GET', null, [], ['HTTP_HOST' => 'localhost', 'REQUEST_URI' => '/index.md']))
            ->withAttribute('ai-bots-love-markdown.requested', true)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        self::assertTrue($this->get(RevisionLog::class)->record($request, 1, 0, $signedPage));

        $revision = $this->revisions()[0];
        self::assertSame('markdown', $revision['request_kind']);
        self::assertSame('http://localhost/index.md', $revision['request_url']);
    }

    private function useTypoScript(string $file): void
    {
        $this->setUpFrontendRootPage(1, ['EXT:provenance_signing/Tests/Functional/Fixtures/' . $file]);
    }

    private function setPageTitle(string $title): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['title' => $title], ['uid' => 1]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revisions(): array
    {
        return $this->getConnectionPool()->getConnectionForTable('tx_provenancesigning_revision')
            ->select(['*'], 'tx_provenancesigning_revision', [], [], ['uid' => 'ASC'])->fetchAllAssociative();
    }

    private function fetch(string $path): string
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost' . $path));
        self::assertSame(200, $response->getStatusCode(), substr((string)$response->getBody(), 0, 300));

        return (string)$response->getBody();
    }

    private function deliveredRecord(string $html): \stdClass
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches));

        return json_decode($matches[1], false, 512, JSON_THROW_ON_ERROR);
    }

    private function removeKeys(): void
    {
        array_map('unlink', glob($this->keyDirectory . '/*') ?: []);
        @rmdir($this->keyDirectory);
    }
}
