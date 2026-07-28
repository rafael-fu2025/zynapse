<?php

declare(strict_types=1);

namespace Config;

/**
 * Paths — canonical SYNAPSE backend path map.
 *
 * Loaded by `public/index.php` and `spark` BEFORE the framework boots,
 * so property names MUST match `CodeIgniter\Boot` expectations
 * (`systemDirectory`, not `systemPath`).
 */
class Paths
{
    public string $systemDirectory = __DIR__ . '/../../vendor/codeigniter4/framework/system';

    public string $appDirectory = __DIR__ . '/..';

    public string $writableDirectory = __DIR__ . '/../../writable';

    public string $testsDirectory = __DIR__ . '/../../tests';

    public string $viewDirectory = __DIR__ . '/../Views';
}
