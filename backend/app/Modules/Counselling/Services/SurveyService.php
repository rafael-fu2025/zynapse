<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use App\Services\Crypto\EncryptionService;
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
            if ($draftQuestions === []) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Add at least one question before publishing.', 'field' => 'questions'],
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
     * context). Gated by counselling.responses.read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listResponses(int $surveyId): array
    {
        $this->assertSurveyInTenant($surveyId);

        $rows = $this->db->table('survey_responses r')
            ->select('r.id, r.survey_id, r.student_user_id, r.submitted_at, u.username, u.first_name, u.last_name, i.secret AS email')
            ->join('users u', 'u.id = r.student_user_id')
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('r.tenant_id', CurrentTenant::id())
            ->where('r.survey_id', $surveyId)
            ->orderBy('r.submitted_at', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'              => (int) $r['id'],
            'survey_id'       => (int) $r['survey_id'],
            'student_user_id' => (int) $r['student_user_id'],
            'student_name'    => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])) ?: null,
            'email'           => $r['email'] !== null ? (string) $r['email'] : null,
            'submitted_at'    => (string) $r['submitted_at'],
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
            ->select('r.*, u.username, u.first_name, u.last_name')
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

        return [
            'id'              => (int) $response['id'],
            'survey_id'       => (int) $response['survey_id'],
            'student_user_id' => (int) $response['student_user_id'],
            'student_name'    => trim(((string) $response['first_name']) . ' ' . ((string) $response['last_name'])) ?: null,
            'submitted_at'    => (string) $response['submitted_at'],
            'answers'         => is_array($snapshot) ? $snapshot : [],
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
            $submitted = $this->db->table('survey_responses')
                ->where(['survey_id' => $surveyId, 'student_user_id' => $studentUserId])
                ->countAllResults();
            if ($submitted > 0) {
                continue;
            }
            $out[] = [
                'id'          => $surveyId,
                'title'       => (string) $r['title'],
                'description' => $r['description'] !== null ? (string) $r['description'] : null,
                'category'    => (string) $r['category'],
                'is_required' => (bool) $r['is_required'],
                'publish_at'  => $r['publish_at'] !== null ? (string) $r['publish_at'] : null,
                'close_at'    => $r['close_at'] !== null ? (string) $r['close_at'] : null,
            ];
        }
        return $out;
    }

    /**
     * The clearance gate: required surveys with an open window the
     * student has not yet completed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requirements(int $studentUserId): array
    {
        return array_values(array_filter(
            $this->availableForStudent($studentUserId),
            static fn (array $s): bool => $s['is_required'] === true,
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

        $submitted = $this->db->table('survey_responses')
            ->where(['survey_id' => $surveyId, 'student_user_id' => $studentUserId])
            ->countAllResults();
        if ($submitted > 0) {
            throw ApiException::conflict('resource.conflict', 'You have already answered this survey.');
        }

        return [
            'id'          => (int) $survey['id'],
            'title'       => (string) $survey['title'],
            'description' => $survey['description'] !== null ? (string) $survey['description'] : null,
            'close_at'    => $survey['close_at'] !== null ? (string) $survey['close_at'] : null,
            'version_id'  => (int) $survey['version_id'],
            'questions'   => $this->questionsForVersion((int) $survey['version_id']),
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
            'is_required' => ($input['is_required'] ?? false) === true ? 1 : 0,
            'publish_at'  => isset($input['publish_at']) && $input['publish_at'] !== '' ? (string) $input['publish_at'] : null,
            'close_at'    => isset($input['close_at']) && $input['close_at'] !== '' ? (string) $input['close_at'] : null,
        ];
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
