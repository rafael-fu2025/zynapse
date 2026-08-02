<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Add reader indexes without mutating the deployed audit-log migration. */
final class AuditReaderIndexes extends Migration
{
    public function up(): void
    {
        $this->db->query('CREATE INDEX ix_audit_events_actor ON ' . SYNAPSE_AUDIT_EVENTS . ' (actor_user_id)');
        $this->db->query('CREATE INDEX ix_audit_events_request ON ' . SYNAPSE_AUDIT_EVENTS . ' (request_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX ix_audit_events_request ON ' . SYNAPSE_AUDIT_EVENTS);
        $this->db->query('DROP INDEX ix_audit_events_actor ON ' . SYNAPSE_AUDIT_EVENTS);
    }
}
