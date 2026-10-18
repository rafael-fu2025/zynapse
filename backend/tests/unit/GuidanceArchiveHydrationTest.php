<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Modules\Counselling\Services\GuidanceContentService;
use Modules\Counselling\Services\SurveyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GuidanceArchiveHydrationTest extends TestCase
{
    public static function archiveStates(): array
    {
        return ['active' => [null], 'archived' => ['2026-10-17 03:04:05']];
    }

    public static function listScopes(): array
    {
        $cases = [];
        foreach ([
            [GuidanceContentService::class, 'listAnnouncements', 'guidance_announcements a', 'a'],
            [GuidanceContentService::class, 'listServices', 'guidance_services s', 's'],
            [SurveyService::class, 'listSurveys', 'surveys s', 's'],
        ] as [$class, $method, $table, $alias]) {
            foreach ([false, true] as $includeArchived) {
                $cases[$method . ($includeArchived ? ' archived' : ' active')] = [$class, $method, $table, $alias, $includeArchived];
            }
        }
        return $cases;
    }

    #[DataProvider('listScopes')]
    public function testStaffListsIsolateArchiveScopeAndPreserveTenantScope(string $class, string $method, string $table, string $alias, bool $includeArchived): void
    {
        $result = $this->createMock(BaseResult::class);
        $result->method('getResultArray')->willReturn([]);
        $builder = $this->getMockBuilder(BaseBuilder::class)->disableOriginalConstructor()->getMock();
        $builder->method('select')->willReturnSelf();
        $builder->method('orderBy')->willReturnSelf();
        $builder->method('get')->willReturn($result);
        $where = [];
        $builder->expects($this->exactly(2))->method('where')
            ->willReturnCallback(function ($column, $value = null) use (&$where, $builder) {
                $where[] = [$column, $value];
                return $builder;
            });
        $db = $this->createMock(BaseConnection::class);
        $db->method('table')->with($table)->willReturn($builder);
        $reflection = new ReflectionClass($class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('db')->setValue($service, $db);
        $previousTenant = CurrentTenant::id();
        CurrentTenant::set(73);
        try {
            $this->assertSame([], $service->$method($includeArchived));
            $expected = [[$alias . '.tenant_id', 73]];
            $expected[] = [$alias . ($includeArchived ? '.archived_at IS NOT NULL' : '.archived_at'), null];
            $this->assertSame($expected, $where);
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }

    #[DataProvider('archiveStates')]
    public function testGuidanceHydrationExposesArchiveStampWithoutChangingPublishOrActiveState(?string $stamp): void
    {
        $reflection = new ReflectionClass(GuidanceContentService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $common = ['id' => '42', 'created_at' => '2026-10-01 00:00:00', 'archived_at' => $stamp];
        $announcement = $reflection->getMethod('hydrateAnnouncement')->invoke($service, $common + [
            'title' => 'Notice', 'body' => 'Body', 'audience' => 'all', 'action_url' => null,
            'action_label' => null, 'is_required' => '1', 'publish_at' => null, 'unpublish_at' => null,
            'updated_at' => '2026-10-01 00:00:00',
        ]);
        $this->assertArrayHasKey('archived_at', $announcement);
        $this->assertSame($stamp, $announcement['archived_at']);
        $this->assertSame('live', $announcement['status']);
        $serviceRow = $reflection->getMethod('hydrateService')->invoke($service, $common + [
            'code' => 'testing', 'name' => 'Testing', 'description' => null, 'cmo_reference' => null,
            'sort_order' => '1', 'queue_destination' => null, 'is_active' => '0',
        ]);
        $this->assertArrayHasKey('archived_at', $serviceRow);
        $this->assertSame($stamp, $serviceRow['archived_at']);
        $this->assertFalse($serviceRow['is_active']);
    }

    #[DataProvider('archiveStates')]
    public function testSurveyHydrationExposesArchiveStampAndPreservesVersionAndWindowStatus(?string $stamp): void
    {
        $result = $this->createMock(BaseResult::class);
        $result->method('getRowArray')->willReturn(['id' => '91', 'version_no' => '1']);
        $builder = $this->getMockBuilder(BaseBuilder::class)->disableOriginalConstructor()->getMock();
        $builder->method('where')->willReturnSelf();
        $builder->method('orderBy')->willReturnSelf();
        $builder->method('get')->willReturn($result);
        $builder->method('countAllResults')->willReturn(1);
        $db = $this->createMock(BaseConnection::class);
        $db->method('table')->with('survey_versions')->willReturn($builder);
        $reflection = new ReflectionClass(SurveyService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('db')->setValue($service, $db);
        $row = $reflection->getMethod('hydrateSurvey')->invoke($service, [
            'id' => '42', 'title' => 'Survey', 'description' => null, 'category' => 'survey',
            'audience' => 'all', 'is_required' => '1', 'publish_at' => null,
            'close_at' => '2020-01-01 00:00:00', 'created_at' => '2019-01-01 00:00:00',
            'archived_at' => $stamp,
        ]);
        $this->assertArrayHasKey('archived_at', $row);
        $this->assertSame($stamp, $row['archived_at']);
        $this->assertSame('closed', $row['status'], 'Archive is independent of publication state.');
        $this->assertSame(91, $row['version_id']);
        $this->assertSame(1, $row['version_no']);
    }
}
