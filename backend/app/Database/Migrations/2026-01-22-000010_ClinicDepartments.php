<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicDepartments — HR department lookup (Phase P3, recycled from
 * legacy synapse_ag departments).
 *
 * A managed lookup that populates the employee department field. Kept
 * non-destructive: `patients_employees.department` remains a free-text
 * column; this table is the curated source for the picker.
 */
final class ClinicDepartments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'code'        => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false],
            'description' => ['type' => 'TEXT', 'null' => true],
            'is_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => false],
            'updated_at'  => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('clinic_departments');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_departments', true);
    }
}
