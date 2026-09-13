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
        return $this->ok($this->service->listResponses($id));
    }

    public function responseDetail(int $id, int $responseId): ResponseInterface
    {
        $this->authorize('counselling.responses.read');
        return $this->ok($this->service->responseDetail($id, $responseId));
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

    public function myRequirements(): ResponseInterface
    {
        return $this->ok($this->service->requirements(CurrentUser::assert()));
    }
}
