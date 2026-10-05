<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use App\Services\Crypto\EncryptionService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use DateTimeImmutable;
use DateTimeZone;

/**
 * SurveyService — the guidance surveys engine (parity plan Phase B).
 *
 * Versioning model (canonical hybrid, see docs/GUIDANCE-KIOSK-PARITY.md):
 *   - a survey starts as a DRAFT (questions with version_id NULL);
 *   - publish() snapshots the draft into an immutable survey_version
 *     (questions + options copied with version_id set) — v1 keeps one
 *     version per survey; future edits require a new survey;
 *   - every response references the version it answered, so historical
 *     answers stay interpretable;
 *   - submissions carry an ENCRYPTED full-answer snapshot
 *     (AES-256-GCM, counselling-notes posture) in survey_responses,
 *     plus a long-table projection (survey_answers) that feeds the
 *     reports module without touching the encrypted record.
 *
 * Availability windows (publish_at/close_at) are UTC instants; the UI
 * presents them in app time (Asia/Manila).
 */
final class SurveyService extends BaseService
{
    private const QUESTION_TYPES = ['single', 'multi', 'likert', 'rating', 'free_text', 'external_url'];

    /** Dynamic link types (survey_links.type). */
    private const LINK_TYPES = ['survey', 'test', 'evaluation'];

    /** Canonical instruments for test-type links (survey_links.instrument_key). */
    private const INSTRUMENT_KEYS = ['new_transferees', 'mi', 'ls', 'bfpt', 'custom'];

    /** Screenshot proof constraints — the frontend mirrors these. */
    public const SCREENSHOT_MIME_TYPES = ['image/jpeg', 'image/png'];
    public const SCREENSHOT_MAX_BYTES = 5 * 1024 * 1024;

    private const SCREENSHOT_DIR = 'survey-screenshots';

    /** Registry vocabulary: users.year_level (TINYINT 1-6). */
    private const YEAR_LEVELS = [1, 2, 3, 4, 5, 6];

    public function __construct(
        ?\CodeIgniter\Database\BaseConnection $db = null,
        private readonly AuditOutboxService $audit = new AuditOutboxService(),
        private readonly EncryptionService $crypto = new EncryptionService(),
    ) {
        parent::__construct($db);
    }

