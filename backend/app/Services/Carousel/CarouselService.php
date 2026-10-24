<?php

declare(strict_types=1);

namespace App\Services\Carousel;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * CarouselService — login-page carousel CRUD with upload validation.
 *
 * Storage:
 *   - Public-brand assets live under `assets/img/brand/` (committed to
 *     the repo and shipped with the build).
 *   - Admin uploads live under `public/uploads/carousel/` with random
 *     filenames (`slide_{variant}_{bin2hex(random_bytes(16))}.{ext}`).
 *     The browser fetches them directly at `/uploads/carousel/...`.
 *
 * Validation:
 *   - `finfo` MIME sniff on the temp file — client-sent extensions and
 *     Content-Type are decoration, never a trust signal.
 *   - Allowed raster types: PNG / JPEG / WEBP / GIF.
 *   - Stored file's extension must match its sniffed MIME.
 *   - Path inputs (when no upload is provided) must resolve to a real
 *     file under `assets/img/brand/` or `public/uploads/carousel/`,
 *     with a realpath() check that blocks `../` traversal.
 *
 * Cleanup:
 *   - Files are only removed when zero slide rows (in ANY tenant) still
 *     reference them — protects against races during edit/replace.
 *   - On partial failure mid-upload, already-moved files are unlinked
 *     so an aborted create doesn't strand an orphan.
 *
 * Tenant scoping:
 *   Every read/write is filtered by `tenant_id = CurrentTenant::id()`.
 *   The cleanup reference check is deliberately cross-tenant because
 *   files live on a shared filesystem.
 */
final class CarouselService extends BaseService
{
    private const FIT_MODES = ['cover', 'contain_blur'];

    private const ALLOWED_MIMES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** Default per-file cap (5 MB). Override with CAROUSEL_MAX_UPLOAD_BYTES (>= 1 MB). */
    private const DEFAULT_MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const MIN_UPLOAD_BYTES = 1024 * 1024;

