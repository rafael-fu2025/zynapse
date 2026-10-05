<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SurveyLinks — dynamic, database-driven links on the surveys engine
 * (2026-10). Three link types share one table (survey_links.type):
 *
 *   survey     — external survey forms aimed at a student audience
 *                (e.g. a Google Form for New & Transferees);
 *   test       — external assessments (MI / LS / BFPT / New &
 *                Transferees / custom) whose completion a student
 *                proves with a screenshot upload;
 *   evaluation — the guidance-services evaluation form.
 *
 * Like questions, links follow copy-on-publish: draft rows carry
 * version_id NULL and are freely editable; publish() snapshots them
 * into the immutable version and students only ever see the snapshot.
 *
 *   survey_link_opens       — click attestation ("Take Test" opened),
 *                             idempotent per student + link; the honest
 *                             "Completed" signal for OFF-platform work.
 *   survey_link_screenshots — proof images, staged against the survey
 *                             before submit (response_id NULL) and bound
 *                             to the response atomically on submit.
 *                             Bytes live under WRITEPATH, never public.
 *
 * No new permission codes: staff management stays behind
 * counselling.surveys.manage, proof reads behind counselling.responses.
 *
 * Numbered 000040 to sit behind the untracked Bmg unit migrations
 * (000010-000030) in this working tree — no version collisions.
 */
final class SurveyLinks extends Migration
{
    public function up(): void
    {
        // ---- survey_links ----------------------------------------------------
        if (! $this->db->tableExists('survey_links')) {
            $this->forge->addField([
                'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'survey_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                // NULL = draft config; set = immutable published snapshot.
                'version_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'type'           => ['type' => 'ENUM', 'constraint' => ['survey', 'test', 'evaluation'], 'null' => false],
                'title'          => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
                'description'    => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
                'external_url'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false],
                // survey-type only: audience targeting (null = every student).
                'audience'       => ['type' => 'ENUM', 'constraint' => ['all', 'new_students', 'continuing_students', 'graduating_students'], 'null' => true],
                // test-type only: canonical instrument vocabulary.
                'instrument_key' => ['type' => 'ENUM', 'constraint' => ['new_transferees', 'mi', 'ls', 'bfpt', 'custom'], 'null' => true],
                'is_required'    => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 0],
                'is_enabled'     => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 1],
                'sort_order'     => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => false, 'default' => 0],
                'created_at'     => ['type' => 'DATETIME', 'null' => false],
                'updated_at'     => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['survey_id', 'version_id']);
            $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
            $this->forge->addForeignKey('survey_id', 'surveys', 'id', '', 'CASCADE');
            $this->forge->addForeignKey('version_id', 'survey_versions', 'id', '', 'CASCADE');
            $this->forge->createTable('survey_links');
        }

        // ---- survey_link_opens -------------------------------------------------
        if (! $this->db->tableExists('survey_link_opens')) {
            $this->forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'survey_id'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'link_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'student_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'opened_at'       => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['link_id', 'student_user_id']);
            $this->forge->addKey('student_user_id');
            $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
            $this->forge->addForeignKey('survey_id', 'surveys', 'id', '', 'CASCADE');
            $this->forge->addForeignKey('link_id', 'survey_links', 'id', '', 'CASCADE');
            $this->forge->createTable('survey_link_opens');
        }

        // ---- survey_link_screenshots -------------------------------------------
        if (! $this->db->tableExists('survey_link_screenshots')) {
            $this->forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'survey_id'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'link_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                // NULL while staged (pre-submit); set once bound at submit.
                'response_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'student_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'original_name'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'stored_name'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'mime_type'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
                'size_bytes'      => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'created_at'      => ['type' => 'DATETIME', 'null' => false],
                'updated_at'      => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['student_user_id', 'survey_id']);
            $this->forge->addKey('response_id');
            $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
            $this->forge->addForeignKey('survey_id', 'surveys', 'id', '', 'CASCADE');
            $this->forge->addForeignKey('link_id', 'survey_links', 'id', '', 'CASCADE');
            $this->forge->addForeignKey('response_id', 'survey_responses', 'id', '', 'CASCADE');
            $this->forge->createTable('survey_link_screenshots');
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('survey_link_screenshots', true);
        $this->forge->dropTable('survey_link_opens', true);
        $this->forge->dropTable('survey_links', true);
    }
}
