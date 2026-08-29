<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Database\Config as BaseDatabaseConfig;

class Database extends BaseDatabaseConfig
{
    /**
     * Directory holding seed files (framework Seeder requirement).
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Which connection group to use by default.
     */
    public string $defaultGroup = 'default';

    /**
     * The default group — sole connection in Phase 1.
     * Values are loaded from `.env`; defaults below match CI 4 stock.
     */
    public array $default = [
        'DSN'      => '',
        'hostname' => '127.0.0.1',
        'username' => 'root',
        'password' => '',
        'database' => 'synapse_zcode',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => true,
        'cacheOn'  => false,
        'cacheDir' => '',
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_0900_ai_ci',
        'swapPre'  => '',
        // No TLS to localhost by default; enable per-env via
        // `database.default.encrypt` when the DB is remote.
        'encrypt'  => false,
        'compress'        => false,
        'strictOn'        => true,    // STRICT_TRANS_TABLES / STRICT_ALL_TABLES
        'failover'        => [],
        'port'            => 3306,
        'dateFormat'      => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
        'numberFormat'    => [
            'decimal'    => 4,
            'thousands'  => ',',
            'decimalPt'  => '.',
        ],
    ];

    /**
     * The feature/integration suite connection.
     *
     * MySQLi, not SQLite: ~40 migrations emit raw DDL that SQLite cannot
     * parse — `SIGNAL SQLSTATE '45000'` triggers (BmgBatches,
     * BmgCompositionSumCheck), `ALTER TABLE ... ADD CONSTRAINT ... CHECK`,
     * and ENUM columns. The BMG mass invariants are enforced *by the
     * database*, so they are only reachable against real MySQL/MariaDB.
     *
     * `database` defaults to a DISTINCT schema from the dev connection:
     * DatabaseTestTrait truncates/refreshes between cases, so pointing
     * this at `synapse_zcode` would destroy local dev data.
     *
     * `DBPrefix` must stay empty — migrations and services name tables
     * literally in raw SQL, so a prefix would not be applied consistently.
     */
    public array $tests = [
        'DSN'      => '',
        'hostname' => '127.0.0.1',
        'username' => 'root',
        'password' => '',
        'database' => 'synapse_zcode_test',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => true,
        'cacheOn'  => false,
        'cacheDir' => '',
        'charset'  => 'utf8mb4',
        // Portable across MySQL 5.7+/8.4 and MariaDB 10.4+.
        // `utf8mb4_0900_ai_ci` (used by $default) is MySQL-8-only.
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => true,
        'failover' => [],
        'port'     => 3306,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
        'numberFormat' => [
            'decimal'   => 4,
            'thousands' => ',',
            'decimalPt' => '.',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        // Never let a test run touch the live/dev schema. The unit suite
        // (tests/bootstrap.php) pins ENVIRONMENT='development' and stays
        // on `default`; the feature suite boots the real kernel with
        // ENVIRONMENT='testing' and lands here.
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}