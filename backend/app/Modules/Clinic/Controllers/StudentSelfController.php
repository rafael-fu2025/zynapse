<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
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
}
