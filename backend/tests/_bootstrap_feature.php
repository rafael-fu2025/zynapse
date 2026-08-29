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

/*
 * CI-provided credentials — MUST run before the dev-schema guard below so
 * an env override participates in that decision.
 *
 * GitHub Actions cannot export `env:` names that contain dots (getenv() on
 * the runner sees nothing — the first CI run died with "using password: NO"
 * despite the workflow setting `database.tests.password`), so the workflow
 * provides plain uppercase names. The dotted `database.tests.*` names
 * remain supported for local overrides.
 *
 * CI4's BaseConfig hydrates dotted env vars into config arrays ONLY from
 * $_ENV/$_SERVER, and PHP's default `variables_order` ('GPCS') leaves
 * $_ENV empty — so whatever their source, the values are mirrored into
 * $_ENV/$_SERVER (putenv for good measure) so that BOTH this bootstrap's
 * mysqli_connect() below AND the framework's own connection (a fresh
 * Config\Database hydrating from the superglobals) get the creds.
 */
$dbEnvMap = [
    'SYNAPSE_TEST_DB_HOST' => 'hostname',
    'SYNAPSE_TEST_DB_USER' => 'username',
    'SYNAPSE_TEST_DB_PASS' => 'password',
    'SYNAPSE_TEST_DB_NAME' => 'database',
    'SYNAPSE_TEST_DB_PORT' => 'port',
];
foreach ($dbEnvMap as $envName => $configKey) {
    $envValue = getenv($envName);
    if ($envValue === false || $envValue === '') {
        $envValue = getenv("database.tests.{$configKey}");
    }
    if ($envValue === false || $envValue === '') {
        continue;
    }
    $dbConfig->tests[$configKey] = $configKey === 'port' ? (int) $envValue : $envValue;
    $_ENV["database.tests.{$configKey}"]    = $envValue;
    $_SERVER["database.tests.{$configKey}"] = $envValue;
    putenv("database.tests.{$configKey}={$envValue}");
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
