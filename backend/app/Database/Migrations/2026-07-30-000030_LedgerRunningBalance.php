<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * LedgerRunningBalance — panel revision (July 2026):
 *
 * Ledger-style in/out tracking: every stock movement row now carries
 * `balance_after` — the on-hand quantity right after the movement was
 * applied — so the SPA can render a debit/credit ledger (Date | In |
 * Out | Balance) without recomputing sums client-side.
 *
 *   - clinic_inventory_movements.balance_after
 *       = running SUM(qty_delta) per item (exact reconstruction).
 *   - clinic_medicine_transactions.balance_after
 *       = stock on hand after the transaction. Backfilled as a signed
 *         cumulative sum (received/returned = in, everything else =
 *         out) — the best reconstruction the typed ledger allows.
 *         Going forward the services store the authoritative batch-sum
 *         balance at write time.
 */
final class LedgerRunningBalance extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('clinic_inventory_movements', [
            'balance_after' => ['type' => 'INT', 'null' => true, 'after' => 'reason_code'],
        ]);
        $this->forge->addColumn('clinic_medicine_transactions', [
            'balance_after' => ['type' => 'INT', 'null' => true, 'after' => 'quantity'],
        ]);

        // Backfill supplies: exact per-item running sum of signed deltas.
        $this->db->query(<<<'SQL'
            UPDATE clinic_inventory_movements m
            JOIN (
                SELECT id,
                       SUM(qty_delta) OVER (PARTITION BY item_id ORDER BY created_at, id) AS bal
                FROM clinic_inventory_movements
            ) t ON t.id = m.id
            SET m.balance_after = t.bal
        SQL);

        // Backfill medicines: signed cumulative sum by transaction type.
        $this->db->query(<<<'SQL'
            UPDATE clinic_medicine_transactions x
            JOIN (
                SELECT id,
                       SUM(CASE WHEN type IN ('received', 'returned') THEN quantity ELSE -quantity END)
                           OVER (PARTITION BY medicine_id ORDER BY created_at, id) AS bal
                FROM clinic_medicine_transactions
            ) t ON t.id = x.id
            SET x.balance_after = t.bal
        SQL);
    }

    public function down(): void
    {
        $this->forge->dropColumn('clinic_inventory_movements', 'balance_after');
        $this->forge->dropColumn('clinic_medicine_transactions', 'balance_after');
    }
}
