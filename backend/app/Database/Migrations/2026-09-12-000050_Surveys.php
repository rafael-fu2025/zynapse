<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Surveys — Phase B of the guidance parity plan
 * (docs/GUIDANCE-KIOSK-PARITY.md). Replaces the kiosk's Google-Forms
 * escape hatch with a first-class, versioned survey engine:
 *
 *   surveys            — the container: audience + availability window
 *                        (Manila-day aware in the service layer);
 *   survey_versions    — IMMUTABLE, copy-on-publish; responses always
 *                        reference the exact version they answered, so
 *                        historical answers stay interpretable;
 *   survey_questions   — draft rows (version_id NULL) + published
 *                        snapshots (version_id set) in one table;
 *   survey_answer_options — choices for single/multi questions;
 *   survey_responses   — one submission per student per survey
 *                        (UNIQUE), carrying an ENCRYPTED full-answer
 *                        snapshot (AES-256-GCM via EncryptionService —
 *                        the same posture as counselling notes);
 *   survey_answers     — long-table, one row per question-response
 *                        pair, aggregation-ready for the reports module.
 *
 * Permission codes added (module: counselling):
 *   counselling.surveys.manage   — guidance_admin (builder + publish)
 *   counselling.responses.read   — guidance_admin/supervisor/counsellor
 *   counselling.responses.read_any — guidance_admin/supervisor
 */
