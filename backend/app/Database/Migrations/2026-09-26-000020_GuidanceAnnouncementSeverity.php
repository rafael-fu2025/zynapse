<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * GuidanceAnnouncementSeverity — red urgent announcements (2026-09-25
 * staff meeting): urgent posts must stand apart from ordinary notices
 * with a red outline instead of the amber "required" treatment.
 *
 * `is_required` already answers "must the student act on this?"; it says
 * nothing about how loud the post should be. `severity` is that loudness:
 * `normal` renders like today, `urgent` gets the destructive outline +
 * badge on both the staff Announcements surface and the student portal.
 */
final class GuidanceAnnouncementSeverity extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `guidance_announcements`"
            . " ADD COLUMN `severity` ENUM('normal','urgent') NOT NULL DEFAULT 'normal' AFTER `is_required`"
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `guidance_announcements` DROP COLUMN `severity`');
    }
}
