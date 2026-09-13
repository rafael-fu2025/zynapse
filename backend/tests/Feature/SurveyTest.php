<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Surveys engine (2026-09 parity plan Phase B) — end-to-end through the
 * real routes:
 *
 *   - draft → questions → publish (immutable version) → archive;
 *   - student flow: availability → form → submit → encrypted snapshot +
 *     long-table projection; one submission per student (409);
 *   - required-question enforcement;
 *   - requirements = the clearance gate (pending → submitted → gone);
 *   - audience + window gating;
 *   - response access control (responses.read holders; unit admins 403).
 *
 * EncryptionService needs COUNSELLING_KEY — pinned per-suite like
 * CounsellingNoteAccessTest.
 */
final class SurveyTest extends FeatureTestCase
{
    private const KEY = 'f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3';

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

    /** @return array{id: int} */
    private function createPublishedSurvey(string $suffix, array $extra = []): array
    {
        $admin = $this->login(['guidance_admin']);

        $created = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys', array_merge([
            'title'       => "Evaluation {$suffix}",
            'body'        => null,
            'description' => 'Term evaluation of the guidance services.',
            'audience'    => 'all',
            'is_required' => true,
        ], $extra));
        $created->assertStatus(201);
        $surveyId = (int) ($this->envelope($created)['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $surveyId);

        $questions = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $surveyId . '/questions', [
            'questions' => [
                [
                    'question_text' => 'How satisfied are you with the guidance office?',
                    'question_type' => 'likert',
                    'is_required'   => true,
                ],
                [
                    'question_text' => 'Which services did you use?',
                    'question_type' => 'multi',
                    'is_required'   => false,
                    'options'       => ['Counseling', 'Career talks', 'Peer facilitators'],
                ],
                [
                    'question_text' => 'Any suggestions for the office?',
                    'question_type' => 'free_text',
                    'is_required'   => false,
                ],
                [
                    'question_text' => 'Learning-style assessment completed?',
                    'question_type' => 'external_url',
                    'is_required'   => false,
                ],
            ],
        ]);
        $questions->assertStatus(200);

        $published = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $surveyId . '/publish');
        $published->assertStatus(200);
        $body = $this->envelope($published)['data'];

        return ['id' => $surveyId, 'version_id' => (int) $body['version_id'], 'admin' => $admin];
    }

    public function testDraftLifecycleAndPublishImmutability(): void
    {
        $ctx = $this->createPublishedSurvey(bin2hex(random_bytes(3)));

        // Published surveys are immutable: edits + re-publish → 409.
        $update = $this->authed($ctx['admin']['token'], 'post', 'api/v1/counselling/surveys/' . $ctx['id'] . '/update', [
            'title' => 'Renamed after publish',
            'audience' => 'all',
        ]);
        $update->assertStatus(409);

        $republish = $this->authed($ctx['admin']['token'], 'post', 'api/v1/counselling/surveys/' . $ctx['id'] . '/publish');
        $republish->assertStatus(409);

        // The version snapshot exists with 4 questions.
        $questions = db_connect()->table('survey_questions')
            ->where(['survey_id' => $ctx['id']])
            ->where('version_id', $ctx['version_id'])
            ->countAllResults();
        $this->assertSame(4, $questions);

        $archived = $this->authed($ctx['admin']['token'], 'post', 'api/v1/counselling/surveys/' . $ctx['id'] . '/archive');
        $archived->assertStatus(200);
    }

    public function testPublishWithoutQuestionsIsRejected(): void
    {
        $admin = $this->login(['guidance_admin']);
        $created = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys', [
            'title' => 'Empty survey ' . bin2hex(random_bytes(3)),
            'audience' => 'all',
        ]);
        $created->assertStatus(201);
        $id = (int) ($this->envelope($created)['data']['id'] ?? 0);

        $publish = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $id . '/publish');
        $publish->assertStatus(422);
    }

    public function testUnitAdminCannotManageSurveys(): void
    {
        $actor = $this->login(['clinic_admin']);
        $read = $this->authed($actor['token'], 'get', 'api/v1/counselling/surveys');
        $read->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:counselling.surveys.manage', $read);
    }

    public function testStudentSubmitEncryptsSnapshotAndProjectsAnswers(): void
    {
        $ctx = $this->createPublishedSurvey(bin2hex(random_bytes(3)));
        $student = $this->login([]);
        db_connect()->table('users')->where('id', $student['userId'])->update(['kind' => 'student']);

        // The form is visible with version questions + options.
        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $form->assertStatus(200);
        $formBody = $this->envelope($form)['data'];
        $this->assertSame(4, count($formBody['questions']));
        $likert = $formBody['questions'][0];
        $multi = $formBody['questions'][1];
        $this->assertSame('likert', $likert['question_type']);
        $this->assertSame(3, count($multi['options']));

        // Submit with a missing required likert → 422 naming the question.
        $incomplete = $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [
                ['question_id' => $multi['id'], 'value' => [$multi['options'][0]['id']]],
            ],
        ]);
        $incomplete->assertStatus(422);

        $submit = $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [
                ['question_id' => $likert['id'], 'value' => 4],
                ['question_id' => $multi['id'], 'value' => [$multi['options'][0]['id'], $multi['options'][2]['id']]],
                ['question_id' => $formBody['questions'][2]['id'], 'value' => 'Keep the career talks coming.'],
                ['question_id' => $formBody['questions'][3]['id'], 'value' => true],
            ],
        ]);
        $submit->assertStatus(201);

        // The encrypted snapshot is present and long-table rows projected.
        $row = db_connect()->table('survey_responses')
            ->where(['survey_id' => $ctx['id'], 'student_user_id' => $student['userId']])
            ->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertNotSame('', (string) $row['payload_cipher']);
        $answers = db_connect()->table('survey_answers')->where('response_id', (int) $row['id'])->countAllResults();
        $this->assertSame(5, $answers, 'likert 1 + multi 2 + free_text 1 + external_url 1 = 5 rows.');

        // One submission per student: a second attempt → 409.
        $again = $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [['question_id' => $likert['id'], 'value' => 5]],
        ]);
        $again->assertStatus(409);

        // The submitted survey disappears from the student's list.
        $mine = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys');
        $mine->assertStatus(200);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($mine)['data'] ?? []);
        $this->assertNotContains($ctx['id'], $ids);
    }

    public function testRequirementsEndpointIsTheClearanceGate(): void
    {
        $ctx = $this->createPublishedSurvey(bin2hex(random_bytes(3)));
        $student = $this->login([]);
        db_connect()->table('users')->where('id', $student['userId'])->update(['kind' => 'student']);

        $before = $this->authed($student['token'], 'get', 'api/v1/me/guidance/requirements');
        $before->assertStatus(200);
        $pending = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($before)['data'] ?? []);
        $this->assertContains($ctx['id'], $pending, 'A required, open, unanswered survey is pending.');

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $questions = $this->envelope($form)['data']['questions'];

        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [
                ['question_id' => $questions[0]['id'], 'value' => 5],
                ['question_id' => $questions[2]['id'], 'value' => 'All good.'],
                ['question_id' => $questions[3]['id'], 'value' => true],
            ],
        ])->assertStatus(201);

        $after = $this->authed($student['token'], 'get', 'api/v1/me/guidance/requirements');
        $afterIds = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($after)['data'] ?? []);
        $this->assertNotContains($ctx['id'], $afterIds, 'Submitted requirements leave the clearance checklist.');
    }

    public function testClosedSurveysAreHiddenFromStudents(): void
    {
        $ctx = $this->createPublishedSurvey(bin2hex(random_bytes(3)), [
            'close_at' => '2020-01-01 00:00:00',
        ]);
        $student = $this->login([]);
        db_connect()->table('users')->where('id', $student['userId'])->update(['kind' => 'student']);

        $mine = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($mine)['data'] ?? []);
        $this->assertNotContains($ctx['id'], $ids, 'A closed survey is not answerable.');

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $form->assertStatus(404);
    }

    public function testResponseAccessControl(): void
    {
        $ctx = $this->createPublishedSurvey(bin2hex(random_bytes(3)));
        $student = $this->login([]);
        db_connect()->table('users')->where('id', $student['userId'])->update(['kind' => 'student']);

        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $ctx['id']);
        $questions = $this->envelope($form)['data']['questions'];
        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [
                ['question_id' => $questions[0]['id'], 'value' => 3],
            ],
        ])->assertStatus(201);

        // counsellor (responses.read) sees the list.
        $counsellor = $this->login(['counsellor']);
        $list = $this->authed($counsellor['token'], 'get', 'api/v1/counselling/surveys/' . $ctx['id'] . '/responses');
        $list->assertStatus(200);
        $rows = $this->envelope($list)['data'] ?? [];
        $this->assertSame(1, count($rows));
        $responseId = (int) ($rows[0]['id'] ?? 0);

        $detail = $this->authed($counsellor['token'], 'get', 'api/v1/counselling/surveys/' . $ctx['id'] . '/responses/' . $responseId);
        $detail->assertStatus(200);
        $answers = $this->envelope($detail)['data']['answers'] ?? [];
        $this->assertNotEmpty($answers, 'The decrypted snapshot carries the answers.');

        // clinic_admin (no responses.read) → 403.
        $unitAdmin = $this->login(['clinic_admin']);
        $denied = $this->authed($unitAdmin['token'], 'get', 'api/v1/counselling/surveys/' . $ctx['id'] . '/responses');
        $denied->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:counselling.responses.read', $denied);

        // Student cannot read staff response lists.
        $asStudent = $this->authed($student['token'], 'get', 'api/v1/counselling/surveys/' . $ctx['id'] . '/responses');
        $asStudent->assertStatus(403);
    }
}
