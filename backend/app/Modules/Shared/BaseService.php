<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * BaseService — transaction wrapper shared by every module service.
 *
 * All state-changing service methods MUST extend `txn()` semantics:
 *
 *     $this->db->transStart();
 *       // ... business + outbox writes
 *     $this->db->transComplete();
 *     if ($this->db->transStatus() === false) { ... }
 *
 * `selectForUpdate()` is the standard pattern for hot rows.
 */
abstract class BaseService
{
    protected BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Services::database();
    }

    /**
     * Row-locking read — `SELECT ... FOR UPDATE` inside the current
     * transaction. CI4's query builder has no lock helper, so this is
     * the single sanctioned raw-SQL escape hatch. `null` values in
     * `$where` compile to `IS NULL`.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>|null
     */
    protected function selectForUpdate(string $table, array $where, string $select = '*'): ?array
    {
        $conds = [];
        $binds = [];
        foreach ($where as $col => $val) {
            if ($val === null) {
                $conds[] = "`{$col}` IS NULL";
                continue;
            }
            $conds[] = "`{$col}` = ?";
            $binds[] = $val;
        }

        $sql = sprintf(
            'SELECT %s FROM `%s` WHERE %s LIMIT 1 FOR UPDATE',
            $select,
            $table,
            implode(' AND ', $conds),
        );

        $row = $this->db->query($sql, $binds)->getRowArray();
        return $row !== null && $row !== [] ? $row : null;
    }

    /**
     * Run `callback` inside an atomic transaction.
     * On unhandled exception, the transaction is rolled back and the
     * exception rethrown.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function txn(callable $callback): mixed
    {
        $this->db->transStart();
        try {
            $result = $callback();
            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw \App\Exceptions\ApiException::conflict('transaction.rolled_back', 'Database transaction failed.');
            }
            return $result;
        } catch (\Throwable $t) {
            $this->db->transRollback();
            throw $t;
        }
    }
}