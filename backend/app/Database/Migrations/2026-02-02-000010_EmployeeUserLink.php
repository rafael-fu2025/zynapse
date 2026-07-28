<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * EmployeeUserLink — let a `users` row point at exactly one
 * `patients_employees` row so the employee portal (Phase 11) can
 * resolve "who am I?" without scraping emails or relying on a
 * shared username convention.
 *
 * Constraints:
 *   - `user_id` is nullable: not every `patients_employees` row
 *     is a portal user (e.g. historical records imported from HR).
 *   - `user_id` is UNIQUE: at most one employee per user. The
 *     uniqueness is enforced in the index, not as a separate FK
 *     row, so the `users` table can still be dropped/recreated
 *     during a wipe-and-reseed cycle without breaking FK rules.
 *
 * Index: `uniq_employees_user_id` on `user_id` (NULLs allowed).
 */
final class EmployeeUserLink extends Migration
{
    public function up(): void
    {
        if ($this->columnExists('patients_employees', 'user_id')) {
            return;
        }

        $this->forge->addColumn('patients_employees', [
            'user_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'id',
            ],
        ]);
        $this->forge->addUniqueKey('patients_employees', 'user_id', 'uniq_employees_user_id');
    }

    public function down(): void
    {
        if (! $this->columnExists('patients_employees', 'user_id')) {
            return;
        }
        // Drop the unique key first; dropColumn fails if the key
        // is still present on this connection.
        if ($this->db->indexExists('patients_employees', 'uniq_employees_user_id')) {
            $this->forge->dropKey('patients_employees', 'uniq_employees_user_id');
        }
        $this->forge->dropColumn('patients_employees', 'user_id');
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->db->fieldExists($column, $table);
    }
}
