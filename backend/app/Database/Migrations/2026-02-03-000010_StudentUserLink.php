<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * StudentUserLink — let a `users` row point at exactly one
 * `patients_students` row so the (future) student self-service
 * portal and the employee portal can resolve "who am I?"
 * symmetrically.
 *
 * Same shape as `EmployeeUserLink` (Phase 11): nullable
 * `user_id` with a UNIQUE index. NULL is allowed for historical
 * or HR-imported students who are not portal users.
 *
 * Phase 13 wires 1 student demo user to demonstrate the
 * "linked student" path. The bigger student self-service
 * project (login + book + QR) is still deferred.
 */
final class StudentUserLink extends Migration
{
    public function up(): void
    {
        if ($this->columnExists('patients_students', 'user_id')) {
            return;
        }

        $this->forge->addColumn('patients_students', [
            'user_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'id',
            ],
        ]);
        $this->forge->addUniqueKey('patients_students', 'user_id', 'uniq_students_user_id');
    }

    public function down(): void
    {
        if (! $this->columnExists('patients_students', 'user_id')) {
            return;
        }
        if ($this->db->indexExists('patients_students', 'uniq_students_user_id')) {
            $this->forge->dropKey('patients_students', 'uniq_students_user_id');
        }
        $this->forge->dropColumn('patients_students', 'user_id');
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->db->fieldExists($column, $table);
    }
}
