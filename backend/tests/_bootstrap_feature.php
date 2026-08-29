<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap — feature / integration suite.
 *
 * WHY THIS IS A SEPARATE FILE FROM `tests/bootstrap.php`
 * -----------------------------------------------------
 * The two bootstraps are mutually exclusive and can never be merged:
 *
 *   - `tests/bootstrap.php` pins ENVIRONMENT='development' and hand-stubs
 *     the global `service()` and `config()` helpers behind
 *     `function_exists()` guards. It deliberately never boots the kernel.
 *   - This file boots the real kernel, which loads the framework's
 *     `system/Common.php` — and that file declares `service()` and
 *     `config()` for real. Loading it after the stubs is a fatal
 *     redeclare error.
 *
 * PHPUnit has no per-testsuite bootstrap attribute, so the split is
 * expressed as two config files instead: `phpunit.xml` (unit) and
 * `phpunit.feature.xml` (this one). Run both via `composer test:all`.
 *
 * Booting the kernel is what makes `FeatureTestTrait` usable: it gives us
 * a real Router, Filters pipeline, Request/Response, and DB connection, so
 * a test can call an actual route end-to-end instead of poking a service.
 *
 * ENVIRONMENT='testing' (set by the framework bootstrap below) makes
 * `Config\Database::__construct()` swap `$defaultGroup` to `tests`, which
 * points at a schema distinct from the dev database. That swap is the only
 * thing standing between a `DatabaseTestTrait` refresh and your dev data,
 * so it is asserted defensively at the bottom of this file.
 */

// The framework's own test bootstrap: defines ENVIRONMENT='testing' and
// $_SERVER['CI_ENVIRONMENT']='testing' BEFORE loading `.env`. CI4's
// DotEnv::setVariable() never overwrites an existing value, so the repo's
// `.env` (CI_ENVIRONMENT = development) cannot clobber the test
// environment. It then runs Boot::bootTest() and loads the route table.
//
// HOMEPATH is derived from getcwd(), so PHPUnit must be invoked from the
// `backend/` directory. Fail loudly rather than mysteriously if it isn't.
$backendRoot = realpath(__DIR__ . '/..');
if ($backendRoot === false || realpath((string) getcwd()) !== $backendRoot) {
    fwrite(STDERR, sprintf(
        "\n[feature-bootstrap] PHPUnit must run from the backend/ directory.\n"
        . "  expected cwd: %s\n  actual cwd:   %s\n\n",
        (string) $backendRoot,
        (string) getcwd(),
    ));
    exit(1);
}

require $backendRoot . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

/*
 * Guard rail: refuse to run if the test connection resolves to the same
 * schema as the development connection.
 *
 * `DatabaseTestTrait` truncates and re-migrates between test cases. If the
 * `tests` group ever points at `synapse_zcode`, a single `composer
 * test:feature` would silently destroy the local dev database. This makes
 * that a hard, early failure instead.
 */
$dbConfig = config('Database');

if ($dbConfig->defaultGroup !== 'tests') {
    fwrite(STDERR, sprintf(
        "\n[feature-bootstrap] Expected Config\\Database::\$defaultGroup === 'tests', got '%s'.\n"
        . "  ENVIRONMENT is '%s'. The 'testing' guard in Config\\Database::__construct() did not fire.\n\n",
        $dbConfig->defaultGroup,
        ENVIRONMENT,
    ));
    exit(1);
}

$testDatabase = (string) ($dbConfig->tests['database'] ?? '');
$devDatabase  = (string) ($dbConfig->default['database'] ?? '');

if ($testDatabase === '' || $testDatabase === $devDatabase) {
    fwrite(STDERR, sprintf(
        "\n[feature-bootstrap] REFUSING TO RUN — the test schema must differ from the dev schema.\n"
        . "  tests.database:   '%s'\n  default.database: '%s'\n\n"
        . "  Set `database.tests.database` in .env to a dedicated schema.\n\n",
        $testDatabase,
        $devDatabase,
    ));
    exit(1);
}

/*
 * Mirror `database.tests.*` process-env overrides into the config and the
 * superglobals. CI4's BaseConfig hydrates dotted env vars into array
 * properties ONLY from $_ENV/$_SERVER, and PHP's default `variables_order`
 * ('GPCS') leaves $_ENV empty on CI runners — so GitHub Actions step env
 * (`database.tests.username: …`) is visible to getenv() but never reaches
 * the config, and the suite dies with "Access denied (using password: NO)".
 * getenv() always sees the real process environment, so read from there and
 * publish into $_ENV/$_SERVER (putenv for good measure) so that BOTH this
 * bootstrap's mysqli_connect() below AND the framework's own connection
 * (a fresh Config\Database hydrating from the superglobals) get the creds.
 */
foreach (['hostname', 'username', 'password', 'port', 'database'] as $envKey) {
    $envValue = getenv("database.tests.{$envKey}");
    if ($envValue === false || $envValue === '') {
        continue;
    }
    $dbConfig->tests[$envKey] = $envKey === 'port' ? (int) $envValue : $envValue;
    $_ENV["database.tests.{$envKey}"]    = $envValue;
    $_SERVER["database.tests.{$envKey}"] = $envValue;
    putenv("database.tests.{$envKey}={$envValue}");
}

/*
 * Self-provision the test schema when it does not exist, so a fresh
 * checkout (or a CI container) can run `composer test:feature` without a
 * manual CREATE DATABASE. Connecting WITHOUT a database name keeps this
 * independent of CI4's connection state; the collation matches
 * Config\Database::$tests so migrated tables stay consistent.
 */
$provision = mysqli_connect(
    (string) ($dbConfig->tests['hostname'] ?? '127.0.0.1'),
    (string) ($dbConfig->tests['username'] ?? 'root'),
    (string) ($dbConfig->tests['password'] ?? ''),
    port: (int) ($dbConfig->tests['port'] ?? 3306),
);

if ($provision === false) {
    fwrite(STDERR, sprintf(
        "\n[feature-bootstrap] Cannot reach MySQL/MariaDB for the feature suite (%s:%s as '%s').\n"
        . "  Start the database (a local MariaDB service or a CI service container)\n"
        . "  and re-run.\n\n",
        (string) ($dbConfig->tests['hostname'] ?? '127.0.0.1'),
        (string) ($dbConfig->tests['port'] ?? 3306),
        (string) ($dbConfig->tests['username'] ?? 'root'),
    ));
    exit(1);
}

// PHP >= 8.1 mysqli is in exception mode: a missing schema THROWS rather
// than making mysqli_select_db() return false.
$dbExists = true;
try {
    $dbExists = mysqli_select_db($provision, $testDatabase);
} catch (mysqli_sql_exception) {
    $dbExists = false;
}

if (! $dbExists) {
    $create = 'CREATE DATABASE `' . str_replace('`', '', $testDatabase) . '`'
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    if (! mysqli_query($provision, $create)) {
        fwrite(STDERR, sprintf(
            "\n[feature-bootstrap] Could not create test schema `%s`: %s\n\n",
            $testDatabase,
            mysqli_error($provision),
        ));
        mysqli_close($provision);
        exit(1);
    }
    echo "[feature-bootstrap] Created test schema `{$testDatabase}`.\n";
}
mysqli_close($provision);

unset($backendRoot, $dbConfig, $testDatabase, $devDatabase, $provision);
