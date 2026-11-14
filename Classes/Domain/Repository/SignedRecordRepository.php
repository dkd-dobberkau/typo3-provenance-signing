<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Keeps the latest signed record per page and language, for the audit
 * endpoint. Rendering a page again overwrites its row.
 */
final readonly class SignedRecordRepository
{
    private const TABLE = 'tx_provenancesigning_record';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function save(int $pageUid, int $languageUid, \stdClass $signedRecord): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = [
            'document_uri' => (string)($signedRecord->{'@id'} ?? ''),
            'content_hash' => (string)($signedRecord->contentHash ?? ''),
            'key_id' => (string)($signedRecord->signature->keyId ?? ''),
            'signed_record' => json_encode($signedRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'signed_at' => time(),
        ];
        $identifier = ['page_uid' => $pageUid, 'language_uid' => $languageUid];

        $connection->transactional(static function () use ($connection, $row, $identifier): void {
            $updated = $connection->update(self::TABLE, $row, $identifier);
            if ($updated === 0) {
                $connection->insert(self::TABLE, $row + $identifier);
            }
        });
    }

    /**
     * The signed record each language of a page currently delivers.
     *
     * @return array<int, \stdClass> by language uid
     */
    public function findForPage(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('language_uid', 'signed_record')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAllAssociative();

        $records = [];
        foreach ($rows as $row) {
            $record = json_decode((string)$row['signed_record'], false);
            if ($record instanceof \stdClass) {
                $records[(int)$row['language_uid']] = $record;
            }
        }

        return $records;
    }
}
