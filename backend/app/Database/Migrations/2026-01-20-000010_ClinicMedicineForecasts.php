<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicMedicineForecasts — deterministic stock forecast log (Phase P2b,
 * recycled from legacy synapse_ag ai_inventory_forecasts).
 *
 * One "latest wins" row per (medicine_id, forecast_date): the service
 * deletes any same-day row before inserting.
 */
final class ClinicMedicineForecasts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                        => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'medicine_id'               => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'forecast_date'             => ['type' => 'DATE', 'null' => false],
            'forecast_period_start'     => ['type' => 'DATE', 'null' => false],
            'forecast_period_end'       => ['type' => 'DATE', 'null' => false],
            'predicted_daily_usage'     => ['type' => 'DECIMAL', 'constraint' => '10,4', 'null' => false],
            'predicted_stockout_date'   => ['type' => 'DATE', 'null' => true],
            'predicted_reorder_date'    => ['type' => 'DATE', 'null' => true],
            'current_stock'             => ['type' => 'INT', 'null' => false],
            'reorder_threshold'         => ['type' => 'INT', 'null' => false],
            'model_type'                => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false, 'default' => 'moving_average'],
            'seasonality_factor'        => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => true],
            'confidence_interval_lower' => ['type' => 'DECIMAL', 'constraint' => '10,4', 'null' => true],
            'confidence_interval_upper' => ['type' => 'DECIMAL', 'constraint' => '10,4', 'null' => true],
            'accuracy_metrics'          => ['type' => 'JSON', 'null' => true],
            'created_at'                => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['medicine_id', 'forecast_date']);
        $this->forge->addForeignKey('medicine_id', 'clinic_medicines', 'id', '', 'CASCADE');
        $this->forge->createTable('clinic_medicine_forecasts');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_medicine_forecasts', true);
    }
}