    // -----------------------------------------------------------------
    // Staff: builder + publish
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSurveys(): array
    {
        $rows = $this->db->table('surveys s')
            ->select('s.*')
            ->where('s.tenant_id', CurrentTenant::id())
            ->where('s.archived_at', null)
            ->orderBy('s.created_at', 'DESC')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $surveyId = (int) $r['id'];
            $out[] = $this->hydrateSurvey($r) + [
                'question_count'  => $this->db->table('survey_questions')
                    ->where(['survey_id' => $surveyId])
                    ->where('version_id', null)
                    ->countAllResults(),
                'response_count'  => $this->countResponses($surveyId),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createSurvey(array $input): array
    {
        $actorId = CurrentUser::assert();
        $fields = $this->validateSurvey($input);

        return $this->txn(function () use ($fields, $actorId): array {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('surveys')->insert($fields + [
                'tenant_id'  => CurrentTenant::id(),
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('guidance.survey_created', 'surveys', $id, $actorId, [
                'resource_code' => 'audience#' . $fields['audience'],
            ]);

            return $this->getSurvey($id);
        });
    }

    /**
     * Draft-only update: a published survey is immutable (versioning).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateSurvey(int $id, array $input): array
    {
        $actorId = CurrentUser::assert();
        $fields = $this->validateSurvey($input);

        return $this->txn(function () use ($id, $fields, $actorId): array {
            $row = $this->assertDraft($id);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('surveys')
                ->where('id', $id)
                ->where('tenant_id', CurrentTenant::id())
                ->update($fields + ['updated_at' => $now]);

            $this->audit->enqueue('guidance.survey_updated', 'surveys', $id, $actorId, [
                'resource_code' => 'audience#' . $fields['audience'],
            ]);

            return $this->getSurvey($id);
        });
    }

    /**
     * Replaces the DRAFT question set (questions + options). Rejected
     * once published.
     *
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function setQuestions(int $id, array $questions): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($id, $questions, $actorId): array {
            $this->assertDraft($id);
            $normalized = $this->validateQuestions($questions);

            $this->db->table('survey_questions')
                ->where(['survey_id' => $id, 'tenant_id' => CurrentTenant::id()])
                ->where('version_id', null)
                ->delete();

            foreach ($normalized as $q) {
                $this->db->table('survey_questions')->insert([
                    'tenant_id'     => CurrentTenant::id(),
                    'survey_id'     => $id,
                    'version_id'    => null,
                    'sort_order'    => $q['sort_order'],
                    'question_type' => $q['question_type'],
                    'question_text' => $q['question_text'],
                    'is_required'   => $q['is_required'],
                ]);
                $questionId = (int) $this->db->insertID();

                foreach ($q['options'] as $optionIndex => $optionText) {
                    $this->db->table('survey_answer_options')->insert([
                        'tenant_id'   => CurrentTenant::id(),
                        'question_id' => $questionId,
                        'option_text' => $optionText,
                        'sort_order'  => $optionIndex + 1,
                    ]);
                }
            }

            $this->audit->enqueue('guidance.survey_questions_set', 'surveys', $id, $actorId, [
                'resource_code' => 'count#' . count($normalized),
            ]);

            return $this->getSurvey($id);
        });
    }

    /**
     * Replaces the DRAFT link set (survey / test / evaluation links).
     * Rejected once published — live surveys fix links through
     * patchPublishedLink() instead of unfreezing the version.
     *
     * @param array<int, array<string, mixed>> $links
     * @return array<string, mixed>
     */
    public function setLinks(int $id, array $links): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($id, $links, $actorId): array {
            $this->assertDraft($id);
            $normalized = $this->validateLinks($links);

            // Draft links carry no student-attached data (students only
            // ever see the published snapshot), so a hard replace is safe.
            $this->db->table('survey_links')
                ->where(['survey_id' => $id, 'tenant_id' => CurrentTenant::id()])
                ->where('version_id', null)
                ->delete();

            foreach ($normalized as $row) {
                $this->db->table('survey_links')->insert($row + [
                    'tenant_id'  => CurrentTenant::id(),
                    'survey_id'  => $id,
                    'version_id' => null,
                    'created_at' => $this->utcNow(),
                    'updated_at' => $this->utcNow(),
                ]);
            }

            $this->audit->enqueue('guidance.survey_links_set', 'surveys', $id, $actorId, [
                'resource_code' => 'count#' . count($normalized),
            ]);

            return $this->getSurvey($id);
        });
    }

    /**
     * Narrow hot-fix for live surveys: retitle / re-URL a link on the
     * published snapshot WITHOUT unfreezing the question set — a dead
     * external link is an operations emergency. Required/enabled flags
     * are NOT patchable (they change gating semantics; retire + new
     * survey for that).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function patchPublishedLink(int $surveyId, int $linkId, array $input): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($surveyId, $linkId, $input, $actorId): array {
            $this->assertSurveyInTenant($surveyId);

            $version = $this->db->table('survey_versions')
                ->where('survey_id', $surveyId)
                ->orderBy('version_no', 'DESC')
                ->get()->getRowArray();
            if ($version === null) {
                throw ApiException::conflict('statemachine.invalid_transition', 'Draft surveys change links through the normal save flow.');
            }

            $link = $this->db->table('survey_links')
                ->where(['id' => $linkId, 'survey_id' => $surveyId, 'version_id' => (int) $version['id'], 'tenant_id' => CurrentTenant::id()])
                ->get()->getRowArray();
            if ($link === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $fields = ['updated_at' => $this->utcNow()];
            if (array_key_exists('title', $input)) {
                $title = trim((string) $input['title']);
                if ($title === '' || mb_strlen($title) > 200) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'message' => 'Title is required (max 200 chars).', 'field' => 'links'],
                    ]);
                }
                $fields['title'] = $title;
            }
            if (array_key_exists('description', $input)) {
                $description = trim((string) $input['description']);
                $fields['description'] = $description === '' ? null : mb_substr($description, 0, 1000);
            }
            if (array_key_exists('external_url', $input)) {
                $fields['external_url'] = self::validateExternalUrl($input['external_url']);
            }

            $this->db->table('survey_links')
                ->where('id', $linkId)
                ->where('tenant_id', CurrentTenant::id())
                ->update($fields);

            $this->audit->enqueue('guidance.survey_link_patched', 'survey_links', $linkId, $actorId, [
                'resource_code' => 'survey#' . $surveyId,
            ]);

            return $this->shapeLink(
                $this->db->table('survey_links')->where('id', $linkId)->get()->getRowArray() ?? $link,
            );
        });
    }

    /**
     * Stored external-link URLs must be http(s) with a public routable
     * host. The student's browser opens these — the backend never
     * fetches them — but this is the stored-data half of the SSRF
     * posture: nothing pointing at loopback/private/reserved targets
     * ever enters the database, so any future server-side fetch
     * (health check, preview) starts from vetted data.
     */
    public static function validateExternalUrl(mixed $raw): string
    {
        $url = trim((string) ($raw ?? ''));
        if ($url === '' || mb_strlen($url) > 500) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'A link URL is required (max 500 characters).', 'field' => 'links'],
            ]);
        }

        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Enter the full link URL including https:// .', 'field' => 'links'],
            ]);
        }

        $scheme = strtolower((string) $parsed['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Only http(s) links are allowed.', 'field' => 'links'],
            ]);
        }
        if (isset($parsed['user'], $parsed['pass'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'URLs with embedded credentials are not allowed.', 'field' => 'links'],
            ]);
        }

        $host = strtolower((string) $parsed['host']);
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Links must point at a public host.', 'field' => 'links'],
                ]);
            }
        } elseif ($host === 'localhost' || str_ends_with($host, '.localhost') || preg_match('/^[0-9.]+$/', $host) === 1 || ! str_contains($host, '.')) {
            // Single-label / localhost / dotted-numeric hosts resolve
            // only inside the campus network — refuse them at the door.
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Links must point at a public host.', 'field' => 'links'],
            ]);
        }

        return $url;
    }

    /**
     * Copy-on-publish: snapshot draft questions + options into an
     * immutable version. v1 allows one version; retiring a survey is
     * archive() (new campaigns = new surveys).
     *
     * @return array<string, mixed>
     */
    public function publishSurvey(int $id): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($id, $actorId): array {
            $row = $this->selectForUpdate('surveys', [
                'id' => $id, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null,
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $published = $this->db->table('survey_versions')->where('survey_id', $id)->countAllResults();
            if ($published > 0) {
                throw ApiException::conflict('statemachine.invalid_transition', 'This survey is already published and immutable.');
            }

            $draftQuestions = $this->db->table('survey_questions')
                ->where(['survey_id' => $id, 'version_id' => null])
                ->orderBy('sort_order', 'ASC')
                ->get()->getResultArray();

            // Links snapshot together with the questions — students read
            // the published version, never live draft rows.
            $draftLinks = $this->db->table('survey_links')
                ->where(['survey_id' => $id, 'version_id' => null])
                ->orderBy('sort_order', 'ASC')
                ->get()->getResultArray();

            // A survey needs questions OR links to be worth publishing
            // (links-only surveys are submittable with an empty answer set).
            $enabledLinks = count(array_filter($draftLinks, static fn (array $l): bool => (int) $l['is_enabled'] === 1));
            if ($draftQuestions === [] && $enabledLinks === 0) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Add at least one question or link before publishing.', 'field' => 'questions'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('survey_versions')->insert([
                'tenant_id'    => CurrentTenant::id(),
                'survey_id'    => $id,
                'version_no'   => 1,
                'published_at' => $now,
                'published_by' => $actorId,
            ]);
            $versionId = (int) $this->db->insertID();

            foreach ($draftQuestions as $q) {
                $questionId = (int) $q['id'];
                $this->db->table('survey_questions')->insert([
                    'tenant_id'     => CurrentTenant::id(),
                    'survey_id'     => $id,
                    'version_id'    => $versionId,
                    'sort_order'    => (int) $q['sort_order'],
                    'question_type' => (string) $q['question_type'],
                    'question_key'  => $q['question_key'] ?? null,
                    'question_text' => (string) $q['question_text'],
                    'is_required'   => (int) $q['is_required'],
                ]);
                $snapshotQuestionId = (int) $this->db->insertID();

                $options = $this->db->table('survey_answer_options')
                    ->where('question_id', $questionId)
                    ->orderBy('sort_order', 'ASC')
                    ->get()->getResultArray();
                foreach ($options as $option) {
                    $this->db->table('survey_answer_options')->insert([
                        'tenant_id'   => CurrentTenant::id(),
                        'question_id' => $snapshotQuestionId,
                        'option_text' => (string) $option['option_text'],
                        'sort_order'  => (int) $option['sort_order'],
                    ]);
                }
            }

            // Links snapshot together with the questions — students read
            // the published version, never live draft rows.
            foreach ($draftLinks as $link) {
                $this->db->table('survey_links')->insert([
                    'tenant_id'           => CurrentTenant::id(),
                    'survey_id'           => $id,
                    'version_id'          => $versionId,
                    'type'                => (string) $link['type'],
                    'title'               => (string) $link['title'],
                    'description'         => $link['description'] !== null ? (string) $link['description'] : null,
                    'external_url'        => (string) $link['external_url'],
                    'audience'            => $link['audience'] !== null ? (string) $link['audience'] : null,
                    'instrument_key'      => $link['instrument_key'] !== null ? (string) $link['instrument_key'] : null,
                    'is_required'         => (int) $link['is_required'],
                    'is_enabled'          => (int) $link['is_enabled'],
                    'requires_screenshot' => (int) $link['requires_screenshot'],
                    'sort_order'          => (int) $link['sort_order'],
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }

            $this->audit->enqueue('guidance.survey_published', 'surveys', $id, $actorId, [
                'resource_code' => 'version#1',
                'next_status'   => 'published',
            ]);

            return $this->getSurvey($id);
        });
    }

    public function archiveSurvey(int $id): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($id, $actorId): array {
            $row = $this->selectForUpdate('surveys', [
                'id' => $id, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null,
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('surveys')
                ->where('id', $id)
                ->where('tenant_id', CurrentTenant::id())
                ->update(['archived_at' => $now, 'updated_at' => $now]);

            $this->audit->enqueue('guidance.survey_archived', 'surveys', $id, $actorId, [
                'previous_status' => $this->surveyStatus($row),
            ]);

            return ['id' => $id, 'archived' => true];
        });
    }

    // -----------------------------------------------------------------
    // Staff: responses
    // -----------------------------------------------------------------

    /**
     * Response list for one survey (identity included — requirements
     * context). Gated by counselling.responses.read. Optionally filtered
     * to one registry year level (1-6).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listResponses(int $surveyId, ?int $yearLevel = null): array
    {
        $this->assertSurveyInTenant($surveyId);

        $builder = $this->db->table('survey_responses r')
            ->select('r.id, r.survey_id, r.student_user_id, r.submitted_at, u.username, u.first_name, u.last_name, u.year_level AS student_year_level, i.secret AS email')
            ->join('users u', 'u.id = r.student_user_id')
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('r.tenant_id', CurrentTenant::id())
            ->where('r.survey_id', $surveyId);
        if ($yearLevel !== null) {
            $builder->where('u.year_level', $yearLevel);
        }
        $rows = $builder
            ->orderBy('r.submitted_at', 'DESC')
            ->get()->getResultArray();

        // Submission itself is the completion signal now — links are
        // informational and live in responseDetail().
        return array_map(static fn (array $r): array => [
            'id'                 => (int) $r['id'],
            'survey_id'          => (int) $r['survey_id'],
            'student_user_id'    => (int) $r['student_user_id'],
            'student_name'       => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])) ?: null,
            'email'              => $r['email'] !== null ? (string) $r['email'] : null,
            'student_year_level' => $r['student_year_level'] !== null ? (int) $r['student_year_level'] : null,
            'submitted_at'       => (string) $r['submitted_at'],
        ], $rows);
    }

    /**
     * Full decrypted answers for one response (counselling.responses.read).
     * Access is audited — the canonical record is the encrypted snapshot.
     *
     * @return array<string, mixed>
     */
    public function responseDetail(int $surveyId, int $responseId): array
    {
        $this->assertSurveyInTenant($surveyId);
        $response = $this->db->table('survey_responses r')
            ->select('r.*, u.username, u.first_name, u.last_name, u.year_level AS student_year_level')
            ->join('users u', 'u.id = r.student_user_id')
            ->where('r.id', $responseId)
            ->where('r.survey_id', $surveyId)
            ->where('r.tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        if ($response === null) {
            throw ApiException::notFound('resource.not_found');
        }

        $actorId = CurrentUser::assert();
        $this->audit->enqueue('guidance.response_accessed', 'survey_responses', $responseId, $actorId, [
            'resource_code' => 'survey#' . $surveyId,
        ]);

        $snapshot = json_decode(
            $this->crypto->decryptField(
                (string) $response['payload_cipher'],
                (string) $response['payload_nonce'],
                (int) $response['payload_key_version'],
            ),
            true,
        );

        $versionId = (int) $response['version_id'];
        $versionLinks = $this->linksForVersion((int) $response['survey_id'], $versionId);

        $links = [];
        foreach ($versionLinks as $link) {
            if (! $link['is_enabled']) {
                continue;
            }
            $shot = $this->db->table('survey_link_screenshots')
                ->select('id, link_id, original_name, mime_type, size_bytes, created_at')
                ->where(['response_id' => (int) $response['id'], 'link_id' => (int) $link['id'], 'tenant_id' => CurrentTenant::id()])
                ->orderBy('id', 'ASC')
                ->get()->getRowArray();
            $openedAt = $this->db->table('survey_link_opens')
                ->select('opened_at')
                ->where(['link_id' => (int) $link['id'], 'student_user_id' => (int) $response['student_user_id'], 'tenant_id' => CurrentTenant::id()])
                ->get()->getRowArray();
            $links[] = [
                'link_id'        => (int) $link['id'],
                'type'           => $link['type'],
                'title'          => $link['title'],
                'instrument_key' => $link['instrument_key'],
                'is_required'    => $link['is_required'],
                'opened'         => $openedAt !== null,
                'opened_at'      => $openedAt !== null ? (string) $openedAt['opened_at'] : null,
                'screenshot'     => $shot !== null ? [
                    'id'            => (int) $shot['id'],
                    'link_id'       => (int) $shot['link_id'],
                    'original_name' => (string) $shot['original_name'],
                    'mime_type'     => (string) $shot['mime_type'],
                    'size_bytes'    => (int) $shot['size_bytes'],
                    'created_at'    => (string) $shot['created_at'],
                ] : null,
            ];
        }

        return [
            'id'                 => (int) $response['id'],
            'survey_id'          => (int) $response['survey_id'],
            'student_user_id'    => (int) $response['student_user_id'],
            'student_name'       => trim(((string) $response['first_name']) . ' ' . ((string) $response['last_name'])) ?: null,
            'student_year_level' => $response['student_year_level'] !== null ? (int) $response['student_year_level'] : null,
            'submitted_at'       => (string) $response['submitted_at'],
            'answers'            => is_array($snapshot) ? $snapshot : [],
            'links'              => $links,
        ];
    }

    // -----------------------------------------------------------------
    // Student: availability, form, submit, requirements
    // -----------------------------------------------------------------

    /**
     * Open surveys for the caller (window open, not yet submitted).
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableForStudent(int $studentUserId): array
    {
        $audiences = GuidanceAudience::audiencesFor($this->db, $studentUserId, CurrentTenant::id());
        $studentYear = $this->studentYearLevel($studentUserId);
        $rows = $this->db->table('surveys s')
            ->select('s.*, sv.id AS version_id')
            ->join('survey_versions sv', 'sv.survey_id = s.id')
            ->where('s.tenant_id', CurrentTenant::id())
            ->where('s.archived_at', null)
            ->whereIn('s.audience', $audiences)
            ->groupStart()
                ->where('s.publish_at', null)
                ->orWhere('s.publish_at <=', $this->utcNow())
            ->groupEnd()
            ->groupStart()
                ->where('s.close_at', null)
                ->orWhere('s.close_at >', $this->utcNow())
            ->groupEnd()
            ->orderBy('s.close_at', 'ASC')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $surveyId = (int) $r['id'];
            // Year-level targeting: NULL/[] matches everyone; otherwise
            // the student's registry year level must be listed (a student
            // with no year level on file only sees untargeted surveys).
            $targets = self::decodeYearLevels($r['year_levels'] ?? null);
            if ($targets !== null && ($studentYear === null || ! in_array($studentYear, $targets, true))) {
                continue;
            }
            $submitted = $this->db->table('survey_responses')
                ->where(['survey_id' => $surveyId, 'student_user_id' => $studentUserId])
                ->countAllResults();
            $out[] = [
                'id'          => $surveyId,
                'title'       => (string) $r['title'],
                'description' => $r['description'] !== null ? (string) $r['description'] : null,
                'category'    => (string) $r['category'],
                'is_required' => (bool) $r['is_required'],
                // Submitted surveys stay listed for OPTIONAL proofs while
                // the window is open; requirements() filters them out.
                'submitted'   => $submitted > 0,
                'publish_at'  => $r['publish_at'] !== null ? (string) $r['publish_at'] : null,
                'close_at'    => $r['close_at'] !== null ? (string) $r['close_at'] : null,
            ];
        }
        return $out;
    }

    /**
     * The clearance gate: required surveys with an open window the
     * student has not yet submitted. Links never affect clearance.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requirements(int $studentUserId): array
    {
        return array_values(array_filter(
            $this->availableForStudent($studentUserId),
            static fn (array $s): bool => $s['is_required'] === true && $s['submitted'] === false,
        ));
    }

    /**
     * The answerable form (version + questions + options) for the
     * caller; hidden once submitted.
     *
     * @return array<string, mixed>
     */
    public function getFormForStudent(int $surveyId, int $studentUserId): array
    {
        $survey = $this->publishedSurveyForStudent($surveyId, $studentUserId);
        $versionId = (int) $survey['version_id'];

        // Submitted surveys stay open for OPTIONAL proofs while the
        // window is live — the runner renders questions read-only.
        $response = $this->db->table('survey_responses')
            ->select('id')
            ->where(['survey_id' => $surveyId, 'student_user_id' => $studentUserId, 'tenant_id' => CurrentTenant::id()])
            ->get()->getRowArray();

        $audiences = GuidanceAudience::audiencesFor($this->db, $studentUserId, CurrentTenant::id());
        $links = $this->visibleLinksForStudent($surveyId, $versionId, $audiences);

        $opened = [];
        if ($links !== []) {
            $openRows = $this->db->table('survey_link_opens')
                ->select('link_id')
                ->where(['student_user_id' => $studentUserId, 'tenant_id' => CurrentTenant::id()])
                ->whereIn('link_id', array_map(static fn (array $l): int => $l['id'], $links))
                ->get()->getResultArray();
            $opened = array_map(static fn (array $r): int => (int) $r['link_id'], $openRows);
        }

        // All of the student's proofs (staged or bound) — the runner
        // restores the dropzones from this payload after a reload.
        $proofs = $this->db->table('survey_link_screenshots')
            ->select('id, link_id, original_name, mime_type, size_bytes, created_at')
            ->where(['student_user_id' => $studentUserId, 'survey_id' => $surveyId, 'tenant_id' => CurrentTenant::id()])
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return [
            'id'              => (int) $survey['id'],
            'title'           => (string) $survey['title'],
            'description'     => $survey['description'] !== null ? (string) $survey['description'] : null,
            'close_at'        => $survey['close_at'] !== null ? (string) $survey['close_at'] : null,
            'version_id'      => $versionId,
            'submitted'       => $response !== null,
            'questions'       => $this->questionsForVersion($versionId),
            'links'           => $links,
            'opened_link_ids' => $opened,
            'screenshots'     => array_map(static fn (array $s): array => [
                'id'            => (int) $s['id'],
                'link_id'       => (int) $s['link_id'],
                'original_name' => (string) $s['original_name'],
                'mime_type'     => (string) $s['mime_type'],
                'size_bytes'    => (int) $s['size_bytes'],
                'created_at'    => (string) $s['created_at'],
            ], $proofs),
        ];
    }

    /**
     * Validates and stores a submission: one response per student per
     * survey, required questions enforced, encrypted snapshot + long-
     * table projection.
     *
     * @param array<int, array<string, mixed>> $answers
     * @return array<string, mixed>
     */
    public function submit(int $surveyId, array $answers): array
    {
        $studentUserId = CurrentUser::assert();
        $survey = $this->publishedSurveyForStudent($surveyId, $studentUserId);
        $versionId = (int) $survey['version_id'];

        $questions = $this->questionsForVersion($versionId);
        $byId = [];
        foreach ($questions as $q) {
            $byId[$q['id']] = $q;
        }

        $given = [];
        foreach ($answers as $a) {
            $questionId = (int) ($a['question_id'] ?? 0);
            if (! isset($byId[$questionId])) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Unknown question in answers.', 'field' => 'answers'],
                ]);
            }
            $given[$questionId] = $a;
        }

        // Required-question enforcement + payload assembly.
        $payload = [];
        $rows = [];
        foreach ($questions as $q) {
            $qid = $q['id'];
            $answer = $given[$qid] ?? null;
            $isEmpty = $answer === null
                || ! array_key_exists('value', $answer)
                || $answer['value'] === null
                || $answer['value'] === ''
                || (is_array($answer['value']) && $answer['value'] === []);

            if ($isEmpty) {
                if ($q['is_required']) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'message' => 'Please answer: ' . mb_substr($q['question_text'], 0, 80), 'field' => 'answers'],
                    ]);
                }
                continue;
            }

            $value = $answer['value'];
            $payloadRow = ['question_id' => $qid, 'question_type' => $q['question_type']];
            switch ($q['question_type']) {
                case 'single':
                    $optionId = (int) $value;
                    if (! in_array($optionId, $q['option_ids'], true)) {
                        throw ApiException::validationFailure([
                            ['code' => 'validation.field', 'message' => 'Invalid choice.', 'field' => 'answers'],
                        ]);
                    }
                    $rows[] = ['question_id' => $qid, 'question_type' => 'single', 'value_number' => $optionId, 'value_text' => null];
                    $payloadRow['value'] = $optionId;
                    break;

                case 'multi':
                    $chosen = array_map('intval', (array) $value);
                    if ($chosen === [] || array_diff($chosen, $q['option_ids']) !== []) {
                        throw ApiException::validationFailure([
                            ['code' => 'validation.field', 'message' => 'Invalid choice(s).', 'field' => 'answers'],
                        ]);
                    }
                    foreach ($chosen as $optionId) {
                        $rows[] = ['question_id' => $qid, 'question_type' => 'multi', 'value_number' => $optionId, 'value_text' => null];
                    }
                    $payloadRow['value'] = $chosen;
                    break;

                case 'likert':
                case 'rating':
                    $score = (int) $value;
                    if ($score < 1 || $score > 5) {
                        throw ApiException::validationFailure([
                            ['code' => 'validation.field', 'message' => 'Scale answers must be 1-5.', 'field' => 'answers'],
                        ]);
                    }
                    $rows[] = ['question_id' => $qid, 'question_type' => $q['question_type'], 'value_number' => $score, 'value_text' => null];
                    $payloadRow['value'] = $score;
                    break;

                case 'external_url':
                    $attested = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    if (! $attested) {
                        throw ApiException::validationFailure([
                            ['code' => 'validation.field', 'message' => 'Please confirm you completed the linked activity.', 'field' => 'answers'],
                        ]);
                    }
                    $rows[] = ['question_id' => $qid, 'question_type' => 'external_url', 'value_number' => 1, 'value_text' => null];
                    $payloadRow['value'] = true;
                    break;

                case 'free_text':
                default:
                    $text = trim((string) $value);
                    if ($text === '' || mb_strlen($text) > 2000) {
                        throw ApiException::validationFailure([
                            ['code' => 'validation.field', 'message' => 'Answer length must be 1-2000 characters.', 'field' => 'answers'],
                        ]);
                    }
                    $rows[] = ['question_id' => $qid, 'question_type' => 'free_text', 'value_number' => null, 'value_text' => $text];
                    $payloadRow['value'] = $text;
                    break;
            }
            $payload[] = $payloadRow;
        }

        return $this->txn(function () use ($surveyId, $versionId, $studentUserId, $payload, $rows): array {
            $existing = $this->db->table('survey_responses')
                ->where(['survey_id' => $surveyId, 'student_user_id' => $studentUserId])
                ->countAllResults();
            if ($existing > 0) {
                throw ApiException::conflict('resource.conflict', 'You have already answered this survey.');
            }

            $snapshot = json_encode($payload, JSON_THROW_ON_ERROR);
            $env = $this->crypto->encryptField($snapshot);
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('survey_responses')->insert([
                'tenant_id'           => CurrentTenant::id(),
                'survey_id'           => $surveyId,
                'version_id'          => $versionId,
                'student_user_id'     => $studentUserId,
                'payload_cipher'      => $env['ciphertext'],
                'payload_nonce'       => $env['nonce'],
                'payload_key_version' => $env['key_version'],
                'submitted_at'        => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $responseId = (int) $this->db->insertID();

            foreach ($rows as $row) {
                $this->db->table('survey_answers')->insert($row + [
                    'tenant_id'   => CurrentTenant::id(),
                    'response_id' => $responseId,
                    'version_id'  => $versionId,
                ]);
            }

            // Bind staged screenshot proofs to the response, atomic with
            // the submission; the record is locked from here on.
            $this->db->table('survey_link_screenshots')
                ->where(['survey_id' => $surveyId, 'student_user_id' => $studentUserId, 'response_id' => null])
                ->where('tenant_id', CurrentTenant::id())
                ->update(['response_id' => $responseId, 'updated_at' => $this->utcNow()]);

            $this->audit->enqueue('guidance.response_submitted', 'survey_responses', $responseId, $studentUserId, [
                'resource_code' => 'survey#' . $surveyId,
            ]);

            // Phase C triage: WHO-5 threshold + explicit contact request,
            // evaluated in the same transaction (RA 11036 §24 aftercare).
            (new GuidanceFollowupService($this->db))->evaluateResponse($responseId);

            return ['id' => $responseId, 'survey_id' => $surveyId, 'submitted' => true];
        });
    }

    // -----------------------------------------------------------------
    // Student: dynamic links — open attestation + screenshot proofs
    // -----------------------------------------------------------------

    /**
     * Logs the open attestation for a dynamic link and returns its URL.
     * Idempotent per student + link (UNIQUE key). Hidden or disabled
     * links 404 — a student can never attest to a link they were not
     * shown, and the client fires this AFTER window.open so the popup
     * keeps its user gesture.
     *
     * @return array<string, mixed>
     */
    public function openLink(int $surveyId, int $linkId): array
    {
        $studentUserId = CurrentUser::assert();
        $survey = $this->publishedSurveyForStudent($surveyId, $studentUserId);
        $link = $this->assertLinkForStudent($surveyId, (int) $survey['version_id'], $linkId, $studentUserId);

        $first = ! $this->hasOpened($linkId, $studentUserId);
        if ($first) {
            try {
                $this->db->table('survey_link_opens')->insert([
                    'tenant_id'       => CurrentTenant::id(),
                    'survey_id'       => $surveyId,
                    'link_id'         => $linkId,
                    'student_user_id' => $studentUserId,
                    'opened_at'       => $this->utcNow(),
                ]);
            } catch (DatabaseException $e) {
                // Lost a race against a duplicate click — the UNIQUE key
                // already did our job; keep the response idempotent.
                if (! str_contains($e->getMessage(), '1062') && ! str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw $e;
                }
            }

            $this->audit->enqueue('guidance.survey_link_opened', 'survey_links', $linkId, $studentUserId, [
                'resource_code' => 'survey#' . $surveyId,
            ]);
        }

        return ['link_id' => $linkId, 'external_url' => (string) $link['external_url'], 'opened' => true];
    }

    /**
     * Stages a screenshot proof for a link flagged requires_screenshot.
     * Optional end-to-end: uploads never gate submission, and proofs
     * stay editable while the survey window is live — submitted or not.
     * If a response already exists the proof binds to it immediately;
     * pre-submit proofs bind atomically at submit (see submit()).
     *
     * @return array<string, mixed>
     */
    public function uploadScreenshot(int $surveyId, int $linkId, mixed $file): array
    {
        $studentUserId = CurrentUser::assert();
        $survey = $this->publishedSurveyForStudent($surveyId, $studentUserId);
        $link = $this->assertLinkForStudent($surveyId, (int) $survey['version_id'], $linkId, $studentUserId);
        if ((int) $link['requires_screenshot'] !== 1) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'This link does not accept screenshot uploads.', 'field' => 'screenshot'],
            ]);
        }

        // is_uploaded_file()/move_uploaded_file() are hard false under the
        // CLI test harness — PHP only registers files through real SAPI
        // uploads — so the canonical guard degrades to: OK error code, not
        // yet moved, readable temp file. In production tmp_name is
        // PHP-assigned, so the MIME sniff + size cap + storage copy below
        // remain the real validators.
        if (
            ! $file instanceof UploadedFile
            || $file->getError() !== UPLOAD_ERR_OK
            || $file->hasMoved()
            || ! is_file($file->getTempName())
        ) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Choose a screenshot to upload.', 'field' => 'screenshot'],
            ]);
        }

        // Real MIME sniffing (finfo on the temp file) — the client's
        // extension is decoration, never a trust signal.
        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, self::SCREENSHOT_MIME_TYPES, true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Screenshots must be JPG or PNG images.', 'field' => 'screenshot'],
            ]);
        }
        if ((int) $file->getSize() > self::SCREENSHOT_MAX_BYTES) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Screenshots must be 5 MB or smaller.', 'field' => 'screenshot'],
            ]);
        }

        $originalName = mb_substr((string) $file->getClientName(), 0, 255);
        $size = (int) $file->getSize();
        $storedName = 'scr_' . bin2hex(random_bytes(16)) . '.' . ($mime === 'image/png' ? 'png' : 'jpg');
        $dir = $this->screenshotDirectory();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw ApiException::conflict('resource.conflict', 'Could not store the screenshot.');
        }
        // copy(), not UploadedFile::move() — the latter refuses CLI-staged
        // files (is_uploaded_file), see the guard above.
        if (! @copy($file->getTempName(), $dir . DIRECTORY_SEPARATOR . $storedName)) {
            throw ApiException::conflict('resource.conflict', 'Could not store the screenshot.');
        }

        $response = $this->db->table('survey_responses')
            ->select('id')
            ->where(['survey_id' => $surveyId, 'student_user_id' => $studentUserId, 'tenant_id' => CurrentTenant::id()])
            ->get()->getRowArray();
        $responseId = $response !== null ? (int) $response['id'] : null;

        $out = $this->txn(function () use ($surveyId, $linkId, $studentUserId, $responseId, $originalName, $mime, $size, $storedName): array {
            // Replace semantics: one active proof per link, staged or bound.
            $old = $this->db->table('survey_link_screenshots')
                ->where(['survey_id' => $surveyId, 'link_id' => $linkId, 'student_user_id' => $studentUserId])
                ->where('tenant_id', CurrentTenant::id())
                ->get()->getResultArray();
            if ($old !== []) {
                $this->db->table('survey_link_screenshots')
                    ->where(['survey_id' => $surveyId, 'link_id' => $linkId, 'student_user_id' => $studentUserId])
                    ->where('tenant_id', CurrentTenant::id())
                    ->delete();
            }

            $now = $this->utcNow();
            $this->db->table('survey_link_screenshots')->insert([
                'tenant_id'       => CurrentTenant::id(),
                'survey_id'       => $surveyId,
                'link_id'         => $linkId,
                'response_id'     => $responseId,
                'student_user_id' => $studentUserId,
                'original_name'   => $originalName,
                'stored_name'     => $storedName,
                'mime_type'       => $mime,
                'size_bytes'      => $size,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            return [
                'out' => [
                    'id'            => (int) $this->db->insertID(),
                    'link_id'       => $linkId,
                    'original_name' => $originalName,
                    'mime_type'     => $mime,
                    'size_bytes'    => $size,
                ],
                'old' => array_map(static fn (array $r): string => (string) $r['stored_name'], $old),
            ];
        });

        // Unlink replaced files only after the rows committed.
        foreach ($out['old'] as $oldName) {
            $this->unlinkStored($oldName);
        }
        return $out['out'];
    }

    /**
     * Removes the caller's screenshot for a link (staged or bound) —
     * proofs are optional and editable while the window is live.
     *
     * @return array<string, mixed>
     */
    public function removeScreenshot(int $surveyId, int $linkId): array
    {
        $studentUserId = CurrentUser::assert();
        $survey = $this->publishedSurveyForStudent($surveyId, $studentUserId);
        $this->assertLinkForStudent($surveyId, (int) $survey['version_id'], $linkId, $studentUserId);

        $removed = $this->txn(fn (): array => $this->db->table('survey_link_screenshots')
            ->where(['survey_id' => $surveyId, 'link_id' => $linkId, 'student_user_id' => $studentUserId])
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getResultArray());

        if ($removed !== []) {
            $this->db->table('survey_link_screenshots')
                ->where(['survey_id' => $surveyId, 'link_id' => $linkId, 'student_user_id' => $studentUserId])
                ->where('tenant_id', CurrentTenant::id())
                ->delete();
            foreach ($removed as $row) {
                $this->unlinkStored((string) $row['stored_name']);
            }
        }

        return ['link_id' => $linkId, 'removed' => true];
    }

    /**
     * Streams the caller's own screenshot back (restores the preview
     * after a reload). Self-scoped — no staff permission involved.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function studentScreenshot(int $surveyId, int $linkId): array
    {
        $studentUserId = CurrentUser::assert();
        $survey = $this->publishedSurveyForStudent($surveyId, $studentUserId);

        $row = $this->db->table('survey_link_screenshots')
            ->where(['survey_id' => $surveyId, 'link_id' => $linkId, 'student_user_id' => $studentUserId])
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }
        return $this->screenshotFile($row);
    }

    /**
     * Screenshot file for a staff download (counselling.responses.read).
     * Every read is audited — same posture as the decrypted response.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function staffScreenshot(int $surveyId, int $responseId, int $screenshotId): array
    {
        $this->assertSurveyInTenant($surveyId);

        $row = $this->db->table('survey_link_screenshots s')
            ->select('s.*')
            ->join('survey_responses r', 'r.id = s.response_id')
            ->where(['s.id' => $screenshotId, 's.survey_id' => $surveyId, 's.response_id' => $responseId, 's.tenant_id' => CurrentTenant::id()])
            ->where('r.survey_id', $surveyId)
            ->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }

        $this->audit->enqueue('guidance.screenshot_accessed', 'survey_link_screenshots', $screenshotId, CurrentUser::assert(), [
            'resource_code' => 'survey#' . $surveyId . ' response#' . $responseId,
        ]);

        return $this->screenshotFile($row);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateSurvey(array $row): array
    {
        $version = $this->db->table('survey_versions')
            ->where('survey_id', (int) $row['id'])
            ->orderBy('version_no', 'DESC')
            ->get()->getRowArray();

        return [
            'id'          => (int) $row['id'],
            'title'       => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'category'    => (string) $row['category'],
            'audience'    => (string) $row['audience'],
            'year_levels' => self::decodeYearLevels($row['year_levels'] ?? null),
            'is_required' => (bool) $row['is_required'],
            'publish_at'  => $row['publish_at'] !== null ? (string) $row['publish_at'] : null,
            'close_at'    => $row['close_at'] !== null ? (string) $row['close_at'] : null,
            'created_at'  => (string) $row['created_at'],
            'status'      => $this->surveyStatus($row),
            'version_id'  => $version !== null ? (int) $version['id'] : null,
            'version_no'  => $version !== null ? (int) $version['version_no'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function surveyStatus(array $row): string
    {
        $hasVersion = (int) $this->db->table('survey_versions')->where('survey_id', (int) $row['id'])->countAllResults() > 0;
        if (! $hasVersion) {
            return 'draft';
        }
        if ($row['close_at'] !== null && strtotime((string) $row['close_at']) <= time()) {
            return 'closed';
        }
        if ($row['publish_at'] !== null && strtotime((string) $row['publish_at']) > time()) {
            return 'scheduled';
        }
        return 'live';
    }

    private function countResponses(int $surveyId): int
    {
        return $this->db->table('survey_responses')->where('survey_id', $surveyId)->countAllResults();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateSurvey(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $audience = (string) ($input['audience'] ?? 'all');

        $errors = [];
        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = ['code' => 'validation.field', 'message' => 'Title is required (max 200 chars).', 'field' => 'title'];
        }
        if (! in_array($audience, ['all', 'new_students', 'continuing_students', 'graduating_students'], true)) {
            $errors[] = ['code' => 'validation.field', 'message' => 'Unknown audience.', 'field' => 'audience'];
        }
        $category = (string) ($input['category'] ?? 'survey');
        if (! in_array($category, ['survey', 'interview'], true)) {
            $errors[] = ['code' => 'validation.field', 'message' => 'Unknown category.', 'field' => 'category'];
        }
        foreach (['publish_at', 'close_at'] as $field) {
            $value = $input[$field] ?? null;
            if ($value !== null && $value !== '' && strtotime((string) $value) === false) {
                $errors[] = ['code' => 'validation.field', 'message' => 'Invalid datetime.', 'field' => $field];
            }
        }
        $yearLevels = $this->normalizeYearLevels($input['year_levels'] ?? null, $errors);
        if ($errors !== []) {
            throw ApiException::validationFailure($errors);
        }

        return [
            'title'       => $title,
            'description' => isset($input['description']) && trim((string) $input['description']) !== ''
                ? mb_substr(trim((string) $input['description']), 0, 1000)
                : null,
            'category'    => $category,
            'audience'    => $audience,
            'year_levels' => $yearLevels,
            'is_required' => ($input['is_required'] ?? false) === true ? 1 : 0,
            'publish_at'  => isset($input['publish_at']) && $input['publish_at'] !== '' ? (string) $input['publish_at'] : null,
            'close_at'    => isset($input['close_at']) && $input['close_at'] !== '' ? (string) $input['close_at'] : null,
        ];
    }

    /**
     * Year-level targeting: a JSON list of registry year levels (1-6);
     * null/empty means "every year level".
     *
     * @param list<string> $errors
     */
    private function normalizeYearLevels(mixed $raw, array &$errors): ?string
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        $levels = array_values(array_unique(array_map('intval', (array) $raw)));
        sort($levels);
        foreach ($levels as $level) {
            if (! in_array($level, self::YEAR_LEVELS, true)) {
                $errors[] = ['code' => 'validation.field', 'message' => 'Year levels must be integers 1-6.', 'field' => 'year_levels'];
                return null;
            }
        }
        return json_encode($levels, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<int>|null null = every year level
     */
    private static function decodeYearLevels(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === '[]') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        $levels = is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
        return $levels === [] ? null : $levels;
    }

    /**
     * The caller's registry year level (users.year_level — the student
     * registry lives on users since the identity consolidation); null
     * when the account carries no year level yet.
     */
    private function studentYearLevel(int $studentUserId): ?int
    {
        $row = $this->db->table('users')
            ->select('year_level')
            ->where('id', $studentUserId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        return ($row === null || $row['year_level'] === null) ? null : (int) $row['year_level'];
    }

    /**
     * Normalizes the builder's question payload.
     *
     * @param array<int, array<string, mixed>> $questions
     * @return list<array{sort_order: int, question_type: string, question_text: string, is_required: int, options: list<string>}>
     */
    private function validateQuestions(array $questions): array
    {
        if ($questions === []) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Add at least one question.', 'field' => 'questions'],
            ]);
        }

        $normalized = [];
        foreach (array_values($questions) as $index => $q) {
            $text = trim((string) ($q['question_text'] ?? ''));
            $type = (string) ($q['question_type'] ?? 'free_text');
            if ($text === '' || mb_strlen($text) > 1000) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Question ' . ($index + 1) . ' needs text (max 1000 chars).', 'field' => 'questions'],
                ]);
            }
            if (! in_array($type, self::QUESTION_TYPES, true)) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Question ' . ($index + 1) . ' has an unknown type.', 'field' => 'questions'],
                ]);
            }

            $options = [];
            if (in_array($type, ['single', 'multi'], true)) {
                $options = array_values(array_filter(array_map(
                    static fn ($o): string => trim((string) ($o['option_text'] ?? $o)),
                    (array) ($q['options'] ?? []),
                )));
                if (count($options) < 2) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'message' => 'Question ' . ($index + 1) . ' needs at least 2 options.', 'field' => 'questions'],
                    ]);
                }
            }

            $normalized[] = [
                'sort_order'    => ($index + 1) * 10,
                'question_type' => $type,
                'question_text' => $text,
                'is_required'   => ($q['is_required'] ?? false) === true ? 1 : 0,
                'options'       => $options,
            ];
        }
        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questionsForVersion(int $versionId): array
    {
        $questions = $this->db->table('survey_questions')
            ->where(['version_id' => $versionId])
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();

        $out = [];
        foreach ($questions as $q) {
            $options = $this->db->table('survey_answer_options')
                ->where('question_id', (int) $q['id'])
                ->orderBy('sort_order', 'ASC')
                ->get()->getResultArray();
            $out[] = [
                'id'          => (int) $q['id'],
                'sort_order'  => (int) $q['sort_order'],
                'question_type' => (string) $q['question_type'],
                'question_text' => (string) $q['question_text'],
                'is_required' => (bool) $q['is_required'],
                'options'     => array_map(static fn (array $o): array => [
                    'id'   => (int) $o['id'],
                    'text' => (string) $o['option_text'],
                ], $options),
                'option_ids'  => array_map(static fn (array $o): int => (int) $o['id'], $options),
            ];
        }
        return $out;
    }

    /**
     * Published + window-open + audience-matched check for the caller.
     *
     * @param array<string, mixed> $survey
     */
    private function publishedSurveyForStudent(int $surveyId, int $studentUserId): array
    {
        $audiences = GuidanceAudience::audiencesFor($this->db, $studentUserId, CurrentTenant::id());
        $survey = $this->db->table('surveys s')
            ->select('s.*, sv.id AS version_id')
            ->join('survey_versions sv', 'sv.survey_id = s.id')
            ->where('s.id', $surveyId)
            ->where('s.tenant_id', CurrentTenant::id())
            ->where('s.archived_at', null)
            ->whereIn('s.audience', $audiences)
            ->groupStart()
                ->where('s.publish_at', null)
                ->orWhere('s.publish_at <=', $this->utcNow())
            ->groupEnd()
            ->groupStart()
                ->where('s.close_at', null)
                ->orWhere('s.close_at >', $this->utcNow())
            ->groupEnd()
            ->get()->getRowArray();

        if ($survey === null) {
            throw ApiException::notFound('resource.not_found');
        }
        $targets = self::decodeYearLevels($survey['year_levels'] ?? null);
        $studentYear = $this->studentYearLevel($studentUserId);
        if ($targets !== null && ($studentYear === null || ! in_array($studentYear, $targets, true))) {
            throw ApiException::notFound('resource.not_found');
        }
        return $survey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSurvey(int $id): array
    {
        $row = $this->db->table('surveys')
            ->where('id', $id)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }
        $hydrated = $this->hydrateSurvey($row);
        $hydrated['question_count'] = $this->db->table('survey_questions')
            ->where(['survey_id' => $id])
            ->where('version_id', $hydrated['version_id'] ?? null)
            ->countAllResults();
        $hydrated['response_count'] = $this->countResponses($id);
        // Drafts expose their DRAFT questions (version_id NULL); published
        // surveys expose the immutable version snapshot.
        $hydrated['questions'] = $hydrated['version_id'] !== null
            ? $this->questionsForVersion($hydrated['version_id'])
            : $this->draftQuestions($id);
        $hydrated['links'] = $hydrated['version_id'] !== null
            ? $this->linksForVersion($id, $hydrated['version_id'])
            : $this->linksForVersion($id, null);
        return $hydrated;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function draftQuestions(int $surveyId): array
    {
        $questions = $this->db->table('survey_questions')
            ->where(['survey_id' => $surveyId, 'version_id' => null])
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();

        $out = [];
        foreach ($questions as $q) {
            $options = $this->db->table('survey_answer_options')
                ->where('question_id', (int) $q['id'])
                ->orderBy('sort_order', 'ASC')
                ->get()->getResultArray();
            $out[] = [
                'id'          => (int) $q['id'],
                'sort_order'  => (int) $q['sort_order'],
                'question_type' => (string) $q['question_type'],
                'question_text' => (string) $q['question_text'],
                'is_required' => (bool) $q['is_required'],
                'options'     => array_map(static fn (array $o): array => [
                    'id'   => (int) $o['id'],
                    'text' => (string) $o['option_text'],
                ], $options),
                'option_ids'  => array_map(static fn (array $o): int => (int) $o['id'], $options),
            ];
        }
        return $out;
    }

    /**
     * Normalizes the builder's link payload (draft set). Rows with a
     * `local` id are new; the hard-replace semantics of setLinks make
     * id bookkeeping unnecessary.
     *
     * @param array<int, array<string, mixed>> $links
     * @return list<array<string, mixed>>
     */
    private function validateLinks(array $links): array
    {
        $normalized = [];
        foreach (array_values($links) as $index => $row) {
            $n = $index + 1;
            $type = (string) ($row['type'] ?? '');
            if (! in_array($type, self::LINK_TYPES, true)) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Link ' . $n . ' has an unknown type.', 'field' => 'links'],
                ]);
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 200) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Link ' . $n . ' needs a title (max 200 chars).', 'field' => 'links'],
                ]);
            }

            $url = self::validateExternalUrl($row['external_url'] ?? null);

            $description = trim((string) ($row['description'] ?? ''));
            $description = $description === '' ? null : mb_substr($description, 0, 1000);

            $audience = null;
            if ($type === 'survey') {
                $rawAudience = $row['audience'] ?? null;
                if ($rawAudience !== null && $rawAudience !== '') {
                    $candidate = (string) $rawAudience;
                    if (! in_array($candidate, ['all', 'new_students', 'continuing_students', 'graduating_students'], true)) {
                        throw ApiException::validationFailure([
                            ['code' => 'validation.field', 'message' => 'Link ' . $n . ' has an unknown audience.', 'field' => 'links'],
                        ]);
                    }
                    $audience = $candidate === 'all' ? null : $candidate;
                }
            }

            $instrument = null;
            if ($type === 'test') {
                $instrument = (string) ($row['instrument_key'] ?? 'custom');
                if (! in_array($instrument, self::INSTRUMENT_KEYS, true)) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'message' => 'Link ' . $n . ' has an unknown assessment instrument.', 'field' => 'links'],
                    ]);
                }
            }

            $normalized[] = [
                'type'                => $type,
                'title'               => $title,
                'description'         => $description,
                'external_url'        => $url,
                'audience'            => $audience,
                'instrument_key'      => $instrument,
                // Deprecated gating flag — accepted for compatibility,
                // never read for gating any more.
                'is_required'         => ($row['is_required'] ?? false) === true ? 1 : 0,
                'is_enabled'          => ($row['is_enabled'] ?? true) === false ? 0 : 1,
                'requires_screenshot' => ($row['requires_screenshot'] ?? false) === true ? 1 : 0,
                'sort_order'          => ($index + 1) * 10,
            ];
        }
        return $normalized;
    }

    /**
     * Shaped links for one survey: the immutable version snapshot, or
     * the draft rows when $versionId is null.
     *
     * @return list<array<string, mixed>>
     */
    private function linksForVersion(int $surveyId, ?int $versionId): array
    {
        $builder = $this->db->table('survey_links')
            ->where(['survey_id' => $surveyId, 'tenant_id' => CurrentTenant::id()]);
        if ($versionId === null) {
            $builder->where('version_id', null);
        } else {
            $builder->where('version_id', $versionId);
        }
        return array_map(
            fn (array $row): array => $this->shapeLink($row),
            $builder->orderBy('sort_order', 'ASC')->get()->getResultArray(),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeLink(array $row): array
    {
        return [
            'id'                  => (int) $row['id'],
            'survey_id'           => (int) $row['survey_id'],
            'type'                => (string) $row['type'],
            'title'               => (string) $row['title'],
            'description'         => $row['description'] !== null ? (string) $row['description'] : null,
            'external_url'        => (string) $row['external_url'],
            'audience'            => $row['audience'] !== null ? (string) $row['audience'] : null,
            'instrument_key'      => $row['instrument_key'] !== null ? (string) $row['instrument_key'] : null,
            'is_required'         => (bool) $row['is_required'],
            'is_enabled'          => (bool) $row['is_enabled'],
            'requires_screenshot' => (bool) $row['requires_screenshot'],
            'sort_order'          => (int) $row['sort_order'],
        ];
    }

    /**
     * Enabled links of a version the student may see: audience-targeted
     * survey links are filtered by the caller's resolved audiences, so
     * hidden links can never gate that student's submission.
     *
     * @param list<string> $audiences
     * @return list<array<string, mixed>>
     */
    private function visibleLinksForStudent(int $surveyId, int $versionId, array $audiences): array
    {
        return array_values(array_filter(
            $this->linksForVersion($surveyId, $versionId),
            static fn (array $l): bool => $l['is_enabled']
                && ($l['audience'] === null || in_array($l['audience'], $audiences, true)),
        ));
    }

    /**
     * The published-version link visible to this student, or 404.
     *
     * @return array<string, mixed> raw row
     */
    private function assertLinkForStudent(int $surveyId, int $versionId, int $linkId, int $studentUserId): array
    {
        $link = $this->db->table('survey_links')
            ->where(['id' => $linkId, 'survey_id' => $surveyId, 'version_id' => $versionId, 'tenant_id' => CurrentTenant::id()])
            ->get()->getRowArray();
        if ($link === null || (int) $link['is_enabled'] !== 1) {
            throw ApiException::notFound('resource.not_found');
        }
        if ($link['audience'] !== null) {
            $audiences = GuidanceAudience::audiencesFor($this->db, $studentUserId, CurrentTenant::id());
            if (! in_array((string) $link['audience'], $audiences, true)) {
                throw ApiException::notFound('resource.not_found');
            }
        }
        return $link;
    }

    private function hasOpened(int $linkId, int $studentUserId): bool
    {
        return $this->db->table('survey_link_opens')
            ->where(['link_id' => $linkId, 'student_user_id' => $studentUserId, 'tenant_id' => CurrentTenant::id()])
            ->countAllResults() > 0;
    }

    private function screenshotDirectory(): string
    {
        return WRITEPATH . self::SCREENSHOT_DIR . DIRECTORY_SEPARATOR . CurrentTenant::id();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{path: string, name: string, mime: string}
     */
    private function screenshotFile(array $row): array
    {
        $path = $this->screenshotDirectory() . DIRECTORY_SEPARATOR . basename((string) $row['stored_name']);
        if (! is_file($path)) {
            throw ApiException::notFound('resource.not_found');
        }
        return ['path' => $path, 'name' => (string) $row['original_name'], 'mime' => (string) $row['mime_type']];
    }

    private function unlinkStored(string $storedName): void
    {
        $path = $this->screenshotDirectory() . DIRECTORY_SEPARATOR . basename($storedName);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Draft-only guard: 404 on missing, 409 on published.
     *
     * @return array<string, mixed>
     */
    private function assertDraft(int $id): array
    {
        $row = $this->selectForUpdate('surveys', [
            'id' => $id, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null,
        ]);
        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }
        $published = $this->db->table('survey_versions')->where('survey_id', $id)->countAllResults();
        if ($published > 0) {
            throw ApiException::conflict('statemachine.invalid_transition', 'Published surveys are immutable — archive and create a new one instead.');
        }
        return $row;
    }

    private function assertSurveyInTenant(int $surveyId): void
    {
        $exists = $this->db->table('surveys')
            ->where(['id' => $surveyId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null])
            ->countAllResults();
        if ($exists === 0) {
            throw ApiException::notFound('resource.not_found');
        }
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
