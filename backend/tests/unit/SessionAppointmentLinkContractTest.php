<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SessionAppointmentLinkContractTest — resolving a booking to its session
 * (2026-09-23).
 *
 * Why: the **Sessions & Notes** tab was retired and its content moved into each
 * patient's booking. Expanding a row in the Appointments table now has to
 * resolve an appointment to the session that belongs to it, and nothing in the
 * schema links the two directly — the hop is
 *
 *     counselling_appointments.id
 *       → counselling_queue_entries.counselling_appointment_id
 *         → counselling_queue_entries.counselling_session_id
 *           → counselling_sessions.id
 *
 * Three properties are load-bearing:
 *
 *   1. **It is a filter, not a lookup.** A re-opened slot can legitimately own
 *      more than one session, so the endpoint returns a list and the caller
 *      takes the newest. Encoding it as a single-row lookup would silently drop
 *      the others.
 *   2. **The link is resolved as a separate id query, not a join.** The keyset
 *      paginator orders on bare `created_at` / `id`, and `counselling_queue_entries`
 *      carries both columns — joining would make every clause ambiguous.
 *   3. **An empty result short-circuits.** A booking with no session must
 *      return an empty page, not fall through to "all sessions".
 *
 * The failure mode this guards against is a privacy one: if the filter is
 * dropped or the early return removed, expanding a booking would list the whole
 * tenant's sessions — including patients the viewer did not ask about.
 *
 * Mirrors `GuidanceDayBoardContractTest`: single-line, alignment-free needles
 * only, because the backend sources are CRLF and these fixtures are LF.
 */
final class SessionAppointmentLinkContractTest extends TestCase
{
    public function testListSessionsAcceptsAnAppointmentFilter(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/CounsellingService.php');

        $this->assertStringContainsString(
            'public function listSessions(?string $cursor, int $limit, ?int $appointmentId = null, ?string $patientSchoolId = null, bool $archived = false): array',
            $service,
            'The appointment and patient filters must be optional, so the unfiltered list keeps working.',
        );
        $this->assertStringContainsString('if ($appointmentId !== null) {', $service);
    }

    /**
     * The per-patient guidance record filter (2026-10) must 404 on an
     * unknown identifier: falling through to the unfiltered builder would
     * list the whole tenant's sessions for a patient the caller did not
     * name — the same privacy failure mode as the appointment filter.
     */
    public function testThePatientFilterRejectsUnknownIdentifiers(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/CounsellingService.php');

        $this->assertStringContainsString('if ($patientSchoolId !== null) {', $service);
        $this->assertStringContainsString("->where('counselling_sessions.patient_school_id', \$patientSchoolId)", $service);
        $this->assertStringContainsString('resource.not_found', $service);
    }

    public function testTheLinkGoesThroughTheQueueEntry(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/CounsellingService.php');

        // The only path from an appointment to a session.
        $this->assertStringContainsString("->where('counselling_appointment_id', \$appointmentId)", $service);
        $this->assertStringContainsString("->where('counselling_session_id IS NOT NULL', null, false)", $service);
        $this->assertStringContainsString("whereIn('counselling_sessions.id', \$sessionIds)", $service);
    }

    public function testABookingWithNoSessionReturnsNothingRatherThanEverything(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/CounsellingService.php');

        // Without this early return, an appointment whose sessions list is empty
        // would skip the `whereIn` entirely and return the tenant's whole
        // session table.
        $this->assertStringContainsString(
            "if (\$sessionIds === []) {",
            $service,
            'An empty session set must short-circuit.',
        );
        $this->assertStringContainsString(
            "return ['data' => [], 'next' => null, 'count' => 0];",
            $service,
            'The short-circuit must return an empty page.',
        );
    }

    public function testTheLinkIsResolvedWithoutAJoin(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/CounsellingService.php');

        // The id resolution must not join into the paginated builder: both
        // tables carry `created_at` and `id`, and the keyset clauses are
        // unqualified.
        $this->assertStringContainsString(
            "\$this->db->table('counselling_queue_entries')",
            $service,
            'The session ids must be resolved in their own query.',
        );
        // The counsellor-name join (2026-10) adds a second `id`/`created_at`,
        // so the keyset columns MUST be qualified — unqualified names would
        // be ambiguous the moment a cursor is present.
        $this->assertStringContainsString(
            "KeysetPaginator::apply(\$builder, \$cursor, \$limit, 'counselling_sessions.created_at', 'counselling_sessions.id');",
            $service,
            'The paginated builder must qualify its keyset columns so they stay unambiguous.',
        );
    }

    public function testTheControllerValidatesTheAppointmentId(): void
    {
        $controller = $this->read('app/Modules/Counselling/Controllers/CounsellingController.php');

        $this->assertStringContainsString("getGet('appointment_id')", $controller);
        // A non-numeric id must 422, not be coerced to 0 and match nothing.
        $this->assertStringContainsString('! is_numeric($rawAppointment)', $controller);
        $this->assertStringContainsString("(int) \$rawAppointment < 1", $controller);
        $this->assertStringContainsString(
            "'appointment_id must be a positive integer.'",
            $controller,
        );
        // And it has to reach the service, not just be validated.
        $this->assertStringContainsString(
            '$this->service->listSessions($cursor !== \'\' ? $cursor : null, $limit, $appointmentId, $patientSchoolId, $archived)',
            $controller,
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
