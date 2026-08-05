<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Auth\CurrentUser;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Services\StudentSelfService;

/**
 * StudentSelfController — Phase 13.
 *
 * Mirror of `EmployeeSelfController` for the student side. The
 * routes live under `/api/v1/me/...` so the SPA doesn't need to
 * know the caller's student id. Strictly self-scoped: the
 * `user_id` UNIQUE link is the only thing that lets the caller
 * see the data; an unlinked user gets 404, not 403.
 *
 * READ-ONLY by design. The full student self-service flow
 * (book appointment, QR check-in) is still deferred.
 */
final class StudentSelfController extends ApiController
{
    private readonly StudentSelfService $service;

    public function __construct(?StudentSelfService $service = null)
    {
        $this->service = $service ?? new StudentSelfService();
    }

    public function profile(): ResponseInterface
    {
        $this->authorize('student.portal.read');
        $dto = $this->service->getMyProfile();

        $row = $dto->toArray();
        $row['kiosk_identifier'] = $row['has_qr']
            ? 'qr:' . (string) $row['student_number']
            : ($row['has_rfid']
                ? 'rfid:' . (string) $row['student_number']
                : 'stu:' . (string) $row['student_number']);

        return $this->ok($row);
    }

    public function clinicVisits(): ResponseInterface
    {
        $this->authorize('student.portal.read');
        $limit = (int) ($this->request->getGet('limit') ?? 50);
        if ($limit < 1 || $limit > 200) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'limit must be 1..200.', 'field' => 'limit'],
            ]);
        }
        return $this->ok($this->service->listMyClinicVisits($limit));
    }

    /**
     * Minimal clinic-provider list for the self-booking picker
     * (name + id only).
     */
    public function providers(): ResponseInterface
    {
        $this->authorize('student.portal.read');
        return $this->ok(Services::appointmentService()->providers());
    }

    /**
     * Self-scoped list of the calling student's appointments.
     */
    public function appointments(): ResponseInterface
    {
        $this->authorize('student.portal.read');
        return $this->ok(Services::appointmentService()->myAppointments(CurrentUser::assert()));
    }

    /**
     * Self-service booking — the calling student books a clinic
     * appointment for THEMSELVES (no staff permission needed).
     */
    public function bookAppointment(): ResponseInterface
    {
        $this->authorize('student.portal.read');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'provider_user_id' => 'required|is_natural_no_zero',
            'scheduled_at'     => 'required|max_length[19]',
            'reason'           => 'permit_empty|max_length[500]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = Services::appointmentService()->bookSelf(
            CurrentUser::assert(),
            (int) $payload['provider_user_id'],
            (string) $payload['scheduled_at'],
            isset($payload['reason']) && $payload['reason'] !== '' ? (string) $payload['reason'] : null,
        );

        return $this->ok($dto->toArray(), null, 201);
    }

    /**
     * Self-scoped queue status for the caller's own encounter — powers
     * the portal "Your queue" card. Returns null (not queued today).
     */
    public function queueStatus(): ResponseInterface
    {
        $this->authorize('student.portal.read');
        return $this->ok(
            \Config\Services::queueService()->myStatus(\App\Auth\CurrentUser::assert()),
        );
    }

    private function collectErrors(): array
    {
        $errs = [];
        foreach ($this->validation->getErrors() as $field => $msg) {
            $errs[] = ['code' => 'validation.field', 'message' => (string) $msg, 'field' => (string) $field];
        }
        return $errs;
    }
}
