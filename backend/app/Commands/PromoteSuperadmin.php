<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\CurrentTenant;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * PromoteSuperadmin — the ONLY sanctioned way to mint the first
 * superadmin (Platform Owner). Never exposed via UI: privileged role
 * grants through the API require an existing `rbac.privileged.manage`
 * holder, so bootstrap must be a shell command on a trusted host
 * (never over HTTP).
 *
 * Supply EXACTLY ONE selector:
 *
 *   php spark synapse:promote-superadmin owner@foundationu.edu.ph
 *   php spark synapse:promote-superadmin --employee 20269099
 *   php spark synapse:promote-superadmin --user-id 42
 *   php spark synapse:promote-superadmin owner@foundationu.edu.ph --confirm  (production)
 *
 * The positional email resolves the local `email_password` identity.
 * MIS-delegated accounts never have one, so `--employee` matches the
 * LOCAL `users.employee_number` that the MIS login/sync flow provisions
 * — run that first; this command never touches the network. `--user-id`
 * is the unambiguous local surrogate. Every selector resolves a LOCAL
 * user only, scoped to the active tenant, and refuses deleted, archived
 * or disabled accounts.
 *
 * Idempotent: promoting an existing superadmin is a no-op. The audit
 * trail records ONLY actual grants (rbac.privileged_role_granted), in
 * the same transaction as the membership insert.
 *
 * Additive: the superadmin membership is added without touching the
 * user's existing groups. Existing superadmins may keep authorising UI
 * grants — this command introduces no new governance restriction.
 */
