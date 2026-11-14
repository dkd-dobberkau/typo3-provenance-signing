<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Tests\Functional\Backend;

use CmsProvenance\ProvenanceSigning\Backend\Controller\RevisionsModuleController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RevisionsModuleControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['cms-provenance/provenance', 'cms-provenance/provenance-signing'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RevisionsModule.csv');
    }

    #[Test]
    public function revisionsOfThePageAreListedNewestFirst(): void
    {
        $this->logIn(1);
        $body = $this->render('handleRequest', ['id' => 1]);

        $newest = strpos($body, 'http://localhost/index.md');
        $french = strpos($body, 'http://localhost/fr/');
        $oldest = strpos($body, 'First revision');
        self::assertNotFalse($newest);
        self::assertNotFalse($french);
        self::assertNotFalse($oldest);
        self::assertLessThan($french, $newest);
        self::assertLessThan($oldest, $french);
        self::assertStringContainsString('bbbbbbbb', $body);
        self::assertStringContainsString('text, headline', $body);
        self::assertStringNotContainsString('http://localhost/outside', $body);
    }

    #[Test]
    public function revisionTheSiteDeliversNowIsMarked(): void
    {
        // A, then B, then back to A: the oldest revision is the current one again.
        $this->logIn(1);
        $body = $this->render('handleRequest', ['id' => 1]);

        self::assertSame(1, substr_count($body, 'Currently delivered'));
        self::assertGreaterThan(strpos($body, 'http://localhost/fr/'), strpos($body, 'Currently delivered'));
    }

    #[Test]
    public function languagesTheEditorMayNotAccessAreLeftOut(): void
    {
        $this->logIn(2);

        self::assertStringNotContainsString('http://localhost/fr/', $this->render('handleRequest', ['id' => 1]));
        self::assertStringContainsString('You may not view this revision.', $this->render('detailAction', ['id' => 1, 'revision' => 3]));
    }

    #[Test]
    public function pageOutsideTheMountShowsNothing(): void
    {
        $this->logIn(2);
        $body = $this->render('handleRequest', ['id' => 2]);

        self::assertStringNotContainsString('http://localhost/outside', $body);
        self::assertStringContainsString('You may not view this page.', $body);
    }

    #[Test]
    public function detailShowsTextAndRecordAsTextOnly(): void
    {
        $this->logIn(1);
        $body = $this->render('detailAction', ['id' => 1, 'revision' => 2]);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringNotContainsString('&amp;lt;script', $body);
        self::assertStringContainsString('<del>', $body);
        self::assertStringContainsString('Root, revised', $body);
    }

    #[Test]
    public function firstRevisionShowsItsTextWithoutDiff(): void
    {
        $this->logIn(1);
        $body = $this->render('detailAction', ['id' => 1, 'revision' => 1]);

        self::assertStringContainsString('Old text', $body);
        self::assertStringNotContainsString('<ins>', $body);
    }

    #[Test]
    public function workspaceUsersAreToldTheLogShowsLiveRenders(): void
    {
        $this->logIn(1);
        $GLOBALS['BE_USER']->workspace = 1;

        self::assertStringContainsString('The revision log shows what the live site delivered.', $this->render('handleRequest', ['id' => 1]));
    }

    private function logIn(int $userUid): void
    {
        $backendUser = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, int> $query
     */
    private function render(string $action, array $query): string
    {
        $module = $this->get(ModuleProvider::class)->getModule('provenance_revisions', $GLOBALS['BE_USER']);
        $request = (new ServerRequest('https://example.org/typo3/module/web/provenance/revisions', 'GET'))
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', ModuleData::createFromModule($module, []))
            ->withAttribute('route', new Route('/module/web/provenance/revisions', ['packageName' => 'cms-provenance/provenance-signing', '_identifier' => 'provenance_revisions']));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $this->get(RevisionsModuleController::class)->{$action}($request);
        self::assertSame(200, $response->getStatusCode());

        return (string)$response->getBody();
    }
}
