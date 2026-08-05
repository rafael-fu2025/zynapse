<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Clinic\Services\EmployeeSelfService;

/**
 * EmployeeSelfController — the employee portal surface (Phase 11).
 *
 * Every endpoint:
 *   - requires the `employee.portal.read` permission,
 *   - is strictly self-scoped (the calling user's `users.id` is
 *     resolved to a `patients_employees` row by the UNIQUE link
 *     added in `EmployeeUserLink`),
 *   - returns 404 when the caller has no employee record (NOT 403
 *     — the caller IS authorized to see *their own* profile; the
 *     answer is that they don't have one).
 *
 * Routes are mounted under `/api/v1/me/...` so the SPA and any
 * future client can reach the surface without first knowing the
 * caller's employee id.
 */
final class EmployeeSelfController extends ApiController
{
    private readonly EmployeeSelfService $service;

    public function __construct(?EmployeeSelfService $service = null)
    {
        $this->service = $service ?? new EmployeeSelfService();
    }

    public function profile(): ResponseInterface
    {
        $this->authorize('employee.portal.read');
        $dto = $this->service->getMyProfile();

        // Flatten to array; we ALSO expose a `kiosk_identifier`
        // convenience field so the SPA doesn't have to know which
        // of `qr_code` / `rfid_tag` / `employee_number` is the
        // kiosk's preferred scan payload.
        $row = $dto->toArray();
        $row['kiosk_identifier'] = $row['has_qr']
            ? 'qr:' . $this->kioskPayload($row)
            : ($row['has_rfid']
                ? 'rfid:' . $this->kioskPayload($row)
                : 'emp:' . $this->kioskPayload($row));

        return $this->ok($row);
    }

    public function clinicVisits(): ResponseInterface
    {
        $this->authorize('employee.portal.read');
        $limit = (int) ($this->request->getGet('limit') ?? 50);
        if ($limit < 1 || $limit > 200) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'limit must be 1..200.', 'field' => 'limit'],
            ]);
        }
        return $this->ok($this->service->listMyClinicVisits($limit));
    }

    /**
     * Self-scoped queue status for the caller's own encounter — powers
     * the portal "Your queue" card. Returns null (not queued today).
     */
    public function queueStatus(): ResponseInterface
    {
        $this->authorize('employee.portal.read');
        return $this->ok(
            \Config\Services::queueService()->myStatus(\App\Auth\CurrentUser::assert()),
        );
    }

    /**
     * Phase 14: self-update of the calling employee's own profile
     * fields (name, emergency contact, address, gender, DOB). HR
     * fields (department, position, employment_status, is_teaching,
     * qr_code, rfid_tag) are immutable through this endpoint.
     */
    public function updateProfile(): ResponseInterface
    {
        $this->authorize('employee.portal.read');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'first_name'              => 'permit_empty|max_length[100]',
            'last_name'               => 'permit_empty|max_length[100]',
            'middle_name'             => 'permit_empty|max_length[100]',
            'emergency_contact_name'  => 'permit_empty|max_length[150]',
            'emergency_contact_phone' => 'permit_empty|max_length[20]',
            'address'                 => 'permit_empty|max_length[2000]',
            'gender'                  => 'permit_empty|in_list[male,female,other]',
            'date_of_birth'           => 'permit_empty|valid_date[Y-m-d]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->updateMyProfile($payload);

        // Re-derive the kiosk_identifier so the SPA doesn't have
        // to re-fetch after a successful PATCH.
        $row = $dto->toArray();
        $row['kiosk_identifier'] = $row['has_qr']
            ? 'qr:' . $this->kioskPayload($row)
            : ($row['has_rfid']
                ? 'rfid:' . $this->kioskPayload($row)
                : 'emp:' . $this->kioskPayload($row));

        return $this->ok($row);
    }

    /**
     * The kiosk can scan a QR, an RFID, or read the 8-digit
     * employee number off an ID card. We hand the strongest
     * available identifier back so the SPA renders the right
     * one to the user.
     */
    private function kioskPayload(array $row): string
    {
        if (! empty($row['has_qr'])) {
            // EmployeeDto doesn't expose qr_code (it's a sensitive
            // handle). The SPA can use the employee_number as a
            // human-typed fallback at the kiosk. The actual qr_code
            // is rendered into a QR image by the SPA using
            // qrcode.react on the employee_number + a `me.` prefix.
            return (string) $row['employee_number'];
        }
        return (string) $row['employee_number'];
    }
}
