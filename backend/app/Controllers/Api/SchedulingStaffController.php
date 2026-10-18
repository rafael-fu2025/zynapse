<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\SchedulingStaffService;
use CodeIgniter\HTTP\ResponseInterface;

final class SchedulingStaffController extends ApiController
{
    public function index(string $unit): ResponseInterface
    {
        return $this->ok((new SchedulingStaffService())->discover(
            $unit, (string) ($this->request->getGet('q') ?? ''),
            $this->request->getGet('cursor'), (int) ($this->request->getGet('limit') ?? 25),
        ));
    }

    public function providers(string $unit): ResponseInterface
    {
        return $this->ok((new SchedulingStaffService())->providers($unit));
    }

    public function enroll(string $unit): ResponseInterface
    {
        return $this->ok((new SchedulingStaffService())->enroll($unit, $this->request->getJSON(true) ?? []));
    }

    public function status(string $unit, int $id): ResponseInterface
    {
        return $this->ok((new SchedulingStaffService())->status($unit, $id, $this->request->getJSON(true) ?? []));
    }

    public function template(string $unit): ResponseInterface
    {
        return $this->ok((new SchedulingStaffService())->template($unit, $this->request->getJSON(true) ?? []));
    }
}
