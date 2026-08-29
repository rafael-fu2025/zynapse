<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Apply the panel-approved 21-day decomposition baseline to unset categories. */
final class BmgDurationBaseline extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'UPDATE `facilities_waste_categories` SET `reference_duration_days` = 21'
            . ' WHERE `reference_duration_days` IS NULL',
        );
    }

    public function down(): void
    {
        // The former NULL values cannot be distinguished from category-specific
        // values of 21 after backfill. Avoid destructive/incorrect reversal.
    }
}
