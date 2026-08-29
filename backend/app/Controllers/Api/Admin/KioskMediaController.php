<?php

declare(strict_types=1);

namespace App\Controllers\Api\Admin;

use App\Auth\CurrentUser;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Services\Kiosk\KioskMediaService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

final class KioskMediaController extends ApiController
{
    private readonly KioskMediaService $service;

    public function __construct(?KioskMediaService $service = null)
    {
        $this->service = $service ?? new KioskMediaService(Services::auditOutbox());
    }

    public function index(): ResponseInterface
    {
        $this->authorize('kiosk.content.manage');
        $includeArchived = in_array((string) ($this->request->getGet('include_archived') ?? ''), ['1', 'true'], true);
        $search = $this->request->getGet('search');
        return $this->ok($this->service->list(
            $includeArchived,
            max(1, (int) ($this->request->getGet('page') ?? 1)),
            max(1, (int) ($this->request->getGet('limit') ?? 60)),
            is_string($search) ? $search : null,
        ));
    }

    public function upload(): ResponseInterface
    {
        $this->authorize('kiosk.content.manage');
        $file = $this->request->getFile('file');
        if ($file === null) {
            throw ApiException::validationFailure([[
                'code' => 'validation.field',
                'message' => 'The server did not receive the selected file. Restart it with the project PHP configuration, then retry the upload.',
                'field' => 'file',
            ]]);
        }
        $label = $this->request->getPost('label');
        if ($label !== null && (! is_string($label) || mb_strlen($label) > 120)) {
            throw ApiException::validationFailure([['code' => 'validation.field', 'message' => 'Label must be 120 characters or fewer.', 'field' => 'label']]);
        }
        return $this->ok($this->service->upload($file, is_string($label) ? $label : null, CurrentUser::assert()), null, 201);
    }

    public function archive(int $id): ResponseInterface
    {
        $this->authorize('kiosk.content.manage');
        return $this->ok($this->service->archive($id, CurrentUser::assert()));
    }

    public function restore(int $id): ResponseInterface
    {
        $this->authorize('kiosk.content.manage');
        return $this->ok($this->service->restore($id, CurrentUser::assert()));
    }

    /** Public bearer-by-UUID content used by the unauthenticated queue display. */
    public function content(string $publicId): ResponseInterface
    {
        $media = $this->service->publicFile($publicId);
        $extension = pathinfo($media['path'], PATHINFO_EXTENSION);
        $filename = 'kiosk-media' . ($extension !== '' ? '.' . $extension : '');
        return $this->response
            ->download($media['path'], null)
            ->setFileName($filename)
            ->setContentType($media['mime_type'])
            ->setHeader('Content-Disposition', 'inline; filename="' . addcslashes($filename, '"\\') . '"')
            ->setHeader('Cache-Control', 'public, max-age=86400, immutable')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    /** Small public poster used by the gallery instead of loading full videos. */
    public function thumbnail(string $publicId): ResponseInterface
    {
        $media = $this->service->publicThumbnail($publicId);
        return $this->response
            ->download($media['path'], null)
            ->setFileName('kiosk-thumbnail.' . $media['extension'])
            ->setContentType($media['mime_type'])
            ->setHeader('Content-Disposition', 'inline; filename="kiosk-thumbnail.' . $media['extension'] . '"')
            ->setHeader('Cache-Control', 'public, max-age=86400, immutable')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }
}
