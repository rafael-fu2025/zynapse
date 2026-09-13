<?php

declare(strict_types=1);

namespace Modules\Counselling\Controllers;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Counselling\Services\GuidanceFollowupService;

/**
 * GuidanceFollowupController — the RA 11036 §24 aftercare loop (Phase C).
 *
 * Gates: the whole loop is `counselling.responses.read_any`
 * (guidance_admin / guidance_supervisor); plain `responses.read`
 * holders see the survey responses but not the follow-up caseload.
 * Session linkage (the counsellor's session view) is responses.read +
 * ownership (own session or read_any).
 */
final class GuidanceFollowupController extends ApiController
{
    private GuidanceFollowupService $service;

    public function __construct(?GuidanceFollowupService $service = null)
    {
        $this->service = $service ?? new GuidanceFollowupService();
    }

    public function listFollowups(): ResponseInterface
    {
        $this->authorize('counselling.responses.read_any');
        $status = trim((string) ($this->request->getGet('status') ?? 'all'));
        $mineOnly = $this->request->getGet('mine') === '1';
        return $this->ok($this->service->listFollowups($status, $mineOnly));
    }

    public function assign(int $id): ResponseInterface
    {
        $this->authorize('counselling.responses.read_any');
        $payload = $this->request->getJSON(true) ?? [];
        $counsellorId = (int) ($payload['counsellor_user_id'] ?? 0);
        return $this->ok($this->service->assign($id, $counsellorId));
    }

    public function transition(int $id): ResponseInterface
    {
        $this->authorize('counselling.responses.read_any');
        $payload = $this->request->getJSON(true) ?? [];
        $action = (string) ($payload['action'] ?? '');
        $note = isset($payload['outcome_note']) && $payload['outcome_note'] !== null
            ? (string) $payload['outcome_note']
            : null;
        return $this->ok($this->service->transition($id, $action, $note));
    }

    /** Aggregate distribution for one survey (responses.read). */
    public function aggregate(int $id): ResponseInterface
    {
        $this->authorize('counselling.responses.read');
        return $this->ok($this->service->aggregate($id));
    }

    /** The student's latest interview submissions, for a session view. */
    public function sessionInterviews(int $sessionId): ResponseInterface
    {
        $this->authorize('counselling.responses.read');
        return $this->ok($this->service->latestInterviewsForSession($sessionId));
    }
}
