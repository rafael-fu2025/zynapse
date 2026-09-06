<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CounsellingNoteAmendments — correction-by-amendment for session notes
 * (counselling audit 2026-09-03, F15 decision 2026-09-05).
 *
 * `counselling_notes` stays INSERT-ONLY: no edit, no delete — mutating a
 * clinical note would destroy the only evidence that an error occurred.
 * Corrections are new rows that reference the note they supersede via
 * `supersedes_note_id`; the API returns both so the workspace renders the
 * amendment alongside what it replaces. FK is ON DELETE RESTRICT — even
 * a DBA removing a row cannot strand an amendment pointer.
 *
 * Idempotent: re-runs are no-ops.
 */
final class CounsellingNoteAmendments extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('supersedes_note_id', 'counselling_notes')) {
            return;
        }

        $this->forge->addColumn('counselling_notes', [
            'supersedes_note_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'session_id',
            ],
        ]);
        $this->db->query(
            'ALTER TABLE `counselling_notes` ADD INDEX `idx_counselling_notes_supersedes` (`supersedes_note_id`)'
        );
        $this->db->query(
            'ALTER TABLE `counselling_notes` ADD CONSTRAINT `fk_counselling_notes_supersedes` '
            . 'FOREIGN KEY (`supersedes_note_id`) REFERENCES `counselling_notes`(`id`) ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `counselling_notes` DROP FOREIGN KEY `fk_counselling_notes_supersedes`');
        $this->forge->dropColumn('counselling_notes', 'supersedes_note_id');
    }
}
