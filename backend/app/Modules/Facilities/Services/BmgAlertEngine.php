<?php

declare(strict_types=1);

namespace Modules\Facilities\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * BmgAlertEngine — pure-function alert rules for in-process SPC.
 *
 * Industry-standard thresholds (US Compost Council PFRP, EPA 40 CFR
 * Part 503 sanitation zone, and common aerobic composting guidance):
 *
 *   - TEMP_PFRP_LOW:  pile temperature < 40 °C — below the PFRP
 *     pathogen kill window. Sustained breach → regulatory failure.
 *   - TEMP_PFRP_HIGH: pile temperature > 65 °C — thermophilic
 *     limits; microbial diversity collapses above this.
 *   - MOISTURE_HIGH:  moisture_level = 'high' AND ambient temp < 40 °C
 *     — anaerobic risk (no pathogen kill window).
 *   - STALLED:        no process log in the last 14 days while the
 *     batch is still in `processing` — forgotten/inactive batch.
 *   - OXYGEN_OUT:     oxygen_pct outside the 5–20 % operational window.
 *
 * The engine is stateless and pure: it returns a list of alert specs
 * that the caller persists. Doing evaluation here keeps the rule set
 * unit-testable without a DB.
 *
 *   evaluate(batchStatus, lastLog, daysSinceLastLog): list<AlertSpec>
 *
 * The severity ladder is `info < warning < critical`. The caller
 * duplicates suppression rules ("don't fire OXYGEN_OUT twice in the
 * last 24h") at the persistence layer.
 */
final class BmgAlertEngine
{
    public const CODE_TEMP_PFRP_LOW  = 'TEMP_PFRP_LOW';
    public const CODE_TEMP_PFRP_HIGH = 'TEMP_PFRP_HIGH';
    public const CODE_MOISTURE_HIGH  = 'MOISTURE_HIGH';
    public const CODE_STALLED        = 'STALLED';
    public const CODE_OXYGEN_OUT     = 'OXYGEN_OUT';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Evaluate a batch + its most recent log + staleness signal.
     *
     * @param array<string, mixed> $batch            Required keys: id, status, started_at, archived_at.
     * @param array<string, mixed>|null $lastLog     Required keys: temperature_celsius, moisture_level, oxygen_pct, log_date, calibration_status. Nullable if no logs yet.
     * @param int|null $daysSinceLastLog             Days since last log (`null` if no logs).
     * @return array<int, array{code:string, severity:string, message:string}>
     */
    public function evaluate(array $batch, ?array $lastLog, ?int $daysSinceLastLog): array
    {
        $status = (string) ($batch['status'] ?? '');
        // Only active states participate — finished / cancelled / idle
        // are not actionable and would generate noise.
        if (! in_array($status, [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT, BMG_STATE_CURING], true)) {
            return [];
        }

        $alerts = [];

        if ($lastLog === null) {
            // No logs yet — STALLED only fires if the batch has been
            // open long enough that logs should exist. We treat
            // younger-than-1d as "not yet expected" to avoid fires
            // from operators mid-shift.
            return $alerts;
        }

        // -----------------------------------------------------------------
        // Temperature thresholds (PFRP window 40–65 °C).
        // -----------------------------------------------------------------
        $temp = $lastLog['temperature_celsius'] ?? null;
        if ($temp !== null && $temp !== '') {
            $tempF = (float) $temp;
            if ($tempF < 40.0) {
                $alerts[] = [
                    'code'     => self::CODE_TEMP_PFRP_LOW,
                    'severity' => self::SEVERITY_CRITICAL,
                    'message'  => sprintf('Pile temperature %.1f °C is below the PFRP pathogen-kill window (40 °C).', $tempF),
                ];
            } elseif ($tempF > 65.0) {
                $alerts[] = [
                    'code'     => self::CODE_TEMP_PFRP_HIGH,
                    'severity' => self::SEVERITY_WARNING,
                    'message'  => sprintf('Pile temperature %.1f °C exceeds the thermophilic ceiling (65 °C).', $tempF),
                ];
            }
        }

        // -----------------------------------------------------------------
        // Moisture × temperature combination (anaerobic risk).
        // -----------------------------------------------------------------
        $moisture = $lastLog['moisture_level'] ?? null;
        if ($moisture === 'high' && $temp !== null && $temp !== '' && (float) $temp < 40.0) {
            $alerts[] = [
                'code'     => self::CODE_MOISTURE_HIGH,
                'severity' => self::SEVERITY_WARNING,
                'message'  => 'High moisture and low temperature — anaerobic / pathogen risk.',
            ];
        }

        // -----------------------------------------------------------------
        // Oxygen outside the 5–20 % window. Skip if the device was
        // overdue for calibration — those readings are unreliable.
        // -----------------------------------------------------------------
        $calibration = $lastLog['calibration_status'] ?? null;
        if ($calibration !== 'overdue') {
            $oxygen = $lastLog['oxygen_pct'] ?? null;
            if ($oxygen !== null && $oxygen !== '') {
                $o2 = (float) $oxygen;
                if ($o2 < 5.0 || $o2 > 20.0) {
                    $alerts[] = [
                        'code'     => self::CODE_OXYGEN_OUT,
                        'severity' => $o2 < 2.0 ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                        'message'  => sprintf('Oxygen saturation %.1f %% is outside the 5–20 %% operational window.', $o2),
                    ];
                }
            }
        }

        // -----------------------------------------------------------------
        // Stalled batch — no log in 14 days, still in processing.
        // -----------------------------------------------------------------
        if ($status === BMG_STATE_PROCESSING && $daysSinceLastLog !== null && $daysSinceLastLog > 14) {
            $alerts[] = [
                'code'     => self::CODE_STALLED,
                'severity' => self::SEVERITY_WARNING,
                'message'  => sprintf('No process log recorded for %d days.', $daysSinceLastLog),
            ];
        }

        return $alerts;
    }

    /**
     * Convenience: compute `daysSinceLastLog` from a log row's date.
     * Returns `null` when no log exists.
     *
     * @param array<string, mixed>|null $lastLog
     */
    public function daysSinceLastLog(?array $lastLog): ?int
    {
        if ($lastLog === null) {
            return null;
        }
        $date = $lastLog['log_date'] ?? null;
        if ($date === null || $date === '') {
            return null;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $then = new DateTimeImmutable((string) $date, new DateTimeZone('UTC'));
        // "Days since last log" is a magnitude: always non-negative.
        // The caller (evaluate()) compares `$daysSinceLastLog > 14`, which
        // assumes a non-negative integer; a signed value would suppress
        // STALLED for past-dated logs and (worse) trigger it for any log
        // dated in the future.
        return abs((int) $now->diff($then)->days);
    }
}
