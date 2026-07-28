<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Validation\StrictRules\CreditCardRules;
use CodeIgniter\Validation\StrictRules\FileRules;
use CodeIgniter\Validation\StrictRules\FormatRules;
use CodeIgniter\Validation\StrictRules\Rules;

/**
 * Validation — registers SYNAPSE custom rule sets with CodeIgniter 4.
 *
 * CI4's auto-resolver picks this class up via the `Config\Validation`
 * service binding; rule sets listed here are merged with the framework
 * defaults.
 */
class Validation extends BaseConfig
{
    /**
     * Rule-set classes. Order is preserved; later sets can override
     * earlier ones if a method name collides.
     *
     * @var list<class-string>
     */
    public array $ruleSets = [
        Rules::class,
        FormatRules::class,
        FileRules::class,
        CreditCardRules::class,
        \App\Validation\BmgMassInvariant::class,
    ];

    /**
     * @var array<string, string>
     */
    public array $templates = [
        'list'   => 'CodeIgniter\Validation\Views\list',
        'single' => 'CodeIgniter\Validation\Views\single',
    ];

    /**
     * Per-field rules and messages that controllers may opt into.
     * Keep this empty — controllers declare rules inline so the contract
     * stays near the endpoint that owns it.
     */
    public array $files = [];

    public array $rules = [];

    public array $errors = [];
}