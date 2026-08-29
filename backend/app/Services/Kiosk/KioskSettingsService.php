<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Exceptions\ApiException;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

final class KioskSettingsService
{
    private readonly BaseConnection $db;

    public function __construct(
        private readonly AuditOutboxService $audit,
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{settings:array<string,mixed>,revision:int,updated_at:?string} */
    public function get(): array
    {
        $row = $this->db->table('kiosk_settings')->where('tenant_id', CurrentTenant::id())->get()->getRowArray();
        if ($row === null) {
            return ['settings' => self::defaults(), 'revision' => 0, 'updated_at' => null];
        }
        $settings = json_decode((string) $row['settings_json'], true, 64, JSON_THROW_ON_ERROR);
        return [
            'settings' => is_array($settings) ? $settings : self::defaults(),
            'revision' => (int) $row['revision'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string,mixed> $settings @return array{settings:array<string,mixed>,revision:int,updated_at:?string} */
    public function save(array $settings, int $expectedRevision, int $actorUserId): array
    {
        self::validate($settings);
        $tenantId = CurrentTenant::id();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $encoded = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->db->transStart();
        $row = $this->db->query(
            'SELECT id, revision FROM kiosk_settings WHERE tenant_id = ? FOR UPDATE',
            [$tenantId],
        )->getRowArray();
        if ($row === null) {
            if ($expectedRevision !== 0) {
                $this->db->transRollback();
                throw self::conflict();
            }
            $this->db->table('kiosk_settings')->insert([
                'tenant_id' => $tenantId,
                'settings_json' => $encoded,
                'revision' => 1,
                'updated_by_user_id' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();
        } else {
            if ((int) $row['revision'] !== $expectedRevision) {
                $this->db->transRollback();
                throw self::conflict();
            }
            $id = (int) $row['id'];
            $this->db->table('kiosk_settings')->where('id', $id)->update([
                'settings_json' => $encoded,
                'revision' => $expectedRevision + 1,
                'updated_by_user_id' => $actorUserId,
                'updated_at' => $now,
            ]);
        }
        $this->audit->enqueue('kiosk.settings_updated', 'kiosk_settings', $id, $actorUserId);
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new \RuntimeException('Could not save kiosk settings.');
        }
        return $this->get();
    }

    /** @param array<string,mixed> $settings */
    private static function validate(array $settings): void
    {
        $playlist = $settings['playlist'] ?? null;
        if (($settings['version'] ?? null) !== 2 || ! is_array($settings['display'] ?? null) || ! is_array($playlist)) {
            throw self::fieldError('settings', 'Kiosk settings must use the version 2 structure.');
        }
        if (count($playlist) > 100) {
            throw self::fieldError('playlist', 'The playlist may contain at most 100 items.');
        }
        $encoded = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 1_048_576) {
            throw self::fieldError('settings', 'Kiosk settings are too large.');
        }
        $ids = [];
        foreach ($playlist as $index => $item) {
            if (! is_array($item) || ! in_array($item['type'] ?? null, ['announcement', 'photo', 'video'], true)) {
                throw self::fieldError("playlist.{$index}", 'Every playlist item needs a supported type.');
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '' || isset($ids[$id])) {
                throw self::fieldError("playlist.{$index}.id", 'Playlist item IDs must be present and unique.');
            }
            $ids[$id] = true;
            if (in_array($item['type'], ['photo', 'video'], true) && ! self::safeMediaUrl((string) ($item['source'] ?? ''))) {
                throw self::fieldError("playlist.{$index}.source", 'Media must use HTTPS or a same-origin path.');
            }
        }
    }

    private static function safeMediaUrl(string $value): bool
    {
        $value = trim($value);
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) return true;
        return filter_var($value, FILTER_VALIDATE_URL) !== false && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'version' => 2, 'enabled' => true, 'preset' => 'bell', 'volume' => 0.5,
            'display' => [
                'mediaEnabled' => true, 'mediaHeight' => 'standard', 'transition' => 'fade',
                'transitionDurationMs' => 500, 'defaultItemDurationMs' => 10000,
                'background' => 'black', 'photoFit' => 'contain', 'videoFit' => 'contain',
                'showCaptions' => true, 'showProgress' => true, 'waitingTextSize' => 'standard',
                'autoScroll' => false, 'autoScrollSpeed' => 24,
            ],
            'playlist' => [],
        ];
    }

    private static function fieldError(string $field, string $message): ApiException
    {
        return ApiException::validationFailure([['code' => 'validation.field', 'message' => $message, 'field' => $field]]);
    }

    private static function conflict(): ApiException
    {
        return new ApiException('kiosk.settings_conflict', 409, [[
            'code' => 'kiosk.settings_conflict',
            'message' => 'Kiosk settings changed on another device. Reload and try again.',
        ]]);
    }
}
