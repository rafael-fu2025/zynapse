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
 * GuidanceFollowupService — the RA 11036 §24 aftercare loop (Phase C).
 *
 * Triggers (evaluated at survey submit time, inside the same
 * transaction): the WHO-5 block (question_key who5_1..who5_5, answered
 * on a 1-5 scale, mapped to the standard 0-100 score) scoring at/below
 * the configured threshold, OR the student explicitly requesting
 * contact (concerns_flag = the affirmative option). NEVER keyword
 * sniffing — the request must be explicit or the score low; outreach
 * tone is supportive, never punitive (parity plan research).
 *
 * The loop is CLOSED: a followup cannot reach addressed/closed without
 * an outcome note. Visible only to `counselling.responses.read_any`
 * holders; every status change is audited.
 */
final class GuidanceFollowupService extends BaseService
{
    /** WHO-5 percentage score at/below which a followup is created. */
    private const WHO5_FLAG_THRESHOLD = 50;

    private const TRANSITIONS = [
        'start_review'    => ['from' => 'new',       'to' => 'in_review', 'requires_note' => false],
        'mark_addressed'  => ['from' => 'in_review', 'to' => 'addressed', 'requires_note' => true],
        'close'           => ['from' => 'addressed', 'to' => 'closed',    'requires_note' => true],
    ];

    public function __construct(
        ?\CodeIgniter\Database\BaseConnection $db = null,
        private readonly AuditOutboxService $audit = new AuditOutboxService(),
    ) {
        parent::__construct($db);
    }

