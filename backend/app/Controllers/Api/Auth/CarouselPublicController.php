<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\ApiController;
use App\Services\Carousel\CarouselService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * CarouselPublicController — public read for the login page.
 *
 * Unauthenticated by design: the login screen can't carry a JWT.
 * Active slides only, ordered, tenant-scoped via CurrentTenant.
 *
 *   GET /api/v1/auth/carousel-slides
 *   → 200 { success: true, data: [{...slide}, ...] }
 */
final class CarouselPublicController extends ApiController
{
    public function index(): ResponseInterface
    {
        $service = Services::carouselService();
        return $this->ok($service->listActivePublic());
    }
}