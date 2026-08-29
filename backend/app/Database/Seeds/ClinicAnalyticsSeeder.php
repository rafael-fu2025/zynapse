<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ClinicAnalyticsSeeder — DEV/STAGING ONLY.
 *
 * Wipes the whole clinic module (encounters, check-ins, queue, vitals,
 * treatments, appointments, medicines + batches + transactions,
 * inventory + movements, reorder requests) and reseeds a RICH demo
 * dataset so the analytics / data-visualization surfaces actually have
 * something to show:
 *
 *   - ~14 medicines with batches (incl. low-stock, expiring soon and
 *     already-expired lots) and realistic dispensing records
 *   - 10 inventory supplies with receive + dispense movements
 *   - ~55 clinic encounters spread across the trailing ~5 months
 *     (varied statuses, complaint categories, patient types, stations,
 *     vitals, treatments), each with a check-in + queue entry
 *   - ~12 appointments across the same window (linked to some visits)
 *   - ~10 referrals (both directions) + ~8 counselling appointments so
 *     the cross-module analytics tables are populated too
 *
 * Dates are anchored to "today" (UTC) so the data always falls inside
 * the Reports/Dashboard trailing windows no matter when it is run.
 *
 * Refuses to run in production. Idempotent.
 */
final class ClinicAnalyticsSeeder extends Seeder
{
    private const TENANT = 1;

    /** @var array<int, array{id:int, remaining:int}> batch registry keyed by medicine id */
    private array $batchRegistry = [];

