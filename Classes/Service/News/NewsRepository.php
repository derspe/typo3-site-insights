<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\News;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * News records (EXT:news) of a folder that have a detail view of their own.
 * Only records visible in the frontend are returned (the default restrictions apply).
 */
class NewsRepository
{
    public const TABLE = 'tx_news_domain_model_news';
    // Folders with more records are cut off (newest first)
    public const MAX_RECORDS = 1000;
    // News of type "internal page" and "external URL" link elsewhere
    private const LINK_TYPES = ['1', '2'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * EXT:news is installed (its TCA only exists then).
     */
    public function isAvailable(): bool
    {
        return isset($GLOBALS['TCA'][self::TABLE]);
    }

    public function hasRecords(int $folder): bool
    {
        return $this->isAvailable()
            && $this->connectionPool->getConnectionForTable(self::TABLE)->count('uid', self::TABLE, $this->where($folder)) > 0;
    }

    /**
     * Records of the folder in the given language, newest first.
     *
     * @return array{records: list<array{uid: int, newsUid: int, title: string, datetime: int}>, truncated: bool}
     */
    public function findInFolder(int $folder, int $language): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['uid', 'title', 'datetime', 'type', 'sys_language_uid', 'l10n_parent'], self::TABLE, $this->where($folder), [], ['datetime' => 'DESC'])
            ->fetchAllAssociative();
        $records = [];
        foreach ($rows as $row) {
            $rowLanguage = (int)$row['sys_language_uid'];
            if (($rowLanguage !== $language && $rowLanguage !== -1) || in_array((string)$row['type'], self::LINK_TYPES, true)) {
                continue;
            }
            $records[] = self::record($row);
        }
        usort($records, static fn(array $a, array $b): int => $b['datetime'] <=> $a['datetime']);
        return [
            'records' => array_slice($records, 0, self::MAX_RECORDS),
            'truncated' => count($records) > self::MAX_RECORDS,
        ];
    }

    /**
     * @return array{uid: int, newsUid: int, title: string, datetime: int}|null
     */
    public function findOne(int $folder, int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['uid', 'title', 'datetime', 'type', 'sys_language_uid', 'l10n_parent'], self::TABLE, ['uid' => $uid] + $this->where($folder))
            ->fetchAssociative();
        if (!is_array($row) || in_array((string)$row['type'], self::LINK_TYPES, true)) {
            return null;
        }
        return self::record($row);
    }

    /**
     * @return array<string, int>
     */
    private function where(int $folder): array
    {
        $where = ['pid' => $folder];
        // Only live records, no workspace versions
        if (($GLOBALS['TCA'][self::TABLE]['ctrl']['versioningWS'] ?? false) === true) {
            $where['t3ver_wsid'] = 0;
        }
        return $where;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{uid: int, newsUid: int, title: string, datetime: int}
     */
    private static function record(array $row): array
    {
        $parent = (int)($row['l10n_parent'] ?? 0);
        return [
            'uid' => (int)$row['uid'],
            // The detail view expects the uid of the default language record
            'newsUid' => (int)$row['sys_language_uid'] > 0 && $parent > 0 ? $parent : (int)$row['uid'],
            'title' => (string)$row['title'],
            'datetime' => (int)$row['datetime'],
        ];
    }
}
