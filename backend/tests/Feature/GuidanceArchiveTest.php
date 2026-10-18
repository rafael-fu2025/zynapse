<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

/** Pattern A isolates archived rows without changing publication state. */
final class GuidanceArchiveTest extends FeatureTestCase
{
    public static function resources(): array
    {
        return [
            'announcements' => ['announcements', 'guidance_announcements', 'announcement', ['title' => 'Archive notice', 'body' => 'Retain this notice.']],
            'services' => ['services', 'guidance_services', 'service', ['name' => 'Archive service']],
            'surveys' => ['surveys', 'surveys', 'survey', ['title' => 'Archive survey']],
        ];
    }

    #[DataProvider('resources')]
    public function testExclusiveListAndIdempotentArchiveRestore(string $resource, string $table, string $event, array $input): void
    {
        $admin = $this->login(['guidance_admin']);
        $student = $this->login([]);
        db_connect()->table('users')->where('id', $student['userId'])->update(['kind' => 'student']);
        $base = 'api/v1/counselling/' . $resource;
        if ($resource === 'services') {
            $input['code'] = 'archive_' . bin2hex(random_bytes(5));
        }
        $created = $this->authed($admin['token'], 'post', $base, $input);
        $created->assertStatus(201);
        $row = $this->envelope($created)['data'];
        $id = (int) $row['id'];
        $this->assertArrayHasKey('archived_at', $row);
        $this->assertNull($row['archived_at']);
        $original = db_connect()->table($table)->where('id', $id)->get()->getRowArray();

        // A live fixture proves that Show archived excludes active rows.
        if ($resource === 'services') {
            $input['code'] .= '_live';
        }
        $live = $this->authed($admin['token'], 'post', $base, $input);
        $live->assertStatus(201);
        $liveId = (int) $this->envelope($live)['data']['id'];

        foreach (['unarchive', 'archive', 'archive'] as $action) {
            $result = $this->authed($admin['token'], 'post', $base . '/' . $id . '/' . $action);
            $result->assertStatus(200);
            $this->assertSame(['id' => $id, 'archived' => $action === 'archive'], $this->envelope($result)['data']);
        }
        $stamp = db_connect()->table($table)->where('id', $id)->get()->getRowArray()['archived_at'];
        $this->assertIsString($stamp);
        foreach (['', '?archived=0', '?archived=invalid'] as $query) {
            $list = $this->authed($admin['token'], 'get', $base . $query);
            $list->assertStatus(200);
            $rows = array_column($this->envelope($list)['data'], null, 'id');
            $this->assertArrayNotHasKey($id, $rows);
            $this->assertArrayHasKey($liveId, $rows);
            $this->assertNull($rows[$liveId]['archived_at']);
        }
        $list = $this->authed($admin['token'], 'get', $base . '?archived=1');
        $list->assertStatus(200);
        $rows = array_column($this->envelope($list)['data'], null, 'id');
        $this->assertSame($stamp, $rows[$id]['archived_at']);
        $this->assertArrayNotHasKey($liveId, $rows);
        if (isset($row['status'])) {
            $this->assertSame($row['status'], $rows[$id]['status']);
        }
        $feed = $this->authed($student['token'], 'get', 'api/v1/me/guidance/' . $resource);
        $feed->assertStatus(200);
        $this->assertNotContains($id, array_column($this->envelope($feed)['data'], 'id'));

        // Mutations must not bypass the archive guard while the row is hidden.
        $this->authed($admin['token'], 'post', $base . '/' . $id . '/update', $input)->assertStatus(404);
        foreach ([1, 2] as $retry) {
            $restored = $this->authed($admin['token'], 'post', $base . '/' . $id . '/unarchive');
            $restored->assertStatus(200);
            $this->assertSame(['id' => $id, 'archived' => false], $this->envelope($restored)['data']);
        }
        $after = db_connect()->table($table)->where('id', $id)->get()->getRowArray();
        unset($original['updated_at'], $after['updated_at']);
        $this->assertSame($original, $after, 'Restore must retain every business field.');
        $active = $this->authed($admin['token'], 'get', $base);
        $active->assertStatus(200);
        $rows = array_column($this->envelope($active)['data'], null, 'id');
        $this->assertNull($rows[$id]['archived_at']);
        $archivedList = $this->authed($admin['token'], 'get', $base . '?archived=1');
        $archivedList->assertStatus(200);
        $this->assertNotContains($id, array_column($this->envelope($archivedList)['data'], 'id'));
        foreach (['archived', $resource === 'surveys' ? 'unarchived' : 'restored'] as $suffix) {
            $this->assertSame(1, db_connect()->table('audit_outbox')
                ->where('action_code', 'guidance.' . $event . '_' . $suffix)
                ->where('entity_id', $id)->countAllResults(), 'Retries must not duplicate audit events.');
        }
    }