    /** @var array<string, int> next sequential queue position per queue_date */
    private array $queuePositions = [];

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('ClinicAnalyticsSeeder must never run in production.');
        }

        $admin = $this->resolveGroupUser('admin');
        $staff = $this->resolveGroupUser('clinic_staff');
        $counsellor = $this->resolveGroupUser('counsellor');
        if ($admin === null || $staff === null) {
            throw new \RuntimeException('ClinicAnalyticsSeeder: admin + clinic_staff users required. Run SeedDemoUsersSeeder first.');
        }

        $this->wipe();

        $medicines = $this->seedMedicines($staff);
        $inventory = $this->seedInventory($staff);
        $appointments = $this->seedAppointments($admin, $staff);
        $encounters = $this->seedVisits($medicines, $inventory, $admin, $staff, $appointments);
        $this->seedReferrals($admin, $staff, $counsellor);
        $this->seedCounselling($counsellor, $staff);

        fwrite(STDOUT, sprintf(
            "ClinicAnalyticsSeeder: %d medicines + %d batches, %d inventory items, %d appointments, %d encounters (spread across ~5 months), %d referrals, %d counselling appointments.\n",
            count($medicines),
            count($this->allBatches()),
            count($inventory),
            count($appointments),
            count($encounters),
            10,
            8,
        ));
    }

    // ------------------------------------------------------------ wipe

    private function wipe(): void
    {
        $db = $this->db;
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $db->table('clinic_reorder_requests')->emptyTable();
            $db->table('clinic_inventory_movements')->emptyTable();
            $db->table('clinic_inventory_items')->emptyTable();
            $db->table('clinic_medicine_transactions')->emptyTable();
            $db->table('clinic_medicine_batches')->emptyTable();
            $db->table('clinic_medicines')->emptyTable();
            $db->table('clinic_checkins')->emptyTable();
            $db->table('clinic_treatments')->emptyTable();
            $db->table('clinic_triage_predictions')->emptyTable();
            $db->table('clinic_vitals')->emptyTable();
            $db->table('clinic_queue_entries')->emptyTable();
            $db->table('clinic_encounters')->emptyTable();
            $db->table('clinic_appointments')->emptyTable();
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $db->table('audit_outbox')
            ->groupStart()
                ->like('action_code', 'clinic.', 'after')
                ->orWhere('entity_type', 'clinic_encounters')
                ->orWhere('entity_type', 'clinic_appointments')
                ->orWhere('entity_type', 'clinic_checkins')
                ->orWhere('entity_type', 'clinic_medicines')
                ->orWhere('entity_type', 'clinic_inventory_items')
            ->groupEnd()
            ->delete();
    }

    // ------------------------------------------------------- medicines

    /**
     * @return array<int, array{generic_name:string, unit:string, reorder_threshold:int, batches:list<array{id:int, remaining:int}>}>
     */
    private function seedMedicines(int $actorId): array
    {
        $now = $this->now();
        $today = $this->today();
        $catalog = [
            ['Paracetamol 500mg', 'Paracetamol', 'Analgesic / Antipyretic', 'tablet', '500 mg', 'tab', 200],
            ['Paracetamol 120mg/5mL', 'Tempra', 'Antipyretic', 'syrup', '120 mg/5 mL', 'btl', 30],
            ['Ibuprofen 200mg', 'Advil', 'NSAID', 'tablet', '200 mg', 'tab', 150],
            ['Mefenamic acid 250mg', 'Dolfenal', 'NSAID', 'capsule', '250 mg', 'cap', 120],
            ['Cetirizine 10mg', 'Zyrtec', 'Antihistamine', 'tablet', '10 mg', 'tab', 90],
            ['Loratadine 10mg', 'Claritin', 'Antihistamine', 'tablet', '10 mg', 'tab', 80],
            ['Amoxicillin 500mg', 'Amoxil', 'Antibiotic', 'capsule', '500 mg', 'cap', 100],
            ['Co-amoxiclav 625mg', 'Augmentin', 'Antibiotic', 'tablet', '625 mg', 'tab', 60],
            ['ORS sachet', 'Pedialyte', 'Rehydration', 'powder', '21 g', 'sachet', 120],
            ['Loperamide 2mg', 'Imodium', 'Antidiarrheal', 'capsule', '2 mg', 'cap', 70],
            ['Hyoscine 10mg', 'Buscopan', 'Antispasmodic', 'tablet', '10 mg', 'tab', 50],
            ['Salbutamol 100mcg', 'Ventolin', 'Bronchodilator', 'inhaler', '100 mcg/dose', 'puff', 40],
            ['Dextromethorphan 15mg/5mL', 'Robitussin DM', 'Cough', 'syrup', '15 mg/5 mL', 'btl', 25],
            ['Mupirocin 2% ointment', 'Bactroban', 'Topical antibiotic', 'ointment', '2%', 'tube', 35],
            ['Chlorphenamine 4mg', 'Piriton', 'Antihistamine', 'tablet', '4 mg', 'tab', 60],
            ['Clotrimazole 1% cream', 'Canesten', 'Antifungal', 'cream', '1%', 'tube', 30],
        ];

        $result = [];
        foreach ($catalog as $i => $c) {
            [$generic, $brand, $category, $form, $strength, $unit, $threshold] = $c;
            $this->db->table('clinic_medicines')->insert([
                'tenant_id' => self::TENANT,
                'generic_name' => $generic,
                'brand_name' => $brand,
                'category' => $category,
                'dosage_form' => $form,
                'dosage_strength' => $strength,
                'unit' => $unit,
                'reorder_threshold' => $threshold,
                'target_stock' => $threshold * 2,
                'description' => $category . ' — school clinic stock.',
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $medId = (int) $this->db->insertID();

            $batches = $this->buildBatches($i, $medId, $actorId, $today);
            $result[$medId] = [
                'generic_name' => $generic,
                'unit' => $unit,
                'reorder_threshold' => $threshold,
                'batches' => $batches,
            ];
        }

        return $result;
    }

    /**
     * Create 1-2 batches per medicine. Some lots are already expired,
     * some expire within 90 days, some are nearly depleted (low stock).
     *
     * @return list<array{id:int, remaining:int}>
     */
    private function buildBatches(int $medIndex, int $medId, int $actorId, string $today): array
    {
        $now = $this->now();
        $receivedDate = $this->date(-160);

        $makeBatch = function (string $batchNo, int $received, int $remaining, string $expiry, string $status, string $supplier) use ($medId, $receivedDate, $actorId, $now): int {
            $this->db->table('clinic_medicine_batches')->insert([
                'tenant_id' => self::TENANT,
                'medicine_id' => $medId,
                'batch_number' => $batchNo,
                'quantity_received' => $received,
                'quantity_remaining' => $remaining,
                'expiration_date' => $expiry,
                'received_date' => $receivedDate,
                'supplier' => $supplier,
                'status' => $status,
                'created_at' => $now,
            ]);
            $batchId = (int) $this->db->insertID();

            // Initial receive ledger row (balance = received).
            $this->db->table('clinic_medicine_transactions')->insert([
                'tenant_id' => self::TENANT,
                'medicine_id' => $medId,
                'batch_id' => $batchId,
                'type' => 'received',
                'quantity' => $received,
                'balance_after' => $received,
                'reference_type' => null,
                'reference_id' => null,
                'performed_by_user_id' => $actorId,
                'note' => 'Initial stock receipt — ' . $supplier,
                'created_at' => $now,
            ]);

            $this->batchRegistry[$medId][] = ['id' => $batchId, 'remaining' => $remaining];
            return $batchId;
        };

        // Deterministic per-medicine variation using the index.
        $mode = $medIndex % 5;
        switch ($mode) {
            case 0: // one generous batch
                $makeBatch('LOT-' . $medIndex . '-A', 300, 240, $this->date(540), 'active', 'MedSource Pharma');
                break;
            case 1: // low stock (at/below threshold)
                $makeBatch('LOT-' . $medIndex . '-A', 120, 18, $this->date(300), 'active', 'HealthFirst');
                break;
            case 2: // two batches, one nearly depleted
                $makeBatch('LOT-' . $medIndex . '-A', 100, 4, $this->date(90), 'active', 'MedSource Pharma');
                $makeBatch('LOT-' . $medIndex . '-B', 150, 130, $this->date(400), 'active', 'HealthFirst');
                break;
            case 3: // expiring soon + an ACTIVE lot already past expiry
                $makeBatch('LOT-' . $medIndex . '-A', 80, 40, $this->date(45), 'active', 'CarePlus');
                $makeBatch('LOT-' . $medIndex . '-B', 60, 12, $this->date(-5), 'active', 'CarePlus');
                break;
            default: // expired lot (all written off) + fresh stock
                $makeBatch('LOT-' . $medIndex . '-A', 50, 0, $this->date(-60), 'expired', 'Legacy Stock');
                $makeBatch('LOT-' . $medIndex . '-B', 200, 160, $this->date(360), 'active', 'MedSource Pharma');
                break;
        }

        return $this->batchRegistry[$medId];
    }

    // ------------------------------------------------------ inventory

    /**
     * @return array<int, int> item id => on-hand
     */
    private function seedInventory(int $actorId): array
    {
        $now = $this->now();
        $items = [
            ['GLV-M-100', 'Disposable gloves (M, 100/box)', 'box', 4, 5],
            ['MSK-3PLY-50', '3-ply surgical mask (50/box)', 'box', 12, 6],
            ['ORS-21G', 'ORS sachets (21g, 25/box)', 'box', 3, 4],
            ['PCM-500-100', 'Paracetamol 500mg (100/btl)', 'btl', 14, 5],
            ['BDG-25-PK', 'Adhesive bandages 25x72mm (100/pk)', 'pack', 22, 8],
            ['ALC-70-500', 'Isopropyl alcohol 70% (500ml)', 'btl', 9, 4],
            ['GAU-5CM', 'Gauze roll 5cm (12/roll)', 'pack', 2, 4],
            ['BP-CUF-AA', 'BP cuff battery (AA, 4/pk)', 'pack', 11, 3],
            ['THM-CV-200', 'Thermometer probe covers (200/box)', 'box', 5, 2],
            ['CLD-PK-6', 'Instant cold packs (6/pk)', 'pack', 7, 3],
            ['SYR-10ML', 'Disposable syringes 10mL', 'pc', 24, 10],
            ['TPE-TAPE-1', 'Micropore tape (1 roll)', 'roll', 6, 3],
        ];

        $itemIds = [];
        foreach ($items as [$sku, $name, $unit, $qty, $reorder]) {
            $this->db->table('clinic_inventory_items')->insert([
                'sku' => $sku,
                'name' => $name,
                'unit' => $unit,
                'quantity_on_hand' => $qty,
                'reorder_level' => $reorder,
                'target_stock' => max(1, $reorder * 2),
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $itemId = (int) $this->db->insertID();
            $itemIds[$itemId] = $qty;

            // Initial receive movement.
            $this->db->table('clinic_inventory_movements')->insert([
                'item_id' => $itemId,
                'qty_delta' => $qty,
                'reason_code' => 'receive',
                'reference_type' => null,
                'reference_id' => null,
                'balance_after' => $qty,
                'moved_by_user_id' => $actorId,
                'note' => 'Initial stock receipt',
                'created_at' => $now,
            ]);
        }

        return $itemIds;
    }

    // ---------------------------------------------------- appointments

    /**
     * @return array<int, int> appointment id => encounter-ready (0/1)
     */
    private function seedAppointments(int $admin, int $staff): array
    {
        $now = $this->now();
        $students = $this->studentNumbers();
        $employees = $this->employeeNumbers();

        $rows = [
            // Historical (closed visits) — spread across the window.
            ['patient' => $students[0],  'provider' => $staff, 'offset' => -160, 'time' => '08:00:00', 'status' => 'completed', 'reason' => 'Routine check-up'],
            ['patient' => $students[1],  'provider' => $admin, 'offset' => -155, 'time' => '09:30:00', 'status' => 'completed', 'reason' => 'Vaccination booster'],
            ['patient' => $employees[0], 'provider' => $staff, 'offset' => -120, 'time' => '10:15:00', 'status' => 'completed', 'reason' => 'BP monitoring'],
            ['patient' => $students[2],  'provider' => $staff, 'offset' => -95,  'time' => '13:00:00', 'status' => 'completed', 'reason' => 'Medication refill'],
            ['patient' => $students[3],  'provider' => $admin, 'offset' => -70,  'time' => '14:30:00', 'status' => 'no_show', 'reason' => 'Health screening'],
            ['patient' => $students[4],  'provider' => $staff, 'offset' => -45,  'time' => '09:00:00', 'status' => 'completed', 'reason' => 'Sports physical'],
            ['patient' => $students[5],  'provider' => $staff, 'offset' => -21,  'time' => '15:00:00', 'status' => 'completed', 'reason' => 'First-aid: minor cut'],
            ['patient' => $employees[1], 'provider' => $admin, 'offset' => -7,   'time' => '08:45:00', 'status' => 'completed', 'reason' => 'Lab result follow-up'],
            // Future (scheduled / checked-in) for the schedule flow.
            ['patient' => $students[6],  'provider' => $staff, 'offset' => 0,    'time' => '09:00:00', 'status' => 'checked_in', 'reason' => 'Walk-in'],
            ['patient' => $students[7],  'provider' => $admin, 'offset' => 1,    'time' => '10:00:00', 'status' => 'scheduled', 'reason' => 'Follow-up consultation'],
            ['patient' => $students[8],  'provider' => $staff, 'offset' => 2,    'time' => '11:30:00', 'status' => 'scheduled', 'reason' => 'Routine check-up'],
            ['patient' => $students[9],  'provider' => $staff, 'offset' => 5,    'time' => '14:00:00', 'status' => 'scheduled', 'reason' => 'Allergy consult'],
        ];

        $ids = [];
        foreach ($rows as $r) {
            $scheduledAt = $this->datetime($r['offset'], $r['time']);
            $this->db->table('clinic_appointments')->insert([
                'tenant_id' => self::TENANT,
                'patient_school_id' => $r['patient'],
                'patient_user_id' => $this->userIdByNumber($r['patient']),
                'provider_user_id' => $r['provider'],
                'scheduled_at' => $scheduledAt,
                'status' => $r['status'],
                'reason' => $r['reason'],
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ids[(int) $this->db->insertID()] = $r['offset'] < 0 ? 1 : 0;
        }

        return $ids;
    }

    // --------------------------------------------------- visits / flow

    /**
     * Seed encounters (with vitals, treatments, check-ins, queue rows,
     * medicine + supply dispensing). Returns encounter ids created.
     *
     * @param array<int, array{generic_name:string, unit:string, reorder_threshold:int, batches:list<array{id:int, remaining:int}>}> $medicines
     * @param array<int, int> $inventory item id => on-hand
     * @param array<int, int> $appointments appointment id => encounter-ready
     * @return list<int>
     */
    private function seedVisits(array $medicines, array $inventory, int $admin, int $staff, array $appointments): array
    {
        $students = $this->studentNumbers();
        $employees = $this->employeeNumbers();
        $medIds = array_keys($medicines);

        // Complaint pools mapped to the report's privacy-safe categories.
        $complaints = [
            'Cough and cold', 'Sore throat', 'Difficulty breathing (asthma)', 'Runny nose and congestion',
            'Sprained ankle', 'Cut / laceration', 'Lower back pain', 'Headache after a fall', 'Knee pain',
            'Stomach ache', 'Nausea and vomiting', 'Diarrhea', 'Abdominal cramps',
            'Fever', 'Suspected ear infection', 'Flu-like symptoms',
            'Routine check-up', 'School physical clearance', 'BP monitoring', 'Follow-up check-up',
            'Skin rash', 'Allergy reaction', 'Fatigue', 'Dental pain',
        ];

        $encounterIds = [];
        $apptPool = array_keys(array_filter($appointments, static fn (int $v): bool => $v === 1));
        $apptIdx = 0;

        // Month-bucketed plan so every trailing month (including the
        // current one) has a healthy number of visits — the bar chart
        // reads as a rising 5-6 month trend instead of one huge month.
        $plan = [];
        foreach ([5 => 15, 4 => 16, 3 => 18, 2 => 20, 1 => 24, 0 => 12] as $monthsBack => $count) {
            for ($k = 0; $k < $count; $k++) {
                $plan[] = $monthsBack;
            }
        }
        shuffle($plan);
        $totalVisits = count($plan);

        for ($i = 0; $i < $totalVisits; $i++) {
            $offset = -$this->daysAgoInMonth($plan[$i]);
            $hour = mt_rand(7, 16);
            $minute = [0, 15, 30, 45][mt_rand(0, 3)];
            $startedAt = $this->datetime($offset, sprintf('%02d:%02d:00', $hour, $minute));
            $closed = $i % 5 !== 4; // ~80% closed
            $referred = $i % 13 === 12; // occasional referred
            $status = $referred ? 'referred' : ($closed ? 'closed' : 'open');
            $isGuest = $i % 8 === 7; // ~12% guest
            $isEmployee = ! $isGuest && $i % 5 === 3; // ~20% employee
            $attending = $i % 3 === 2 ? $admin : $staff;

            $patientNum = null;
            $patientUserId = null;
            $guestName = null;
            if ($isGuest) {
                $guestName = ['Walk-in visitor', 'Day scholar parent', 'Campus guest', 'Contractor'][mt_rand(0, 3)];
            } elseif ($isEmployee) {
                $patientNum = $employees[mt_rand(0, count($employees) - 1)];
                $patientUserId = $this->userIdByNumber($patientNum);
            } else {
                $patientNum = $students[mt_rand(0, count($students) - 1)];
                $patientUserId = $this->userIdByNumber($patientNum);
            }

            $chief = $complaints[$i % count($complaints)];
            $diagnosis = $this->diagnosisFor($chief);
            $closedAt = $closed || $referred
                ? $this->datetime($offset, sprintf('%02d:%02d:00', $hour + (mt_rand(1, 3)), ($minute + mt_rand(0, 45)) % 60))
                : null;
            $station = $i % 3 === 0 ? 'Kiosk-0' . (($i % 3) + 1) : null;

            // Link some historical visits to an appointment.
            $appointmentId = null;
            if ($closed && $apptIdx < count($apptPool)) {
                $appointmentId = $apptPool[$apptIdx++];
            }

            $this->db->table('clinic_encounters')->insert([
                'tenant_id' => self::TENANT,
                'patient_school_id' => $patientNum,
                'patient_user_id' => $patientUserId,
                'guest_name' => $guestName,
                'appointment_id' => $appointmentId,
                'chief_complaint' => $chief,
                'triage_priority' => ['low', 'medium', 'medium', 'high'][mt_rand(0, 3)],
                'triage_override' => 0,
                'diagnosis' => $diagnosis,
                'status' => $status,
                'attending_user_id' => $attending,
                'station_id' => $station,
                'started_at' => $startedAt,
                'closed_at' => $closedAt,
                // chk_ce_outcome allows only NULL | no_show | auto_closed;
                // the status column already carries closed/referred/open.
                'outcome' => null,
                'archived_at' => null,
                'created_at' => $startedAt,
                'updated_at' => $closedAt ?? $startedAt,
            ]);
            $encounterId = (int) $this->db->insertID();
            $encounterIds[] = $encounterId;

            $this->seedCheckin($encounterId, $patientNum, $patientUserId, $guestName, $attending, $startedAt, $station, $chief, $status);
            $this->seedQueueEntry($encounterId, $startedAt, $closedAt, $status, $attending);
            $this->seedVitals($encounterId, $attending, $startedAt);
            $this->seedTreatment($encounterId, $attending, $startedAt, $chief, $medicines, $medIds);

            // Medicine dispense for ~65% of closed visits.
            if (($closed || $referred) && $i % 3 !== 2) {
                $this->dispenseMedicines($encounterId, $medicines, $medIds, $attending, $startedAt);
            }
            // Supply dispense for ~30% of visits.
            if ($i % 10 < 3) {
                $this->dispenseSupply($encounterId, $inventory, $attending, $startedAt);
            }
        }

        return $encounterIds;
    }

    private function seedCheckin(int $encounterId, ?string $patientNum, ?int $patientUserId, ?string $guestName, int $actor, string $at, ?string $station, string $purpose, string $status): void
    {
        $method = $station !== null ? 'qr' : 'manual';
        $outcome = $status === 'referred' ? 'counselling_confirmed' : 'clinic_queued';
        $this->db->table('clinic_checkins')->insert([
            'tenant_id' => self::TENANT,
            'patient_school_id' => $patientNum,
            'patient_user_id' => $patientUserId,
            'method' => $method,
            'station_id' => $station,
            'outcome' => $outcome,
            'purpose' => $purpose,
            'guest_name' => $guestName,
            'counselling_appointment_id' => null,
            'encounter_id' => $encounterId,
            'recorded_by_user_id' => $actor,
            'scanned_at' => $at,
            'created_at' => $at,
        ]);
    }

    private function seedQueueEntry(int $encounterId, string $startedAt, ?string $closedAt, string $status, int $actor): void
    {
        $date = substr($startedAt, 0, 10);
        $isClosed = $status !== 'open';
        // Sequential per-day position — the queue_date_position key is UNIQUE.
        $position = ($this->queuePositions[$date] ?? 0) + 1;
        $this->queuePositions[$date] = $position;
        $this->db->table('clinic_queue_entries')->insert([
            'tenant_id' => self::TENANT,
            'encounter_id' => $encounterId,
            'queue_date' => $date,
            'position' => $position,
            'status' => $isClosed ? 'done' : 'waiting',
            'called_at' => $isClosed ? $this->addMinutes($startedAt, 5) : null,
            'called_by_user_id' => $isClosed ? $actor : null,
            'started_at' => $startedAt,
            'finished_at' => $isClosed ? ($closedAt ?? $this->addMinutes($startedAt, 25)) : null,
            // chk_cqe_outcome allows only NULL | no_show | auto_closed.
            'outcome' => null,
            'created_at' => $startedAt,
            'updated_at' => $closedAt ?? $startedAt,
        ]);
    }

    private function seedVitals(int $encounterId, int $actor, string $at): void
    {
        $this->db->table('clinic_vitals')->insert([
            'encounter_id' => $encounterId,
            'bp_systolic' => mt_rand(100, 140),
            'bp_diastolic' => mt_rand(60, 90),
            'pulse_bpm' => mt_rand(62, 104),
            'temp_c' => (string) (36.2 + (mt_rand(0, 40) / 10)),
            'spo2_pct' => mt_rand(95, 100),
            'weight_kg' => (string) (40 + mt_rand(0, 500) / 10),
            'height_cm' => (string) (145 + mt_rand(0, 450) / 10),
            'recorded_by_user_id' => $actor,
            'recorded_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * @param array<int, array{generic_name:string, unit:string, reorder_threshold:int, batches:list<array{id:int, remaining:int}>}> $medicines
     * @param list<int> $medIds
     */
    private function seedTreatment(int $encounterId, int $actor, string $at, string $chief, array $medicines, array $medIds): void
    {
        $types = ['first_aid', 'procedure', 'other'];
        $descriptions = [
            'Cleaned and dressed the wound', 'Applied cold compress', 'Advised rest and hydration',
            'Applied antiseptic', 'Advised follow-up if symptoms persist', 'Referral to specialist noted',
        ];
        $this->db->table('clinic_treatments')->insert([
            'tenant_id' => self::TENANT,
            'encounter_id' => $encounterId,
            'treatment_type' => $types[mt_rand(0, count($types) - 1)],
            'description' => $descriptions[mt_rand(0, count($descriptions) - 1)],
            'batch_id' => null,
            'medicine_id' => null,
            'quantity_used' => null,
            'administered_by_user_id' => $actor,
            'administered_at' => $this->addMinutes($at, mt_rand(2, 12)),
            'created_at' => $at,
        ]);
    }

    /**
     * Dispense 1-2 medicines for a visit (FEFO-ish by decrementing the
     * first active batch), writing ledger rows with running balance.
     *
     * @param array<int, array{generic_name:string, unit:string, reorder_threshold:int, batches:list<array{id:int, remaining:int}>}> $medicines
     * @param list<int> $medIds
     */
    private function dispenseMedicines(int $encounterId, array $medicines, array $medIds, int $actor, string $at): void
    {
        $count = mt_rand(1, 2);
        for ($k = 0; $k < $count; $k++) {
            $medId = $medIds[mt_rand(0, count($medIds) - 1)];
            $med = $medicines[$medId];
            $qty = mt_rand(1, 4);

            // Find an active batch with remaining stock.
            $taken = false;
            foreach ($med['batches'] as &$batch) {
                if ($batch['remaining'] > 0 && $qty <= $batch['remaining']) {
                    $batch['remaining'] -= $qty;
                    $this->batchRegistry[$medId] = $med['batches'];
                    $this->db->table('clinic_medicine_transactions')->insert([
                        'tenant_id' => self::TENANT,
                        'medicine_id' => $medId,
                        'batch_id' => $batch['id'],
                        'type' => 'dispensed',
                        'quantity' => $qty,
                        'balance_after' => $batch['remaining'],
                        'reference_type' => 'encounter',
                        'reference_id' => $encounterId,
                        'performed_by_user_id' => $actor,
                        'note' => 'Dispensed during visit #' . $encounterId,
                        'created_at' => $at,
                    ]);
                    $taken = true;
                    break;
                }
            }
            unset($batch);

            // If the only batch was low but had some stock, take what's left.
            if (! $taken) {
                foreach ($med['batches'] as &$batch) {
                    if ($batch['remaining'] > 0) {
                        $take = min($qty, $batch['remaining']);
                        $batch['remaining'] -= $take;
                        $this->batchRegistry[$medId] = $med['batches'];
                        $this->db->table('clinic_medicine_transactions')->insert([
                            'tenant_id' => self::TENANT,
                            'medicine_id' => $medId,
                            'batch_id' => $batch['id'],
                            'type' => 'dispensed',
                            'quantity' => $take,
                            'balance_after' => $batch['remaining'],
                            'reference_type' => 'encounter',
                            'reference_id' => $encounterId,
                            'performed_by_user_id' => $actor,
                            'note' => 'Dispensed during visit #' . $encounterId,
                            'created_at' => $at,
                        ]);
                        $taken = true;
                        break;
                    }
                }
                unset($batch);
            }
        }
    }

    /**
     * @param array<int, int> $inventory item id => on-hand
     */
    private function dispenseSupply(int $encounterId, array $inventory, int $actor, string $at): void
    {
        $itemIds = array_keys($inventory);
        $itemId = $itemIds[mt_rand(0, count($itemIds) - 1)];
        $qty = mt_rand(1, 2);
        $before = $inventory[$itemId];
        $after = max(0, $before - $qty);

        $this->db->table('clinic_inventory_items')
            ->where('id', $itemId)
            ->update(['quantity_on_hand' => $after, 'updated_at' => $at]);
        $this->db->table('clinic_inventory_movements')->insert([
            'item_id' => $itemId,
            'qty_delta' => -$qty,
            'reason_code' => 'dispense',
            'reference_type' => 'encounter',
            'reference_id' => $encounterId,
            'balance_after' => $after,
            'moved_by_user_id' => $actor,
            'note' => 'Dispensed during visit #' . $encounterId,
            'created_at' => $at,
        ]);
    }

    // ------------------------------------------------------ referrals

    private function seedReferrals(int $admin, int $staff, ?int $counsellor): void
    {
        $now = $this->now();
        $students = $this->studentNumbers();
        $provider = $counsellor ?? $staff;

        $flows = [
            ['clinic', 'counselling', 'submitted'],
            ['clinic', 'counselling', 'acknowledged'],
            ['clinic', 'counselling', 'under_review'],
            ['clinic', 'counselling', 'closed'],
            ['counselling', 'clinic', 'submitted'],
            ['counselling', 'clinic', 'acknowledged'],
            ['counselling', 'clinic', 'under_review'],
            ['clinic', 'counselling', 'closed'],
            ['counselling', 'clinic', 'closed'],
            ['clinic', 'counselling', 'acknowledged'],
        ];

        foreach ($flows as $i => $flow) {
            [$source, $target, $status] = $flow;
            $offset = -160 + ($i * 16);
            $issuer = $source === 'clinic' ? $staff : $provider;
            $patient = $students[$i % count($students)];
            $this->db->table('referral_referrals')->insert([
                'tenant_id' => self::TENANT,
                'patient_school_id' => $patient,
                'patient_user_id' => $this->userIdByNumber($patient),
                'source_module' => $source,
                'target_module' => $target,
                'artifact_type' => $source === 'clinic' ? 'intake_pass' : 'referral_letter',
                'issuer_user_id' => $issuer,
                'provider_user_id' => $source === 'clinic' ? $provider : $staff,
                'status' => $status,
                'reason_code' => $status === 'closed' ? 'completed' : null,
                'notes_cipher' => null,
                'notes_nonce' => null,
                'notes_key_version' => null,
                'qr_token_hash' => null,
                'qr_expires_at' => null,
                'qr_revoked_at' => null,
                'created_at' => $this->datetime($offset, '09:00:00'),
                'updated_at' => $this->datetime($offset, '09:00:00'),
                'archived_at' => null,
            ]);
        }

        // Keep the reference table happy (unused param).
        unset($admin);
    }

    // ---------------------------------------------------- counselling

    private function seedCounselling(?int $counsellor, int $staff): void
    {
        $now = $this->now();
        $students = $this->studentNumbers();
        $c = $counsellor ?? $staff;

        $statuses = ['scheduled', 'confirmed', 'completed', 'completed', 'no_show', 'cancelled', 'completed', 'confirmed'];
        foreach ($statuses as $i => $status) {
            $offset = -150 + ($i * 19);
            $patient = $students[$i % count($students)];
            $this->db->table('counselling_appointments')->insert([
                'tenant_id' => self::TENANT,
                'patient_school_id' => $patient,
                'patient_user_id' => $this->userIdByNumber($patient),
                'counsellor_user_id' => $c,
                'appointment_date' => $this->date($offset),
                'start_time' => sprintf('%02d:00:00', 8 + ($i % 6)),
                'end_time' => sprintf('%02d:00:00', 9 + ($i % 6)),
                'type' => ['initial', 'follow_up', 'follow_up', 'initial', 'initial', 'crisis', 'referral_based', 'follow_up'][$i],
                'status' => $status,
                'reason' => ['Anxiety management', 'Academic stress', 'Sleep concerns', 'Grief support', 'Counselling intake', 'Crisis support', 'Referral-based counselling', 'Peer conflict'][$i],
                'cancellation_reason' => $status === 'cancelled' ? 'Student unavailable' : null,
                'created_by_user_id' => $c,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // -------------------------------------------------------- helpers

    /** @return list<string> */
    private function studentNumbers(): array
    {
        return $this->numbersFor('student', 'student_number');
    }

    /** @return list<string> */
    private function employeeNumbers(): array
    {
        return $this->numbersFor('employee', 'employee_number');
    }

    /** @return list<string> */
    private function numbersFor(string $kind, string $column): array
    {
        $rows = $this->db->table('users')
            ->select($column . ' AS num')
            ->where('kind', $kind)
            ->where($column . ' IS NOT NULL', null, false)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['num'];
        }
        return $out;
    }

    private function userIdByNumber(string $number): ?int
    {
        $row = $this->db->table('users')
            ->select('id')
            ->groupStart()
                ->where('student_number', $number)
                ->orWhere('employee_number', $number)
            ->groupEnd()
            ->limit(1)
            ->get()->getRowArray();
        return $row !== null ? (int) $row['id'] : null;
    }

    private function resolveGroupUser(string $group): ?int
    {
        $row = $this->db->table('auth_groups_users gu')
            ->select('gu.user_id')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('g.name', $group)
            ->orderBy('gu.user_id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();
        return $row !== null ? (int) $row['user_id'] : null;
    }

    private function diagnosisFor(string $chief): string
    {
        $map = [
            'Cough and cold' => 'Acute upper respiratory tract infection',
            'Sore throat' => 'Acute pharyngitis',
            'Difficulty breathing (asthma)' => 'Bronchial asthma, mild exacerbation',
            'Runny nose and congestion' => 'Allergic rhinitis',
            'Sprained ankle' => 'Ankle sprain, grade I',
            'Cut / laceration' => 'Superficial laceration',
            'Lower back pain' => 'Mechanical low back pain',
            'Headache after a fall' => 'Minor head injury, no concussion',
            'Knee pain' => 'Patellofemoral pain',
            'Stomach ache' => 'Dyspepsia',
            'Nausea and vomiting' => 'Acute gastroenteritis',
            'Diarrhea' => 'Acute gastroenteritis',
            'Abdominal cramps' => 'Irritable bowel-like symptoms',
            'Fever' => 'Viral fever',
            'Suspected ear infection' => 'Acute otitis media',
            'Flu-like symptoms' => 'Influenza-like illness',
            'Routine check-up' => 'Unremarkable on examination',
            'School physical clearance' => 'Fit for school activities',
            'BP monitoring' => 'Essential hypertension, monitoring',
            'Follow-up check-up' => 'Improving, ongoing follow-up',
            'Skin rash' => 'Contact dermatitis',
            'Allergy reaction' => 'Mild allergic reaction',
            'Fatigue' => 'Exhaustion / sleep deficit',
            'Dental pain' => 'Suspected dental caries, refer',
        ];

        return $map[$chief] ?? 'Unspecified / under observation';
    }

    /** @return list<array{id:int, remaining:int}> */
    private function allBatches(): array
    {
        $out = [];
        foreach ($this->batchRegistry as $batches) {
            foreach ($batches as $b) {
                $out[] = $b;
            }
        }
        return $out;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    /**
     * Random day offset (in days-ago) inside a given month back from
     * today. The current month is clamped to today so some visits land
     * on offset 0 (live-looking queue/kiosk data).
     */
    private function daysAgoInMonth(int $monthsBack): int
    {
        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $monthStart = (new DateTimeImmutable($today->format('Y-m-01'), new DateTimeZone('UTC')))->modify('-' . $monthsBack . ' months');
        $monthEnd = $monthStart->modify('+1 month -1 day');
        $max = $monthEnd < $today ? $monthEnd : $today;
        $days = max(0, (int) $monthStart->diff($max)->days);
        $date = $monthStart->modify('+' . mt_rand(0, $days) . ' days');
        return max(0, (int) $today->diff($date)->days);
    }

    private function date(int $offsetDays): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')->format('Y-m-d');
    }

    private function datetime(int $offsetDays, string $time): string
    {
        return $this->date($offsetDays) . ' ' . $time;
    }

    private function addMinutes(string $datetime, int $minutes): string
    {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
    }
}
