<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

final class RegistryArchiveScopeTest extends FeatureTestCase
{
    use \Tests\Feature\Support\SchedulingStaffFixture;
    public static function registries(): iterable
    {
        foreach (['equipment', 'medicines', 'inventory', 'staff-schedules', 'units', 'devices', 'waste-categories', 'configs'] as $registry) {
            yield $registry => [$registry];
        }
    }

    #[DataProvider('registries')]
    public function testExplicitScopeOverridesLegacyAndKeepsLegacyDefaults(string $registry): void
    {
        $session = $this->login(['superadmin']);
        $token = $session['token'];
        if ($registry === 'staff-schedules') $this->enrollSchedulingFixture($session['userId'], 'clinic');
        $route = 'api/v1/' . match ($registry) {
            'units', 'devices', 'waste-categories' => 'facilities/',
            'configs' => 'reports/',
            default => 'clinic/',
        } . $registry;
        $ids = [];
        foreach ([0, 1] as $index) {
            $suffix = bin2hex(random_bytes(5));
            $payload = match ($registry) {
                'equipment' => ['name' => 'Scope ' . $suffix],
                'medicines' => ['generic_name' => 'Scope ' . $suffix],
                'inventory' => ['sku' => 'SCP-' . $suffix, 'name' => 'Scope ' . $suffix, 'unit' => 'pc'],
                'staff-schedules' => ['user_id' => $session['userId'], 'day_of_week' => $index, 'shift_start' => '08:00', 'shift_end' => '09:00'],
                'units' => ['code' => 'scp-' . $suffix, 'display_name' => 'Scope ' . $suffix, 'device_id' => $this->createBmgDevice()['deviceId']],
                'waste-categories' => ['code' => 'scp-' . $suffix, 'name' => 'Scope ' . $suffix],
                'configs' => ['name' => 'Scope ' . $suffix, 'module' => 'clinic'],
                'devices' => null,
            };
            if ($payload === null) {
                $ids[] = $this->createBmgDevice()['deviceId'];
            } else {
                $result = $this->authed($token, 'post', $route, $payload);
                $result->assertStatus(201);
                $ids[] = (int) $this->envelope($result)['data']['id'];
            }
        }
        [$activeId, $archivedId] = $ids;
        $delete = $registry === 'devices';
        $result = $this->authed($token, $delete ? 'delete' : 'post', $route . '/' . $archivedId . ($delete ? '' : '/archive'));
        $result->assertStatus(200);

        $legacyMixedDefault = $registry === 'waste-categories';
        foreach ([
            '' => $legacyMixedDefault ? $ids : [$activeId],
            '&include_archived=1' => $ids,
            '&include_archived=true' => $ids,
            '&archived=1' => [$archivedId],
            '&archived=0' => [$activeId],
            '&archived=1&include_archived=1&active=1' => [$archivedId],
            '&archived=0&include_archived=1' => [$activeId],
        ] as $query => $expected) {
            $result = $this->authed($token, 'get', $route . '?limit=50' . $query);
            $result->assertStatus(200);
            $data = $this->envelope($result)['data'];
            $rows = $registry === 'configs' ? $data['items'] : $data;
            $actual = array_values(array_intersect($ids, array_map('intval', array_column($rows, 'id'))));
            $this->assertSame($expected, $actual, $registry . ' ' . $query);
            if ($registry === 'configs') {
                $count = $this->db->table('report_configurations')->where('tenant_id', 1);
                if (str_contains($query, '&archived=1')) {
                    $count->where('is_active', 0);
                } elseif (str_contains($query, '&archived=0') || ! str_contains($query, 'include_archived')) {
                    $count->where('is_active', 1);
                }
                $this->assertSame($count->countAllResults(), $data['pagination']['total']);
            }
        }

        $table = match ($registry) {
            'equipment' => 'clinic_equipment',
            'medicines' => 'clinic_medicines',
            'inventory' => 'clinic_inventory_items',
            'staff-schedules' => 'clinic_staff_schedules',
            'units' => 'facilities_bmg_units',
            'devices' => 'facilities_bmg_devices',
            'waste-categories' => 'facilities_waste_categories',
            'configs' => 'report_configurations',
        };
        $this->db->table('tenants')->insert([
            'name' => 'Archive scope isolation', 'slug' => 'scope-' . bin2hex(random_bytes(6)),
            'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $tenant = (int) $this->db->insertID();
        $this->db->table($table)->where('id', $archivedId)->update(['tenant_id' => $tenant]);
        $result = $this->authed($token, 'get', $route . '?archived=1&limit=50');
        $result->assertStatus(200);
        $data = $this->envelope($result)['data'];
        $this->assertNotContains($archivedId, array_map('intval', array_column($registry === 'configs' ? $data['items'] : $data, 'id')));
    }

    public function testDeviceRestoreIsAuditedDisabledUnboundAndRevokesOldCredential(): void
    {
        $admin = $this->login(['bmg_admin']);
        $device = $this->createBmgDevice();
        $id = $device['deviceId'];
        $route = 'api/v1/facilities/devices/' . $id;
        $result = $this->authed($admin['token'], 'delete', $route);
        $result->assertStatus(200);
        $oldHash = hash('sha256', $device['token']);
        $result = $this->authed($admin['token'], 'post', $route . '/unarchive');
        $result->assertStatus(200);
        $row = $this->db->table('facilities_bmg_devices')->where('id', $id)->get()->getRowArray();
        $this->assertNull($row['archived_at']);
        $this->assertNull($row['unit_id']);
        $this->assertSame('disabled', $row['status']);
        $this->assertNotSame($oldHash, $row['token_hash']);
        $this->assertNull($row['token_prefix']);
        $this->assertSame(1, $this->db->table('audit_outbox')->where('action_code', 'bmg.device_unarchived')->where('entity_id', $id)->countAllResults());

        // A retry must not disable an already restored and reprovisioned device.
        $hash = $row['token_hash'];
        $result = $this->authed($admin['token'], 'post', $route . '/unarchive');
        $result->assertStatus(200);
        $this->assertSame($hash, $this->db->table('facilities_bmg_devices')->where('id', $id)->get()->getRowArray()['token_hash']);
        $student = $this->login(['student']);
        $this->authed($student['token'], 'post', $route . '/unarchive')->assertStatus(403);
        $this->authed($admin['token'], 'post', 'api/v1/facilities/devices/999999999/unarchive')->assertStatus(404);

        $this->authed($admin['token'], 'delete', $route)->assertStatus(200);
        $before = $this->db->table('facilities_bmg_devices')->where('id', $id)->get()->getRowArray();
        $this->db->table('tenants')->insert([
            'name' => 'Restore isolation', 'slug' => 'restore-' . bin2hex(random_bytes(6)),
            'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $tenant = (int) $this->db->insertID();
        $foreign = $this->login(['bmg_admin']);
        $this->db->table('users')->where('id', $foreign['userId'])->update(['tenant_id' => $tenant]);
        $this->authed($foreign['token'], 'post', $route . '/unarchive')->assertStatus(404);
        $this->assertSame($before, $this->db->table('facilities_bmg_devices')->where('id', $id)->get()->getRowArray());
    }
}
