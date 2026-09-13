<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * GuidanceFollowups — Phase C of the guidance parity plan
 * (docs/GUIDANCE-KIOSK-PARITY.md): the RA 11036 §24 aftercare loop.
 *
 *   guidance_followups — one row per flagged student need. Created at
 *     survey submit time when the WHO-5 block scores at/below the
 *     configured threshold OR the student explicitly asks for contact
 *     (autonomy-first, never keyword-sniffed). Statuses flow
 *     new → in_review → addressed → closed; addressed/closed REQUIRE
 *     an outcome note (the closed-loop rule). Visible only to
 *     `counselling.responses.read_any` holders.
 *
 *   survey_questions.question_key — semantic keys (who5_1..who5_5,
 *     concerns_flag, ...) that let the triage scorer find instrument
 *     items without hard-coding question ids.
 *
 * Seeds ONE Routine Interview template per tenant (draft, so the
 * guidance administrator reviews and publishes) mirroring the kiosk's
 * 7 questions plus the WHO-5 block and a contact-request item.
 */
final class GuidanceFollowups extends Migration
{
    private const ROUTINE_QUESTIONS = [
        ['who5_1', 'likert', 'I have felt cheerful and in good spirits', false],
        ['who5_2', 'likert', 'I have felt calm and relaxed', false],
        ['who5_3', 'likert', 'I have felt active and vigorous', false],
        ['who5_4', 'likert', 'I woke up feeling fresh and rested', false],
        ['who5_5', 'likert', 'My daily life has been filled with things that interest me', false],
        ['routine_1', 'free_text', 'How are you doing right now in terms of your personal life and studies?', false],
        ['routine_2', 'free_text', 'Have your hopes and expectations from your studies been met so far?', false],
        ['routine_3', 'free_text', 'Do you still get fulfillment and a sense of purpose from your course? How?', false],
        ['routine_4', 'free_text', 'How is your relationship with your family? (Parents/Siblings)', false],
        ['routine_5', 'free_text', 'How is your social life going? Do you believe that this has an impact on both your personal and academic lives? In what way?', false],
        ['routine_6', 'free_text', 'How do you usually spend your free time when you\u2019re in school or at home?', false],
        ['routine_7', 'free_text', 'Are there any present concerns in which you need further help? If so, would it be okay if you share them?', false],
        ['concerns_flag', 'single', 'Would you like a Guidance Counselor to reach out to you?', false],
        ['concerns_detail', 'free_text', 'Anything you would like the counselor to know beforehand? (optional)', false],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // ---- guidance_followups -----------------------------------------
        if (! $this->db->tableExists('guidance_followups')) {
            $this->forge->addField([
                'id'                          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'                   => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'student_user_id'             => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'source_response_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'source_survey_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'risk_reason'                 => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'who5_score'                  => ['type' => 'INT', 'null' => true],
                'status'                      => ['type' => 'ENUM', 'constraint' => ['new', 'in_review', 'addressed', 'closed'], 'null' => false, 'default' => 'new'],
                'assigned_counsellor_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'outcome_note'                => ['type' => 'TEXT', 'null' => true],
                'due_at'                      => ['type' => 'DATETIME', 'null' => true],
                'created_by'                  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at'                  => ['type' => 'DATETIME', 'null' => false],
                'updated_at'                  => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['tenant_id', 'status']);
            $this->forge->addKey('assigned_counsellor_user_id');
            $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
            $this->forge->addForeignKey('source_response_id', 'survey_responses', 'id', '', 'CASCADE');
            $this->forge->addForeignKey('student_user_id', 'users', 'id', '', 'CASCADE');
            $this->forge->createTable('guidance_followups');
        }

        // ---- survey_questions.question_key (semantic instrument keys) ----
        if (! $this->db->fieldExists('question_key', 'survey_questions')) {
            $this->forge->addColumn('survey_questions', [
                'question_key' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                    'after'      => 'question_text',
                ],
            ]);
            $this->db->query('CREATE INDEX survey_questions_key_idx ON survey_questions (question_key)');
        }

        // ---- Seed the Routine Interview template (draft) per tenant ------
        $tenants = $this->db->table('tenants')->select('id')->get()->getResultArray();
        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant['id'];
            $exists = $this->db->table('surveys')
                ->where(['tenant_id' => $tenantId, 'category' => 'interview'])
                ->countAllResults();
            if ($exists > 0) {
                continue;
            }

            $this->db->table('surveys')->insert([
                'tenant_id'   => $tenantId,
                'title'       => 'Routine Interview (Continuing Students)',
                'description' => 'The Guidance Office\'s routine interview. The well-being block is voluntary and confidential to the Guidance Office; you may skip it.',
                'category'    => 'interview',
                'audience'    => 'continuing_students',
                'is_required' => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $surveyId = (int) $this->db->insertID();

            foreach (self::ROUTINE_QUESTIONS as $index => [$key, $type, $text, $required]) {
                $this->db->table('survey_questions')->insert([
                    'tenant_id'     => $tenantId,
                    'survey_id'     => $surveyId,
                    'version_id'    => null,
                    'sort_order'    => ($index + 1) * 10,
                    'question_type' => $type,
                    'question_key'  => $key,
                    'question_text' => $text,
                    'is_required'   => $required ? 1 : 0,
                ]);
                if ($key === 'concerns_flag') {
                    $flagQuestionId = (int) $this->db->insertID();
                    foreach (['Yes, please', 'Not right now'] as $optionIndex => $optionText) {
                        $this->db->table('survey_answer_options')->insert([
                            'tenant_id'   => $tenantId,
                            'question_id' => $flagQuestionId,
                            'option_text' => $optionText,
                            'sort_order'  => $optionIndex + 1,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('guidance_followups', true);
        if ($this->db->fieldExists('question_key', 'survey_questions')) {
            $this->forge->dropColumn('survey_questions', 'question_key');
        }
    }
}
