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

    public function addSlot(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'day_of_week'        => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[6]',
            'start_time'         => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'end_time'           => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'max_slots'          => 'permit_empty|is_natural_no_zero',
            'counsellor_user_id' => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->addSlot($payload), null, 201);
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

        if ($status !== '' && ! in_array($status, ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown status filter.', 'field' => 'status'],
            ]);
        }

        $page = $this->service->listAppointments($cursor !== '' ? $cursor : null, $limit, $status !== '' ? $status : null);

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
