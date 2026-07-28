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

    public function __construct()
    {
        parent::__construct();

        // Under the test environment CI4 swaps to the `tests` group;
        // SYNAPSE runs everything against `default` (unit tests are
        // DB-free by design).
    }
}