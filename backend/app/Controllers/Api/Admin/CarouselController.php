<?php

declare(strict_types=1);

namespace App\Controllers\Api\Admin;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Services\Carousel\CarouselService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * CarouselController — admin CRUD for login-page carousel slides.
 *
 * All routes gated by `rbac.manage` (applied at the route group).
 * Multipart uploads (POST) accept:
 *     - `light_image` (required for create, optional for update)
 *     - `dark_image`  (optional)
 *     - `title`       (string, ≤ 150)
 *     - `subtitle`    (string, ≤ 255)
 *     - `fit_mode`    ('cover' | 'contain_blur')
 *     - `is_active`   ('1' | '0', optional)
 *
 * Or JSON for delete/toggle/reorder (no files).
 *
 *   GET    /api/v1/admin/carousel
 *   POST   /api/v1/admin/carousel
 *   POST   /api/v1/admin/carousel/(:num)
 *   POST   /api/v1/admin/carousel/(:num)/delete
 *   POST   /api/v1/admin/carousel/(:num)/toggle
 *   POST   /api/v1/admin/carousel/reorder
 */
final class CarouselController extends ApiController
{
    public function index(): ResponseInterface
    {
        $this->authorize('rbac.manage');
        return $this->ok(Services::carouselService()->listAll());
    }

    public function create(): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $data = $this->collectData();
        $row = Services::carouselService()->create(
            $data,
            $this->request->getFile('light_image'),
            $this->request->getFile('dark_image'),
        );
        return $this->ok($row, null, 201);
    }

    public function update(int $id): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $data = $this->collectData();
        $row = Services::carouselService()->update(
            $id,
            $data,
            $this->request->getFile('light_image'),
            $this->request->getFile('dark_image'),
        );
        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }
        return $this->ok($row);
    }

    public function delete(int $id): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $ok = Services::carouselService()->delete($id);
        if (! $ok) {
            throw ApiException::notFound('resource.not_found');
        }
        return $this->ok(['deleted' => true]);
    }

    public function toggle(int $id): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $row = Services::carouselService()->toggleActive($id);
        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }
        return $this->ok($row);
    }

    public function reorder(): ResponseInterface
    {
        $this->authorize('rbac.manage');
        // `getJSON()` throws on an unparseable body, so a client that
        // posts form-encoded (or empty) data gets a 500 instead of the
        // intended validation error. Catch it and treat it as "no ids".
        try {
            $payload = $this->request->getJSON(true);
        } catch (\Throwable) {
            $payload = null;
        }
        $payload = is_array($payload) ? $payload : [];
        $raw = $payload['ordered_ids'] ?? [];
        if (! is_array($raw)) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'ordered_ids must be an array of slide ids.',
                'field'   => 'ordered_ids',
            ]]);
        }
        $ids = [];
        foreach ($raw as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw ApiException::validationFailure([[
                    'code'    => 'validation.field',
                    'message' => 'ordered_ids must contain integer ids only.',
                    'field'   => 'ordered_ids',
                ]]);
            }
            $ids[] = (int) $value;
        }
        Services::carouselService()->reorder($ids);
        return $this->ok(['reordered' => true]);
    }

    /**
     * Pull text fields. Multipart submissions carry them in $_POST; JSON
     * submissions in the request body. Both may be present (multipart
     * fields plus a JSON blob), so $_POST wins for overlap.
     *
     * `getJSON()` THROWS on a body it cannot parse, and a multipart
     * upload has no JSON body at all — so the content type is checked
     * first rather than catching the parse failure after the fact.
     *
     * @return array<string, mixed>
     */
    private function collectData(): array
    {
        $post = $this->request->getPost() ?? [];
        $post = is_array($post) ? $post : [];

        $contentType = strtolower((string) $this->request->getHeaderLine('Content-Type'));
        if (! str_contains($contentType, 'application/json')) {
            // There is no application-level size cap, so an over-limit
            // body is caught by PHP instead. When `post_max_size` is
            // exceeded PHP discards the ENTIRE body: $_POST comes back
            // empty and the uploaded file is simply absent — no error
            // code, no file entry. Without this guard the service would
            // report the misleading "a safe image path is required".
            if (str_contains($contentType, 'multipart/form-data') && $post === [] && $this->request->getFile('light_image') === null && $this->request->getFile('dark_image') === null) {
                throw ApiException::validationFailure([[
                    'code'    => 'validation.field',
                    'message' => 'The upload exceeded the server request limit (post_max_size) and was discarded before it could be read. Use a smaller image, or ask an administrator to raise post_max_size.',
                    'field'   => 'light_image',
                ]]);
            }
            return $post;
        }

        $json = $this->request->getJSON(true);
        if (! is_array($json)) {
            return $post;
        }
        return array_merge($json, $post);
    }
}