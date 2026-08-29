<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Exceptions\ApiException;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

final class KioskMediaService
{
    /** @var array<string, array{kind:'photo'|'video',extension:string}> */
    private const MIME_TYPES = [
        'image/jpeg' => ['kind' => 'photo', 'extension' => 'jpg'],
        'image/png' => ['kind' => 'photo', 'extension' => 'png'],
        'image/webp' => ['kind' => 'photo', 'extension' => 'webp'],
        'image/gif' => ['kind' => 'photo', 'extension' => 'gif'],
        'video/mp4' => ['kind' => 'video', 'extension' => 'mp4'],
        'video/webm' => ['kind' => 'video', 'extension' => 'webm'],
    ];

    private readonly BaseConnection $db;
    private readonly string $storageDirectory;
    private readonly string $thumbnailDirectory;

    public function __construct(
        private readonly AuditOutboxService $audit,
        ?BaseConnection $db = null,
        ?string $storageDirectory = null,
    ) {
        $this->db = $db ?? Database::connect();
        $this->storageDirectory = $storageDirectory ?? rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kiosk-media';
        $this->thumbnailDirectory = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'kiosk-thumbnails';
    }

    /** @return array{items:list<array<string,mixed>>,page:int,limit:int,total:int} */
    public function list(bool $includeArchived = false, int $page = 1, int $limit = 60, ?string $search = null): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $builder = $this->db->table('kiosk_media_assets')->where('tenant_id', CurrentTenant::id());
        if (! $includeArchived) {
            $builder->where('archived_at', null);
        }
        if ($search !== null && trim($search) !== '') {
            $builder->groupStart()->like('label', trim($search))->orLike('original_name', trim($search))->groupEnd();
        }
        $countBuilder = clone $builder;
        $total = (int) $countBuilder->countAllResults();
        $rows = $builder->orderBy('created_at', 'DESC')->limit($limit, ($page - 1) * $limit)->get()->getResultArray();
        return ['items' => array_map($this->dto(...), $rows), 'page' => $page, 'limit' => $limit, 'total' => $total];
    }

    /** @return array<string,mixed> */
    public function upload(UploadedFile $file, ?string $requestedLabel, int $actorUserId): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw self::fieldError('file', 'This server is still imposing an upload-size limit. Restart it with the project PHP configuration before retrying.');
            }
            throw self::fieldError('file', 'Choose a valid photo or video file.');
        }
        $mime = strtolower($file->getMimeType());
        $classification = self::classify($mime, $file->getSize());
        if ($classification['kind'] === 'photo' && @getimagesize($file->getTempName()) === false) {
            throw self::fieldError('file', 'The uploaded image could not be decoded.');
        }

        $publicId = self::uuid();
        $storedName = str_replace('-', '', $publicId) . '.' . $classification['extension'];
        $originalName = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', basename($file->getClientName())) ?: 'kiosk-media.' . $classification['extension'];
        $originalName = mb_substr($originalName, 0, 255);
        $fallbackLabel = pathinfo($originalName, PATHINFO_FILENAME);
        $label = trim((string) ($requestedLabel ?? ''));
        $label = mb_substr($label !== '' ? $label : $fallbackLabel, 0, 120);
        if ($label === '') {
            $label = ucfirst($classification['kind']) . ' media';
        }
        $this->ensureStorageDirectory();
        $file->move($this->storageDirectory, $storedName, false);
        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . $storedName;
        @chmod($path, 0644);
        $thumbnailPath = null;
        $thumbnailPath = $this->thumbnailPath($publicId);
        $this->generateThumbnail($path, $thumbnailPath, $classification['kind']);

        $now = self::now();
        $this->db->transStart();
        $this->db->table('kiosk_media_assets')->insert([
            'tenant_id' => CurrentTenant::id(),
            'public_id' => $publicId,
            'kind' => $classification['kind'],
            'label' => $label,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'uploaded_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
            'archived_at' => null,
        ]);
        $id = (int) $this->db->insertID();
        $this->audit->enqueue('kiosk.media_uploaded', 'kiosk_media_assets', $id, $actorUserId, ['resource_code' => $publicId]);
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            @unlink($path);
            if ($thumbnailPath !== null) @unlink($thumbnailPath);
            throw new \RuntimeException('Could not persist kiosk media metadata.');
        }
        return $this->findManaged($id);
    }

    /** @return array<string,mixed> */
    public function archive(int $id, int $actorUserId): array
    {
        return $this->setArchived($id, true, $actorUserId);
    }

    /** @return array<string,mixed> */
    public function restore(int $id, int $actorUserId): array
    {
        return $this->setArchived($id, false, $actorUserId);
    }

    /** @return array{path:string,mime_type:string} */
    public function publicFile(string $publicId): array
    {
        if (preg_match('/^[a-f0-9-]{36}$/i', $publicId) !== 1) {
            throw ApiException::notFound();
        }
        $row = $this->db->table('kiosk_media_assets')->where('public_id', $publicId)->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound();
        }
        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . (string) $row['stored_name'];
        $root = realpath($this->storageDirectory);
        $resolved = realpath($path);
        if ($root === false || $resolved === false || ! str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) || ! is_file($resolved)) {
            throw ApiException::notFound();
        }
        return ['path' => $resolved, 'mime_type' => (string) $row['mime_type']];
    }

    /** @return array{path:string,mime_type:string,extension:string} */
    public function publicThumbnail(string $publicId): array
    {
        if (preg_match('/^[a-f0-9-]{36}$/i', $publicId) !== 1) {
            throw ApiException::notFound();
        }
        $row = $this->db->table('kiosk_media_assets')->where('public_id', $publicId)->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound();
        }
        $source = $this->storageDirectory . DIRECTORY_SEPARATOR . (string) $row['stored_name'];
        $thumbnail = $this->thumbnailPath($publicId);
        if (! is_file($thumbnail)) {
            $this->generateThumbnail($source, $thumbnail, (string) $row['kind']);
        }
        $root = realpath($this->thumbnailDirectory);
        $resolved = realpath($thumbnail);
        if ($root === false || $resolved === false || ! str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) || ! is_file($resolved)) {
            throw ApiException::notFound();
        }
        return ['path' => $resolved, 'mime_type' => 'image/jpeg', 'extension' => 'jpg'];
    }

    /** @return array{kind:'photo'|'video',extension:string} */
    public static function classify(string $mime, int $size): array
    {
        $type = self::MIME_TYPES[strtolower(trim($mime))] ?? null;
        if ($type === null) {
            throw self::fieldError('file', 'Allowed formats: JPEG, PNG, WebP, GIF, MP4, and WebM.');
        }
        if ($size < 1) {
            throw self::fieldError('file', 'The uploaded file is empty.');
        }
        return $type;
    }

    /** @return array<string,mixed> */
    private function setArchived(int $id, bool $archived, int $actorUserId): array
    {
        $row = $this->db->table('kiosk_media_assets')->where(['id' => $id, 'tenant_id' => CurrentTenant::id()])->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound();
        }
        $now = self::now();
        $this->db->transStart();
        $this->db->table('kiosk_media_assets')->where(['id' => $id, 'tenant_id' => CurrentTenant::id()])->update([
            'archived_at' => $archived ? $now : null,
            'updated_at' => $now,
        ]);
        $this->audit->enqueue($archived ? 'kiosk.media_archived' : 'kiosk.media_restored', 'kiosk_media_assets', $id, $actorUserId, ['resource_code' => (string) $row['public_id']]);
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new \RuntimeException('Could not update kiosk media.');
        }
        return $this->findManaged($id);
    }

    /** @return array<string,mixed> */
    private function findManaged(int $id): array
    {
        $row = $this->db->table('kiosk_media_assets')->where(['id' => $id, 'tenant_id' => CurrentTenant::id()])->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound();
        }
        return $this->dto($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function dto(array $row): array
    {
        $publicId = (string) $row['public_id'];
        $thumbnailUrl = is_file($this->thumbnailPath($publicId))
            ? '/kiosk-thumbnails/' . str_replace('-', '', $publicId) . '.jpg'
            : '/api/v1/kiosk-media/' . rawurlencode($publicId) . '/thumbnail';

        return [
            'id' => (int) $row['id'],
            'public_id' => (string) $row['public_id'],
            'kind' => (string) $row['kind'],
            'label' => (string) $row['label'],
            'original_name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'url' => '/api/v1/kiosk-media/' . rawurlencode($publicId) . '/content',
            'thumbnail_url' => $thumbnailUrl,
            'archived' => $row['archived_at'] !== null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function ensureStorageDirectory(): void
    {
        if (! is_dir($this->storageDirectory) && ! mkdir($this->storageDirectory, 0755, true) && ! is_dir($this->storageDirectory)) {
            throw new \RuntimeException('Could not create kiosk media storage.');
        }
        if (! is_writable($this->storageDirectory)) {
            throw new \RuntimeException('Kiosk media storage is not writable.');
        }
    }

    private function thumbnailPath(string $publicId): string
    {
        return $this->thumbnailDirectory . DIRECTORY_SEPARATOR . str_replace('-', '', $publicId) . '.jpg';
    }

    private function generateThumbnail(string $source, string $destination, string $kind): bool
    {
        if (! is_file($source) || ! function_exists('proc_open')) return false;
        $this->ensureThumbnailDirectory();
        $binary = (string) (getenv('FFMPEG_BINARY') ?: 'ffmpeg');
        $background = $kind === 'photo' ? 'white' : 'black';
        $filter = "scale=640:360:force_original_aspect_ratio=decrease,pad=640:360:(ow-iw)/2:(oh-ih)/2:color={$background}";
        $command = [$binary, '-hide_banner', '-loglevel', 'error', '-y'];
        if ($kind === 'video') array_push($command, '-ss', '1');
        array_push($command, '-i', $source, '-frames:v', '1', '-vf', $filter, '-q:v', '3', $destination);
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) return false;
        foreach ($pipes as $pipe) fclose($pipe);
        $successful = proc_close($process) === 0 && is_file($destination) && filesize($destination) > 0;
        if (! $successful) @unlink($destination);
        if ($successful) @chmod($destination, 0644);
        return $successful;
    }

    private function ensureThumbnailDirectory(): void
    {
        if (! is_dir($this->thumbnailDirectory) && ! mkdir($this->thumbnailDirectory, 0755, true) && ! is_dir($this->thumbnailDirectory)) {
            throw new \RuntimeException('Could not create kiosk thumbnail storage.');
        }
    }

    private static function fieldError(string $field, string $message): ApiException
    {
        return ApiException::validationFailure([['code' => 'validation.field', 'message' => $message, 'field' => $field]]);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
