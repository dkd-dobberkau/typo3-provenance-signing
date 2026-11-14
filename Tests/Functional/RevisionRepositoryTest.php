<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Tests\Functional;

use CmsProvenance\ProvenanceSigning\Domain\Repository\RevisionRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RevisionRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['cms-provenance/provenance', 'cms-provenance/provenance-signing'];

    protected function setUp(): void
    {
        parent::setUp();
        $repository = $this->get(RevisionRepository::class);
        // uid => page, language, signed_at
        foreach ([[1, 0, 100], [1, 1, 150], [1, 0, 200], [2, 0, 250], [1, 0, 300]] as [$page, $language, $time]) {
            $repository->add([
                'page_uid' => $page, 'language_uid' => $language, 'signed_at' => $time,
                'record_hash' => hash('sha256', (string)$time), 'content_hash' => 'sha256:' . $time,
                'signed_record' => '{}', 'canonical_text' => 'text ' . $time, 'request_kind' => 'html',
            ]);
        }
    }

    #[Test]
    public function revisionsOfAPageAreListedNewestFirst(): void
    {
        $rows = $this->get(RevisionRepository::class)->findForPage(1, [0, 1], 0, 50);

        self::assertSame([300, 200, 150, 100], array_map(static fn(array $row): int => (int)$row['signed_at'], $rows));
        self::assertArrayNotHasKey('canonical_text', $rows[0]);
        self::assertSame(4, $this->get(RevisionRepository::class)->countForPage(1, [0, 1]));
    }

    #[Test]
    public function onlyTheGivenLanguagesAreListed(): void
    {
        $rows = $this->get(RevisionRepository::class)->findForPage(1, [0], 0, 50);

        self::assertSame([300, 200, 100], array_map(static fn(array $row): int => (int)$row['signed_at'], $rows));
        self::assertSame(3, $this->get(RevisionRepository::class)->countForPage(1, [0]));
    }

    #[Test]
    public function previousRevisionStaysInPageAndLanguage(): void
    {
        $repository = $this->get(RevisionRepository::class);
        $newest = $repository->findByUid(5);

        self::assertSame('text 200', $repository->previousOf($newest)['canonical_text']);
        self::assertNull($repository->previousOf($repository->findByUid(2)));
        self::assertNull($repository->previousOf($repository->findByUid(1)));
    }

    #[Test]
    public function unknownRevisionIsNull(): void
    {
        self::assertNull($this->get(RevisionRepository::class)->findByUid(99));
    }
}
