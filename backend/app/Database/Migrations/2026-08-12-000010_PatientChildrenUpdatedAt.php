<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add `updated_at` to `patient_allergies` and `patient_contacts` so
 * edits to safety-critical health/contact rows are timestamped
 * (edit/remove support added 2026-08-12).
 */
class PatientChildrenUpdatedAt extends Migration
{
    public function up(): void
    {
        $cols = $this->db->getFieldNames('patient_allergies');
        if (! in_array('updated_at', $cols, true)) {
            $this->forge->addColumn('patient_allergies', [
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
        }
        $cols = $this->db->getFieldNames('patient_contacts');
        if (! in_array('updated_at', $cols, true)) {
            $this->forge->addColumn('patient_contacts', [
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('patient_allergies', 'updated_at');
        $this->forge->dropColumn('patient_contacts', 'updated_at');
    }
}
