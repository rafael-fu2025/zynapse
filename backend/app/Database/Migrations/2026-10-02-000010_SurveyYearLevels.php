<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SurveyYearLevels — grade-level targeting for the surveys engine.
 *
 * The guidance office's paper intake forms (F-OGC-GIN-IIF-004-00 for
 * Grade 1/7/11, F-OGC-GIN-IIC-004-00 for 1st Year College) differ per
 * level, but surveys could only target the new/continuing/graduating
 * audiences. `surveys.year_levels` now holds a JSON list of
 * users.year_level ints (the student registry's 1-6 vocabulary);
 * NULL keeps the old "all year levels" behavior.
 *
 * Also seeds the four paper intake interviews as DRAFTS (per tenant,
 * idempotent by title): guidance reviews them in the builder, sets the
 * year levels, and publishes — drafts are the only mutable state in
 * the versioning model, and publishing stays a deliberate act.
 */
final class SurveyYearLevels extends Migration
{
    /**
     * @var list<array{title: string, description: string, questions: list<string>}>
     */
    private const PAPER_FORMS = [
        [
            'title'       => 'Intake Interview — Grade 1',
            'description' => 'Form code: F-OGC-GIN-IIF-004-00 — Guidance Office intake interview.',
            'questions'   => [
                'How is your experience in FPA?',
                'Describe your life as a grade school pupil/junior high student/senior high student.',
                'Have you made friends in school? What do you usually do with your friends or what is your favorite pastime with your friends?',
                'How about outside the school? Do you have friends?',
                'How is your relationship with your family? (Parents/Siblings)',
                'What do you usually do during free time and/or weekends?',
                'At home, what is your routine? Do you help with household chores? If yes, what are they?',
                'If you face challenges/problems, who do you usually talk to?',
                'What are your present concerns or problems in which you need further help?',
            ],
        ],
        [
            'title'       => 'Intake Interview — Grade 7',
            'description' => 'Form code: F-OGC-GIN-IIF-004-00 — Guidance Office intake interview.',
            'questions'   => [
                'How is your experience in FPA?',
                'Describe your life as a grade school pupil/junior high student/senior high student.',
                'Have you made friends in school? What do you usually do with your friends or what is your favorite pastime with your friends?',
                'How about outside the school? Do you have friends?',
                'How is your relationship with your family? (Parents/Siblings)',
                'What do you usually do during free time and/or weekends?',
                'At home, what is your routine? Do you help with household chores? If yes, what are they?',
                'If you face challenges/problems, who do you usually talk to?',
                'What are your present concerns or problems in which you need further help?',
            ],
        ],
        [
            'title'       => 'Intake Interview — Grade 11',
            'description' => 'Form code: F-OGC-GIN-IIF-004-00 — Guidance Office intake interview.',
            'questions'   => [
                'How is your experience in FPA?',
                'Describe your life as a grade school pupil/junior high student/senior high student.',
                'Have you made friends in school? What do you usually do with your friends or what is your favorite pastime with your friends?',
                'How about outside the school? Do you have friends?',
                'How is your relationship with your family? (Parents/Siblings)',
                'What do you usually do during free time and/or weekends?',
                'At home, what is your routine? Do you help with household chores? If yes, what are they?',
                'If you face challenges/problems, who do you usually talk to?',
                'What are your present concerns or problems in which you need further help?',
            ],
        ],
        [
            'title'       => 'Intake Interview — 1st Year College',
            'description' => 'Form code: F-OGC-GIN-IIC-004-00 — Guidance Office intake interview.',
            'questions'   => [
                'How is your experience in FU? How do you find your studies?',
                'Is the course that you are taking up at present your personal choice? If not, then who influenced you to choose this course?',
                'Have you made friends, or do you have friends in school? Do you have friends outside FU? How do you usually spend your time with them?',
                'What do you usually do when you\'re at home/boarding house/apartment?',
                'How is your relationship with your family? (Parents/Siblings)',
                'How do you usually spend your vacant time when you are on your own?',
                'What do you expect to learn from FU?',
                'What are the immediate concerns that you have?',
            ],
        ],
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('surveys')) {
            return;
        }

        if (! $this->db->fieldExists('year_levels', 'surveys')) {
            $this->forge->addColumn('surveys', [
                'year_levels' => [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'comment' => 'JSON list of users.year_level ints (1-6); NULL = all year levels',
                ],
            ]);
        }

        // Seed the paper forms for every EXISTING tenant (the
        // GuidanceContent CMO-catalogue precedent).
        $tenants = $this->db->table('tenants')->select('id')->get()->getResultArray();
        $now = date('Y-m-d H:i:s');
        foreach ($tenants as $tenant) {
            foreach (self::PAPER_FORMS as $form) {
                $exists = $this->db->table('surveys')
                    ->where(['tenant_id' => (int) $tenant['id'], 'title' => $form['title']])
                    ->countAllResults();
                if ($exists > 0) {
                    continue;
                }

                $this->db->table('surveys')->insert([
                    'tenant_id'   => (int) $tenant['id'],
                    'title'       => $form['title'],
                    'description' => $form['description'],
                    'category'    => 'interview',
                    'audience'    => 'all',
                    'is_required' => 1,
                    'year_levels' => null,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                $surveyId = (int) $this->db->insertID();

                foreach (array_values($form['questions']) as $index => $questionText) {
                    $this->db->table('survey_questions')->insert([
                        'tenant_id'     => (int) $tenant['id'],
                        'survey_id'     => $surveyId,
                        'version_id'    => null,
                        'sort_order'    => ($index + 1) * 10,
                        'question_type' => 'free_text',
                        'question_text' => $questionText,
                        'is_required'   => 0,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('surveys')) {
            return;
        }

        $titles = array_map(static fn (array $f): string => $f['title'], self::PAPER_FORMS);
        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->table('surveys')->whereIn('title', $titles)->get()->getResultArray(),
        );

        if ($ids !== []) {
            // Responses RESTRICT on surveys — clear children first. Real
            // submissions only exist if a seeded draft was published and
            // answered; removing the column makes them unreadable anyway.
            $responseIds = array_map(
                static fn (array $r): int => (int) $r['id'],
                $this->db->table('survey_responses')->whereIn('survey_id', $ids)->get()->getResultArray(),
            );
            if ($responseIds !== []) {
                $this->db->table('survey_answers')->whereIn('response_id', $responseIds)->delete();
                $this->db->table('survey_responses')->whereIn('id', $responseIds)->delete();
            }
            $questionIds = array_map(
                static fn (array $r): int => (int) $r['id'],
                $this->db->table('survey_questions')->whereIn('survey_id', $ids)->get()->getResultArray(),
            );
            if ($questionIds !== []) {
                $this->db->table('survey_answer_options')->whereIn('question_id', $questionIds)->delete();
            }
            $this->db->table('survey_questions')->whereIn('survey_id', $ids)->delete();
            $this->db->table('survey_versions')->whereIn('survey_id', $ids)->delete();
            $this->db->table('surveys')->whereIn('id', $ids)->delete();
        }

        if ($this->db->fieldExists('year_levels', 'surveys')) {
            $this->forge->dropColumn('surveys', 'year_levels');
        }
    }
}
