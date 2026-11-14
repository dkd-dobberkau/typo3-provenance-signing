<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Revisions of signed page records. Rows are only ever inserted.
 */
final readonly class RevisionRepository
{
    private const TABLE = 'tx_provenancesigning_revision';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, int|string> $row
     */
    public function add(array $row): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $row);
    }

    public function hasRecord(int $pageUid, int $languageUid, string $recordHash): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('record_hash', $queryBuilder->createNamedParameter($recordHash))
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }
}
