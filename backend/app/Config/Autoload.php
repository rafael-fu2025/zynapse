<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\AutoloadConfig;

/**
 * Autoload — PSR-4 namespaces. Composer handles the rest; this file
 * exists because CI 4's framework expects it and because we expose
 * a small in-code whitelist for non-PSR-4 helpers.
 */
class Autoload extends AutoloadConfig
{
    /**
     * NOTE: parent properties are intentionally untyped in the framework;
     * adding types here is a fatal inheritance error.
     *
     * @var array<string, list<string>|string>
     */
    public $psr4 = [
        'App\\'     => APPPATH,
        'Modules\\' => APPPATH . 'Modules',
        'Config\\'  => APPPATH . 'Config',
    ];

    /** @var list<string> */
    public $helpers = ['url', 'form', 'text', 'date', 'inflector'];

    public function __construct()
    {
        parent::__construct();

        // Module-specific namespaces are registered per module via
        // service providers in later phases. Phase 1 only loads the
        // top-level `Modules\` prefix.
    }
}