    /**
     * Evaluates one submitted response and creates a followup when the
     * WHO-5 block scores low or the student requested contact. Idempotent
     * per response. Called INSIDE the submit transaction.
     */
    public function evaluateResponse(int $responseId): void
    {
        $response = $this->db->table('survey_responses r')
            ->select('r.id, r.student_user_id, r.survey_id, r.version_id, r.tenant_id')
            ->where('r.id', $responseId)
            ->get()->getRowArray();
        if ($response === null) {
            return;
        }

        $already = $this->db->table('guidance_followups')
            ->where('source_response_id', $responseId)
            ->countAllResults();
        if ($already > 0) {
            return;
        }

        $answers = $this->db->table('survey_answers a')
            ->select('a.value_number, a.value_text, q.question_key, o.option_text')
            ->join('survey_questions q', 'q.id = a.question_id')
            ->join('survey_answer_options o', 'o.id = a.value_number', 'left')
            ->where('a.response_id', $responseId)
            ->get()->getResultArray();

        $who5Values = [];
        $requestedContact = false;
        foreach ($answers as $a) {
            $key = $a['question_key'] !== null ? (string) $a['question_key'] : '';
            if (preg_match('/^who5_[1-5]$/', $key) === 1 && $a['value_number'] !== null) {
                $who5Values[] = (int) $a['value_number'];
            }
            if ($key === 'concerns_flag' && $a['option_text'] !== null
                && str_starts_with(strtolower(trim((string) $a['option_text'])), 'yes')) {
                $requestedContact = true;
            }
        }

        $who5Score = null;
        $triggered = false;
        $reason = '';
        if (count($who5Values) === 5) {
            // 1-5 likert → WHO-5 raw (0-20) → percentage (0-100).
            $raw = array_sum(array_map(static fn (int $v): int => $v - 1, $who5Values));
            $who5Score = $raw * 5;
            if ($who5Score <= self::WHO5_FLAG_THRESHOLD) {
                $triggered = true;
                $reason = "WHO-5 well-being score {$who5Score}/100 (at or below the " . self::WHO5_FLAG_THRESHOLD . ' threshold)';
            }
        }
        if ($requestedContact) {
            $triggered = true;
            $reason = $reason !== '' ? $reason . '; student requested counselor contact' : 'Student requested counselor contact';
        }

        if (! $triggered) {
            return;
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $sla = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 days')->format('Y-m-d H:i:s');
        $this->db->table('guidance_followups')->insert([
            'tenant_id'           => (int) $response['tenant_id'],
            'student_user_id'     => (int) $response['student_user_id'],
            'source_response_id'  => $responseId,
            'source_survey_id'    => (int) $response['survey_id'],
            'risk_reason'         => $reason,
            'who5_score'          => $who5Score,
            'status'              => 'new',
            'due_at'              => $sla,
            'created_by'          => (int) $response['student_user_id'],
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        // Created by the SYSTEM on behalf of the student's submission —
        // the outbox audit records the student as actor since the
        // submit drives it.
        $this->audit->enqueue('guidance.followup_created', 'guidance_followups', (int) $this->db->insertID(), (int) $response['student_user_id'], [
            'resource_code'  => 'survey#' . (string) $response['survey_id'],
            'reason_code'    => $reason,
        ]);
    }

    /**
     * Staff list (counselling.responses.read_any), newest + open first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFollowups(string $status = 'all', bool $mineOnly = false): array
    {
        $actorId = CurrentUser::assert();
        $builder = $this->db->table('guidance_followups f')
            ->select('f.*, s.title AS survey_title, u.first_name, u.last_name, u.username, c.username AS counsellor_username')
            ->join('surveys s', 's.id = f.source_survey_id')
            ->join('users u', 'u.id = f.student_user_id')
            ->join('users c', 'c.id = f.assigned_counsellor_user_id', 'left')
            ->where('f.tenant_id', CurrentTenant::id());

        if ($status !== 'all' && in_array($status, ['new', 'in_review', 'addressed', 'closed'], true)) {
            $builder->where('f.status', $status);
        }
        if ($mineOnly) {
            $builder->where('f.assigned_counsellor_user_id', $actorId);
        }

        $rows = $builder
            ->orderBy("CASE f.status WHEN 'new' THEN 0 WHEN 'in_review' THEN 1 WHEN 'addressed' THEN 2 ELSE 3 END", 'ASC', false)
            ->orderBy('f.due_at', 'ASC')
            ->orderBy('f.created_at', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'                          => (int) $r['id'],
            'student_user_id'             => (int) $r['student_user_id'],
            'student_name'                => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])) ?: (string) $r['username'],
            'survey_title'                => (string) $r['survey_title'],
            'risk_reason'                 => (string) $r['risk_reason'],
            'who5_score'                  => $r['who5_score'] !== null ? (int) $r['who5_score'] : null,
            'status'                      => (string) $r['status'],
            'assigned_counsellor_user_id' => $r['assigned_counsellor_user_id'] !== null ? (int) $r['assigned_counsellor_user_id'] : null,
            'assigned_counsellor'         => $r['counsellor_username'] !== null ? (string) $r['counsellor_username'] : null,
            'outcome_note'                => $r['outcome_note'] !== null ? (string) $r['outcome_note'] : null,
            'due_at'                      => $r['due_at'] !== null ? (string) $r['due_at'] : null,
            'created_at'                  => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Loop transition with the closed-loop rule: addressed/closed
     * require a non-empty outcome note.
     *
     * @return array<string, mixed>
     */
    public function transition(int $id, string $action, ?string $outcomeNote): array
    {
        $actorId = CurrentUser::assert();
        $rule = self::TRANSITIONS[$action] ?? null;
        if ($rule === null) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown follow-up action.', 'field' => 'action'],
            ]);
        }

        return $this->txn(function () use ($id, $action, $outcomeNote, $rule, $actorId): array {
            $row = $this->selectForUpdate('guidance_followups', [
                'id' => $id, 'tenant_id' => CurrentTenant::id(),
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }
            if ((string) $row['status'] !== $rule['from']) {
                throw ApiException::conflict('statemachine.invalid_transition',
                    "This follow-up is '{$row['status']}' — {$action} applies to '{$rule['from']}' items.");
            }

            $note = $outcomeNote !== null ? trim($outcomeNote) : '';
            if ($rule['requires_note'] && $note === '') {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'An outcome note is required to close the loop.', 'field' => 'outcome_note'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $update = ['status' => $rule['to'], 'updated_at' => $now];
            if ($action === 'start_review') {
                // Self-assignment on pickup keeps ownership unambiguous.
                $update['assigned_counsellor_user_id'] = $actorId;
            }
            if ($rule['requires_note']) {
                $update['outcome_note'] = $note;
            }
            $this->db->table('guidance_followups')->where('id', $id)->update($update);

            $this->audit->enqueue('guidance.followup_status_changed', 'guidance_followups', $id, $actorId, [
                'previous_status' => (string) $row['status'],
                'next_status'     => $rule['to'],
                'resource_code'   => 'student#' . (string) $row['student_user_id'],
            ]);

            return ['id' => $id, 'status' => $rule['to']];
        });
    }

    /**
     * Assigns a counsellor (oversight routing).
     *
     * @return array<string, mixed>
     */
    public function assign(int $id, int $counsellorUserId): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($id, $counsellorUserId, $actorId): array {
            $row = $this->selectForUpdate('guidance_followups', [
                'id' => $id, 'tenant_id' => CurrentTenant::id(),
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $counsellor = $this->db->table('users')
                ->where('id', $counsellorUserId)
                ->where('tenant_id', CurrentTenant::id())
                ->get()->getRowArray();
            if ($counsellor === null) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Unknown counsellor.', 'field' => 'counsellor_user_id'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('guidance_followups')
                ->where('id', $id)
                ->update(['assigned_counsellor_user_id' => $counsellorUserId, 'updated_at' => $now]);

            $this->audit->enqueue('guidance.followup_assigned', 'guidance_followups', $id, $actorId, [
                'resource_code' => 'counsellor#' . $counsellorUserId,
            ]);

            return ['id' => $id, 'assigned_counsellor_user_id' => $counsellorUserId];
        });
    }

    /**
     * Term aggregate for one survey — per-question distribution for
     * likert/rating/single/multi, counts for free_text/external_url.
     * Aggregate counts only — no verbatims leave the detail view.
     *
     * @return array<string, mixed>
     */
    public function aggregate(int $surveyId): array
    {
        $survey = $this->db->table('surveys')
            ->where(['id' => $surveyId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null])
            ->get()->getRowArray();
        if ($survey === null) {
            throw ApiException::notFound('resource.not_found');
        }

        $version = $this->db->table('survey_versions')
            ->where('survey_id', $surveyId)
            ->orderBy('version_no', 'DESC')
            ->get()->getRowArray();
        if ($version === null) {
            return ['survey_id' => $surveyId, 'response_count' => 0, 'questions' => []];
        }

        $questions = $this->db->table('survey_questions')
            ->where(['version_id' => (int) $version['id']])
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();

        $responseCount = $this->db->table('survey_responses')
            ->where('survey_id', $surveyId)
            ->countAllResults();

        $out = [];
        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $type = (string) $q['question_type'];
            $entry = [
                'question_id'   => $qid,
                'question_text' => (string) $q['question_text'],
                'question_type' => $type,
                'answered'      => $this->db->table('survey_answers')
                    ->where(['question_id' => $qid, 'version_id' => (int) $version['id']])
                    ->countAllResults(),
            ];

            if (in_array($type, ['likert', 'rating'], true)) {
                $buckets = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
                $rows = $this->db->table('survey_answers')
                    ->select('value_number, COUNT(*) AS cnt')
                    ->where(['question_id' => $qid, 'version_id' => (int) $version['id']])
                    ->groupBy('value_number')
                    ->get()->getResultArray();
                $sum = 0;
                $total = 0;
                foreach ($rows as $r) {
                    $score = (int) $r['value_number'];
                    if (isset($buckets[$score])) {
                        $buckets[$score] = (int) $r['cnt'];
                        $sum += $score * (int) $r['cnt'];
                        $total += (int) $r['cnt'];
                    }
                }
                $entry['scale_counts'] = $buckets;
                $entry['average'] = $total > 0 ? round($sum / $total, 2) : null;
            } elseif (in_array($type, ['single', 'multi'], true)) {
                $options = $this->db->table('survey_answer_options')
                    ->where('question_id', $qid)
                    ->orderBy('sort_order', 'ASC')
                    ->get()->getResultArray();
                $counts = [];
                foreach ($options as $o) {
                    $counts[(string) $o['option_text']] = $this->db->table('survey_answers')
                        ->where(['question_id' => $qid, 'version_id' => (int) $version['id'], 'value_number' => (int) $o['id']])
                        ->countAllResults();
                }
                $entry['option_counts'] = $counts;
            }
            $out[] = $entry;
        }

        return [
            'survey_id'      => $surveyId,
            'title'          => (string) $survey['title'],
            'response_count' => $responseCount,
            'questions'      => $out,
        ];
    }

    /**
     * Session linkage — the counsellor's session view of the student's
     * latest interview submissions. Gated by counselling.responses.read
     * PLUS ownership: the caller must be the session's counsellor or
     * hold counselling.responses.read_any.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestInterviewsForSession(int $sessionId): array
    {
        $actorId = CurrentUser::assert();
        $session = $this->db->table('counselling_sessions')
            ->where(['id' => $sessionId, 'tenant_id' => CurrentTenant::id()])
            ->get()->getRowArray();
        if ($session === null) {
            throw ApiException::notFound('resource.not_found');
        }

        $hasReadAny = (new \App\Services\Rbac\PermissionService())
            ->userHas($actorId, 'counselling.responses.read_any');
        if ((int) $session['counsellor_user_id'] !== $actorId && ! $hasReadAny) {
            throw ApiException::forbidden('rbac.permission_denied:counselling.responses.read_any');
        }

        // The session links the student directly (patient_user_id); fall
        // back to the school-id join for legacy rows.
        $studentId = $session['patient_user_id'] !== null
            ? (int) $session['patient_user_id']
            : 0;
        if ($studentId === 0) {
            $student = $this->db->table('users')
                ->select('id')
                ->where('student_number', (string) $session['patient_school_id'])
                ->where('tenant_id', CurrentTenant::id())
                ->get()->getRowArray();
            $studentId = $student !== null ? (int) $student['id'] : 0;
        }
        if ($studentId === 0) {
            return [];
        }

        // Latest submitted response per interview survey.
        $rows = $this->db->table('survey_responses r')
            ->select('r.id, r.survey_id, r.submitted_at, s.title, s.category')
            ->join('surveys s', 's.id = r.survey_id')
            ->where('r.student_user_id', $studentId)
            ->where('r.tenant_id', CurrentTenant::id())
            ->where('s.category', 'interview')
            ->orderBy('r.submitted_at', 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $detail = $this->responseAnswers((int) $r['id']);
            $out[] = [
                'response_id'  => (int) $r['id'],
                'survey_id'    => (int) $r['survey_id'],
                'survey_title' => (string) $r['title'],
                'submitted_at' => (string) $r['submitted_at'],
                'answers'      => $detail,
            ];
        }
        return $out;
    }

    /**
     * Decrypted answers keyed by question text (session panel view).
     *
     * @return list<array{question: string, value: string}>
     */
    private function responseAnswers(int $responseId): array
    {
        $response = $this->db->table('survey_responses')
            ->where('id', $responseId)
            ->get()->getRowArray();
        if ($response === null) {
            return [];
        }

        $snapshot = json_decode(
            (new EncryptionService())->decryptField(
                (string) $response['payload_cipher'],
                (string) $response['payload_nonce'],
                (int) $response['payload_key_version'],
            ),
            true,
        );
        if (! is_array($snapshot)) {
            return [];
        }

        $out = [];
        foreach ($snapshot as $entry) {
            $questionId = (int) ($entry['question_id'] ?? 0);
            $question = $this->db->table('survey_questions')->where('id', $questionId)->get()->getRowArray();
            $value = $entry['value'] ?? null;
            $rendered = is_array($value)
                ? implode(', ', array_map('strval', $value))
                : ($value === true ? 'Completed' : ($value === null ? '' : (string) $value));
            $out[] = [
                'question' => $question !== null ? (string) $question['question_text'] : "Question #{$questionId}",
                'value'    => $rendered,
            ];
        }
        return $out;
    }
}
