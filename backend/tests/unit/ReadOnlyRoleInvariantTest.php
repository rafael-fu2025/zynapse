<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Read-only role invariant (RBAC_SECURITY_REVIEW R7).
 *
 * Defense in depth: the `report_viewer` and `audit_reader` groups exist
 * to browse/export only. They must NEVER be granted a mutating
 * permission — a future edit that slips a write code into either group
 * should fail here rather than in production.
 *
 * `Config\AuthGroups` is not PSR-4 autoloadable (namespace `Config`), so
 * the class file is required directly. Its default `groupPermissions` are
 * read via reflection (getDefaultProperties) rather than instantiation,
 * because the CI4 BaseConfig constructor needs a booted framework.
 */
final class ReadOnlyRoleInvariantTest extends TestCase
{
    /** Code fragments that denote a mutating (non-read) capability. */
    private const WRITE_MARKERS = [
        '.write',
        '.manage',
        '.create',
        '.transition',
        '.record',
        '.record_output',
        '.soft_delete',
        '.configure',
        '.dispense',
    ];

    private const READ_ONLY_GROUPS = ['report_viewer', 'audit_reader'];

    /**
     * @return array<string, array<int, string>>
     */
    private function groupPermissions(): array
    {
        require_once __DIR__ . '/../../app/Config/AuthGroups.php';
        $defaults = (new \ReflectionClass(\Config\AuthGroups::class))->getDefaultProperties();
        /** @var array<string, array<int, string>> $gp */
        $gp = $defaults['groupPermissions'] ?? [];

        return $gp;
    }

    public function testReadOnlyGroupsHoldNoWriteCodes(): void
    {
        $gp = $this->groupPermissions();

        foreach (self::READ_ONLY_GROUPS as $group) {
            $this->assertArrayHasKey($group, $gp, "read-only group '{$group}' must be defined");

            foreach ($gp[$group] as $code) {
                foreach (self::WRITE_MARKERS as $marker) {
                    $this->assertStringNotContainsString(
                        $marker,
                        $code,
                        "read-only group '{$group}' must not hold mutating code '{$code}'",
                    );
                }
            }
        }
    }

    public function testReadOnlyGroupsAreNonEmpty(): void
    {
        $gp = $this->groupPermissions();

        foreach (self::READ_ONLY_GROUPS as $group) {
            $this->assertNotEmpty($gp[$group] ?? [], "read-only group '{$group}' should grant read/export codes");
        }
    }
}
