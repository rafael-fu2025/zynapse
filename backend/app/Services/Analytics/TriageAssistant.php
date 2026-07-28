<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * TriageAssistant — deterministic triage suggestion (Phase P2a, recycled
 * from legacy synapse_ag Libraries\TriageAssistant).
 *
 * Pure heuristic (NOT ML): keyword tiers on the chief complaint, then
 * vitals-based escalation, then a severe-allergy force-urgent rule.
 * Stateless and side-effect free so it is fully unit-testable; the
 * orchestrating service handles gathering inputs and persistence.
 *
 * Vitals keys use the ZCode `clinic_vitals` column names
 * (temp_c, pulse_bpm, bp_systolic).
 */
final class TriageAssistant
{
    private const URGENT = [
        'suicide', 'self-harm', 'chest pain', 'breathing', 'unconscious', 'poison',
        'active bleeding', 'stroke', 'seizure', 'heart attack', 'choking', 'anaphylaxis',
    ];
    private const HIGH = [
        'fever', 'severe pain', 'asthma', 'fracture', 'dizzy', 'vomiting',
        'bleeding', 'hypertension', 'abdominal pain', 'migraine', 'burn',
    ];
    private const MEDIUM = [
        'cough', 'cold', 'headache', 'mild pain', 'diarrhea', 'nausea', 'flu',
        'sprain', 'sore throat', 'allergy', 'rash', 'earache', 'constipation',
    ];
    private const LOW = [
        'refill', 'medical certificate', 'check-up', 'consultation', 'wound clean',
        'stitch removal', 'vaccine', 'clearance', 'prescription', 'general',
    ];

    /**
     * @param array<string, mixed>|null       $vitals    ZCode vitals row (temp_c, pulse_bpm, bp_systolic)
     * @param array<int, array<string, mixed>>|null $allergies rows with allergen + severity
     * @return array{predicted_priority: string, confidence_score: float, model_version: string, features_used: array<string, mixed>}
     */
    public function analyze(string $complaint, ?array $vitals = null, ?array $allergies = null): array
    {
        $lc         = strtolower($complaint);
        $priority   = 'low';
        $confidence = 0.60;
        $matches    = 0;

        foreach (self::URGENT as $w) {
            if (str_contains($lc, $w)) {
                $priority = 'urgent';
                $confidence = 0.90;
                $matches++;
            }
        }
        if ($priority !== 'urgent') {
            foreach (self::HIGH as $w) {
                if (str_contains($lc, $w)) {
                    $priority = 'high';
                    $confidence = 0.85;
                    $matches++;
                }
            }
        }
        if ($priority === 'low') {
            foreach (self::MEDIUM as $w) {
                if (str_contains($lc, $w)) {
                    $priority = 'medium';
                    $confidence = 0.75;
                    $matches++;
                }
            }
        }
        if ($priority === 'low') {
            foreach (self::LOW as $w) {
                if (str_contains($lc, $w)) {
                    $confidence = 0.80;
                    $matches++;
                }
            }
        }
        if ($matches > 1) {
            $confidence = min(0.98, $confidence + ($matches * 0.02));
        }

        $features = [
            'keyword_matches'   => $matches,
            'vitals_triggered'  => false,
            'allergy_triggered' => false,
        ];

        // Vitals escalation.
        if ($vitals !== null) {
            $escalated = false;
            if (isset($vitals['temp_c']) && $vitals['temp_c'] !== null && (float) $vitals['temp_c'] >= 38.5) {
                $escalated = true;
                $features['high_temp'] = (float) $vitals['temp_c'];
            }
            if (isset($vitals['pulse_bpm']) && $vitals['pulse_bpm'] !== null && ((int) $vitals['pulse_bpm'] > 120 || (int) $vitals['pulse_bpm'] < 50)) {
                $escalated = true;
                $features['extreme_hr'] = (int) $vitals['pulse_bpm'];
            }
            if (isset($vitals['bp_systolic']) && $vitals['bp_systolic'] !== null && (int) $vitals['bp_systolic'] >= 160) {
                $escalated = true;
                $features['extreme_sbp'] = (int) $vitals['bp_systolic'];
            }
            if ($escalated) {
                $features['vitals_triggered'] = true;
                $priority = $this->escalate($priority);
                $confidence = min(0.99, $confidence + 0.05);
            }
        }

        // Severe-allergy force-urgent.
        if ($allergies !== null) {
            foreach ($allergies as $a) {
                if (($a['severity'] ?? '') !== 'severe') {
                    continue;
                }
                $allergen = strtolower((string) ($a['allergen'] ?? ''));
                if ($allergen !== '' && (str_contains($lc, $allergen) || str_contains($lc, 'allerg'))) {
                    $features['allergy_triggered'] = true;
                    $features['matched_allergen']  = (string) ($a['allergen'] ?? '');
                    $priority = 'urgent';
                    $confidence = 0.95;
                    break;
                }
            }
        }

        return [
            'predicted_priority' => $priority,
            'confidence_score'   => round($confidence, 4),
            'model_version'      => 'weighted_rules_v1.0',
            'features_used'      => $features,
        ];
    }

    private function escalate(string $current): string
    {
        return match ($current) {
            'low'    => 'medium',
            'medium' => 'high',
            default  => 'urgent',
        };
    }
}
