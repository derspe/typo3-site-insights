<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Fixtures;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Array backed replacement for the simple Connection API (select, insert, update, delete,
 * count) used by settings, jobs and similar tables.
 */
final class InMemoryDatabase
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];
    private int $nextUid = 1;
    /** Throw this on every access (simulates a database error) */
    public ?\Throwable $failWith = null;

    public function __construct(private readonly TestCase $test) {}

    public function connectionPool(): ConnectionPool
    {
        $pool = $this->mock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturnCallback(fn(): Connection => $this->connection());
        return $pool;
    }

    public function connection(): Connection
    {
        $connection = $this->mock(Connection::class);
        $connection->method('select')->willReturnCallback(function (array $columns, string $table, array $where = [], array $groupBy = [], array $orderBy = [], int $limit = 0): Result {
            $this->guard();
            $rows = array_values(array_filter($this->tables[$table] ?? [], fn(array $row): bool => $this->matches($row, $where)));
            if ($limit > 0) {
                $rows = array_slice($rows, 0, $limit);
            }
            return $this->result($rows);
        });
        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->guard();
            $this->tables[$table][] = $data + ['uid' => $this->nextUid++];
            return 1;
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $data, array $where): int {
            $this->guard();
            $affected = 0;
            foreach ($this->tables[$table] ?? [] as $i => $row) {
                if ($this->matches($row, $where)) {
                    $this->tables[$table][$i] = $data + $row;
                    $affected++;
                }
            }
            return $affected;
        });
        $connection->method('delete')->willReturnCallback(function (string $table, array $where): int {
            $this->guard();
            $before = count($this->tables[$table] ?? []);
            $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? [], fn(array $row): bool => !$this->matches($row, $where)));
            return $before - count($this->tables[$table]);
        });
        $connection->method('count')->willReturnCallback(function (string $item, string $table, array $where): int {
            $this->guard();
            return count(array_filter($this->tables[$table] ?? [], fn(array $row): bool => $this->matches($row, $where)));
        });
        $connection->method('executeStatement')->willReturnCallback(function (): int {
            $this->guard();
            return 0;
        });
        $connection->method('lastInsertId')->willReturnCallback(fn(): string => (string)($this->nextUid - 1));
        return $connection;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function result(array $rows): Result
    {
        $result = $this->mock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        $result->method('fetchAssociative')->willReturn($rows[0] ?? false);
        $result->method('fetchOne')->willReturn($rows === [] ? false : reset($rows[0]));
        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where
     */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $column => $value) {
            if ((string)($row[$column] ?? '') !== (string)$value) {
                return false;
            }
        }
        return true;
    }

    private function guard(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mock(string $class): object
    {
        $method = new \ReflectionMethod(TestCase::class, 'createMock');
        return $method->invoke($this->test, $class);
    }
}
