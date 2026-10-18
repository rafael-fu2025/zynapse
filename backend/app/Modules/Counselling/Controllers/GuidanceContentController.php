<?php

declare(strict_types=1);

namespace Modules\Counselling\Controllers;

use App\Controllers\Api\ApiController;
use App\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Counselling\Services\GuidanceContentService;

/**
 * GuidanceContentController — staff CRUD over guidance announcements and
 * the CMO service catalogue (Phase A of the parity plan), plus the
 * student self-service feed at /me/guidance/*.
 *
 * Staff gates: `counselling.announcements.manage` and
 * `counselling.services.manage` (guidance_admin). The /me feed is
 * self-scoped (CurrentUser) and needs no staff permission.
 */
final class GuidanceContentController extends ApiController
{
    private GuidanceContentService $service;

    public function __construct(?GuidanceContentService $service = null)
    {
        $this->service = $service ?? new GuidanceContentService();
    }

    // ---- staff: announcements --------------------------------------

    public function listAnnouncements(): ResponseInterface
    {
        $this->authorize('counselling.announcements.manage');
        $showArchived = $this->request->getGet('archived') === '1';
        return $this->ok($this->service->listAnnouncements($showArchived));
    }

    public function createAnnouncement(): ResponseInterface
    {
        $this->authorize('counselling.announcements.manage');
        $payload = $this->request->getJSON(true) ?? [];
        return $this->ok($this->service->createAnnouncement(is_array($payload) ? $payload : []), null, 201);
    }

    public function updateAnnouncement(int $id): ResponseInterface
    {
        $this->authorize('counselling.announcements.manage');
        $payload = $this->request->getJSON(true) ?? [];
        return $this->ok($this->service->updateAnnouncement($id, is_array($payload) ? $payload : []));
    }

    public function archiveAnnouncement(int $id): ResponseInterface
    {
        $this->authorize('counselling.announcements.manage');
        return $this->ok($this->service->archiveAnnouncement($id));
    }

    public function unarchiveAnnouncement(int $id): ResponseInterface
    {
        $this->authorize('counselling.announcements.manage');
        return $this->ok($this->service->unarchiveAnnouncement($id));
    }

    // ---- staff: services -------------------------------------------

    public function listServices(): ResponseInterface
    {
        $this->authorize('counselling.services.manage');
        $showArchived = $this->request->getGet('archived') === '1';
        return $this->ok($this->service->listServices($showArchived));
    }

    public function createService(): ResponseInterface
    {
        $this->authorize('counselling.services.manage');
        $payload = $this->request->getJSON(true) ?? [];
        return $this->ok($this->service->createService(is_array($payload) ? $payload : []), null, 201);
    }

    public function updateService(int $id): ResponseInterface
    {
        $this->authorize('counselling.services.manage');
        $payload = $this->request->getJSON(true) ?? [];
        return $this->ok($this->service->updateService($id, is_array($payload) ? $payload : []));
    }

    public function archiveService(int $id): ResponseInterface
    {
        $this->authorize('counselling.services.manage');
        return $this->ok($this->service->archiveService($id));
    }

    public function unarchiveService(int $id): ResponseInterface
    {
        $this->authorize('counselling.services.manage');
        return $this->ok($this->service->unarchiveService($id));
    }

    // ---- student feed (/me/guidance/*) ------------------------------

    public function feedAnnouncements(): ResponseInterface
    {
        return $this->ok($this->service->feedAnnouncements(CurrentUser::assert()));
    }

    public function feedServices(): ResponseInterface
    {
        return $this->ok($this->service->feedServices());
    }
}
