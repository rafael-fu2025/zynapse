<?php

declare(strict_types=1);

namespace Config;

use App\Auth\JwtService;
use App\Auth\RefreshTokenService;
use App\Auth\UserProvider;
use App\Services\Audit\AuditAutoDrainService;
use App\Services\Audit\AuditDrainService;
use App\Services\Audit\AuditOutboxService;
use App\Services\Crypto\EncryptionService;
use App\Services\Notify\NotificationAutoDrainService;
use App\Services\Notify\NotificationDrainService;
use App\Services\Notify\NotificationOutboxService;
use App\Services\Rbac\PermissionService;
use App\Services\RequestIdService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Database\BaseConnection;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\AppointmentService;
use Modules\Clinic\Services\ClinicService;
use Modules\Clinic\Services\QueueService;
use Modules\Clinic\Services\ReorderAutoCheckService;

/**
 * Services — singleton registry for SYNAPSE cross-cutting services.
 *
 * CodeIgniter's auto-resolver instantiates these on first access.
 */
class Services extends CoreServices
{
    public static function jwt(bool $getShared = true): JwtService
    {
        if ($getShared) {
            return static::getSharedInstance('jwt');
        }
        return new JwtService();
    }

    public static function refreshTokenService(bool $getShared = true): RefreshTokenService
    {
        if ($getShared) {
            return static::getSharedInstance('refreshTokenService');
        }
        return new RefreshTokenService(static::jwt());
    }

    public static function permissionService(bool $getShared = true): PermissionService
    {
        if ($getShared) {
            return static::getSharedInstance('permissionService');
        }
        return new PermissionService();
    }

    public static function auditOutbox(bool $getShared = true): AuditOutboxService
    {
        if ($getShared) {
            return static::getSharedInstance('auditOutbox');
        }
        return new AuditOutboxService();
    }

    public static function auditDrain(bool $getShared = true): AuditDrainService
    {
        if ($getShared) {
            return static::getSharedInstance('auditDrain');
        }
        return new AuditDrainService();
    }

    public static function auditAutoDrain(bool $getShared = true): AuditAutoDrainService
    {
        if ($getShared) {
            return static::getSharedInstance('auditAutoDrain');
        }
        return new AuditAutoDrainService();
    }

    public static function reorderAutoCheck(bool $getShared = true): ReorderAutoCheckService
    {
        if ($getShared) {
            return static::getSharedInstance('reorderAutoCheck');
        }
        return new ReorderAutoCheckService();
    }

    public static function notificationOutbox(bool $getShared = true): NotificationOutboxService
    {
        if ($getShared) {
            return static::getSharedInstance('notificationOutbox');
        }
        return new NotificationOutboxService();
    }

    public static function notificationDrain(bool $getShared = true): NotificationDrainService
    {
        if ($getShared) {
            return static::getSharedInstance('notificationDrain');
        }
        return new NotificationDrainService();
    }

    public static function notificationAutoDrain(bool $getShared = true): NotificationAutoDrainService
    {
        if ($getShared) {
            return static::getSharedInstance('notificationAutoDrain');
        }
        return new NotificationAutoDrainService();
    }

    public static function requestId(bool $getShared = true): RequestIdService
    {
        if ($getShared) {
            return static::getSharedInstance('requestId');
        }
        return new RequestIdService();
    }

    public static function encryptionService(bool $getShared = true): EncryptionService
    {
        if ($getShared) {
            return static::getSharedInstance('encryptionService');
        }
        return new EncryptionService();
    }

    public static function refreshTokenModel(bool $getShared = true): never
    {
        throw new \RuntimeException('Shield RefreshTokenModel is not used; see App\Auth\RefreshTokenService.');
    }

    /**
     * Credential/user lookup used by AuthController (login / me).
     */
    public static function getAuthProvider(bool $getShared = true): UserProvider
    {
        if ($getShared) {
            return static::getSharedInstance('getAuthProvider');
        }
        return new UserProvider();
    }

    public static function database(?string $connection = null, bool $getShared = true): BaseConnection
    {
        // NOTE: there is NO core `database` service — delegating to
        // parent::database() bounces through __callStatic back into this
        // method (infinite recursion). Go straight to the DB factory.
        return \CodeIgniter\Database\Config::connect($connection, $getShared);
    }

    /**
     * Shared clinic appointment service — also serves the student
     * portal's self-scoped booking (`bookSelf` / `myAppointments` /
     * `providers`).
     */
    public static function appointmentService(bool $getShared = true): AppointmentService
    {
        if ($getShared) {
            return static::getSharedInstance('appointmentService');
        }
        return new AppointmentService(
            new ClinicPolicy(),
            static::auditOutbox(),
            static::notificationOutbox(),
        );
    }

    /**
     * Shared walk-in queue service (also serves the self-scoped
     * `myStatus` used by the student/employee portals).
     */
    public static function queueService(bool $getShared = true): QueueService
    {
        if ($getShared) {
            return static::getSharedInstance('queueService');
        }
        return new QueueService(
            new ClinicPolicy(),
            static::auditOutbox(),
            new AppointmentService(new ClinicPolicy(), static::auditOutbox(), static::notificationOutbox()),
            new ClinicService(new ClinicPolicy(), static::auditOutbox(), static::notificationOutbox()),
            static::notificationOutbox(),
        );
    }
}
