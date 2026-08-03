<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| App Namespace & Composer Path (required by the framework bootstrap)
|--------------------------------------------------------------------------
*/
defined('APP_NAMESPACE') || define('APP_NAMESPACE', 'App');
defined('COMPOSER_PATH') || define('COMPOSER_PATH', ROOTPATH . 'vendor/autoload.php');

/*
|--------------------------------------------------------------------------
| Timing Constants (required by Shield configs)
|--------------------------------------------------------------------------
*/
defined('SECOND') || define('SECOND', 1);
defined('MINUTE') || define('MINUTE', 60);
defined('HOUR')   || define('HOUR', 3600);
defined('DAY')    || define('DAY', 86400);
defined('WEEK')   || define('WEEK', 604800);
defined('MONTH')  || define('MONTH', 2592000);
defined('YEAR')   || define('YEAR', 31536000);
defined('DECADE') || define('DECADE', 315360000);

/*
|--------------------------------------------------------------------------
| Display Debug Backtrace
|--------------------------------------------------------------------------
*/
defined('SHOW_DEBUG_BACKTRACE') || define('SHOW_DEBUG_BACKTRACE', true);

/*
|--------------------------------------------------------------------------
| File and Directory Modes
|--------------------------------------------------------------------------
*/
defined('FILE_READ_MODE')  || define('FILE_READ_MODE',  0644);
defined('FILE_WRITE_MODE') || define('FILE_WRITE_MODE', 0666);
defined('DIR_READ_MODE')   || define('DIR_READ_MODE',   0755);
defined('DIR_WRITE_MODE')  || define('DIR_WRITE_MODE',  0777);

/*
|--------------------------------------------------------------------------
| File Stream Modes
|--------------------------------------------------------------------------
*/
defined('FOPEN_READ')                          || define('FOPEN_READ', 'rb');
defined('FOPEN_READ_WRITE')                    || define('FOPEN_READ_WRITE', 'r+b');
defined('FOPEN_WRITE_CREATE_DESTRUCTIVE')      || define('FOPEN_WRITE_CREATE_DESTRUCTIVE', 'wb');
defined('FOPEN_READ_WRITE_CREATE_DESTRUCTIVE') || define('FOPEN_READ_WRITE_CREATE_DESTRUCTIVE', 'w+b');
defined('FOPEN_WRITE_CREATE')                  || define('FOPEN_WRITE_CREATE', 'ab');
defined('FOPEN_READ_WRITE_CREATE')             || define('FOPEN_READ_WRITE_CREATE', 'a+b');
defined('FOPEN_WRITE_CREATE_STRICT')           || define('FOPEN_WRITE_CREATE_STRICT', 'xb');
defined('FOPEN_READ_WRITE_CREATE_STRICT')      || define('FOPEN_READ_WRITE_CREATE_STRICT', 'x+b');

/*
|--------------------------------------------------------------------------
| Exit Status Codes
|--------------------------------------------------------------------------
*/
defined('EXIT_SUCCESS')        || define('EXIT_SUCCESS', 0);
defined('EXIT_ERROR')          || define('EXIT_ERROR', 1);
defined('EXIT_CONFIG')         || define('EXIT_CONFIG', 3);
defined('EXIT_UNKNOWN_FILE')   || define('EXIT_UNKNOWN_FILE', 4);
defined('EXIT_UNKNOWN_CLASS')  || define('EXIT_UNKNOWN_CLASS', 5);
defined('EXIT_UNKNOWN_METHOD') || define('EXIT_UNKNOWN_METHOD', 6);
defined('EXIT_USER_INPUT')     || define('EXIT_USER_INPUT', 7);
defined('EXIT_DATABASE')       || define('EXIT_DATABASE', 8);
defined('EXIT__AUTO_MIN')      || define('EXIT__AUTO_MIN', 9);
defined('EXIT__AUTO_MAX')      || define('EXIT__AUTO_MAX', 125);

/*
|--------------------------------------------------------------------------
| SYNAPSE — Domain Constants
|--------------------------------------------------------------------------
*/
defined('SYNAPSE_ENV_PREFIX')      || define('SYNAPSE_ENV_PREFIX', 'SYNAPSE_');
defined('SYNAPSE_API_VERSION')      || define('SYNAPSE_API_VERSION', 'v1');
defined('SYNAPSE_AUDIT_OUTBOX')    || define('SYNAPSE_AUDIT_OUTBOX', 'audit_outbox');
defined('SYNAPSE_AUDIT_EVENTS')    || define('SYNAPSE_AUDIT_EVENTS', 'audit_events');
defined('SYNAPSE_TIMEZONE_DEFAULT') || define('SYNAPSE_TIMEZONE_DEFAULT', 'Asia/Manila');

/** BMG lifecycle states — must stay in sync with `facilities_bmg_units`. */
defined('BMG_STATE_IDLE')            || define('BMG_STATE_IDLE',            'idle');
defined('BMG_STATE_PROCESSING')      || define('BMG_STATE_PROCESSING',      'processing');
defined('BMG_STATE_AWAITING_OUTPUT') || define('BMG_STATE_AWAITING_OUTPUT', 'awaiting_output');
defined('BMG_STATE_CURING')          || define('BMG_STATE_CURING',          'curing');
defined('BMG_STATE_CANCELLED')       || define('BMG_STATE_CANCELLED',       'cancelled');
defined('BMG_STATE_MAINTENANCE')     || define('BMG_STATE_MAINTENANCE',     'maintenance');

/** Referral lifecycle states. */
defined('REFERRAL_STATUS_SUBMITTED')   || define('REFERRAL_STATUS_SUBMITTED',   'submitted');
defined('REFERRAL_STATUS_ACKNOWLEDGED')|| define('REFERRAL_STATUS_ACKNOWLEDGED','acknowledged');
defined('REFERRAL_STATUS_UNDER_REVIEW')|| define('REFERRAL_STATUS_UNDER_REVIEW','under_review');
defined('REFERRAL_STATUS_CLOSED')      || define('REFERRAL_STATUS_CLOSED',      'closed');

/*
|--------------------------------------------------------------------------
| SYNAPSE — Security Preflight
|--------------------------------------------------------------------------
| Constants.php is loaded by the framework (web AND spark) after `.env`
| is parsed, which makes it the earliest deterministic hook for the
| production posture assertion. See `Config\Boot`.
*/
if (class_exists(\Config\Boot::class)) {
    \Config\Boot::assertSecurityPosture();
}