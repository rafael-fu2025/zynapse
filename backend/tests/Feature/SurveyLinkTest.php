<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\TestResponse;

/**
 * Dynamic survey links (2026-10) — links are FULLY OPTIONAL:
 *
 *   - draft link config with stored-URL validation (public http(s) only);
 *   - copy-on-publish: links snapshot with the questions; a survey may
 *     publish with zero questions when it has an enabled link;
 *   - student form carries visible links + opened ids + proofs and a
 *     `submitted` flag (the form reopens post-submit);
 *   - open attestation is idempotent and audience-aware;
 *   - screenshot proofs: per-link requires_screenshot, MIME sniffing,
 *     size cap, replace, delete — never gating submission;
 *   - submit validation covers required questions only; staged proofs
 *     bind atomically at submit, post-submit uploads bind immediately;
 *   - staff responses carry per-link proof detail (informational) and
 *     audited screenshot downloads; the live URL hot-fix patch.
 */
final class SurveyLinkTest extends FeatureTestCase
{
    /** A real 1x1 PNG (definitely sniffable as image/png). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * EncryptionService needs COUNSELLING_KEY — pinned per-suite like
     * SurveyTest, independent of whatever env state a preceding suite
     * file left behind.
     */
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
        service('superglobals')->setFilesArray([]);
        parent::tearDown();
    }

    // ---- fixtures --------------------------------------------------------

    /**
     * The canonical link rows: three tests (screenshot requested) + one
     * evaluation, in sort order (MI, LS, BFPT, evaluation).
     *
     * @return list<array<string, mixed>>
     */
    private static function defaultLinks(): array
    {
        return [
            ['type' => 'test', 'title' => 'Multiple Intelligences (MI)', 'description' => 'Take the MI assessment online.', 'external_url' => 'https://example.org/mi-test', 'instrument_key' => 'mi', 'is_enabled' => true, 'requires_screenshot' => true],
            ['type' => 'test', 'title' => 'Learning Styles (LS)', 'description' => null, 'external_url' => 'https://example.org/ls-test', 'instrument_key' => 'ls', 'is_enabled' => true, 'requires_screenshot' => true],
            ['type' => 'test', 'title' => 'Big Five Personality Test (BFPT)', 'description' => null, 'external_url' => 'https://example.org/bfpt-test', 'instrument_key' => 'bfpt', 'is_enabled' => true, 'requires_screenshot' => true],
            ['type' => 'evaluation', 'title' => 'Evaluation of Guidance Services', 'description' => 'Please complete the evaluation after the survey and assessments.', 'external_url' => 'https://example.org/evaluation', 'is_enabled' => true],
        ];
    }

    private function createDraftWithQuestions(array $admin, string $suffix): int
    {
        $created = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys', [
            'title'       => 'Linked survey ' . $suffix,
            'audience'    => 'all',
            'is_required' => true,
        ]);
        $created->assertStatus(201);
        $surveyId = (int) ($this->envelope($created)['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $surveyId);

        $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $surveyId . '/questions', [
            'questions' => [
                ['question_text' => 'How satisfied are you with the guidance office?', 'question_type' => 'likert', 'is_required' => true],
            ],
        ])->assertStatus(200);

        return $surveyId;
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return array{id: int, version_id: int, admin: array{token: string, userId: int, email: string}, links: list<int>}
     */
    private function createPublishedSurveyWithLinks(string $suffix, array $links = [], bool $withQuestions = true): array
    {
        $admin = $this->login(['guidance_admin']);
        $surveyId = $withQuestions
            ? $this->createDraftWithQuestions($admin, $suffix)
            : $this->createDraftWithoutQuestions($admin, $suffix);

        $set = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $surveyId . '/links', [
            'links' => $links === [] ? self::defaultLinks() : $links,
        ]);
        $set->assertStatus(200);

        $published = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $surveyId . '/publish');
        $published->assertStatus(200);
        $body = $this->envelope($published)['data'];

        return [
            'id'         => $surveyId,
            'version_id' => (int) $body['version_id'],
            'admin'      => $admin,
            'links'      => array_map(static fn (array $l): int => (int) $l['id'], $body['links'] ?? []),
        ];
    }

    private function createDraftWithoutQuestions(array $admin, string $suffix): int
    {
        $created = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys', [
            'title'    => 'Links-only survey ' . $suffix,
            'audience' => 'all',
        ]);
        $created->assertStatus(201);
        return (int) ($this->envelope($created)['data']['id'] ?? 0);
    }

    /**
     * A registry student. Created before the current academic year, so
     * the resolved audience is a continuing student unless overridden.
     *
     * @return array{token: string, userId: int, email: string}
     */
    private function makeStudent(bool $newStudent = false, ?int $yearLevel = null): array
    {
        $student = $this->login([]);
        db_connect()->table('users')->where('id', $student['userId'])->update([
            'kind'       => 'student',
            'year_level' => $yearLevel,
            'created_at' => $newStudent ? date('Y-m-d H:i:s') : '2024-09-01 00:00:00',
        ]);
        return $student;
    }

    /**
     * Seed a multipart file for the next call() — CI4's FileCollection
     * reads $_FILES through the superglobals service, the same path the
     * cookie helper in FeatureTestCase drives.
     *
     * @param string $contents raw file bytes
     */
    private function upload(array $student, int $surveyId, int $linkId, string $contents, string $name = 'result.png', ?int $declaredSize = null): TestResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'syn_scr');
        $this->assertIsString($tmp);
        file_put_contents($tmp, $contents);

        service('superglobals')->setFilesArray([
            'screenshot' => [
                'name'     => $name,
                'type'     => 'image/png',
                'tmp_name' => $tmp,
                'error'    => 0,
                'size'     => $declaredSize ?? strlen($contents),
            ],
        ]);

        return $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $surveyId . '/links/' . $linkId . '/screenshot');
    }

    private function uploadDefaultPng(array $student, int $surveyId, int $linkId): TestResponse
    {
        return $this->upload($student, $surveyId, $linkId, (string) base64_decode(self::PNG, true));
    }

    private function openLink(array $actor, int $surveyId, int $linkId): TestResponse
    {
        return $this->authed($actor['token'], 'post', 'api/v1/me/guidance/surveys/' . $surveyId . '/links/' . $linkId . '/open');
    }

    private function studentForm(array $student, int $surveyId): array
    {
        $form = $this->authed($student['token'], 'get', 'api/v1/me/guidance/surveys/' . $surveyId);
        $form->assertStatus(200);
        return $this->envelope($form)['data'];
    }

    // ---- draft config: URL validation -----------------------------------

    public function testDraftLinkConfigRejectsUnsafeUrls(): void
    {
        $admin = $this->login(['guidance_admin']);
        $surveyId = $this->createDraftWithQuestions($admin, bin2hex(random_bytes(3)));
        $route = 'api/v1/counselling/surveys/' . $surveyId . '/links';

        foreach ([
            'javascript:alert(1)',
            'data:text/html;base64,PGI+',
            'ftp://files.example.org/x',
            'https://localhost/x',
            'http://127.0.0.1/x',
            'http://169.254.169.254/latest/meta-data',
            'https://intranet/form',
            'https://user:pass@example.org/form',
            'not-a-url',
        ] as $bad) {
            $res = $this->authed($admin['token'], 'post', $route, [
                'links' => [
                    ['type' => 'test', 'title' => 'MI', 'external_url' => $bad, 'instrument_key' => 'mi'],
                ],
            ]);
            $res->assertStatus(422);
        }

        // A valid set saves and echoes the shaped rows.
        $good = $this->authed($admin['token'], 'post', $route, ['links' => self::defaultLinks()]);
        $good->assertStatus(200);
        $links = $this->envelope($good)['data']['links'];
        $this->assertCount(4, $links);
        $this->assertSame('https://example.org/mi-test', $links[0]['external_url']);
        $this->assertSame('mi', $links[0]['instrument_key']);
        $this->assertTrue($links[0]['requires_screenshot']);
        $this->assertFalse($links[3]['requires_screenshot'], 'The evaluation link does not request proofs.');

        // Hard replace: a second save overwrites the draft set.
        $again = $this->authed($admin['token'], 'post', $route, [
            'links' => [['type' => 'evaluation', 'title' => 'Eval', 'external_url' => 'https://example.org/ev']],
        ]);
        $again->assertStatus(200);
        $this->assertCount(1, $this->envelope($again)['data']['links']);
    }

    // ---- publish snapshot + student visibility ---------------------------

    public function testPublishSnapshotsLinksIntoStudentForm(): void
    {
        $ctx = $this->createPublishedSurveyWithLinks(bin2hex(random_bytes(3)));
        $student = $this->makeStudent();

        $body = $this->studentForm($student, $ctx['id']);
        $this->assertCount(4, $body['links']);
        $this->assertSame(['test', 'test', 'test', 'evaluation'], array_column($body['links'], 'type'));
        $this->assertFalse($body['submitted']);
        $this->assertSame([], $body['opened_link_ids'], 'Nothing opened yet.');
        $this->assertSame([], $body['screenshots'], 'Nothing staged yet.');

        // Snapshot rows exist next to the draft rows (drafts stay for the
        // immutable builder read).
        $db = db_connect();
        $drafts = $db->table('survey_links')->where(['survey_id' => $ctx['id']])->where('version_id', null)->countAllResults();
        $snapshot = $db->table('survey_links')->where(['survey_id' => $ctx['id'], 'version_id' => $ctx['version_id']])->countAllResults();
        $this->assertSame(4, $drafts);
        $this->assertSame(4, $snapshot);
    }

    public function testAudienceTargetedLinksAreHiddenFromOtherStudents(): void
    {
        $ctx = $this->createPublishedSurveyWithLinks(bin2hex(random_bytes(3)), [
            [
                'type' => 'survey', 'title' => 'New & Transferee Form', 'description' => 'For new students only.',
                'external_url' => 'https://example.org/new-form', 'audience' => 'new_students',
                'is_enabled' => true,
            ],
        ]);
        $continuing = $this->makeStudent();

        // A continuing student neither sees the link nor can attest to it.
        $body = $this->studentForm($continuing, $ctx['id']);
        $this->assertSame([], $body['links']);
        $this->openLink($continuing, $ctx['id'], $ctx['links'][0])->assertStatus(404);

        // The hidden link cannot affect submission — answers clear it.
        $questionId = $body['questions'][0]['id'];
        $this->authed($continuing['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [['question_id' => $questionId, 'value' => 4]],
        ])->assertStatus(201);
    }

    // ---- open attestation -------------------------------------------------

    public function testOpenAttestationIsIdempotent(): void
    {
        $ctx = $this->createPublishedSurveyWithLinks(bin2hex(random_bytes(3)));
        $student = $this->makeStudent();
        $mi = $ctx['links'][0];

        $this->openLink($student, $ctx['id'], $mi)->assertStatus(200);
        $this->openLink($student, $ctx['id'], $mi)->assertStatus(200);

        $this->assertSame(1, db_connect()->table('survey_link_opens')
            ->where(['link_id' => $mi, 'student_user_id' => $student['userId']])
            ->countAllResults(), 'UNIQUE(link, student) keeps the attestation idempotent.');

        // The form echoes the opened id.
        $body = $this->studentForm($student, $ctx['id']);
        $this->assertContains($mi, $body['opened_link_ids']);

        // Unknown link → 404.
        $this->openLink($student, $ctx['id'], 999999)->assertStatus(404);
    }

    // ---- screenshot proofs -------------------------------------------------

    public function testScreenshotUploadValidationReplaceAndDelete(): void
    {
        $ctx = $this->createPublishedSurveyWithLinks(bin2hex(random_bytes(3)));
        $student = $this->makeStudent();
        $mi = $ctx['links'][0];
        $route = 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/links/' . $mi . '/screenshot';
        $db = db_connect();

        // A text file pretending to be .png → 422 (server-side sniff).
        $this->upload($student, $ctx['id'], $mi, 'definitely not an image', 'result.png')->assertStatus(422);

        // Oversize → 422. CI4's getSize() reports the declared multipart
        // size, so the cap trips on the metadata; the temp file stays
        // small — materializing 6MB here would explode through CI4's
        // var_export()-based debug traces on any failure path.
        $this->upload($student, $ctx['id'], $mi, (string) base64_decode(self::PNG, true), 'big.png', 6 * 1024 * 1024)->assertStatus(422);

        // Links without requires_screenshot accept no uploads.
        $eval = $ctx['links'][3];
        $this->uploadDefaultPng($student, $ctx['id'], $eval)->assertStatus(422);

        // Valid upload → 201 + exactly one staged row.
        $res = $this->uploadDefaultPng($student, $ctx['id'], $mi);
        $res->assertStatus(201);
        $meta = $this->envelope($res)['data'];
        $this->assertSame('image/png', $meta['mime_type']);
        $this->assertSame($mi, $meta['link_id']);
        $row = $db->table('survey_link_screenshots')->where(['student_user_id' => $student['userId'], 'survey_id' => $ctx['id']])->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('result.png', $row['original_name']);
        $firstStored = (string) $row['stored_name'];

        // Replace → still one row, different stored file.
        $this->uploadDefaultPng($student, $ctx['id'], $mi)->assertStatus(201);
        $rows = $db->table('survey_link_screenshots')->where(['student_user_id' => $student['userId'], 'survey_id' => $ctx['id']])->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertNotSame($firstStored, $rows[0]['stored_name']);

        // The proof file exists under WRITEPATH, outside the public root.
        // (Feature-test tenants are pinned to id 1.)
        $this->assertFileExists(
            WRITEPATH . 'survey-screenshots/1/' . $rows[0]['stored_name'],
            'The proof image must be stored outside the public tree.',
        );

        // The student can stream their own staged file back.
        $preview = $this->authed($student['token'], 'get', $route);
        $preview->assertStatus(200);

        // Delete → the staged row is gone.
        $this->authed($student['token'], 'delete', $route)->assertStatus(200);
        $this->assertSame(0, $db->table('survey_link_screenshots')
            ->where(['student_user_id' => $student['userId'], 'survey_id' => $ctx['id']])
            ->countAllResults());
    }

    // ---- submission: links never gate ---------------------------------------

    public function testSubmitIgnoresLinksAndProofsBindAfterSubmit(): void
    {
        $ctx = $this->createPublishedSurveyWithLinks(bin2hex(random_bytes(3)));
        $student = $this->makeStudent();
        $body = $this->studentForm($student, $ctx['id']);
        $submitUrl = 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit';

        // Nothing opened, nothing uploaded — the answers alone clear it.
        $this->authed($student['token'], 'post', $submitUrl, [
            'answers' => [['question_id' => $body['questions'][0]['id'], 'value' => 4]],
        ])->assertStatus(201);

        // The form reopens post-submit, flagged for optional proofs.
        $form = $this->studentForm($student, $ctx['id']);
        $this->assertTrue($form['submitted']);

        // A post-submit upload binds to the response immediately.
        $this->uploadDefaultPng($student, $ctx['id'], $ctx['links'][0])->assertStatus(201);
        $response = db_connect()->table('survey_responses')
            ->where(['survey_id' => $ctx['id'], 'student_user_id' => $student['userId']])
            ->get()->getRowArray();
        $this->assertSame(1, db_connect()->table('survey_link_screenshots')
            ->where(['response_id' => (int) $response['id'], 'link_id' => $ctx['links'][0]])
            ->countAllResults());

        // And it can still be removed while the window is open.
        $this->authed($student['token'], 'delete', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/links/' . $ctx['links'][0] . '/screenshot')->assertStatus(200);
        $this->assertSame(0, db_connect()->table('survey_link_screenshots')
            ->where(['response_id' => (int) $response['id'], 'link_id' => $ctx['links'][0]])
            ->countAllResults());
    }

    public function testProofsBindAtSubmitAndStaffSeesThem(): void
    {
        $ctx = $this->createPublishedSurveyWithLinks(bin2hex(random_bytes(3)));
        $student = $this->makeStudent();
        $body = $this->studentForm($student, $ctx['id']);

        foreach (array_slice($ctx['links'], 0, 3) as $linkId) {
            $this->openLink($student, $ctx['id'], $linkId)->assertStatus(200);
            $this->uploadDefaultPng($student, $ctx['id'], $linkId)->assertStatus(201);
        }
        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [['question_id' => $body['questions'][0]['id'], 'value' => 3]],
        ])->assertStatus(201);

        // Pre-submit proofs bound to the response, atomically.
        $response = db_connect()->table('survey_responses')
            ->where(['survey_id' => $ctx['id'], 'student_user_id' => $student['userId']])
            ->get()->getRowArray();
        $this->assertIsArray($response);
        $this->assertSame(3, db_connect()->table('survey_link_screenshots')
            ->where('response_id', (int) $response['id'])
            ->countAllResults());

        $list = $this->authed($ctx['admin']['token'], 'get', 'api/v1/counselling/surveys/' . $ctx['id'] . '/responses');
        $list->assertStatus(200);
        $rows = $this->envelope($list)['data'];
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('completion_status', $rows[0], 'Submission itself is the completion signal.');
        $responseId = (int) $rows[0]['id'];

        $detailRes = $this->authed($ctx['admin']['token'], 'get', 'api/v1/counselling/surveys/' . $ctx['id'] . '/responses/' . $responseId);
        $detailRes->assertStatus(200);
        $detail = $this->envelope($detailRes)['data'];
        $this->assertArrayNotHasKey('completion_status', $detail);
        $this->assertCount(4, $detail['links']);
        $this->assertTrue($detail['links'][0]['opened']);
        $this->assertFalse($detail['links'][3]['opened'], 'The optional evaluation was never opened.');
        $this->assertNotNull($detail['links'][0]['screenshot']);
        $this->assertSame('result.png', $detail['links'][0]['screenshot']['original_name']);

        // Staff download streams the stored bytes (audited server-side).
        // (The streamed bytes/headers are not observable through
        // TestResponse — CI4 adopts the DownloadResponse only at send
        // time — so the assertion here is the authorization + status.)
        $downloadUrl = sprintf(
            'api/v1/counselling/surveys/%d/responses/%d/screenshots/%d',
            $ctx['id'], $responseId, $detail['links'][0]['screenshot']['id'],
        );
        $this->authed($ctx['admin']['token'], 'get', $downloadUrl)->assertStatus(200);

        // counsellor (responses.read) downloads too.
        $counsellor = $this->login(['counsellor']);
        $this->authed($counsellor['token'], 'get', $downloadUrl)->assertStatus(200);

        // No responses.read → 403 (unit admin and the student themself).
        $unitAdmin = $this->login(['clinic_admin']);
        $this->authed($unitAdmin['token'], 'get', $downloadUrl)->assertStatus(403);
        $this->authed($student['token'], 'get', $downloadUrl)->assertStatus(403);
    }

    // ---- links-only surveys -------------------------------------------------

    public function testLinksOnlySurveyPublishesAndSubmits(): void
    {
        // Zero questions + one enabled link → publishable.
        $ctx = $this->createPublishedSurveyWithLinks(bin2hex(random_bytes(3)), [
            ['type' => 'test', 'title' => 'Learning Styles (LS)', 'description' => null, 'external_url' => 'https://example.org/ls', 'instrument_key' => 'ls', 'is_enabled' => true, 'requires_screenshot' => true],
        ], false);
        $student = $this->makeStudent();

        $body = $this->studentForm($student, $ctx['id']);
        $this->assertSame([], $body['questions']);
        $this->assertCount(1, $body['links']);

        // No questions → the empty answer set clears the gate.
        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $ctx['id'] . '/submit', [
            'answers' => [],
        ])->assertStatus(201);

        // Proofs still flow in afterwards.
        $this->uploadDefaultPng($student, $ctx['id'], $ctx['links'][0])->assertStatus(201);
    }

    public function testPublishWithoutQuestionsOrLinksIsRejected(): void
    {
        $admin = $this->login(['guidance_admin']);
        $surveyId = $this->createDraftWithoutQuestions($admin, bin2hex(random_bytes(3)));
        $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $surveyId . '/publish')->assertStatus(422);
    }

    // ---- live hot-fix + legacy responses ------------------------------------

    public function testLiveUrlHotPatch(): void
    {
        $ctx = $this->createPublishedSurveyWithLinks(bin2hex(random_bytes(3)));
        $student = $this->makeStudent();
        $mi = $ctx['links'][0];

        $patch = $this->authed($ctx['admin']['token'], 'post', 'api/v1/counselling/surveys/' . $ctx['id'] . '/links/' . $mi . '/patch', [
            'external_url' => 'https://example.org/mi-test-v2',
            'title'        => 'Multiple Intelligences (MI) — relocated',
        ]);
        $patch->assertStatus(200);
        $this->assertSame('https://example.org/mi-test-v2', $this->envelope($patch)['data']['external_url']);

        // The student form reads the patched snapshot.
        $body = $this->studentForm($student, $ctx['id']);
        $miLink = array_values(array_filter($body['links'], static fn (array $l): bool => $l['id'] === $mi))[0];
        $this->assertSame('https://example.org/mi-test-v2', $miLink['external_url']);

        // Invalid URL → 422; unknown link → 404.
        $this->authed($ctx['admin']['token'], 'post', 'api/v1/counselling/surveys/' . $ctx['id'] . '/links/' . $mi . '/patch', [
            'external_url' => 'javascript:alert(1)',
        ])->assertStatus(422);
        $this->authed($ctx['admin']['token'], 'post', 'api/v1/counselling/surveys/' . $ctx['id'] . '/links/999999/patch', [
            'external_url' => 'https://example.org/x',
        ])->assertStatus(404);

        // Draft surveys change links through the normal save flow → 409.
        $admin = $this->login(['guidance_admin']);
        $draftId = $this->createDraftWithQuestions($admin, bin2hex(random_bytes(3)));
        $set = $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $draftId . '/links', ['links' => self::defaultLinks()]);
        $set->assertStatus(200);
        $draftLinkId = (int) $this->envelope($set)['data']['links'][0]['id'];
        $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $draftId . '/links/' . $draftLinkId . '/patch', [
            'external_url' => 'https://example.org/new',
        ])->assertStatus(409);
    }

    public function testLegacyResponseWithoutLinksCarriesNoCompletionField(): void
    {
        $admin = $this->login(['guidance_admin']);
        $surveyId = $this->createDraftWithQuestions($admin, bin2hex(random_bytes(3)));
        $this->authed($admin['token'], 'post', 'api/v1/counselling/surveys/' . $surveyId . '/publish')->assertStatus(200);

        $student = $this->makeStudent();
        $body = $this->studentForm($student, $surveyId);
        $this->assertSame([], $body['links']);
        $this->authed($student['token'], 'post', 'api/v1/me/guidance/surveys/' . $surveyId . '/submit', [
            'answers' => [['question_id' => $body['questions'][0]['id'], 'value' => 5]],
        ])->assertStatus(201);

        $list = $this->authed($admin['token'], 'get', 'api/v1/counselling/surveys/' . $surveyId . '/responses');
        $rows = $this->envelope($list)['data'];
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('completion_status', $rows[0]);
    }
}
