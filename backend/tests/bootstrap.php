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
// `Config\Constants` references `ROOTPATH`, normally provided by the
// framework bootstrap. For pure unit tests we synthesize a minimal one.
if (! defined('ROOTPATH')) {
    define('ROOTPATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
}
require __DIR__ . '/../app/Config/Constants.php';