    #[DataProvider('resources')]
    public function testArchiveRoutesEnforcePermissionAndHideMissingRows(string $resource, string $table, string $event, array $input): void
    {
        $admin = $this->login(['guidance_admin']);
        $outsider = $this->login(['clinic_admin']);
        $base = 'api/v1/counselling/' . $resource;
        if ($resource === 'services') {
            $input['code'] = 'guard_' . bin2hex(random_bytes(5));
        }
        $created = $this->authed($admin['token'], 'post', $base, $input);
        $created->assertStatus(201);
        $id = (int) $this->envelope($created)['data']['id'];
        foreach (['archive', 'unarchive'] as $action) {
            $denied = $this->authed($outsider['token'], 'post', $base . '/' . $id . '/' . $action);
            $denied->assertStatus(403);
            $this->assertErrorCode('rbac.permission_denied:counselling.' . $resource . '.manage', $denied);
            $missing = $this->authed($admin['token'], 'post', $base . '/0/' . $action);
            $missing->assertStatus(404);
            $this->assertErrorCode('resource.not_found', $missing);
        }
        $this->assertNull(db_connect()->table($table)->where('id', $id)->get()->getRowArray()['archived_at']);
        $this->authed($outsider['token'], 'get', $base . '?archived=1')->assertStatus(403);
    }

    #[DataProvider('resources')]
    public function testArchiveListsAndMutationsCannotCrossTenants(string $resource, string $table, string $event, array $input): void
    {
        $admin = $this->login(['guidance_admin']);
        $base = 'api/v1/counselling/' . $resource;
        if ($resource === 'services') {
            $input['code'] = 'tenant_' . bin2hex(random_bytes(5));
        }
        $created = $this->authed($admin['token'], 'post', $base, $input);
        $created->assertStatus(201);
        $id = (int) $this->envelope($created)['data']['id'];
        $this->authed($admin['token'], 'post', $base . '/' . $id . '/archive')->assertStatus(200);
        $before = db_connect()->table($table)->where('id', $id)->get()->getRowArray();

        db_connect()->table('tenants')->insert([
            'name' => 'Archive isolation', 'slug' => 'archive-' . bin2hex(random_bytes(6)),
            'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $tenant = (int) db_connect()->insertID();
        $foreign = $this->login(['guidance_admin']);
        db_connect()->table('users')->where('id', $foreign['userId'])->update(['tenant_id' => $tenant]);
        foreach (['', '?archived=1'] as $query) {
            $list = $this->authed($foreign['token'], 'get', $base . $query);
            $list->assertStatus(200);
            $this->assertNotContains($id, array_column($this->envelope($list)['data'], 'id'));
        }
        foreach (['archive', 'unarchive'] as $action) {
            $result = $this->authed($foreign['token'], 'post', $base . '/' . $id . '/' . $action);
            $result->assertStatus(404);
            $this->assertErrorCode('resource.not_found', $result);
        }
        $this->assertSame($before, db_connect()->table($table)->where('id', $id)->get()->getRowArray());
        $this->authed($admin['token'], 'post', $base . '/' . $id . '/unarchive')->assertStatus(200);
    }

    public function testArchivedServiceCodeRemainsReservedAndRestorable(): void
    {
        $admin = $this->login(['guidance_admin']);
        $base = 'api/v1/counselling/services';
        $input = ['name' => 'Reserved service', 'code' => 'reserved_' . bin2hex(random_bytes(5)), 'is_active' => false];
        $created = $this->authed($admin['token'], 'post', $base, $input);
        $created->assertStatus(201);
        $id = (int) $this->envelope($created)['data']['id'];
        $this->authed($admin['token'], 'post', $base . '/' . $id . '/archive')->assertStatus(200);
        $duplicate = $this->authed($admin['token'], 'post', $base, $input);
        $duplicate->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $duplicate);
        $this->authed($admin['token'], 'post', $base . '/' . $id . '/unarchive')->assertStatus(200);
        $row = db_connect()->table('guidance_services')->where('id', $id)->get()->getRowArray();
        $this->assertNull($row['archived_at']);
        $this->assertSame(0, (int) $row['is_active'], 'Restoring must not activate a disabled service.');
    }
}
