<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * GuidanceContentService — staff-owned guidance content (2026-09 parity
 * plan Phase A, docs/GUIDANCE-KIOSK-PARITY.md).
 *
 * Replaces the university kiosk's hand-maintained Guidance tab:
 *   - announcements: targeted, windowed, audience-matched (replaces the
 *     static list AND its duplicate "Survey Links" tab);
 *   - services: the CHED CMO 9 s.2013 catalogue (seeded by migration),
 *     bookable services carrying a queue_destination.
 *
 * Publish state is DERIVED from publish_at/unpublish_at windows — there
 * is no stored flag to drift. Audience matching for the student feed
 * keys on users.kind + users.created_at against the academic-year start
 * (Aug 1, mirroring ReportRange) — graduating_students refines in
 * Phase B when cohort/year-level data lands, and currently matches all
 * students (documented, deliberately).
 */
final class GuidanceContentService extends BaseService
{
    private const AUDIENCES = ['all', 'new_students', 'continuing_students', 'graduating_students'];

    public function __construct(
        ?\CodeIgniter\Database\BaseConnection $db = null,
        private readonly AuditOutboxService $audit = new AuditOutboxService(),
    ) {
        parent::__construct($db);
    }

    // -----------------------------------------------------------------
    // Staff CRUD (gated by counselling.announcements.manage / services.manage)
    // -----------------------------------------------------------------