final class PromoteSuperadmin extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:promote-superadmin';
    protected $description = 'Promote a local user to the superadmin (Platform Owner) role by email, MIS employee id, or local user id. Idempotent; the only sanctioned bootstrap path.';
    protected $usage       = 'synapse:promote-superadmin <email> | --employee <MIS-ID> | --user-id <id> [--confirm]';
    protected $arguments   = [
        'email' => 'Optional positional selector: email address (auth_identities secret) of the local user to promote.',
    ];
    protected $options = [
        '--employee' => 'Selector: MIS employee id (users.employee_number) of a locally provisioned user.',
        '--user-id'  => 'Selector: positive local user id (users.id) in the active tenant.',
        '--confirm'  => 'Required to run in production (same posture as boot security checks).',
    ];

    /**
     * Test seam for the production gate: `null` reads the ENVIRONMENT
     * constant, so the confirm branch stays exercisable offline.
     */
    protected ?string $environmentOverride = null;

    public function run(array $params): int
    {
        $selector = $this->resolveSelector($params);
        if (is_int($selector)) {
            return $selector; // usage/validation error already reported
        }

        if ($this->isProduction() && ! $this->isConfirmed($params)) {
            CLI::error('Refusing to run in production without --confirm.');
            return 1;
        }

        $db       = Services::database();
        $tenantId = CurrentTenant::id();

        $group = $db->table('auth_groups')->where('name', 'superadmin')->get()->getRowArray();
        if ($group === null) {
            CLI::error("The 'superadmin' group does not exist. Run migrations + the PermissionsAndGroupsSeeder first.");
            return 1;
        }
        $groupId = (int) $group['id'];

        $db->transStart();

        try {
            // Resolve AND lock the target inside the transaction. Nothing is
            // written until the locked row is confirmed eligible.
            $locked = $this->lockTarget($db, $tenantId, $selector);
            if ($locked['error'] !== null) {
                $db->transRollback();
                CLI::error($locked['error']);
                return 1;
            }

            $row = $locked['row'];
            if ($row === null) {
                $db->transRollback();
                CLI::error($this->notFoundMessage($selector['kind']));
                return 1;
            }

            if (! $this->isEligible($row)) {
                $db->transRollback();
                CLI::error(sprintf(
                    'User id %d is not eligible: the account is deleted, archived, or inactive.',
                    (int) $row['id'],
                ));
                return 1;
            }

            $userId = (int) $row['id'];

            // Additive + idempotent: only INSERT when the membership is
            // genuinely missing; existing groups are never touched.
            $already = (int) $db->table('auth_groups_users')
                ->where(['group_id' => $groupId, 'user_id' => $userId])
                ->countAllResults();

            if ($already > 0) {
                $db->transComplete();
                CLI::write("User id {$userId} is already a superadmin — nothing to do.", 'yellow');
                return 0;
            }

            $db->table('auth_groups_users')->insert([
                'group_id'   => $groupId,
                'user_id'    => $userId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // CLI bootstrap has no acting user — actor_user_id stays null
            // and the reason_code names the command (audit context
            // whitelist). Enqueued in the SAME transaction as the grant.
            Services::auditOutbox()->enqueue('rbac.privileged_role_granted', 'users', $userId, null, [
                'resource_code' => 'role#superadmin',
                'reason_code'   => 'synapse:promote-superadmin',
                'outcome'       => 'promoted',
            ]);

            $db->transComplete();
            if ($db->transStatus() === false) {
                $db->transRollback();
                CLI::error('Promotion failed and was rolled back.');
                return 1;
            }

            CLI::write("User id {$userId} is now a superadmin (Platform Owner).", 'green');
            CLI::write('Remember: the audit outbox is drained by synapse:audit-drain / the post-system hook.', 'dark_gray');
            return 0;
        } catch (\Throwable $e) {
            $db->transRollback();
            // Never echo driver/exception detail (may carry SQL or PII).
            CLI::error('Promotion failed and was rolled back.');
            return 1;
        }
    }

    /**
     * Resolve and lock the target row for the given selector.
     *
     * - email    → lock the local `email_password` identity, then lock the
     *              user row scoped to the active tenant;
     * - employee → lock every tenant-scoped row with that MIS employee id
     *              (a degraded non-unique index must fail closed, never
     *              silently pick one);
     * - user-id  → lock that tenant-scoped row directly.
     *
     * @param array{kind:string, value:string} $selector
     * @return array{row:?array<string,mixed>, error:?string}
     */
    private function lockTarget(BaseConnection $db, int $tenantId, array $selector): array
    {
        if ($selector['kind'] === 'email') {
            $identity = $db->query(
                "SELECT `user_id` FROM `auth_identities` WHERE `type` = 'email_password' AND `secret` = ? LIMIT 1 FOR UPDATE",
                [$selector['value']],
            )->getRowArray();

            if ($identity === null) {
                return ['row' => null, 'error' => null];
            }

            $row = $db->query(
                'SELECT `id`, `active`, `deleted_at`, `archived_at` FROM `users` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1 FOR UPDATE',
                [(int) $identity['user_id'], $tenantId],
            )->getRowArray();

            return ['row' => $row, 'error' => null];
        }

        if ($selector['kind'] === 'employee') {
            $rows = $db->query(
                'SELECT `id`, `active`, `deleted_at`, `archived_at` FROM `users` WHERE `employee_number` = ? AND `tenant_id` = ? FOR UPDATE',
                [$selector['value'], $tenantId],
            )->getResultArray();

            if (count($rows) > 1) {
                return [
                    'row'   => null,
                    'error' => 'Multiple local accounts share that MIS employee id; reconcile the duplicate before promoting.',
                ];
            }

            return ['row' => $rows[0] ?? null, 'error' => null];
        }

        $row = $db->query(
            'SELECT `id`, `active`, `deleted_at`, `archived_at` FROM `users` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1 FOR UPDATE',
            [(int) $selector['value'], $tenantId],
        )->getRowArray();

        return ['row' => $row, 'error' => null];
    }

    /** @param array<string, mixed> $row */
    private function isEligible(array $row): bool
    {
        return $row['deleted_at'] === null
            && $row['archived_at'] === null
            && (bool) $row['active'];
    }

    /**
     * Parse and validate the selector set. Exactly one of the positional
     * email, `--employee`, or `--user-id` must be present.
     *
     * @return array{kind:string, value:string}|int selector, or an exit code on error
     */
    private function resolveSelector(array $params)
    {
        $selectors = [];

        $email = trim((string) ($params[0] ?? ''));
        if ($email !== '') {
            $selectors['email'] = strtolower($email);
        }

        [$employeeGiven, $employeeValue] = $this->rawOption($params, 'employee');
        if ($employeeGiven) {
            $employeeValue = trim((string) $employeeValue);
            if ($employeeValue === '') {
                CLI::error('Option --employee requires an MIS employee id value.');
                return 1;
            }
            $selectors['employee'] = $employeeValue;
        }

        [$userIdGiven, $userIdValue] = $this->rawOption($params, 'user-id');
        if ($userIdGiven) {
            $userId = filter_var(trim((string) $userIdValue), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) {
                CLI::error('Option --user-id must be a positive integer.');
                return 1;
            }
            $selectors['user-id'] = (string) $userId;
        }

        if ($selectors === []) {
            CLI::error('Usage: php spark synapse:promote-superadmin <email> | --employee <MIS-ID> | --user-id <id>');
            return 1;
        }

        if (count($selectors) > 1) {
            CLI::error('Provide exactly one selector: <email>, --employee <MIS-ID>, or --user-id <id>.');
            return 1;
        }

        $kind  = (string) array_key_first($selectors);
        $value = $selectors[$kind];

        $limit = $kind === 'employee' ? 50 : 255; // column widths
        if (mb_strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            CLI::error($kind === 'employee'
                ? 'The --employee value is not a valid MIS employee id.'
                : 'The email selector is not a valid email address.');
            return 1;
        }

        return ['kind' => $kind, 'value' => $value];
    }

    private function notFoundMessage(string $kind): string
    {
        return match ($kind) {
            'email'    => 'No local user with that email exists in the active tenant.',
            'employee' => 'No local user with that MIS employee id is provisioned in the active tenant. Run the MIS login/sync first.',
            default    => 'No local user with that id exists in the active tenant.',
        };
    }

    /**
     * Read a long option from the parsed params.
     *
     * Supports `--name value` (CI's parser yields the key `name`) and
     * `--name=value` (which CI 4.7's parseCommandLine does NOT split, so
     * the whole token lands as the key). Falls back to CLI::getOption()
     * for the real spark path.
     *
     * @return array{0:bool, 1:?string} [provided, value] — value is null for a bare flag
     */
    private function rawOption(array $params, string $name): array
    {
        if (array_key_exists($name, $params)) {
            $value = $params[$name];

            return [true, is_string($value) ? $value : null];
        }

        $prefix = $name . '=';
        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                return [true, substr($key, strlen($prefix))];
            }
        }

        $option = CLI::getOption($name);
        if ($option !== null) {
            return [true, is_string($option) ? $option : null];
        }

        return [false, null];
    }

    private function isConfirmed(array $params): bool
    {
        [$given, $value] = $this->rawOption($params, 'confirm');
        if (! $given) {
            return false;
        }
        if ($value === null) {
            return true; // bare --confirm flag
        }

        return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function isProduction(): bool
    {
        $environment = $this->environmentOverride ?? (defined('ENVIRONMENT') ? ENVIRONMENT : 'production');

        return $environment === 'production';
    }
}
