<?php

namespace App\Services;

use R;

class PredictorService
{
    /**
     * Calculate oxygen needs using standard hospital prediction weights.
     */
    public function calculateNeeds(array $params): float
    {
        $peaditric  = max(0.0, (float)($params['peaditric'] ?? $params['pediatric'] ?? 0));
        $malaria    = max(0.0, (float)($params['malaria'] ?? 0));
        $intensive  = max(0.0, (float)($params['intensive'] ?? 0));
        $accident   = max(0.0, (float)($params['accident'] ?? 0));
        $theatre    = max(0.0, (float)($params['theatre'] ?? 0));
        $maternity  = max(0.0, (float)($params['materinity'] ?? $params['maternity'] ?? 0));
        $typhoid    = max(0.0, (float)($params['typhoid'] ?? 0));
        $diabetes   = max(0.0, (float)($params['diabetes'] ?? 0));

        $needs = (
            13.01
            + (3.5123 * $peaditric)
            + (5.4793 * $malaria)
            + (2.2490 * $intensive)
            + (6.8767 * $accident)
            + ($theatre * 0.1935)
            + ($maternity * 5.9922)
            + ($typhoid * -10.1190)
            + ($diabetes * -6.0203)
        );

        return max(0.0, round($needs, 2));
    }

    /**
     * Calculate oxygen needs using supervisor-adjusted prediction weights.
     */
    public function calculateSupervisorNeeds(array $params): float
    {
        $peaditric  = max(0.0, (float)($params['peaditric'] ?? $params['pediatric'] ?? 0));
        $malaria    = max(0.0, (float)($params['malaria'] ?? 0));
        $intensive  = max(0.0, (float)($params['intensive'] ?? 0));
        $accident   = max(0.0, (float)($params['accident'] ?? 0));
        $theatre    = max(0.0, (float)($params['theatre'] ?? 0));
        $maternity  = max(0.0, (float)($params['materinity'] ?? $params['maternity'] ?? 0));
        $typhoid    = max(0.0, (float)($params['typhoid'] ?? 0));
        $diabetes   = max(0.0, (float)($params['diabetes'] ?? 0));

        $needs = (
            13.1414
            + (3.7123 * $peaditric)
            + (5.4793 * $malaria)
            + (2.2490 * $intensive)
            + (6.8767 * $accident)
            + ($theatre * 0.1935)
            + ($maternity * 5.9922)
            + ($typhoid * -10.1190)
            + ($diabetes * -6.0203)
        );

        return max(0.0, round($needs, 2));
    }

    /**
     * Save a prediction run to the database.
     */
    public function savePrediction(int $hospitalId, float $needs, ?int $timestamp = null): int
    {
        $time = $timestamp ?? time();
        $prediction = R::dispense('predictions');
        $prediction->hospital_id = $hospitalId;
        $prediction->predictions = $needs;
        $prediction->tym = $time;

        return (int)R::store($prediction);
    }

    /**
     * Predict monthly oxygen requirement using historical consumption and weather features.
     */
    public function predictMonthlyNeed(array $history, float $locationFactor = 1.0): array
    {
        if (!function_exists('predictMonthlyOxygenNeed')) {
            require_once dirname(__DIR__, 2) . '/include/functions/helper.php';
        }

        return predictMonthlyOxygenNeed($history, $locationFactor);
    }
}
