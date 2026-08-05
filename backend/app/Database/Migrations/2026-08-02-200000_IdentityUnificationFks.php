<?php
/**
 * IdentityUnificationFks — Phase 1 of the identity-unification-and-reseed plan.
 *
 * Adds the missing DB-level FKs that make the unified identity model
 * enforceable (not just optional):
 *   1. `users.person_id` column with FK to `persons.id` + UNIQUE.
 *   2. `persons.user_id` FK to `users.id` (the existing UNIQUE stays).
 *   3. `patients_students.user_id` and `patients_employees.user_id`
 *      get FKs to `users.id`; the per-table UNIQUE on user_id is dropped
 *      (the global uniqueness on persons.user_id supersedes it).
 *
 * Idempotent: re-runs are no-ops if the FKs already exist.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class IdentityUnificationFks extends Migration
{
    public function up(): void
    {
        // 1. users.person_id column with FK to persons.id.
        if ($this->db->tableExists('users') && ! $this->db->fieldExists('person_id', 'users')) {
            $this->forge->addColumn('users', [
                'person_id' => [
                    'type'       => 'BIGINT',
                    'unsigned'   => true,
                    'null'       => true,
                    'after'      => 'username',
                ],
            ]);
            $this->forge->addUniqueKey('users', 'person_id', 'uniq_users_person_id');
        }
        if ($this->db->tableExists('users') && $this->db->tableExists('persons')) {
            $r = $this->db->query("
                SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'
                  AND CONSTRAINT_NAME = 'fk_users_persons'
            ");
            if ($r->getNumRows() === 0) {
                $this->db->query("
                    ALTER TABLE `users`
                    ADD CONSTRAINT `fk_users_persons` FOREIGN KEY (`person_id`)
                    REFERENCES `persons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ");
            }
        }

        // 2. persons.user_id FK to users.id.
        if ($this->db->tableExists('persons') && $this->db->tableExists('users')) {
            $r = $this->db->query("
                SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'persons'
                  AND CONSTRAINT_NAME = 'fk_persons_users'
            ");
            if ($r->getNumRows() === 0) {
                $this->db->query("
                    ALTER TABLE `persons`
                    ADD CONSTRAINT `fk_persons_users` FOREIGN KEY (`user_id`)
                    REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ");
            }
        }

        // 3. Drop per-table UNIQUE on user_id (legacy) and add FKs.
        // The global UNIQUE on persons.user_id supersedes these.
        foreach (['patients_students', 'patients_employees'] as $legacy) {
            if (! $this->db->tableExists($legacy)) {
                continue;
            }
            // Find any per-table user_id UNIQUE index that is NOT the FK constraint.
            $r = $this->db->query("
                SELECT INDEX_NAME FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$legacy}'
                  AND COLUMN_NAME = 'user_id'
                  AND NON_UNIQUE = 0
                  AND INDEX_NAME <> 'PRIMARY'
            ");
            $uniqueIndexes = [];
            foreach ($r->getResultArray() as $row) {
                $uniqueIndexes[] = $row['INDEX_NAME'];
            }
            foreach ($uniqueIndexes as $idx) {
                // Don't drop the FK constraint if it shows up.
                $r2 = $this->db->query("
                    SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                      AND TABLE_NAME = '{$legacy}'
                      AND CONSTRAINT_NAME = '{$idx}'
                ");
                if ($r2->getNumRows() === 0) {
                    // Standalone unique index, safe to drop.
                    $this->db->query("ALTER TABLE `{$legacy}` DROP INDEX `{$idx}`");
                }
            }
            // Add FK if missing.
            $fkName = "fk_{$legacy}_users";
            $r = $this->db->query("
                SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$legacy}'
                  AND CONSTRAINT_NAME = '{$fkName}'
            ");
            if ($r->getNumRows() === 0) {
                $this->db->query("
                    ALTER TABLE `{$legacy}`
                    ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`user_id`)
                    REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ");
            }
        }
    }

    public function down(): void
    {
        foreach (['patients_students', 'patients_employees'] as $legacy) {
            if (! $this->db->tableExists($legacy)) {
                continue;
            }
            $fkName = "fk_{$legacy}_users";
            $r = $this->db->query("
                SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$legacy}'
                  AND CONSTRAINT_NAME = '{$fkName}'
            ");
            if ($r->getNumRows() > 0) {
                $this->db->query("ALTER TABLE `{$legacy}` DROP FOREIGN KEY `{$fkName}`");
            }
        }
        if ($this->db->tableExists('persons') && $this->db->tableExists('users')) {
            $r = $this->db->query("
                SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'persons'
                  AND CONSTRAINT_NAME = 'fk_persons_users'
            ");
            if ($r->getNumRows() > 0) {
                $this->db->query("ALTER TABLE `persons` DROP FOREIGN KEY `fk_persons_users`");
            }
        }
        if ($this->db->tableExists('users')) {
            $r = $this->db->query("
                SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'
                  AND CONSTRAINT_NAME = 'fk_users_persons'
            ");
            if ($r->getNumRows() > 0) {
                $this->db->query("ALTER TABLE `users` DROP FOREIGN KEY `fk_users_persons`");
            }
            if ($this->db->fieldExists('person_id', 'users')) {
                $this->forge->dropColumn('users', 'person_id');
            }
        }
    }
}
