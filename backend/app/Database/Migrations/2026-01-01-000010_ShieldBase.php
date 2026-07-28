<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ShieldBase — the canonical CI4 Shield schema for SYNAPSE.
 *
 * Tables:
 *   - auth_identities               (multi-factor identity rows per user)
 *   - auth_groups                   (named roles)
 *   - auth_groups_users             (many-to-many user ↔ group)
 *   - auth_groups_permissions       (group ↔ permission)
 *   - auth_logins                   (login attempts log — non-clinical)
 *   - auth_token_logins             (remember-me tokens)
 *   - users                         (canonical user rows; refers to identities)
 *   - user_permissions              (per-user permission grants)
 *
 * MySQL 8.4 LTS — InnoDB, utf8mb4_0900_ai_ci, STRICT mode enforced.
 */
final class ShieldBase extends Migration
{
    public function up(): void
    {
        // --- users ---------------------------------------------------------
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'username'      => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => false, 'default' => 'active'],
            'status_message'=> ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'active'        => ['type' => 'TINYINT', 'constraint' => 1,   'null' => false, 'default' => 1],
            'last_active'   => ['type' => 'DATETIME','null' => true],
            'created_at'    => ['type' => 'DATETIME','null' => false],
            'updated_at'    => ['type' => 'DATETIME','null' => false],
            'deleted_at'    => ['type' => 'DATETIME','null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('username');
        $this->forge->createTable('users');

        // --- auth_identities -----------------------------------------------
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'type'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'secret'       => ['type' => 'VARCHAR', 'constraint' => 255,'null' => false],
            'secret2'      => ['type' => 'VARCHAR', 'constraint' => 255,'null' => true], // password_hash (only for type=password)
            'expires'      => ['type' => 'DATETIME','null' => true],
            'extra'        => ['type' => 'JSON',    'null' => true],
            'force_reset'  => ['type' => 'TINYINT', 'constraint' => 1,  'null' => false, 'default' => 0],
            'last_used_at' => ['type' => 'DATETIME','null' => true],
            'created_at'   => ['type' => 'DATETIME','null' => false],
            'updated_at'   => ['type' => 'DATETIME','null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['user_id', 'type']);
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('auth_identities');

        // --- auth_groups ---------------------------------------------------
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'display_name' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'description'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => false],
            'updated_at'   => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('auth_groups');

        // --- auth_groups_users --------------------------------------------
        $this->forge->addField([
            'id'        => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'group_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'=> ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['group_id', 'user_id'], false, true);
        $this->forge->addForeignKey('group_id', 'auth_groups', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('user_id',  'users',       'id', '', 'CASCADE');
        $this->forge->createTable('auth_groups_users');

        // --- auth_groups_permissions -------------------------------------
        $this->forge->addField([
            'id'        => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'group_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'permission_code' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'created_at'=> ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['group_id', 'permission_code'], false, true);
        $this->forge->addForeignKey('group_id', 'auth_groups', 'id', '', 'CASCADE');
        $this->forge->createTable('auth_groups_permissions');

        // --- user_permissions ---------------------------------------------
        $this->forge->addField([
            'id'        => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'permission_code' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'created_at'=> ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['user_id', 'permission_code'], false, true);
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('user_permissions');

        // --- permissions (canonical codes) --------------------------------
        $this->forge->addField([
            'id'        => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'code'      => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'module'    => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => false],
            'summary'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'=> ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('permissions');

        // --- auth_logins ---------------------------------------------------
        $this->forge->addField([
            'id'        => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'identifier'=> ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false], // email or username
            'success'   => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'ip_address'=> ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'user_agent'=> ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'=> ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id');
        $this->forge->addKey('identifier');
        $this->forge->createTable('auth_logins');

        // --- auth_token_logins ---------------------------------------------
        $this->forge->addField([
            'id'        => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'selector'  => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'validator' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'expires'   => ['type' => 'DATETIME', 'null' => false],
            'created_at'=> ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['selector'], false, true);
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('auth_token_logins');
    }

    public function down(): void
    {
        foreach ([
            'auth_token_logins',
            'auth_logins',
            'permissions',
            'user_permissions',
            'auth_groups_permissions',
            'auth_groups_users',
            'auth_groups',
            'auth_identities',
            'users',
        ] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}