<?php

/*
 * SYNAPSE API — front controller (stock CI 4.7 boot flow).
 * No views are rendered; all responses are JSON envelopes
 * (see App\Http\ApiResponse). Security posture is asserted from
 * `app/Config/Constants.php`, see `Config\Boot`.
 */

use CodeIgniter\Boot;
use Config\Paths;

$minPhpVersion = '8.2'; // Keep in sync with `spark`.
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

// Path to the front controller (this file)
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

require FCPATH . '../app/Config/Paths.php';

$paths = new Paths();

require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
