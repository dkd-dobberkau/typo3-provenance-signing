<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Tests\Unit\Revision;

use CmsProvenance\ProvenanceSigning\Revision\RevisionChanges;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RevisionChangesTest extends UnitTestCase
{
    #[Test]
    public function firstRevisionHasNoChanges(): void
    {
        self::assertSame([], (new RevisionChanges())->between(null, $this->revision([])));
    }

    #[Test]
    public function changedTextIsNamedFirst(): void
    {
        $previous = $this->revision(['headline' => 'A'], contentHash: 'sha256:1');
        $current = $this->revision(['headline' => 'A'], contentHash: 'sha256:2');

        self::assertSame(['text'], (new RevisionChanges())->between($previous, $current));
    }

    #[Test]
    public function changedMembersAreNamedInOrder(): void
    {
        $previous = $this->revision(['image' => [['@id' => 'urn:uuid:1']], 'dateModified' => '2026-11-14T10:00:00Z', 'headline' => 'A']);
        $current = $this->revision(['image' => [['@id' => 'urn:uuid:2']], 'dateModified' => '2026-11-14T11:00:00Z', 'headline' => 'A']);

        self::assertSame(['dateModified', 'image'], (new RevisionChanges())->between($previous, $current));
    }

    #[Test]
    public function addedAndRemovedMembersCount(): void
    {
        $previous = $this->revision(['maintainedDate' => '2026-11-01']);
        $current = $this->revision(['citation' => [['url' => 'https://example.org']]]);

        self::assertSame(['citation', 'maintainedDate'], (new RevisionChanges())->between($previous, $current));
    }

    #[Test]
    public function keyRotationIsNamedLastAndSignatureIsIgnored(): void
    {
        $previous = $this->revision(['headline' => 'A'], keyId: 'key-1');
        $current = $this->revision(['headline' => 'A'], keyId: 'key-2');

        self::assertSame(['key'], (new RevisionChanges())->between($previous, $current));
    }

    /**
     * @param array<string, mixed> $members
     * @return array<string, mixed>
     */
    private function revision(array $members, string $contentHash = 'sha256:1', string $keyId = 'key-1'): array
    {
        $record = $members + [
            '@id' => 'urn:uuid:0',
            'contentHash' => $contentHash,
            'key' => 'https://example.org/keys/' . $keyId,
            'signature' => ['keyId' => $keyId, 'signatureValue' => bin2hex(random_bytes(4))],
        ];

        return ['content_hash' => $contentHash, 'key_id' => $keyId, 'signed_record' => json_encode($record)];
    }
}
