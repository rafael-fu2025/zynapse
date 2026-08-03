<?php
/**
 * Phase 1.3: EmployeeSelfService — return persons_id and patient_identifier_id.
 */
declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\EmployeeDto;

/**
 * EmployeeSelfService — Phase 11.
 */
final class EmployeeSelfService extends BaseService
{
    /**
     * Return the calling user's employee record, or 404.
     * Phase 1.3: also return persons_id and patient_identifier_id.
     */
    public function getMyProfile(): EmployeeDto
    {
        $userId = CurrentUser::assert();
        $row = $this->findEmployeeRowForUserId($userId);
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'employee.not_registered', 'message' => 'No employee record is linked to your account.'],
            ]);
        }
        $row = $this->enrichWithUnifiedFields($row);
        return EmployeeDto::fromRow($row);
    }

    public function updateMyProfile(array $input): EmployeeDto
    {
        $userId = CurrentUser::assert();
        $existing = $this->findEmployeeRowForUserId($userId);
        if ($existing === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'employee.not_registered', 'message' => 'No employee record is linked to your account.'],
            ]);
        }
        $id = (int) $existing['id'];

        $patch = [];
        if (array_key_exists('first_name', $input) && is_string($input['first_name']) && trim($input['first_name']) !== '') {
            $patch['first_name'] = trim($input['first_name']);
        }
        if (array_key_exists('middle_name', $input)) {
            $patch['middle_name'] = $input['middle_name'] === null ? null : (trim((string) $input['middle_name']) !== '' ? trim((string) $input['middle_name']) : null);
        }
        if (array_key_exists('last_name', $input) && is_string($input['last_name']) && trim($input['last_name']) !== '') {
            $patch['last_name'] = trim($input['last_name']);
        }
        if (array_key_exists('emergency_contact_name', $input)) {
            $patch['emergency_contact_name'] = $input['emergency_contact_name'] === null ? null : trim((string) $input['emergency_contact_name']);
        }
        if (array_key_exists('emergency_contact_phone', $input)) {
            $patch['emergency_contact_phone'] = $input['emergency_contact_phone'] === null ? null : trim((string) $input['emergency_contact_phone']);
        }
        if (array_key_exists('address', $input)) {
            $patch['address'] = $input['address'] === null ? null : trim((string) $input['address']);
        }
        if (array_key_exists('gender', $input) && in_array($input['gender'], ['male', 'female', 'other'], true)) {
            $patch['gender'] = $input['gender'];
        }
        if (array_key_exists('date_of_birth', $input) && $input['date_of_birth'] !== null) {
            $patch['date_of_birth'] = (string) $input['date_of_birth'];
        }

        if ($patch === []) {
            return EmployeeDto::fromRow($this->enrichWithUnifiedFields($existing));
        }

        $patch['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->db->table('patients_employees')
            ->where('id', $id)
            ->update($patch);

        $fresh = $this->findEmployeeRowForUserId($userId);
        return EmployeeDto::fromRow($this->enrichWithUnifiedFields($fresh ?? array_merge($existing, $patch)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEmployeeRowForUserId(int $userId): ?array
    {
        $row = $this->db->table('patients_employees')
            ->select('id, user_id, persons_id, employee_number, first_name, last_name, middle_name, qr_code, rfid_tag, department, position, date_hired, employment_status, hr_synced_at, emergency_contact_name, emergency_contact_phone, date_of_birth, gender, address, is_teaching, archived_at, created_at, updated_at')
            ->where('user_id', $userId)
            ->where('archived_at', null)
            ->get()->getRowArray();
        return $row;
    }

    private function enrichWithUnifiedFields(array $row): array
    {
        $personsId = isset($row['persons_id']) ? (int) $row['persons_id'] : null;
        $patientIdentifierId = null;
        if ($personsId !== null) {
            $piRow = $this->db->table('patient_identifiers')
                ->select('id')
                ->where('persons_id', $personsId)
                ->where('kind', 'employee')
                ->where('archived_at IS NULL', null, false)
                ->get()->getRowArray();
            if ($piRow !== null) {
                $patientIdentifierId = (int) $piRow['id'];
            }
        }
        $row['persons_id'] = $personsId;
        $row['patient_identifier_id'] = $patientIdentifierId;
        return $row;
    }
}
