<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap — pure unit tests only.
 *
 * The suite intentionally avoids booting the CodeIgniter kernel: every
 * class under test (validation rules, paginator, crypto, redaction) is
 * framework-independent or overrides its framework touchpoints in a
 * test subclass. Integration tests requiring MySQL are out of scope
 * for the unit suite and run against a staged database instead.
 *
 * `Config\Constants` is a pure `define()` file with no class side
 * effects, so we load it here to make the SYNAPSE_* / BMG_STATE_*
 * constants available to unit tests without booting the framework.
 */

require __DIR__ . '/../vendor/autoload.php';
/*
 * Path constants — the CodeIgniter bootstrap normally defines
 * APPPATH / SYSTEMPATH / FCPATH / VENDORPATH / WRITEPATH via
 * `Config\Paths`. The unit suite does not boot that subsystem, but
 * several classes (e.g. `Config\Database::$filesPath`, the framework's
 * `BaseConnection`, the file locator used during discovery) reference
 * these constants at class-load or instantiate time. Define minimal
 * stand-ins so `Config\Services::database()` can resolve a real
 * connection in integration-style tests.
 */
if (! defined('ROOTPATH')) {
    define('ROOTPATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
}
if (! defined('APPPATH')) {
    define('APPPATH', realpath(__DIR__ . '/../app') . DIRECTORY_SEPARATOR);
}
if (! defined('SYSTEMPATH')) {
    define('SYSTEMPATH', realpath(__DIR__ . '/../vendor/codeigniter4/framework/system') . DIRECTORY_SEPARATOR);
}
if (! defined('FCPATH')) {
    define('FCPATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
}
if (! defined('VENDORPATH')) {
    define('VENDORPATH', realpath(__DIR__ . '/../vendor') . DIRECTORY_SEPARATOR);
}
if (! defined('WRITEPATH')) {
    define('WRITEPATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR);
}
/*
 * ENVIRONMENT — the framework's database / cache / session factories
 * branch on this. CI4's `CodeIgniter` class defaults to 'production'
 * if unset. We deliberately pin this to 'development' (NOT 'testing')
 * because the app's `Config\Database` only declares a `$default`
 * connection group; setting ENVIRONMENT='testing' would make CI4 look
 * for a `$tests` group and throw. Integration-style unit tests like
 * `PatientLookupServiceTest` still connect through that same default
 * group.
 */
if (! defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'development');
}
require __DIR__ . '/../app/Config/Constants.php';

/*
 * `BaseConfig::__construct()` (CI4 framework) does
 *     static::$moduleConfig ??= new Modules();
 * which resolves `Modules` via the `use Config\Modules;` import at the
 * top of BaseConfig.php. The CI4 framework ships its default `Modules`
 * config at `vendor/codeigniter4/framework/app/Config/Modules.php`,
 * which sits OUTSIDE the framework's PSR-4 namespace
 * (`CodeIgniter\` => `vendor/codeigniter4/framework/system/`). The
 * framework normally wires this up in its own bootstrap; in our
 * minimal unit-test bootstrap we explicitly require the file so
 * `BaseConfig` subclasses can be instantiated.
 */
require_once __DIR__ . '/../vendor/codeigniter4/framework/app/Config/Modules.php';

/*
 * Stub `Config\Modules` for the unit suite.
 *
 * `BaseConfig::registerProperties()` (CI4 framework) auto-discovers
 * `Config\Registrar.php` classes whenever `Modules::$aliases` contains
 * `'registrars'` AND `Modules::$enabled` is true. That path calls
 * `service('locator')` and (in the error branch) `clean_path()` —
 * both helpers are defined by CI4's full bootstrap, which the unit
 * suite deliberately does not run. The app's own `app/Config/Modules.php`
 * keeps `enabled = true` and lists `'registrars'` in `$aliases`, so a
 * stock `Config\Modules` would crash the moment any Config subclass is
 * instantiated.
 *
 * We declare a test-only subclass that disables auto-discovery and
 * inject it via `BaseConfig::setModules()` BEFORE any Config subclass
 * is constructed. This skips the entire registrar-discovery path and
 * makes `service()` / `clean_path()` unreachable from the suite.
 */
final class TestModules extends \Config\Modules
{
    public $enabled = false;
}

\CodeIgniter\Config\BaseConfig::setModules(new TestModules());

/*
 * Stub global `service()` helper.
 *
 * `CodeIgniter\Events::initialize()` (and a few other framework
 * classes) call `service('locator')` when the relevant auto-discovery
 * alias is enabled. The real `service()` lives in
 * `vendor/codeigniter4/framework/system/Common.php` and routes through
 * `\CodeIgniter\Config\Services::get()` — that requires a full CI4
 * kernel boot which the unit suite deliberately skips.
 *
 * For the unit suite we only need the `'locator'` service: the
 * `search()` method is what `Events::initialize()` calls to discover
 * `Config/Events.php` files. We return a stub that yields no files
 * (disabling auto-discovery) and any other service request returns
 * null. This is intentionally a narrow shim — expanding it to a full
 * service factory is out of scope for the unit suite.
 */
if (! function_exists('service')) {
    final class TestLocator
    {
        /** @return list<string> */
        public function search(string $path): array
        {
            return [];
        }

        public function findQualifiedNameFromPath(string $path): string|false
        {
            return false;
        }
    }
    function service(string $name, ...$params): ?object
    {
        if ($name === 'locator') {
            return new TestLocator();
        }
        return null;
    }
}

/*
 * `Config\Services` lives in `app/Config/Services.php`. It's required by
 * `PatientLookupServiceTest` and any other test that exercises a service
 * using CI4's service-locator (`Services::database()`). The PSR-4
 * autoloader maps `Config\` → `app/Config/` for app-defined configs, so
 * the class is normally auto-loadable; we require it explicitly here so
 * the dependency is wired up before the unit suite even attempts to
 * touch a service. The `use` imports inside that file are just type
 * aliases — they do not autoload `App\Auth\*` etc.
 */
require_once __DIR__ . '/../app/Config/Services.php';

/*
 * Minimal `config()` helper for tests that touch Config\* classes.
 *
 * The unit suite deliberately avoids booting the CodeIgniter kernel
 * (see header comment). A handful of tests still need to resolve
 * `Config\PatientRegistry` etc. via the standard CI4 helper. We
 * provide a tiny stub here that:
 *
 *   1. Accepts a fully-qualified class name (with or without leading
 *      backslash) — matches CI4's `config()` signature.
 *   2. Loads the class file from `app/Config/<short>.php` when it
 *      can't be autoloaded by the standard PSR-4 / classmap setup.
 *   3. Caches one shared instance per name, like CI4's helper.
 *
 * This is intentionally NOT a full reimplementation of CI4's
 * `config()` — it does not support dot-notation (e.g. `Foo.bar`) or
 * environment overlays (those require the framework boot).
 */
if (! function_exists('config')) {
    function config(string $name, bool $getShared = true): ?object
    {
        static $instances = [];
        $key = $name . ($getShared ? ':shared' : ':new');
        if ($getShared && isset($instances[$key])) {
            return $instances[$key];
        }

        $class = ltrim($name, '\\');
        if (! class_exists($class)) {
            // Strip a leading `Config\` (if present) so the path maps
            // to `app/Config/<ShortName>.php`. The CI4 convention is
            // that all Config classes live directly under app/Config.
            $shortName = preg_replace('/^Config\\\\/', '', $class);
            $candidates = [
                __DIR__ . '/../app/Config/' . str_replace('\\', '/', $shortName) . '.php',
            ];
            foreach ($candidates as $file) {
                if (is_file($file)) {
                    require_once $file;
                    break;
                }
            }
        }

        if (! class_exists($class)) {
            return null;
        }
        $instance = new $class();
        if ($getShared) {
            $instances[$key] = $instance;
        }
        return $instance;
    }
}
