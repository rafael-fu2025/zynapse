<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Proves the feature harness itself works before any behavioural test
 * relies on it. If this file fails, nothing else in tests/Feature is
 * trustworthy — so it asserts the preconditions rather than app logic:
 *
 *   1. The kernel booted with ENVIRONMENT='testing'.
 *   2. The DB connection resolved to the `tests` group, NOT the dev schema
 *      (the guard that stops DatabaseTestTrait from wiping dev data).
 *   3. Migrations actually ran against that schema.
 *   4. The route table loaded and a real request round-trips through the
 *      filter pipeline in the canonical envelope shape.
 */
final class BootstrapSmokeTest extends FeatureTestCase
{
    public function testEnvironmentIsTesting(): void
    {
        $this->assertSame('testing', ENVIRONMENT);
    }

    public function testDatabaseGroupIsTestsAndNotTheDevSchema(): void
    {
        $config = config('Database');

        $this->assertSame('tests', $config->defaultGroup);

        $db = db_connect();
        $this->assertSame(
            $config->tests['database'],
            $db->getDatabase(),
            'The live connection must be the `tests` schema.',
        );
        $this->assertNotSame(
            $config->default['database'],
            $db->getDatabase(),
            'REFUSE: the test connection resolved to the dev schema — DatabaseTestTrait would destroy dev data.',
        );
    }

    public function testMigrationsRanAgainstTheTestSchema(): void
    {
        $db = db_connect();

        // A representative spread: Shield identity, clinic, and BMG.
        foreach (['users', 'auth_identities', 'clinic_appointments', 'facilities_bmg_batches'] as $table) {
            $this->assertTrue(
                $db->tableExists($table),
                sprintf('Expected table `%s` to exist after migration.', $table),
            );
        }
    }

    public function testRbacSeedRan(): void
    {
        // createUser() resolves group names against auth_groups, so the
        // seed is a hard precondition for every authorization test.
        $count = db_connect()->table('auth_groups')->countAllResults();
        $this->assertGreaterThan(0, $count, 'PermissionsAndGroupsSeeder did not populate auth_groups.');
    }

    public function testUnauthenticatedRequestIsRejectedInEnvelopeShape(): void
    {
        // Exercises the router + api_auth filter + the exception handler's
        // envelope rendering in one shot.
        $result = $this->call('get', 'api/v1/auth/me');

        $result->assertStatus(401);
        $body = $this->envelope($result);
        $this->assertFalse($body['success']);
        $this->assertNull($body['data']);
        $this->assertIsArray($body['errors']);
    }

    public function testLoginHelperReturnsAWorkingAccessToken(): void
    {
        $session = $this->login(['admin']);

        $this->assertNotSame('', $session['token']);

        $me = $this->authed($session['token'], 'get', 'api/v1/auth/me');
        $me->assertStatus(200);

        $body = $this->envelope($me);
        $this->assertTrue($body['success']);
        $this->assertSame($session['email'], $body['data']['email'] ?? null);
    }
}
