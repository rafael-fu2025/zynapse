<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * GuidanceContent — Phase A of the guidance-module parity plan
 * (docs/GUIDANCE-KIOSK-PARITY.md). Replaces the university kiosk's
 * hand-maintained Guidance tab with staff-owned, tenant-scoped content:
 *
 *   guidance_announcements — targeted announcements with publish windows
 *     (audience: all / new / continuing / graduating students). Replaces
 *     the kiosk's static list AND its duplicate "Survey Links" tab.
 *   guidance_services — the CHED CMO 9 s.2013 guidance service catalogue
 *     (the kiosk's 9 bullet points), seeded for every tenant. Bookable
 *     services carry a queue_destination and deep-link into the existing
 *     counselling appointment/queue flows.
 *
 * Also registers two permission codes (guidance_admin manages both):
 *   counselling.announcements.manage, counselling.services.manage
 *
 * All steps idempotent: fresh installs (empty tables) and existing
 * deployments converge. Seeding the services for ALL existing tenants
 * (including the sandbox) happens here so the catalogue is never empty.
 */
final class GuidanceContent extends Migration
{
    /** CHED CMO 9 s.2013 guidance service categories (kiosk parity). */
    private const SERVICES = [
        ['individual_inventory', 'Individual Inventory Service', 'Cumulative records of each student\'s personal, academic, and test data, kept current and usable for counseling.', 1, null],
        ['information', 'Information Service', 'Dissemination of academic, personal-social, and career information through orientations, bulletins, and the guidance portal.', 2, null],
        ['counseling', 'Counseling Service', 'Individual and group counseling sessions with registered guidance counselors — the heart of the guidance program.', 3, 'counselling'],
        ['career_placement', 'Career and Placement Service', 'Career guidance, job placement assistance, and career information for students and graduates.', 4, null],
        ['referral_followup', 'Referral, Follow-up, & Consultation Service', 'Two-way referral bridge with the Clinic and faculty, follow-up of counselee progress, and walk-in consultation.', 5, 'counselling'],
        ['evaluation_survey', 'Evaluation and Survey', 'Periodic evaluation of guidance services and student needs assessments that feed program planning.', 6, null],
        ['peer_facilitator', 'Special Services: Peer Facilitator', 'Trained student peer facilitators extending guidance reach under counselor supervision.', 7, null],
        ['pwd_minority_foreign', 'PWD, Minority, and Foreign Students', 'Specialized support and accommodations for persons with disabilities, minority, and foreign students.', 8, null],
        ['community_extension', 'Community Extension', 'Guidance-led outreach programs extending mental-health and career services to the community.', 9, null],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // ---- guidance_announcements ------------------------------------
        if (! $this->db->tableExists('guidance_announcements')) {
            $this->forge->addField([
                'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'title'       => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
                'body'        => ['type' => 'TEXT', 'null' => false],
                'audience'    => ['type' => 'ENUM', 'constraint' => ['all', 'new_students', 'continuing_students', 'graduating_students'], 'null' => false, 'default' => 'all'],
                'action_url'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'action_label' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
                'is_required' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 0],
                'publish_at'  => ['type' => 'DATETIME', 'null' => true],
                'unpublish_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at'  => ['type' => 'DATETIME', 'null' => false],
                'updated_at'  => ['type' => 'DATETIME', 'null' => false],
                'archived_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['tenant_id', 'archived_at']);
            $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
            $this->forge->createTable('guidance_announcements');
        }

        // ---- guidance_services ------------------------------------------
        if (! $this->db->tableExists('guidance_services')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'code'             => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'name'             => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
                'description'      => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
                'cmo_reference'    => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
                'sort_order'       => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => false, 'default' => 0],
                'queue_destination' => ['type' => 'ENUM', 'constraint' => ['clinic', 'counselling'], 'null' => true],
                'is_active'        => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 1],
                'created_by'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at'       => ['type' => 'DATETIME', 'null' => false],
                'updated_at'       => ['type' => 'DATETIME', 'null' => false],
                'archived_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            // The catalogue is per-tenant content; code is unique within it.
            $this->forge->addUniqueKey(['tenant_id', 'code']);
            $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
            $this->forge->createTable('guidance_services');

            // Seed the CMO catalogue for every EXISTING tenant (fresh
            // installs get it via this same loop once tenants exist).
            $tenants = $this->db->table('tenants')->select('id')->get()->getResultArray();
            foreach ($tenants as $tenant) {
                foreach (self::SERVICES as [$code, $name, $description, $sort, $destination]) {
                    $this->db->table('guidance_services')->insert([
                        'tenant_id'        => (int) $tenant['id'],
                        'code'             => $code,
                        'name'             => $name,
                        'description'      => $description,
                        'cmo_reference'    => 'CHED CMO 9 s.2013 — Guidance and Counseling Services',
                        'sort_order'       => $sort,
                        'queue_destination' => $destination,
                        'is_active'        => 1,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
                }
            }
        }

        // ---- permission codes (idempotent) -----------------------------
        $newCodes = [
            'counselling.announcements.manage' => 'counselling',
            'counselling.services.manage'      => 'counselling',
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

        // Map to guidance_admin (the unit administrator owns content).
        $guidanceAdmin = $this->db->table('auth_groups')->where('name', 'guidance_admin')->get()->getRowArray();
        if ($guidanceAdmin !== null) {
            foreach (array_keys($newCodes) as $code) {
                $mapped = $this->db->table('auth_groups_permissions')
                    ->where(['group_id' => (int) $guidanceAdmin['id'], 'permission_code' => $code])
                    ->countAllResults();
                if ($mapped === 0) {
                    $this->db->table('auth_groups_permissions')->insert([
                        'group_id'        => (int) $guidanceAdmin['id'],
                        'permission_code' => $code,
                        'created_at'      => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('guidance_announcements', true);
        $this->forge->dropTable('guidance_services', true);

        foreach (['counselling.announcements.manage', 'counselling.services.manage'] as $code) {
            $this->db->table('auth_groups_permissions')->where('permission_code', $code)->delete();
            $this->db->table('permissions')->where('code', $code)->delete();
        }
    }
}