    /**
     * Application-level upload ceiling, independent of upload_max_filesize:
     * an admin must not be able to fill the disk with a single giant image
     * even when PHP's own limit is generous.
     */
    private function maxUploadBytes(): int
    {
        $raw = getenv('CAROUSEL_MAX_UPLOAD_BYTES');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_MAX_UPLOAD_BYTES;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_UPLOAD_BYTES, 'max_range' => 52428800]]);
        if ($value === false) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'CAROUSEL_MAX_UPLOAD_BYTES must be an integer between 1048576 and 52428800.',
                'field'   => 'image',
            ]]);
        }
        return $value;
    }

    private const UPLOAD_DIR = FCPATH . 'uploads/carousel/';

    private const UPLOAD_PREFIX = 'uploads/carousel/';

    /**
     * Human-readable text for a PHP upload error code.
     *
     * The application-level cap (maxUploadBytes()) is enforced separately
     * in resolveImageField() with its own actionable message; these are the
     * PHP-level failures below that ceiling (upload_max_filesize etc.).
     * PHP reports an over-limit body as UPLOAD_ERR_INI_SIZE /
     * UPLOAD_ERR_FORM_SIZE, so naming the cause is what turns an opaque
     * rejection into an actionable one.
     *
     * @return array{0: string, 1: string} [message, humanized hint]
     */
    private function uploadErrorMessage(int $code, string $field): array
    {
        $message = 'Carousel image upload failed.';
        $hint = 'The server rejected the upload.';

        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                $message = 'Carousel image is larger than the server upload limit (upload_max_filesize).';
                $hint = 'Ask an administrator to raise upload_max_filesize, or use a smaller image.';
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $message = 'Carousel image exceeds the form MAX_FILE_SIZE limit.';
                $hint = 'Ask an administrator to raise MAX_FILE_SIZE, or use a smaller image.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $message = 'Carousel image was only partially uploaded.';
                $hint = 'The connection dropped mid-upload. Try again.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $message = 'The server has no temporary directory for uploads.';
                $hint = 'Ask an administrator to fix PHP upload_tmp_dir.';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $message = 'The server could not write the uploaded file to disk.';
                $hint = 'Check the disk space and permissions on the upload directory.';
                break;
            case UPLOAD_ERR_EXTENSION:
                $message = 'A PHP extension stopped the upload.';
                $hint = 'Ask an administrator to review upload_ extensions.';
                break;
            default:
                break;
        }

        return [$message . ' ' . $hint, $message];
    }

    /**
     * Active slides for the public login page, ordered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActivePublic(): array
    {
        return $this->db->table('carousel_slides')
            ->where('tenant_id', CurrentTenant::id())
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * All slides (active and inactive) for the admin list, ordered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->db->table('carousel_slides')
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    public function find(int $id): ?array
    {
        $row = $this->db->table('carousel_slides')
            ->where('tenant_id', CurrentTenant::id())
            ->where('id', $id)
            ->get()->getRowArray();
        return $row !== null && $row !== [] ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data, ?UploadedFile $lightFile = null, ?UploadedFile $darkFile = null): array
    {
        $newPaths = [];
        try {
            $values = $this->normalisePayload($data, $lightFile, $darkFile, $newPaths);

            $values['tenant_id']  = CurrentTenant::id();
            $values['sort_order'] = $this->nextSortOrder();
            $values['is_active']  = isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1;
            // Query Builder writes carry no auto-timestamps (that's a Model
            // feature) and the columns have no DB default, so both must be
            // supplied here or the INSERT fails on NOT NULL.
            $values['created_at'] = date('Y-m-d H:i:s');

            $ok = $this->db->table('carousel_slides')->insert($values);
            // `insert()` returns a bool, NOT the new id — the second
            // argument is $escape. `insertID()` is the only way to learn
            // the generated key, and reading it on the same connection
            // right after the write is safe (no interleaved query).
            $id = $ok ? (int) $this->db->insertID() : 0;
            if ($id <= 0) {
                throw ApiException::conflict('resource.conflict', 'Could not create the carousel slide.');
            }

            $row = $this->find($id);
            if ($row === null) {
                throw ApiException::conflict('resource.conflict', 'Saved slide could not be loaded.');
            }
            return $row;
        } catch (\Throwable $t) {
            foreach ($newPaths as $path) {
                $this->unlinkStored($path);
            }
            throw $t;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null null when the slide is missing.
     */
    public function update(int $id, array $data, ?UploadedFile $lightFile = null, ?UploadedFile $darkFile = null): ?array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $newPaths = [];
        try {
            $values = $this->normalisePayload($data, $lightFile, $darkFile, $newPaths);
            if (array_key_exists('is_active', $data)) {
                $values['is_active'] = $data['is_active'] ? 1 : 0;
            }

            $ok = $this->db->table('carousel_slides')
                ->where('tenant_id', CurrentTenant::id())
                ->where('id', $id)
                ->update($values);
            if (! $ok) {
                throw ApiException::conflict('resource.conflict', 'Could not update the carousel slide.');
            }

            foreach (['light_image', 'dark_image'] as $field) {
                $old = $existing[$field] ?? null;
                $new = $values[$field] ?? null;
                if ($old !== null && $old !== $new && $old !== '') {
                    $this->unlinkStored((string) $old);
                }
            }
        } catch (\Throwable $t) {
            foreach ($newPaths as $path) {
                $this->unlinkStored($path);
            }
            throw $t;
        }

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return false;
        }

        $ok = $this->db->table('carousel_slides')
            ->where('tenant_id', CurrentTenant::id())
            ->where('id', $id)
            ->delete();
        if (! $ok) {
            return false;
        }

        foreach (['light_image', 'dark_image'] as $field) {
            $path = $existing[$field] ?? null;
            if ($path !== null && $path !== '') {
                $this->unlinkStored((string) $path);
            }
        }
        return true;
    }

    public function toggleActive(int $id): ?array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }
        $next = ((int) $existing['is_active']) === 1 ? 0 : 1;
        $this->db->table('carousel_slides')
            ->where('tenant_id', CurrentTenant::id())
            ->where('id', $id)
            ->update(['is_active' => $next, 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->find($id);
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $seq = 1;
        foreach ($orderedIds as $id) {
            $this->db->table('carousel_slides')
                ->where('tenant_id', CurrentTenant::id())
                ->where('id', (int) $id)
                ->update(['sort_order' => $seq++, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function nextSortOrder(): int
    {
        $row = $this->db->table('carousel_slides')
            ->selectMax('sort_order')
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        $current = isset($row['sort_order']) ? (int) $row['sort_order'] : 0;
        return $current + 1;
    }

    /**
     * Build the values payload, moving uploaded files into the
     * carousel uploads dir and resolving string paths.
     *
     * @param array<string, mixed> $data
     * @param list<string> $newPaths Out-param for files moved during this
     *                              call — caller must reap them on failure.
     * @return array<string, mixed>
     */
    private function normalisePayload(array $data, ?UploadedFile $lightFile, ?UploadedFile $darkFile, array &$newPaths): array
    {
        $title    = $this->validateText($data['title'] ?? null, 'Title', 150);
        $subtitle = $this->validateText($data['subtitle'] ?? null, 'Subtitle', 255);
        $fitMode  = isset($data['fit_mode']) ? (string) $data['fit_mode'] : 'cover';
        if (! in_array($fitMode, self::FIT_MODES, true)) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'Fit mode must be cover or contain_blur.',
                'field'   => 'fit_mode',
            ]]);
        }

        $values = [
            'title'    => $title,
            'subtitle' => $subtitle,
            'fit_mode' => $fitMode,
        ];

        $values['light_image'] = $this->resolveImageField('light_image', $lightFile, $data, $newPaths);
        $values['dark_image']  = $this->resolveImageField('dark_image', $darkFile, $data, $newPaths, allowEmpty: true);

        $values['updated_at'] = date('Y-m-d H:i:s');
        return $values;
    }

    /**
     * Resolve one image field. If an UploadedFile was sent, validate,
     * move it, and return the relative path. Otherwise read the value
     * from $data and validate it's a known safe local path.
     *
     * @param array<string, mixed> $data
     * @param list<string> $newPaths
     */
    private function resolveImageField(string $field, ?UploadedFile $file, array $data, array &$newPaths, bool $allowEmpty = false): ?string
    {
        if ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                [$message] = $this->uploadErrorMessage($file->getError(), $field);
                throw ApiException::validationFailure([[
                    'code'    => 'validation.field',
                    'message' => $message,
                    'field'   => $field,
                ]]);
            }
            // isValid() demands is_uploaded_file(), which the CLI test
            // harness cannot satisfy with tempnam() seeds — the same reason
            // the move below has a rename()/copy() fallback. Production
            // (HTTP uploads) always takes the strict path.
            $valid = $file->isValid()
                || (defined('ENVIRONMENT') && ENVIRONMENT === 'testing' && $file->getError() === UPLOAD_ERR_OK);
            if (! $valid || $file->hasMoved()) {
                throw ApiException::validationFailure([[
                    'code'    => 'validation.field',
                    'message' => 'Carousel image upload failed.',
                    'field'   => $field,
                ]]);
            }
            // Application size cap: upload_max_filesize is the outer ceiling,
            // this is the policy ceiling (default 5 MB, see maxUploadBytes()).
            $size = $file->getSize();
            if ($size === null || $size < 1 || $size > $this->maxUploadBytes()) {
                throw ApiException::validationFailure([[
                    'code'    => 'validation.field',
                    'message' => sprintf(
                        'Carousel image is %s. The maximum allowed size is %d MB.',
                        $size !== null && $size >= 1 ? sprintf('%.1f MB', $size / 1048576) : 'empty',
                        (int) ($this->maxUploadBytes() / 1048576),
                    ),
                    'field'   => $field,
                ]]);
            }
            // An empty body is still refused below: sniffExtension() runs
            // getimagesize() on it, which fails on a 0-byte file.
            $tempPath = (string) $file->getTempName();
            $ext = $this->sniffExtension($tempPath);
            $variant = $field === 'light_image' ? 'light' : 'dark';
            $name = 'slide_' . $variant . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
            $this->ensureUploadDir();
            $dest = self::UPLOAD_DIR . $name;
            // `move_uploaded_file()` is the canonical guard against
            // attacker-supplied temp paths, but the CLI test harness
            // can't satisfy `is_uploaded_file()`, so `rename()` (and
            // finally `copy()`) act as fallbacks for tests.
            if (! @move_uploaded_file($tempPath, $dest)) {
                if (! @rename($tempPath, $dest) && ! @copy($tempPath, $dest)) {
                    throw ApiException::conflict('resource.conflict', 'Could not store the carousel image.');
                }
                if (is_file($tempPath)) {
                    @unlink($tempPath);
                }
            }
            @chmod($dest, 0644);
            $newPaths[] = self::UPLOAD_PREFIX . $name;
            return self::UPLOAD_PREFIX . $name;
        }

        $raw = $data[$field] ?? null;
        if ($raw === null || $raw === '') {
            if ($allowEmpty) {
                return null;
            }
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'A safe local carousel image path is required.',
                'field'   => $field,
            ]]);
        }
        if (! is_string($raw)) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'Image path must be a string.',
                'field'   => $field,
            ]]);
        }
        return $this->validateImagePath($raw);
    }

    private function validateImagePath(string $path): string
    {
        if (strlen($path) > 255
            || preg_match('#\A(assets/img/brand|uploads/carousel)/([A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*)\z#', $path, $m) !== 1
        ) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'A safe local carousel image path is required.',
                'field'   => 'image_path',
            ]]);
        }

        // uploads/carousel files must be flat (no subdirs) so the cleanup
        // pass can't be tricked into unlinking outside the carousel dir.
        if ($m[1] === 'uploads/carousel' && str_contains($m[2], '/')) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'Carousel upload paths must be flat.',
                'field'   => 'image_path',
            ]]);
        }

        $root = realpath(FCPATH . $m[1]);
        $full = realpath(FCPATH . $path);
        if ($root === false || $full === false || ! is_file($full)
            || ! str_starts_with($full, $root . DIRECTORY_SEPARATOR)
        ) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'Carousel image must exist inside its local image directory.',
                'field'   => 'image_path',
            ]]);
        }

        $ext = $this->sniffExtension($full);
        $pathExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($pathExt !== $ext && ! ($ext === 'jpg' && $pathExt === 'jpeg')) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'Carousel image extension must match its raster MIME type.',
                'field'   => 'image_path',
            ]]);
        }
        return $path;
    }

    private function validateText(mixed $value, string $label, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => $label . ' must be text.',
                'field'   => strtolower($label),
            ]]);
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (! mb_check_encoding($trimmed, 'UTF-8') || mb_strlen($trimmed, 'UTF-8') > $limit) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => $label . ' must not exceed ' . $limit . ' characters of valid UTF-8 text.',
                'field'   => strtolower($label),
            ]]);
        }
        return $trimmed;
    }

    private function sniffExtension(string $path): string
    {
        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        $image = @getimagesize($path);
        if (! isset(self::ALLOWED_MIMES[$mime]) || $image === false || ($image['mime'] ?? null) !== $mime) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'Carousel images must be PNG, JPEG, WEBP, or GIF raster images.',
                'field'   => 'image',
            ]]);
        }
        return self::ALLOWED_MIMES[$mime];
    }

    private function ensureUploadDir(): void
    {
        if (! is_dir(self::UPLOAD_DIR)
            && ! @mkdir(self::UPLOAD_DIR, 0775, true) && ! is_dir(self::UPLOAD_DIR)) {
            throw ApiException::conflict('resource.conflict', 'Could not prepare the carousel upload directory.');
        }
        @file_put_contents(self::UPLOAD_DIR . 'index.html', '');
        // Defense in depth for Apache: the directory holds validated raster
        // images only, so no script handler may ever execute anything from
        // here — even a hypothetical upload bypass or a stray file. For
        // nginx, mirror this with: location ~* ^/uploads/.*\.(ph[0-9]?|phtml|phar|pht)$ { deny all; }
        // (documented in backend/README.md). Idempotent: re-writes on every
        // ensure so an operator-deleted file self-heals on the next upload.
        @file_put_contents(
            self::UPLOAD_DIR . '.htaccess',
            "# Managed by CarouselService — deny script execution in uploads.\n"
            . "Options -Indexes\n"
            . "<FilesMatch \"\\.(ph[0-9]?|phtml|phar|pht)$\">\n"
            . "    <IfModule mod_authz_core.c>\n"
            . "        Require all denied\n"
            . "    </IfModule>\n"
            . "    <IfModule !mod_authz_core.c>\n"
            . "        Order allow,deny\n"
            . "        Deny from all\n"
            . "    </IfModule>\n"
            . "</FilesMatch>\n"
            . "php_flag engine off\n",
        );
    }

    /**
     * Unlink a stored file IF no slide row references it (cross-tenant
     * check, since uploads live on a shared filesystem). A symlink or
     * a path that escapes the carousel dir is never removed.
     */
    private function unlinkStored(mixed $path): void
    {
        if (! is_string($path)
            || $path === ''
            || preg_match('#\Auploads/carousel/[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:png|jpe?g|webp|gif)\z#', $path) !== 1
        ) {
            return;
        }
        $full = FCPATH . $path;
        $root = realpath(self::UPLOAD_DIR);
        $resolved = realpath($full);
        if ($root === false || $resolved === false || is_link($full) || ! is_file($full)
            || dirname($resolved) !== $root
        ) {
            return;
        }
        try {
            $references = $this->db->table('carousel_slides')
                ->groupStart()
                    ->where('light_image', $path)
                    ->orWhere('dark_image', $path)
                ->groupEnd()
                ->countAllResults();
            if ((int) $references === 0) {
                @unlink($full);
            }
        } catch (\Throwable) {
            // If the reference check can't run, retain the file.
        }
    }
}