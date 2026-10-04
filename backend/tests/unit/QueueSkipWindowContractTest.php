<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\QueueSkipWindow;
use Modules\Clinic\Services\QueueService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * QueueSkipWindowContractTest — the recall window's numbers and states
 * must stay in lockstep across three files that cannot import each
 * other at runtime:
 *
 *   1. `QueueSkipWindow::SKIP_WINDOW_MINUTES` — the migration that
 *      documents the window (and whose index the sweep depends on).
 *   2. `QueueService::SKIP_WINDOW_MINUTES` — the service that stamps
 *      `skip_deadline_at` and enforces expiry.
 *   3. `frontend/src/lib/queueSkip.ts` — the SPA countdown constant.
 *
 * Why this test exists: if the window drifts (e.g. someone changes the
 * service to 30 minutes while the SPA still renders 60), the countdown
 * hits zero half an hour before the backend actually resolves the
 * visit, and staff watch a stuck `00:00` — the exact class of bug the
 * panel revision was raised to fix. The three-way parity is cheap to
 * assert and impossible to notice by eye.
 *
 * Also guards the transition table: `return` and `recall` must be
 * reachable only from `skipped`, and the sweep must key off
 * `skip_deadline_at` (not `skipped_at`), so a refactor cannot quietly
 * move the deadline math somewhere the SPA cannot see it.
 *
 * Pure / source-scanning — no DB, mirroring `QueueFifoContractTest`.
 */
final class QueueSkipWindowContractTest extends TestCase
{
    private const WINDOW_MINUTES = 60;

    private function serviceSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../../app/Modules/Clinic/Services/QueueService.php');
        $this->assertIsString($source);
        return $source;
    }

    public function testMigrationAndServiceAgreeOnTheWindowLength(): void
    {
        $ref = new ReflectionClass(QueueSkipWindow::class);
        $const = $ref->getReflectionConstant('SKIP_WINDOW_MINUTES');
        $this->assertNotNull($const, 'Migration must declare SKIP_WINDOW_MINUTES.');
        $this->assertSame(self::WINDOW_MINUTES, $const->getValue());

        $serviceRef = new ReflectionClass(QueueService::class);
        $serviceConst = $serviceRef->getReflectionConstant('SKIP_WINDOW_MINUTES');
        $this->assertNotNull($serviceConst, 'QueueService must declare SKIP_WINDOW_MINUTES.');
        $this->assertSame(self::WINDOW_MINUTES, $serviceConst->getValue());
    }

    public function testFrontendCountdownUsesTheSameWindow(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../frontend/src/lib/queueSkip.ts',
        );
        $this->assertIsString($source, 'frontend/src/lib/queueSkip.ts must exist — the SPA renders the countdown from it.');

        // The SPA constant is the countdown's fallback when a row lacks
        // a deadline; it must not disagree with the backend.
        $this->assertMatchesRegularExpression(
            '/SKIP_WINDOW_MINUTES\s*=\s*' . self::WINDOW_MINUTES . '\b/',
            $source,
            'The frontend SKIP_WINDOW_MINUTES must equal the backend window.',
        );
    }

    public function testReturnAndRecallAreReachableOnlyFromSkipped(): void
    {
        $source = $this->serviceSource();

        $ref = new ReflectionClass(QueueService::class);
        $transitions = $ref->getReflectionConstant('TRANSITIONS');
        $this->assertNotNull($transitions);
        /** @var array<string, array<int, string>> $map */
        $map = $transitions->getValue();

        $this->assertSame(['skipped'], $map['return'] ?? null, '`return` must only accept a skipped entry.');
        $this->assertSame(['skipped'], $map['recall'] ?? null, '`recall` must only accept a skipped entry.');

        // Skip stays reachable from `called` only — it must not become a
        // way to eject an in-session patient.
        $this->assertSame(['called'], $map['skip'] ?? null);

        // The deadline, not the skip timestamp, is what the sweep reads.
        $this->assertStringContainsString('skip_deadline_at <=', $source);
        $this->assertStringContainsString('skip_deadline_at IS NOT NULL', $source);
    }

    public function testSweepResolvesThroughTheSystemNoShowCascade(): void
    {
        $source = $this->serviceSource();

        // The expiry path must reuse the shared no-show cascade (so the
        // appointment and audit behaviour matches the manual action)
        // rather than hand-rolling its own encounter update.
        $this->assertStringContainsString('markNoShowSystem(', $source);
        $this->assertStringContainsString('sweepExpiredSkips', $source);

        // Duplicate suppression: the candidate is re-validated under a
        // row lock before anything is written.
        $this->assertStringContainsString('selectForUpdate(', $source);

        // The automatic no-show must notify clinic staff, and only via
        // the permission fan-out (never a hardcoded recipient).
        $this->assertStringContainsString("'queue.skip_expired'", $source);
        $this->assertStringContainsString("enqueueToPermissions(", $source);
    }

    public function testSkipStampAndClearArePaired(): void
    {
        $source = $this->serviceSource();

        // Skip opens the window — timestamp, deadline, and the cleared
        // return stamp, in the same update as the status flip.
        $this->assertMatchesRegularExpression(
            "/\\\$update\\['skipped_at'\\]\\s*=\\s*\\\$now;/",
            $source,
            'skip must stamp skipped_at in the transition update.',
        );
        $this->assertMatchesRegularExpression(
            "/\\\$update\\['skip_deadline_at'\\]\\s*=\\s*\\\$this->addMinutes\\(\\\$now, self::SKIP_WINDOW_MINUTES\\);/",
            $source,
            'skip must derive the deadline from the shared window constant.',
        );
        // … and clears any previous return so a re-skip is not rendered
        // as already-returned.
        $this->assertMatchesRegularExpression(
            "/\\\$update\\['returned_at'\\]\\s*=\\s*null;/",
            $source,
            'skip must clear returned_at so a re-skip starts a fresh episode.',
        );
    }
}