    /**
     * Staff list — everything non-archived, with the derived publish
     * status so the UI can show draft / scheduled / live / expired.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAnnouncements(): array
    {
        $rows = $this->db->table('guidance_announcements a')
            ->select('a.*')
            ->where('a.tenant_id', CurrentTenant::id())
            ->where('a.archived_at', null)
            ->orderBy('a.created_at', 'DESC')
            ->get()->getResultArray();

        return array_map(fn (array $r): array => $this->hydrateAnnouncement($r), $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createAnnouncement(array $input): array
    {
        $actorId = CurrentUser::assert();
        $fields = $this->validateAnnouncement($input);

        return $this->txn(function () use ($fields, $actorId): array {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('guidance_announcements')->insert($fields + [
                'tenant_id'  => CurrentTenant::id(),
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('guidance.announcement_created', 'guidance_announcements', $id, $actorId, [
                'resource_code' => 'audience#' . $fields['audience'],
            ]);

            return $this->getAnnouncement($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateAnnouncement(int $id, array $input): array
    {
        $actorId = CurrentUser::assert();
        $fields = $this->validateAnnouncement($input);

        return $this->txn(function () use ($id, $fields, $actorId): array {
            $row = $this->selectForUpdate('guidance_announcements', [
                'id' => $id, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null,
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('guidance_announcements')
                ->where('id', $id)
                ->where('tenant_id', CurrentTenant::id())
                ->update($fields + ['updated_at' => $now]);

            $this->audit->enqueue('guidance.announcement_updated', 'guidance_announcements', $id, $actorId, [
                'resource_code' => 'audience#' . $fields['audience'],
            ]);

            return $this->getAnnouncement($id);
        });
    }

    /**
     * Soft delete per the repo house rule (archived_at, never DELETE).
     */
    public function archiveAnnouncement(int $id): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($id, $actorId): array {
            $row = $this->selectForUpdate('guidance_announcements', [
                'id' => $id, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null,
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('guidance_announcements')
                ->where('id', $id)
                ->where('tenant_id', CurrentTenant::id())
                ->update(['archived_at' => $now, 'updated_at' => $now]);

            $this->audit->enqueue('guidance.announcement_archived', 'guidance_announcements', $id, $actorId, [
                'previous_status' => (string) $row['audience'],
            ]);

            return ['id' => $id, 'archived' => true];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listServices(): array
    {
        $rows = $this->db->table('guidance_services s')
            ->select('s.*')
            ->where('s.tenant_id', CurrentTenant::id())
            ->where('s.archived_at', null)
            ->orderBy('s.sort_order', 'ASC')
            ->get()->getResultArray();

        return array_map(fn (array $r): array => $this->hydrateService($r), $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createService(array $input): array
    {
        $actorId = CurrentUser::assert();
        $fields = $this->validateService($input);

        return $this->txn(function () use ($fields, $actorId): array {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $conflict = $this->db->table('guidance_services')
                ->where(['tenant_id' => CurrentTenant::id(), 'code' => $fields['code']])
                ->where('archived_at', null)
                ->countAllResults();
            if ($conflict > 0) {
                throw ApiException::conflict('resource.conflict', "A service with code '{$fields['code']}' already exists.");
            }

            $this->db->table('guidance_services')->insert($fields + [
                'tenant_id'  => CurrentTenant::id(),
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('guidance.service_created', 'guidance_services', $id, $actorId, [
                'resource_code' => 'code#' . $fields['code'],
            ]);

            return $this->getService($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateService(int $id, array $input): array
    {
        $actorId = CurrentUser::assert();
        $fields = $this->validateService($input, forUpdate: true);

        return $this->txn(function () use ($id, $fields, $actorId): array {
            $row = $this->selectForUpdate('guidance_services', [
                'id' => $id, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null,
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('guidance_services')
                ->where('id', $id)
                ->where('tenant_id', CurrentTenant::id())
                ->update($fields + ['updated_at' => $now]);

            $this->audit->enqueue('guidance.service_updated', 'guidance_services', $id, $actorId, [
                'resource_code' => 'code#' . (string) $row['code'],
            ]);

            return $this->getService($id);
        });
    }

    public function archiveService(int $id): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($id, $actorId): array {
            $row = $this->selectForUpdate('guidance_services', [
                'id' => $id, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null,
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('guidance_services')
                ->where('id', $id)
                ->where('tenant_id', CurrentTenant::id())
                ->update(['archived_at' => $now, 'updated_at' => $now]);

            $this->audit->enqueue('guidance.service_archived', 'guidance_services', $id, $actorId, [
                'resource_code' => 'code#' . (string) $row['code'],
            ]);

            return ['id' => $id, 'archived' => true];
        });
    }

    // -----------------------------------------------------------------
    // Student self-service feed (/me/guidance/*) — no staff permission,
    // scoped to the CALLER as the student.
    // -----------------------------------------------------------------

    /**
     * Published announcements matching the caller's audience + window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function feedAnnouncements(int $studentUserId): array
    {
        $rows = $this->db->table('guidance_announcements a')
            ->select('a.*')
            ->where('a.tenant_id', CurrentTenant::id())
            ->where('a.archived_at', null)
            ->groupStart()
                ->where('a.publish_at', null)
                ->orWhere('a.publish_at <=', $this->utcNow())
            ->groupEnd()
            ->groupStart()
                ->where('a.unpublish_at', null)
                ->orWhere('a.unpublish_at >', $this->utcNow())
            ->groupEnd()
            ->whereIn('a.audience', $this->audiencesFor($studentUserId))
            ->orderBy('a.publish_at', 'DESC')
            ->orderBy('a.created_at', 'DESC')
            ->get()->getResultArray();

        return array_map(fn (array $r): array => [
            'id'           => (int) $r['id'],
            'title'        => (string) $r['title'],
            'body'         => (string) $r['body'],
            'audience'     => (string) $r['audience'],
            'action_url'   => $r['action_url'] !== null ? (string) $r['action_url'] : null,
            'action_label' => $r['action_label'] !== null ? (string) $r['action_label'] : null,
            'is_required'  => (bool) $r['is_required'],
            'publish_at'   => $r['publish_at'] !== null ? (string) $r['publish_at'] : null,
            'unpublish_at' => $r['unpublish_at'] !== null ? (string) $r['unpublish_at'] : null,
        ], $rows);
    }

    /**
     * Active service catalogue for the portal / kiosk feed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function feedServices(): array
    {
        return array_values(array_filter(
            $this->listServices(),
            static fn (array $s): bool => $s['is_active'] === true,
        ));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Audience → student user-id filter (shared helper — see
     * GuidanceAudience for the new/continuing academic-year split).
     *
     * @return list<string>
     */
    private function audiencesFor(int $studentUserId): array
    {
        return GuidanceAudience::audiencesFor($this->db, $studentUserId, CurrentTenant::id());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateAnnouncement(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $audience = (string) ($input['audience'] ?? 'all');

        $errors = [];
        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = ['code' => 'validation.field', 'message' => 'Title is required (max 200 chars).', 'field' => 'title'];
        }
        if ($body === '') {
            $errors[] = ['code' => 'validation.field', 'message' => 'Body is required.', 'field' => 'body'];
        }
        if (! in_array($audience, self::AUDIENCES, true)) {
            $errors[] = ['code' => 'validation.field', 'message' => 'Unknown audience.', 'field' => 'audience'];
        }
        foreach (['publish_at', 'unpublish_at'] as $field) {
            $value = $input[$field] ?? null;
            if ($value !== null && $value !== '' && strtotime((string) $value) === false) {
                $errors[] = ['code' => 'validation.field', 'message' => 'Invalid datetime.', 'field' => $field];
            }
        }
        if ($errors !== []) {
            throw ApiException::validationFailure($errors);
        }

        $actionUrl = isset($input['action_url']) && trim((string) $input['action_url']) !== ''
            ? trim((string) $input['action_url'])
            : null;
        if ($actionUrl !== null && ! preg_match('#^https?://#i', $actionUrl)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Action URL must start with http(s)://.', 'field' => 'action_url'],
            ]);
        }

        return [
            'title'        => $title,
            'body'         => $body,
            'audience'     => $audience,
            'action_url'   => $actionUrl,
            'action_label' => isset($input['action_label']) && trim((string) $input['action_label']) !== ''
                ? mb_substr(trim((string) $input['action_label']), 0, 60)
                : null,
            'is_required'  => ($input['is_required'] ?? false) === true ? 1 : 0,
            'publish_at'   => isset($input['publish_at']) && $input['publish_at'] !== '' ? (string) $input['publish_at'] : null,
            'unpublish_at' => isset($input['unpublish_at']) && $input['unpublish_at'] !== '' ? (string) $input['unpublish_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateService(array $input, bool $forUpdate = false): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Service name is required (max 120 chars).', 'field' => 'name'],
            ]);
        }

        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            // Derive a slug from the name when not supplied.
            $code = preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?? '';
            $code = trim($code, '_');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Code must be lowercase letters, digits, underscores.', 'field' => 'code'],
            ]);
        }

        $destination = $input['queue_destination'] ?? null;
        if ($destination !== null && $destination !== '' && ! in_array($destination, ['clinic', 'counselling'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'queue_destination must be clinic, counselling, or empty.', 'field' => 'queue_destination'],
            ]);
        }

        $isActive = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        return [
            'code'             => $code,
            'name'             => $name,
            'description'      => isset($input['description']) && trim((string) $input['description']) !== ''
                ? mb_substr(trim((string) $input['description']), 0, 1000)
                : null,
            'cmo_reference'    => isset($input['cmo_reference']) && trim((string) $input['cmo_reference']) !== ''
                ? mb_substr(trim((string) $input['cmo_reference']), 0, 160)
                : null,
            'sort_order'       => max(0, (int) ($input['sort_order'] ?? 0)),
            'queue_destination' => $destination !== null && $destination !== '' ? (string) $destination : null,
            'is_active'        => $isActive ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function hydrateAnnouncement(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'title'        => (string) $r['title'],
            'body'         => (string) $r['body'],
            'audience'     => (string) $r['audience'],
            'action_url'   => $r['action_url'] !== null ? (string) $r['action_url'] : null,
            'action_label' => $r['action_label'] !== null ? (string) $r['action_label'] : null,
            'is_required'  => (bool) $r['is_required'],
            'publish_at'   => $r['publish_at'] !== null ? (string) $r['publish_at'] : null,
            'unpublish_at' => $r['unpublish_at'] !== null ? (string) $r['unpublish_at'] : null,
            'created_at'   => (string) $r['created_at'],
            'updated_at'   => (string) $r['updated_at'],
            // Derived publish state — windows are the single source of truth.
            'status'       => $this->publishStatus($r),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function hydrateService(array $r): array
    {
        return [
            'id'               => (int) $r['id'],
            'code'             => (string) $r['code'],
            'name'             => (string) $r['name'],
            'description'      => $r['description'] !== null ? (string) $r['description'] : null,
            'cmo_reference'    => $r['cmo_reference'] !== null ? (string) $r['cmo_reference'] : null,
            'sort_order'       => (int) $r['sort_order'],
            'queue_destination' => $r['queue_destination'] !== null ? (string) $r['queue_destination'] : null,
            'is_active'        => (bool) $r['is_active'],
            'created_at'       => (string) $r['created_at'],
        ];
    }

    private function publishStatus(array $r): string
    {
        $now = time();
        $from = $r['publish_at'] !== null ? strtotime((string) $r['publish_at']) : null;
        $to = $r['unpublish_at'] !== null ? strtotime((string) $r['unpublish_at']) : null;

        if ($from !== null && $from > $now) {
            return 'scheduled';
        }
        if ($to !== null && $to <= $now) {
            return 'expired';
        }
        return 'live';
    }

    /**
     * @return array<string, mixed>
     */
    private function getAnnouncement(int $id): array
    {
        $row = $this->db->table('guidance_announcements')
            ->where('id', $id)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }
        return $this->hydrateAnnouncement($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function getService(int $id): array
    {
        $row = $this->db->table('guidance_services')
            ->where('id', $id)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }
        return $this->hydrateService($row);
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
