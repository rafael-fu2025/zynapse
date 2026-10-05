<?php

declare(strict_types=1);

namespace Modules\Counselling\Controllers;

use App\Auth\CurrentUser;
use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Counselling\Services\SurveyService;

/**
 * SurveyController — staff builder/publish + responses (Phase B), and
 * the student self-service surfaces under /api/v1/me/guidance/surveys.
 *
 * Staff gates:
 *   - builder/publish: counselling.surveys.manage (guidance_admin);
 *   - response list/detail: counselling.responses.read
 *     (guidance_admin/supervisor/counsellor) — every detail read is audited.
 * Student endpoints are self-scoped (CurrentUser), no staff permission.
 */
final class SurveyController extends ApiController
{
    private SurveyService $service;

    public function __construct(?SurveyService $service = null)
    {
        $this->service = $service ?? new SurveyService();
    }

    // ---- staff: builder + publish -----------------------------------

    public function listSurveys(): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        return $this->ok($this->service->listSurveys());
    }

    public function showSurvey(int $id): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        return $this->ok($this->service->getSurvey($id));
    }

    public function createSurvey(): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        $payload = $this->request->getJSON(true) ?? [];
        return $this->ok($this->service->createSurvey(is_array($payload) ? $payload : []), null, 201);
    }

    public function updateSurvey(int $id): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        $payload = $this->request->getJSON(true) ?? [];
        return $this->ok($this->service->updateSurvey($id, is_array($payload) ? $payload : []));
    }

    public function setQuestions(int $id): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        $payload = $this->request->getJSON(true) ?? [];
        $questions = is_array($payload['questions'] ?? null) ? $payload['questions'] : [];
        return $this->ok($this->service->setQuestions($id, $questions));
    }

    public function setLinks(int $id): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        $payload = $this->request->getJSON(true) ?? [];
        $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];
        return $this->ok($this->service->setLinks($id, $links));
    }

    /** Narrow hot-fix: retitle / re-URL one link on a live version. */
    public function patchLink(int $id, int $linkId): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        $payload = $this->request->getJSON(true) ?? [];
        return $this->ok($this->service->patchPublishedLink($id, $linkId, is_array($payload) ? $payload : []));
    }

    public function publishSurvey(int $id): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        return $this->ok($this->service->publishSurvey($id));
    }

    public function archiveSurvey(int $id): ResponseInterface
    {
        $this->authorize('counselling.surveys.manage');
        return $this->ok($this->service->archiveSurvey($id));
    }

    // ---- staff: responses -------------------------------------------

    public function listResponses(int $id): ResponseInterface
    {
        $this->authorize('counselling.responses.read');
        $yearParam = $this->request->getGet('year_level');
        $yearLevel = is_numeric($yearParam) ? (int) $yearParam : null;
        return $this->ok($this->service->listResponses($id, $yearLevel));
    }

    public function responseDetail(int $id, int $responseId): ResponseInterface
    {
        $this->authorize('counselling.responses.read');
        return $this->ok($this->service->responseDetail($id, $responseId));
    }

    /** Streams a submitted screenshot proof (audited server-side). */
    public function downloadScreenshot(int $id, int $responseId, int $screenshotId): ResponseInterface
    {
        $this->authorize('counselling.responses.read');
        return $this->screenshotResponse($this->service->staffScreenshot($id, $responseId, $screenshotId));
    }

    private function screenshotResponse(array $meta): ResponseInterface
    {
        return $this->response
            ->download($meta['path'], null)
            ->setFileName($meta['name'])
            ->setContentType($meta['mime'], 'UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    // ---- student: /me/guidance/surveys -------------------------------

    public function mySurveys(): ResponseInterface
    {
        return $this->ok($this->service->availableForStudent(CurrentUser::assert()));
    }

    public function myForm(int $id): ResponseInterface
    {
        return $this->ok($this->service->getFormForStudent($id, CurrentUser::assert()));
    }

    public function submit(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
        return $this->ok($this->service->submit($id, $answers), null, 201);
    }

    /** Open attestation for a dynamic link (idempotent per student). */
    public function openLink(int $id, int $linkId): ResponseInterface
    {
        return $this->ok($this->service->openLink($id, $linkId));
    }

    /** Stages a screenshot proof (multipart field: screenshot). */
    public function uploadScreenshot(int $id, int $linkId): ResponseInterface
    {
        return $this->ok($this->service->uploadScreenshot($id, $linkId, $this->request->getFile('screenshot')), null, 201);
    }

    /** Streams the caller's own screenshot back (post-reload preview). */
    public function previewScreenshot(int $id, int $linkId): ResponseInterface
    {
        return $this->screenshotResponse($this->service->studentScreenshot($id, $linkId));
    }

    /** Removes a staged (unsubmitted) screenshot. */
    public function removeScreenshot(int $id, int $linkId): ResponseInterface
    {
        return $this->ok($this->service->removeScreenshot($id, $linkId));
    }

    public function myRequirements(): ResponseInterface
    {
        return $this->ok($this->service->requirements(CurrentUser::assert()));
    }
}
