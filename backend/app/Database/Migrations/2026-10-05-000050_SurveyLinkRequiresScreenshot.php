<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SurveyLinkRequiresScreenshot — external survey links become fully
 * optional (2026-10): submission gating moves to required questions
 * only, so the per-link screenshot proof is now an explicit choice by
 * the Guidance Office instead of implicit for every test link.
 *
 * The `is_required` column stays (history) but is no longer read for
 * gating — deprecated, not dropped.
 */
final class SurveyLinkRequiresScreenshot extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('requires_screenshot', 'survey_links')) {
            $this->forge->addColumn('survey_links', [
                'requires_screenshot' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'unsigned'   => true,
                    'null'       => false,
                    'default'    => 0,
                    'after'      => 'is_enabled',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('requires_screenshot', 'survey_links')) {
            $this->forge->dropColumn('survey_links', 'requires_screenshot');
        }
    }
}
