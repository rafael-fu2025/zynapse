<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ApiAppsAndKeys — external API surface (2026-09, D4).
 *
 * api_apps  — registered external integrations (future FU capstone
 *             teams). Tenant-pinned; DPA acknowledgement is recorded
 *             before any LIVE key may be issued (RA 10173 posture).
 * api_keys  — Stripe-style credentials: env-separated prefix
 *             (`syn_test_ab12` / `syn_live_ab12`), SHA-256 of the full
 *             secret (the secret itself is shown ONCE at creation and
 *             NEVER stored), last4 hint, JSON scope array, per-key
 *             rate limit, 90-day default expiry, immediate revocation.
 *
 * The prefix column is UNIQUE and is the lookup index at request time
 * (the key self-identifies its prefix; the key row then determines the
 * tenant — the lookup is deliberately tenant-agnostic).
 */
final class ApiAppsAndKeys extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'name'                => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'description'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'owner_contact'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'              => ['type' => 'ENUM', 'constraint' => ['active', 'suspended'], 'null' => false, 'default' => 'active'],
            'dpa_acknowledged_at' => ['type' => 'DATETIME', 'null' => true],
            'dpa_acknowledged_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_by'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
            'updated_at'          => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
        $this->forge->createTable('api_apps');

        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'app_id'             => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'env'                => ['type' => 'ENUM', 'constraint' => ['test', 'live'], 'null' => false],
            'prefix'             => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false],
            'key_hash'           => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'last4'              => ['type' => 'CHAR', 'constraint' => 4, 'null' => false],
            'scopes'             => ['type' => 'JSON', 'null' => false],
            'rate_limit_per_min' => ['type' => 'INT', 'constraint' => 6, 'unsigned' => true, 'null' => false, 'default' => 60],
            'expires_at'         => ['type' => 'DATETIME', 'null' => true],
            'revoked_at'         => ['type' => 'DATETIME', 'null' => true],
            'last_used_at'       => ['type' => 'DATETIME', 'null' => true],
            'created_by'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
            'updated_at'         => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('prefix');
        $this->forge->addKey('tenant_id');
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('app_id', 'api_apps', 'id', '', 'CASCADE');
        $this->forge->createTable('api_keys');
    }

    public function down(): void
    {
        $this->forge->dropTable('api_keys', true);
        $this->forge->dropTable('api_apps', true);
    }
}
