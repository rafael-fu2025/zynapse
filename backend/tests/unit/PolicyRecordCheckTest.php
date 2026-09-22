<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PolicyRecordCheckTest — ratchet for BasePolicy::canOnRecord overrides.
 *
 * BasePolicy::canOnRecord defaults to `true` (fail-open). That default is
 * intentional for policies whose domain has no per-record ownership semantics,
 * but it means a new policy that forgets to override the method silently grants
 * record-level access to every holder of the module permission.
 *
 * This test scans every concrete BasePolicy subclass and requires each to:
 *   a) override `canOnRecord` (meaning the author made an explicit decision), OR
 *   b) carry a `@noRecordCheck` docblock tag on the CLASS, documenting the
 *      deliberate choice to use the default.
 *
 * The combination ensures no new policy can ship with an accidentally-open
 * record gate without the CI catching it.
 *
 * Inspired by the TenantScopeFitnessTest pattern: heuristic source scan,
 * one-sided assertion, zero false negatives by design.
 */
final class PolicyRecordCheckTest extends TestCase
{
    /**
     * Every concrete BasePolicy subclass must either override `canOnRecord`
     * or carry a `@noRecordCheck` class-level docblock tag.
     */
    public function testEveryPolicyExplicitlyAddressesRecordCheck(): void
    {
        $appRoot = $this->appRoot();
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Only inspect classes that extend BasePolicy.
            if (! preg_match('/\bextends\s+BasePolicy\b/', $source)) {
                continue;
            }

            // Skip BasePolicy itself (abstract).
            if (preg_match('/\babstract\s+class\b/', $source)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr(
                $file->getPathname(),
                strlen($appRoot) + 1,
            ));

            $overrides    = str_contains($source, 'function canOnRecord');
            $documented   = str_contains($source, '@noRecordCheck');

            if (! $overrides && ! $documented) {
                $violations[] = $relative;
            }
        }

        $this->assertSame([], $violations, sprintf(
            "The following BasePolicy subclass(es) neither override `canOnRecord()` nor carry a\n"
            . "`@noRecordCheck` class docblock tag. Each policy must make an explicit choice about\n"
            . "record-level ownership — the base default is fail-open (`return true`).\n\n"
            . "Violations:\n%s",
            implode("\n", $violations),
        ));
    }

    private function appRoot(): string
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app';
        $this->assertDirectoryExists($root);
        return (string) realpath($root);
    }
}
