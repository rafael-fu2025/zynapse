<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Guidance content (2026-09 parity plan Phase A) — announcements +
 * CMO service catalogue, end-to-end through the real routes:
 *
 *   - guidance_admin CRUD with derived publish status + soft archive;
 *   - unit admins cannot touch guidance content (403, server-enforced);
 *   - validation envelope for bad payloads;
 *   - the student feed (/me/guidance/*) matches audience + publish
 *     windows (new vs continuing via the Aug-1 academic-year boundary);
 *   - the CMO 9 s.2013 service catalogue is seeded and bookable entries
 *     carry a queue_destination.
 */
final class GuidanceContentTest extends FeatureTestCase
{
    public function testGuidanceAdminManagesAnnouncements(): void
    {
        $actor = $this->login(['guidance_admin']);

        $created = $this->authed($actor['token'], 'post', 'api/v1/counselling/announcements', [
            'title'       => 'Guidance week ' . bin2hex(random_bytes(3)),
            'body'        => 'Career talks every Wednesday of September.',
            'audience'    => 'all',
            'action_url'  => 'https://docs.google.com/forms/d/example',
            'action_label' => 'Sign up',
            'is_required' => true,
        ]);
        $created->assertStatus(201);
        $row = $this->envelope($created)['data'];
        $id = (int) $row['id'];
        $this->assertSame('live', $row['status'], 'No window = live immediately.');

        $updated = $this->authed($actor['token'], 'post', 'api/v1/counselling/announcements/' . $id . '/update', [
            'title'       => 'Renamed',
            'body'        => 'Updated body.',
            'audience'    => 'continuing_students',
        ]);
        $updated->assertStatus(200);
        $this->assertSame('continuing_students', $this->envelope($updated)['data']['audience']);

        // Audit outbox captured the lifecycle (same-transaction enqueue).
        $audit = db_connect()->table('audit_outbox')
            ->where('action_code', 'guidance.announcement_created')
            ->where('entity_id', $id)
            ->countAllResults();
        $this->assertSame(1, $audit, 'guidance.announcement_created must be audited.');

        $archived = $this->authed($actor['token'], 'post', 'api/v1/counselling/announcements/' . $id . '/archive');
        $archived->assertStatus(200);

        $list = $this->authed($actor['token'], 'get', 'api/v1/counselling/announcements');
        $list->assertStatus(200);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($list)['data'] ?? []);
        $this->assertNotContains($id, $ids, 'Archived announcements leave the staff list.');
    }

    public function testUnitAdminCannotManageGuidanceContent(): void
    {
        $actor = $this->login(['clinic_admin']);

        $read = $this->authed($actor['token'], 'get', 'api/v1/counselling/announcements');
        $read->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:counselling.announcements.manage', $read);

        $write = $this->authed($actor['token'], 'post', 'api/v1/counselling/services', [
            'name' => 'Sneaky Service',
        ]);
        $write->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:counselling.services.manage', $write);
    }

    public function testAnnouncementValidationEnvelope(): void
    {
        $actor = $this->login(['guidance_admin']);

        $missing = $this->authed($actor['token'], 'post', 'api/v1/counselling/announcements', [
            'title' => '',
            'body'  => '',
            'audience' => 'not-an-audience',
        ]);
        $missing->assertStatus(422);
        $fields = array_map(
            static fn (array $e): string => (string) ($e['field'] ?? ''),
            $this->envelope($missing)['errors'] ?? [],
        );
        $this->assertContains('title', $fields);
        $this->assertContains('body', $fields);
        $this->assertContains('audience', $fields);

        $badUrl = $this->authed($actor['token'], 'post', 'api/v1/counselling/announcements', [
            'title' => 'Valid title',
            'body'  => 'Body',
            'action_url' => 'javascript:alert(1)',
        ]);
        $badUrl->assertStatus(422);
        $fields2 = array_map(
            static fn (array $e): string => (string) ($e['field'] ?? ''),
            $this->envelope($badUrl)['errors'] ?? [],
        );
        $this->assertContains('action_url', $fields2, 'javascript: URLs are rejected with a field error.');
    }

    public function testStudentFeedMatchesAudienceAndWindows(): void
    {
        $admin = $this->login(['guidance_admin']);
        $suffix = bin2hex(random_bytes(3));

        $make = function (string $audience, array $extra = []) use ($admin, $suffix): int {
            $res = $this->authed($admin['token'], 'post', 'api/v1/counselling/announcements', array_merge([
                'title'    => "Feed {$audience} {$suffix}",
                'body'     => 'Feed body',
                'audience' => $audience,
            ], $extra));
            $res->assertStatus(201);
            return (int) ($this->envelope($res)['data']['id'] ?? 0);
        };

        $allId        = $make('all');
        $newId        = $make('new_students');
        $continuingId = $make('continuing_students');
        $graduatingId = $make('graduating_students');
        // Expired (unpublished in the past) and scheduled (future) are hidden.
        $make('new_students', ['unpublish_at' => '2020-01-01 00:00:00']);
        $make('new_students', ['publish_at' => '2030-01-01 00:00:00']);

        // A NEW student (created inside the current academic year) —
        // login() creates the user, then we stamp the kind; the feed
        // re-reads the row per request.
        $newStudent = $this->login([]);
        db_connect()->table('users')->where('id', $newStudent['userId'])->update([
            'kind'       => 'student',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        // A CONTINUING student (created well before this academic year).
        $oldStudent = $this->login([]);
        db_connect()->table('users')->where('id', $oldStudent['userId'])->update([
            'kind'       => 'student',
            'created_at' => '2020-01-01 00:00:00',
        ]);

        $feedNew = $this->authed($newStudent['token'], 'get', 'api/v1/me/guidance/announcements');
        $feedNew->assertStatus(200);
        $newIds = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($feedNew)['data'] ?? []);
        $this->assertContains($allId, $newIds);
        $this->assertContains($newId, $newIds);
        $this->assertContains($graduatingId, $newIds, 'graduating targeting matches all students until year-level data lands (Phase B).');
        $this->assertNotContains($continuingId, $newIds);

        $feedOld = $this->authed($oldStudent['token'], 'get', 'api/v1/me/guidance/announcements');
        $feedOld->assertStatus(200);
        $oldIds = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($feedOld)['data'] ?? []);
        $this->assertContains($allId, $oldIds);
        $this->assertContains($continuingId, $oldIds);
        $this->assertNotContains($newId, $oldIds);
    }

    public function testServiceCatalogueSeededAndManaged(): void
    {
        $actor = $this->login(['guidance_admin']);

        $list = $this->authed($actor['token'], 'get', 'api/v1/counselling/services');
        $list->assertStatus(200);
        $rows = $this->envelope($list)['data'] ?? [];
        $this->assertGreaterThanOrEqual(9, count($rows), 'The 9 CMO 9 s.2013 categories must be seeded.');

        $byCode = [];
        foreach ($rows as $r) {
            $byCode[(string) $r['code']] = $r;
        }
        $this->assertArrayHasKey('counseling', $byCode);
        $this->assertSame('counselling', $byCode['counseling']['queue_destination'], 'Counseling Service is bookable into the guidance queue.');
        $this->assertArrayHasKey('individual_inventory', $byCode);
        $this->assertNull($byCode['individual_inventory']['queue_destination']);

        // Custom service: create → duplicate code 409 → archive hides it.
        $code = 'custom_' . bin2hex(random_bytes(3));
        $created = $this->authed($actor['token'], 'post', 'api/v1/counselling/services', [
            'name' => 'Custom Test Service',
            'code' => $code,
            'queue_destination' => 'counselling',
        ]);
        $created->assertStatus(201);
        $createdId = (int) ($this->envelope($created)['data']['id'] ?? 0);

        $dupe = $this->authed($actor['token'], 'post', 'api/v1/counselling/services', [
            'name' => 'Another Custom Test Service',
            'code' => $code,
        ]);
        $dupe->assertStatus(409);

        $this->authed($actor['token'], 'post', 'api/v1/counselling/services/' . $createdId . '/archive')->assertStatus(200);

        $after = $this->authed($actor['token'], 'get', 'api/v1/counselling/services');
        $afterIds = array_map(static fn (array $r): int => (int) $r['id'], $this->envelope($after)['data'] ?? []);
        $this->assertNotContains($createdId, $afterIds);
    }

    public function testStudentFeedServicesOnlyActive(): void
    {
        $student = $this->login([]);
        db_connect()->table('users')->where('id', $student['userId'])->update(['kind' => 'student']);

        $feed = $this->authed($student['token'], 'get', 'api/v1/me/guidance/services');
        $feed->assertStatus(200);
        $rows = $this->envelope($feed)['data'] ?? [];
        $this->assertGreaterThanOrEqual(9, count($rows));
        foreach ($rows as $r) {
            $this->assertTrue((bool) $r['is_active'], 'The portal feed exposes only active services.');
        }
    }
}
