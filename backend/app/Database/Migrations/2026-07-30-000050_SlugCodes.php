<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SlugCodes — panel revision (July 2026):
 *
 * The `code` field on drums (BMG units) and waste categories is a SLUG
 * — a human-readable, URL-safe identifier distinct from the numeric
 * primary key — not a free-form label. Contract (also enforced by
 * BmgService on create): lowercase `a-z0-9` groups separated by single
 * hyphens, e.g. `drum-01`, `food-waste-meat`.
 *
 * Existing rows are normalized in place (lowercase, non-alphanumerics
 * collapsed to `-`). A row whose normalized code would collide with
 * another row is left untouched — codes appear in audit trails, so a
 * silent merge is worse than a legacy-cased leftover.
 */
final class SlugCodes extends Migration
{
    private const TABLES = ['facilities_bmg_units', 'facilities_waste_categories'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $rows = $this->db->table($table)->select('id, code')->get()->getResultArray();

            foreach ($rows as $row) {
                $slug = $this->slugify((string) $row['code']);
                if ($slug === '' || $slug === (string) $row['code']) {
                    continue;
                }

                // Skip on collision with any other row's current code.
                $dup = $this->db->table($table)
                    ->where('code', $slug)
                    ->where('id !=', (int) $row['id'])
                    ->countAllResults();
                if ($dup > 0) {
                    continue;
                }

                $this->db->table($table)->where('id', (int) $row['id'])->update(['code' => $slug]);
            }
        }
    }

    public function down(): void
    {
        // Lossy normalization — original casing is not recoverable.
        // Nothing to do; slugs remain valid identifiers.
    }

    private function slugify(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