final class Surveys extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // ---- surveys -----------------------------------------------------
        if (! $this->db->tableExists('surveys')) {
            $this->forge->addField([
                'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'title'        => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
                'description'  => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
                'category'     => ['type' => 'ENUM', 'constraint' => ['survey', 'interview'], 'null' => false, 'default' => 'survey'],
                'audience'     => ['type' => 'ENUM', 'constraint' => ['all', 'new_students', 'continuing_students', 'graduating_students'], 'null' => false, 'default' => 'all'],
                'is_required'  => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 0],
                'publish_at'   => ['type' => 'DATETIME', 'null' => true],
                'close_at'     => ['type' => 'DATETIME', 'null' => true],
                'created_by'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => false],
                'updated_at'   => ['type' => 'DATETIME', 'null' => false],
                'archived_at'  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['tenant_id', 'archived_at']);
            $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
            $this->forge->createTable('surveys');
        }

        // ---- survey_versions ----------------------------------------------
        if (! $this->db->tableExists('survey_versions')) {
            $this->forge->addField([
                'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'survey_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'version_no'   => ['type' => 'INT', 'constraint' => 6, 'unsigned' => true, 'null' => false, 'default' => 1],
                'published_at' => ['type' => 'DATETIME', 'null' => false],
                'published_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['survey_id', 'version_no']);
            $this->forge->addForeignKey('survey_id', 'surveys', 'id', '', 'CASCADE');
            $this->forge->createTable('survey_versions');
        }

        // ---- survey_questions ---------------------------------------------
        if (! $this->db->tableExists('survey_questions')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'survey_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'version_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'sort_order'    => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => false, 'default' => 0],
                'question_type' => ['type' => 'ENUM', 'constraint' => ['single', 'multi', 'likert', 'rating', 'free_text', 'external_url'], 'null' => false, 'default' => 'free_text'],
                'question_text' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => false],
                'is_required'   => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 0],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['survey_id', 'version_id']);
            $this->forge->addForeignKey('survey_id', 'surveys', 'id', '', 'CASCADE');
            $this->forge->createTable('survey_questions');
        }

        // ---- survey_answer_options ----------------------------------------
        if (! $this->db->tableExists('survey_answer_options')) {
            $this->forge->addField([
                'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'question_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'option_text' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'sort_order'  => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => false, 'default' => 0],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('question_id');
            $this->forge->addForeignKey('question_id', 'survey_questions', 'id', '', 'CASCADE');
            $this->forge->createTable('survey_answer_options');
        }

        // ---- survey_responses ----------------------------------------------
        if (! $this->db->tableExists('survey_responses')) {
            $this->forge->addField([
                'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'survey_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'version_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'student_user_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                // Encrypted canonical snapshot (AES-256-GCM) — the
                // counselling-notes posture. BLOB (not TEXT): the raw
                // ciphertext+tag is binary, not utf8-safe. The long-table
                // answers below are the aggregation projection.
                'payload_cipher'     => ['type' => 'BLOB', 'null' => false],
                'payload_nonce'      => ['type' => 'BINARY', 'constraint' => 12, 'null' => false],
                'payload_key_version' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'null' => false],
                'submitted_at'       => ['type' => 'DATETIME', 'null' => false],
                'created_at'         => ['type' => 'DATETIME', 'null' => false],
                'updated_at'         => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['survey_id', 'student_user_id']);
            $this->forge->addKey('version_id');
            $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
            $this->forge->addForeignKey('survey_id', 'surveys', 'id', '', 'CASCADE');
            $this->forge->addForeignKey('version_id', 'survey_versions', 'id', '', 'RESTRICT');
            $this->forge->createTable('survey_responses');
        }

        // ---- survey_answers -------------------------------------------------
        if (! $this->db->tableExists('survey_answers')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'response_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'question_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'version_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'question_type' => ['type' => 'ENUM', 'constraint' => ['single', 'multi', 'likert', 'rating', 'free_text', 'external_url'], 'null' => false],
                'value_text'    => ['type' => 'VARCHAR', 'constraint' => 2000, 'null' => true],
                'value_number'  => ['type' => 'INT', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['question_id', 'version_id']);
            $this->forge->addForeignKey('response_id', 'survey_responses', 'id', '', 'CASCADE');
            $this->forge->createTable('survey_answers');
        }

        // ---- permission codes (idempotent) ---------------------------------
        $newCodes = [
            'counselling.surveys.manage'    => 'counselling',
            'counselling.responses.read'    => 'counselling',
            'counselling.responses.read_any' => 'counselling',
        ];
        foreach ($newCodes as $code => $module) {
            if ($this->db->table('permissions')->where('code', $code)->countAllResults() > 0) {
                continue;
            }
            $this->db->table('permissions')->insert([
                'code'       => $code,
                'module'     => $module,
                'summary'    => null,
                'created_at' => $now,
            ]);
        }

        // Map: manage/read/read_any → guidance_admin; read/read_any →
        // guidance_supervisor; read → counsellor.
        $mappings = [
            'guidance_admin'       => array_keys($newCodes),
            'guidance_supervisor'  => ['counselling.responses.read', 'counselling.responses.read_any'],
            'counsellor'           => ['counselling.responses.read'],
        ];
        foreach ($mappings as $groupName => $codes) {
            $group = $this->db->table('auth_groups')->where('name', $groupName)->get()->getRowArray();
            if ($group === null) {
                continue;
            }
            foreach ($codes as $code) {
                $mapped = $this->db->table('auth_groups_permissions')
                    ->where(['group_id' => (int) $group['id'], 'permission_code' => $code])
                    ->countAllResults();
                if ($mapped === 0) {
                    $this->db->table('auth_groups_permissions')->insert([
                        'group_id'        => (int) $group['id'],
                        'permission_code' => $code,
                        'created_at'      => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('survey_answers', true);
        $this->forge->dropTable('survey_responses', true);
        $this->forge->dropTable('survey_answer_options', true);
        $this->forge->dropTable('survey_questions', true);
        $this->forge->dropTable('survey_versions', true);
        $this->forge->dropTable('surveys', true);

        foreach (['counselling.surveys.manage', 'counselling.responses.read', 'counselling.responses.read_any'] as $code) {
            $this->db->table('auth_groups_permissions')->where('permission_code', $code)->delete();
            $this->db->table('permissions')->where('code', $code)->delete();
        }
    }
}
