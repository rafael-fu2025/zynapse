<?php

declare(strict_types=1);

namespace Modules\Counselling\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Counselling\Services\ScheduleService;

/**
 * ScheduleController — availability + appointments (Phase 15,
 * recycled from legacy synapse_ag).
 */
final class ScheduleController extends ApiController
{
    private readonly ScheduleService $service;

    public function __construct(?ScheduleService $service = null)
    {
        $this->service = $service ?? new ScheduleService(new CounsellingPolicy(), Services::auditOutbox());
    }

    public function listAvailability(): ResponseInterface
    {
        $counsellor = (string) ($this->request->getGet('counsellor_user_id') ?? '');
        return $this->ok($this->service->listAvailability($counsellor !== '' ? (int) $counsellor : null));
    }

    public function counsellors(): ResponseInterface
    {
        return $this->ok($this->service->counsellors());
    }

    public function addSlot(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        // `day_of_week` is no longer required: the desk now ticks a set of
        // weekdays and posts `days_of_week` (2026-09-23). The single-day
        // shape is still accepted so older callers keep working.
        $rules = [
            'day_of_week'        => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[6]',
            'start_time'         => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'end_time'           => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'counsellor_user_id' => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $this->assertWeekdaysPresent($payload);

        return $this->ok($this->service->addSlot($payload), null, 201);
    }

    /**
     * Reject a weekday set that is not a non-empty list of 0-6.
     *
     * CI4's rule engine validates scalars, not arrays of ints, so the shape
     * check lives here; `ScheduleService::normaliseDaysOfWeek()` remains the
     * authoritative normaliser for the values themselves.
     *
     * @param array<string, mixed> $payload
     */
    private function assertWeekdaysPresent(array $payload): void
    {
        $raw = $payload['days_of_week'] ?? null;

        if ($raw === null) {
            if (array_key_exists('day_of_week', $payload) && $payload['day_of_week'] !== '' && $payload['day_of_week'] !== null) {
                return;
            }
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Pick at least one weekday.', 'field' => 'days_of_week'],
            ]);
        }

        if (! is_array($raw)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Weekdays must be a list of numbers 0-6.', 'field' => 'days_of_week'],
            ]);
        }

        foreach ($raw as $candidate) {
            if (! is_numeric($candidate) || (int) $candidate < 0 || (int) $candidate > 6) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Weekdays must be numbers 0-6.', 'field' => 'days_of_week'],
                ]);
            }
        }

        if ($raw === []) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Pick at least one weekday.', 'field' => 'days_of_week'],
            ]);
        }
    }

    /**
     * Edit an availability window in place (2026-09-24).
     *
     * Every field is optional — a caller may send only what it changed — so
     * the service keeps the current value for anything absent. Duplicate and
     * ordering checks live in the service, next to the write they guard.
     */
    public function updateSlot(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'day_of_week'        => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[6]',
            'start_time'         => 'permit_empty|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'end_time'           => 'permit_empty|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'counsellor_user_id' => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->updateSlot($id, $payload));
    }

    public function removeSlot(int $id): ResponseInterface
    {
        $this->service->removeSlot($id);
        return $this->ok(['removed' => true]);
    }

    public function listAppointments(): ResponseInterface
    {
        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);
        $status = (string) ($this->request->getGet('status') ?? '');
        $date   = (string) ($this->request->getGet('date') ?? '');
        $scope  = (string) ($this->request->getGet('scope') ?? '');
        $type   = (string) ($this->request->getGet('type') ?? '');

        if ($status !== '' && ! in_array($status, ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown status filter.', 'field' => 'status'],
            ]);
        }

        // `scope` is the Queue board's calendar bucket (upcoming / today /
        // archived); `all` is accepted so the Appointments tab can name its
        // unfiltered state explicitly.
        if ($scope !== '' && $scope !== 'all' && ! in_array($scope, ScheduleService::APPOINTMENT_SCOPES, true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown scope filter.', 'field' => 'scope'],
            ]);
        }

        if ($type !== '' && ! in_array($type, ['initial', 'follow_up', 'crisis', 'referral_based'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown appointment type filter.', 'field' => 'type'],
            ]);
        }

        $page = $this->service->listAppointments(
            $cursor !== '' ? $cursor : null,
            $limit,
            $status !== '' ? $status : null,
            $date !== '' ? $date : null,
            $scope !== '' ? $scope : null,
            $type !== '' ? $type : null,
        );

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function book(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'patient_school_id'  => 'required|max_length[32]',
            'appointment_date'   => 'required|valid_date[Y-m-d]',
            'start_time'         => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'end_time'           => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'type'               => 'permit_empty|in_list[initial,follow_up,crisis,referral_based]',
            'reason'             => 'permit_empty|max_length[255]',
            'counsellor_user_id' => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->book($payload), null, 201);
    }

    public function transition(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'action'              => 'required|in_list[confirm,complete,cancel,no_show]',
            'cancellation_reason' => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->transition(
            $id,
            (string) $payload['action'],
            isset($payload['cancellation_reason']) ? (string) $payload['cancellation_reason'] : null,
        ));
    }

    // --------------------------------------------------- scheduling analytics

    public function listAnalytics(): ResponseInterface
    {
        $counsellor = (string) ($this->request->getGet('counsellor_user_id') ?? '');
        return $this->ok($this->service->listAnalytics($counsellor !== '' ? (int) $counsellor : null));
    }

    public function recomputeAnalytics(): ResponseInterface
    {
        $payload    = $this->request->getJSON(true) ?? [];
        $counsellor = isset($payload['counsellor_user_id']) && $payload['counsellor_user_id'] !== ''
            ? (int) $payload['counsellor_user_id']
            : null;

        return $this->ok($this->service->recomputeAnalytics($counsellor));
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
