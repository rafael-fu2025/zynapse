<?php

declare(strict_types=1);

namespace App\Controllers\Api\Admin;

use App\Auth\CurrentUser;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Services\Kiosk\KioskSettingsService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

final class KioskSettingsController extends ApiController
{
    private readonly KioskSettingsService $service;

    public function __construct(?KioskSettingsService $service = null)
    {
        $this->service = $service ?? new KioskSettingsService(Services::auditOutbox());
    }

    /** Public read-only configuration used by the lobby display. */
    public function show(): ResponseInterface
    {
        return $this->ok($this->service->get());
    }

    public function update(): ResponseInterface
    {
        $this->authorize('kiosk.content.manage');
        $payload = $this->request->getJSON(true) ?? [];
        if (! is_array($payload['settings'] ?? null) || ! is_int($payload['revision'] ?? null)) {
            throw ApiException::validationFailure([['code' => 'validation.field', 'message' => 'Settings and integer revision are required.', 'field' => 'settings']]);
        }
        return $this->ok($this->service->save($payload['settings'], $payload['revision'], CurrentUser::assert()));
    }
}
