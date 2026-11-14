<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

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

    /**
     * Without the large columns; newest first.
     *
     * @param list<int> $languageUids
     * @return list<array<string, mixed>>
     */
    public function findForPage(int $pageUid, array $languageUids, int $offset, int $limit): array
    {
        if ($languageUids === []) {
            return [];
        }
        $queryBuilder = $this->forPage($pageUid, $languageUids);

        return $queryBuilder
            ->select('uid', 'page_uid', 'language_uid', 'document_uri', 'content_hash', 'key_id', 'cps_conformance', 'signed_record', 'request_kind', 'request_url', 'signed_at')
            ->orderBy('signed_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param list<int> $languageUids
     */
    public function countForPage(int $pageUid, array $languageUids): int
    {
        if ($languageUids === []) {
            return 0;
        }

        return (int)$this->forPage($pageUid, $languageUids)->count('uid')->executeQuery()->fetchOne();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * The revision of the same page and language written just before.
     *
     * @param array<string, mixed> $revision
     * @return array<string, mixed>|null
     */
    public function previousOf(array $revision): ?array
    {
        $queryBuilder = $this->forPage((int)$revision['page_uid'], [(int)$revision['language_uid']]);
        $signedAt = $queryBuilder->createNamedParameter((int)$revision['signed_at'], Connection::PARAM_INT);
        $row = $queryBuilder
            ->select('*')
            ->andWhere($queryBuilder->expr()->or(
                $queryBuilder->expr()->lt('signed_at', $signedAt),
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('signed_at', $signedAt),
                    $queryBuilder->expr()->lt('uid', $queryBuilder->createNamedParameter((int)$revision['uid'], Connection::PARAM_INT))
                )
            ))
            ->orderBy('signed_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @param list<int> $languageUids
     */
    private function forPage(int $pageUid, array $languageUids): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('language_uid', $queryBuilder->createNamedParameter($languageUids, Connection::PARAM_INT_ARRAY))
            );
    }
}
