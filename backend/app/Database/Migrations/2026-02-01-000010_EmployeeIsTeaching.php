<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * EmployeeIsTeaching — add an `is_teaching` flag to `patients_employees`.
 *
 * Two employee types live in the same registry table:
 *   - **teaching**   — faculty; can refer students to counselling.
 *   - **non-teaching** — admin / facilities / support staff.
 *
 * The flag is small (TINYINT(1)) and defaults to 0 (non-teaching) so
 * the existing rows keep their current behaviour. A future policy
 * layer in `ReferralService::create` will read this flag to gate
 * referral creation; the migration is intentionally separate from
 * that policy so the column ships independently.
 */
final class EmployeeIsTeaching extends Migration
{
    public function up(): void
    {
        if ($this->columnExists('patients_employees', 'is_teaching')) {
            return;
        }

        $this->forge->addColumn('patients_employees', [
            'is_teaching' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 0,
                'after'      => 'archived_at',
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->columnExists('patients_employees', 'is_teaching')) {
            return;
        }
        $this->forge->dropColumn('patients_employees', 'is_teaching');
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = $this->db->query("SHOW COLUMNS FROM {$table} WHERE Field = '{$column}'")->getRowArray();
        return $row !== null;
    }
}
