<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Guidance aftercare loop (2026-09 parity plan Phase C, RA 11036 §24):
 *
 *   - the seeded Routine Interview template (WHO-5 block + contact
 *     request) publishes and reaches continuing students;
 *   - triage triggers at submit time: low WHO-5 score OR an explicit
 *     contact request creates ONE followup; high score + no request
 *     creates none; a skipped WHO-5 block is legal and scores nothing;
 *   - the loop is CLOSED: addressed/closed require an outcome note;
 *   - the caseload is read_any-only (counsellors with plain read → 403);
 *   - aggregate distribution + session linkage endpoints.
 */
final class GuidanceFollowupTest extends FeatureTestCase
{
    private const KEY = 'f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3';

    /** @var array<int, array{id:int, version_id:int, questions: list<array<string, mixed>>}> */
    private array $routineCache = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('COUNSELLING_KEY=' . self::KEY);
        putenv('COUNSELLING_KEY_VERSION=1');
    }

    protected function tearDown(): void
    {
        putenv('COUNSELLING_KEY');
        putenv('COUNSELLING_KEY_VERSION');
        parent::tearDown();
    }

    /**
     * Ensures the migration-seeded Routine Interview template is
     * published (the test schema is stateful — a previous run may have
     * published it already) and returns its questions keyed by stable
     * question-text prefixes (the payload does not expose question_key).
     *
     * @return array{id: int, version_id: int, by_key: array<string, array<string, mixed>>, admin: array{token: string, userId: int, email: string}}
     */
    private function publishRoutineInterview(): array
    {
        $admin = $this->login(['guidance_admin']);
        $list = $this->authed($admin['token'], 'get', 'api/v1/counselling/surveys');
        $list->assertStatus(200);
        $rows = $this->envelope($list)['data'] ?? [];
        $survey = null;
        foreach ($rows as $r) {
            if (($r['category'] ?? '') === 'interview') {
                $survey = $r;
                break;
            }
        }
        $this->assertNotNull($survey, 'The migration seeds one Routine Interview template per tenant.');
        $surveyId = (int) $survey['id'];

        if (($survey['status'] ?? '') === 'draft') {
            $published = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $surveyId . '/publish');
            $published->assertStatus(200);
        }

        $detail = $this->authed($admin['token'], 'get', 'api/v1/counselling/surveys/' . $surveyId);
        $detail->assertStatus(200);
        $body = $this->envelope($detail)['data'];

        // Keyed by stable text prefixes → question row (question_key is
        // internal; the payload doesn't expose it).
        $byKey = [];
        foreach ($body['questions'] as $q) {
            $text = (string) $q['question_text'];
            if (str_starts_with($text, 'I have felt cheerful')) {
                $byKey['who5_1'] = $q;
            } elseif (str_starts_with($text, 'I have felt calm')) {
                $byKey['who5_2'] = $q;
            } elseif (str_starts_with($text, 'I have felt active')) {
                $byKey['who5_3'] = $q;
            } elseif (str_starts_with($text, 'I woke up feeling')) {
                $byKey['who5_4'] = $q;
            } elseif (str_starts_with($text, 'My daily life')) {
                $byKey['who5_5'] = $q;
            } elseif (str_starts_with($text, 'Would you like a Guidance Counselor')) {
                $byKey['concerns_flag'] = $q;
            } elseif (str_starts_with($text, 'How are you doing right now')) {
                $byKey['routine_1'] = $q;
            }
        }
        $this->assertArrayHasKey('who5_1', $byKey);
        $this->assertArrayHasKey('concerns_flag', $byKey);
        $this->assertCount(2, $byKey['concerns_flag']['options']);

        return ['id' => $surveyId, 'version_id' => (int) $body['version_id'], 'by_key' => $byKey, 'admin' => $admin];
    }

    /**
     * A continuing student + their session token.
     *
     * @return array{token: string, userId: int, email: string, student_number: string}
     */
    private function continuingStudent(): array
    {
        $session = $this->login([]);
        $schoolId = 'C-' . bin2hex(random_bytes(4));
        db_connect()->table('users')->where('id', $session['userId'])->update([
            'kind'           => 'student',
            'student_number' => $schoolId,
            'created_at'     => '2020-01-01 00:00:00',
        ]);
        return ['token' => $session['token'], 'userId' => $session['userId'], 'email' => $session['email'], 'student_number' => $schoolId];
    }

    /**
     * Keys form questions by the same stable text prefixes the helper
     * uses (the payload does not expose question_key).
     *
     * @param list<array<string, mixed>> $questions
     * @return array<string, array<string, mixed>>
     */
    private function keyByText(array $questions): array
    {
        $byKey = [];
        foreach ($questions as $q) {
            $text = (string) $q['question_text'];
            if (str_starts_with($text, 'I have felt cheerful')) {
                $byKey['who5_1'] = $q;
            } elseif (str_starts_with($text, 'I have felt calm')) {
                $byKey['who5_2'] = $q;
            } elseif (str_starts_with($text, 'I have felt active')) {
                $byKey['who5_3'] = $q;
            } elseif (str_starts_with($text, 'I woke up feeling')) {
                $byKey['who5_4'] = $q;
            } elseif (str_starts_with($text, 'My daily life')) {
                $byKey['who5_5'] = $q;
            } elseif (str_starts_with($text, 'Would you like a Guidance Counselor')) {
                $byKey['concerns_flag'] = $q;
            } elseif (str_starts_with($text, 'How are you doing right now')) {
                $byKey['routine_1'] = $q;
            }
        }
        return $byKey;
    }

    public function testRoutineInterviewSeedsPublishesAndReachesContinuingStudents(): void
    {
        $ctx = $this->publishRoutineInterview();
        $student = $this->continuingStudent();

        $mine = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($mine)['data'] ?? []);
        $this->assertContains($ctx['id'], $ids);
    }

    public function testLowWho5CreatesExactlyOneFollowup(): void
    {
        $ctx = $this->publishRoutineInterview();
        $student = $this->continuingStudent();

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $form->assertStatus(200);
        $byKey = $this->keyByText($this->envelope($form)['data']['questions'] ?? []);

        // All WHO-5 at 1 → raw 0 → 0/100 ≤ 50 → flag. Free texts skipped.
        $answers = [];
        for ($i = 1; $i <= 5; $i++) {
            $answers[] = ['question_id' => $byKey['who5_' . $i]['id'], 'value' => 1];
        }
        $notNow = $byKey['concerns_flag']['options'][1]['id'];
        $answers[] = ['question_id' => $byKey['concerns_flag']['id'], 'value' => $notNow];

        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => $answers,
        ])->assertStatus(201);

        $followups = db_connect()->table('guidance_followups')
            ->where(['student_user_id' => $student['userId']])
            ->get()->getResultArray();
        $this->assertSame(1, count($followups), 'Exactly one followup per response.');
        $this->assertSame(0, (int) $followups[0]['who5_score']);
        $this->assertSame('new', $followups[0]['status']);

        // Audit trail exists (scoped to this followup — the schema is stateful).
        $audit = db_connect()->table('audit_outbox')
            ->where('action_code', 'guidance.followup_created')
            ->where('entity_id', (int) $followups[0]['id'])
            ->countAllResults();
        $this->assertSame(1, $audit);
    }

    public function testHealthyScoreWithoutContactRequestCreatesNoFollowup(): void
    {
        $ctx = $this->publishRoutineInterview();
        $student = $this->continuingStudent();

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $form->assertStatus(200);
        $byKey = $this->keyByText($this->envelope($form)['data']['questions'] ?? []);

        $answers = [];
        for ($i = 1; $i <= 5; $i++) {
            $answers[] = ['question_id' => $byKey['who5_' . $i]['id'], 'value' => 5];
        }
        $notNow = $byKey['concerns_flag']['options'][1]['id'];
        $answers[] = ['question_id' => $byKey['concerns_flag']['id'], 'value' => $notNow];
        $answers[] = ['question_id' => $byKey['routine_1']['id'], 'value' => 'Doing fine, thanks.'];

        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => $answers,
        ])->assertStatus(201);

        $followups = db_connect()->table('guidance_followups')
            ->where('student_user_id', $student['userId'])
            ->countAllResults();
        $this->assertSame(0, $followups, 'Healthy score + no contact request = no flag.');
    }

    public function testContactRequestTriggersFollowupEvenWithHighScore(): void
    {
        $ctx = $this->publishRoutineInterview();
        $student = $this->continuingStudent();

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $form->assertStatus(200);
        $byKey = $this->keyByText($this->envelope($form)['data']['questions'] ?? []);

        $answers = [];
        for ($i = 1; $i <= 5; $i++) {
            $answers[] = ['question_id' => $byKey['who5_' . $i]['id'], 'value' => 5];
        }
        $yes = $byKey['concerns_flag']['options'][0]['id'];
        $answers[] = ['question_id' => $byKey['concerns_flag']['id'], 'value' => $yes];

        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => $answers,
        ])->assertStatus(201);

        $followups = db_connect()->table('guidance_followups')
            ->where('student_user_id', $student['userId'])
            ->get()->getResultArray();
        $this->assertSame(1, count($followups));
        $this->assertStringContainsString('requested counselor contact', (string) $followups[0]['risk_reason']);
        $this->assertSame(100, (int) $followups[0]['who5_score']);
    }

    public function testSkippableWho5BlockScoresNothing(): void
    {
        $ctx = $this->publishRoutineInterview();
        $student = $this->continuingStudent();

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $form->assertStatus(200);
        $byKey = $this->keyByText($this->envelope($form)['data']['questions'] ?? []);

        // Only the contact flag (negative) — WHO-5 fully skipped.
        $notNow = $byKey['concerns_flag']['options'][1]['id'];
        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [
                ['question_id' => $byKey['concerns_flag']['id'], 'value' => $notNow],
            ],
        ])->assertStatus(201, 'The WHO-5 block is skippable — submission succeeds without it.');

        $followups = db_connect()->table('guidance_followups')
            ->where('student_user_id', $student['userId'])
            ->countAllResults();
        $this->assertSame(0, $followups, 'No WHO-5 answers → no score → no flag.');
    }

    public function testClosedLoopRequiresOutcomeNotes(): void
    {
        $ctx = $this->publishRoutineInterview();
        $student = $this->continuingStudent();

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $form->assertStatus(200);
        $byKey = $this->keyByText($this->envelope($form)['data']['questions'] ?? []);
        $answers = [];
        for ($i = 1; $i <= 5; $i++) {
            $answers[] = ['question_id' => $byKey['who5_' . $i]['id'], 'value' => 2];
        }
        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => $answers,
        ])->assertStatus(201);

        $admin = $this->login(['guidance_admin']);
        $list = $this->authed($admin['token'], 'get', 'api/v1/counselling/followups');
        $list->assertStatus(200);
        // Stateful schema: scope to THIS student's followup.
        $rows = array_values(array_filter(
            $this->envelope($list)['data'] ?? [],
            static fn (array $r): bool => (int) $r['student_user_id'] === $student['userId'],
        ));
        $this->assertSame(1, count($rows));
        $followupId = (int) ($rows[0]['id'] ?? 0);

        // mark_addressed straight from new is a state-machine error.
        $skip = $this->authed($admin['token'], 'post', 'api/v1/counselling/followups/' . $followupId . '/transition', [
            'action'       => 'mark_addressed',
            'outcome_note' => 'Called the student.',
        ]);
        $skip->assertStatus(409);

        // start_review assigns the actor.
        $review = $this->authed($admin['token'], 'post', 'api/v1/counselling/followups/' . $followupId . '/transition', [
            'action' => 'start_review',
        ]);
        $review->assertStatus(200);
        $row = db_connect()->table('guidance_followups')->where('id', $followupId)->get()->getRowArray();
        $this->assertSame($admin['userId'], (int) $row['assigned_counsellor_user_id']);

        // addressed without a note → 422; with a note → 200.
        $noNote = $this->authed($admin['token'], 'post', 'api/v1/counselling/followups/' . $followupId . '/transition', [
            'action'       => 'mark_addressed',
            'outcome_note' => '   ',
        ]);
        $noNote->assertStatus(422);

        $addressed = $this->authed($admin['token'], 'post', 'api/v1/counselling/followups/' . $followupId . '/transition', [
            'action'       => 'mark_addressed',
            'outcome_note' => 'Called the student; scheduled a follow-up session.',
        ]);
        $addressed->assertStatus(200);

        // close without a note → 422; with a note → closed.
        $closeNoNote = $this->authed($admin['token'], 'post', 'api/v1/counselling/followups/' . $followupId . '/transition', [
            'action' => 'close',
        ]);
        $closeNoNote->assertStatus(422);

        $closed = $this->authed($admin['token'], 'post', 'api/v1/counselling/followups/' . $followupId . '/transition', [
            'action'       => 'close',
            'outcome_note' => 'Loop closed after the follow-up session.',
        ]);
        $closed->assertStatus(200);

        // Status changes are audited (scoped to this followup — the
        // schema is stateful).
        $audit = db_connect()->table('audit_outbox')
            ->where('action_code', 'guidance.followup_status_changed')
            ->where('entity_id', $followupId)
            ->countAllResults();
        $this->assertSame(3, $audit, 'start_review + addressed + close are each audited.');
    }

    public function testFollowupCaseloadIsReadAnyOnly(): void
    {
        $counsellor = $this->login(['counsellor']);
        $denied = $this->authed($counsellor['token'], 'get', 'api/v1/counselling/followups');
        $denied->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:counselling.responses.read_any', $denied);
    }

    public function testAggregateDistributionAndSessionLinkage(): void
    {
        $ctx = $this->publishRoutineInterview();
        $student = $this->continuingStudent();

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $form->assertStatus(200);
        $byKey = $this->keyByText($this->envelope($form)['data']['questions'] ?? []);
        $answers = [];
        for ($i = 1; $i <= 5; $i++) {
            $answers[] = ['question_id' => $byKey['who5_' . $i]['id'], 'value' => 4];
        }
        $notNow = $byKey['concerns_flag']['options'][1]['id'];
        $answers[] = ['question_id' => $byKey['concerns_flag']['id'], 'value' => $notNow];
        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => $answers,
        ])->assertStatus(201);

        // Aggregate: stateful survey — this run adds one 4 to the WHO-5
        // likert distribution.
        $admin = $this->login(['guidance_admin']);
        $agg = $this->authed($admin['token'], 'get', 'api/v1/counselling/surveys/' . $ctx['id'] . '/aggregate');
        $agg->assertStatus(200);
        $aggBody = $this->envelope($agg)['data'];
        $this->assertGreaterThanOrEqual(1, (int) $aggBody['response_count']);
        $who5Agg = null;
        foreach ($aggBody['questions'] as $q) {
            if (($q['question_id'] ?? 0) === (int) $byKey['who5_1']['id']) {
                $who5Agg = $q;
            }
        }
        $this->assertNotNull($who5Agg);
        $this->assertArrayHasKey('scale_counts', $who5Agg);
        $this->assertGreaterThanOrEqual(1, $who5Agg['scale_counts'][4] ?? 0);

        // Session linkage: the session's own counsellor sees the interview.
        $counsellor = $this->login(['counsellor']);
        $now = date('Y-m-d H:i:s');
        db_connect()->table('counselling_sessions')->insert([
            'tenant_id'         => 1,
            'patient_school_id' => $student['student_number'],
            'patient_user_id'   => $student['userId'],
            'counsellor_user_id' => $counsellor['userId'],
            'started_at'        => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $sessionId = (int) db_connect()->insertID();

        $linkage = $this->authed($counsellor['token'], 'get', 'api/v1/counselling/sessions/' . $sessionId . '/interviews');
        $linkage->assertStatus(200);
        $interviews = $this->envelope($linkage)['data'] ?? [];
        $this->assertSame(1, count($interviews));
        $this->assertNotEmpty($interviews[0]['answers']);

        // A counsellor who is neither the session's counsellor nor a
        // read_any holder → 403.
        $other = $this->login(['counsellor']);
        $forbidden = $this->authed($other['token'], 'get', 'api/v1/counselling/sessions/' . $sessionId . '/interviews');
        $forbidden->assertStatus(403);
    }
}
