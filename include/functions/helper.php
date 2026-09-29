<?php

/**
 * Oxygen Prediction Helper 
 * Provides machine learning functions for oxygen demand forecasting
 */

function predictMonthlyOxygenNeed(array $history, float $locationFactor = 1.0, int $minSamples = 9): array
{
    if (empty($history)) {
        return ['prediction' => 0.0, 'method' => 'no_data', 'accuracy' => 0.0];
    }

    // Sort by month to ensure chronological order
    usort($history, function($a, $b) {
        return strcmp($a['month'], $b['month']);
    });

    if (count($history) < $minSamples) {
        $avg = (array_sum(array_column($history, 'total_cubic_meters')) / count($history)) * $locationFactor;
        $accuracy = calculateSimpleAccuracy($history, $avg);
        return [
            'prediction' => round($avg, 2), 
            'method' => 'moving_average', 
            'accuracy' => round($accuracy, 2)
        ];
    }

    // Build training dataset with correct temporal relationships
    $X = []; 
    $y = [];
    
    for ($i = 1; $i < count($history); $i++) {
        $prev = $history[$i - 1];
        $cur = $history[$i];

        $monthIndex = (int)explode('-', $cur['month'])[1];
        $angle = 2 * pi() * ($monthIndex / 12);
        
        // Use previous month's weather to predict current month's total_cubic_meters
        $features = [
            1, // intercept
            (float)$prev['total_cubic_meters'], // previous total_cubic_meters
            (float)($prev['avg_temp'] ?? 0), // previous temperature
            (float)($prev['avg_humidity'] ?? 0), // previous humidity
            sin($angle),
            cos($angle)
        ];

        $X[] = $features;
        $y[] = (float)$cur['total_cubic_meters']; // current month's total_cubic_meters as target
    }

    try {
        $XT = transpose($X);
        $XTX = matmul($XT, $X);
        $XTy = matmulVec($XT, $y);
        $inv = invertMatrix($XTX);
        
        if (!$inv) {
            throw new RuntimeException('Matrix inversion failed - dataset may be too small or correlated');
        }
        
        $beta = matmulVec($inv, $XTy);

        // Calculate model accuracy
        $accuracy = calculateModelAccuracy($X, $y, $beta);
        
        // Predict next month using most recent data
        $last = end($history);
        $nextMonth = ((int)explode('-', $last['month'])[1] % 12) + 1;
        $angleNext = 2 * pi() * ($nextMonth / 12);
        
        // Use last month's data to predict next month
        $xNext = [
            1, 
            $last['total_cubic_meters'], 
            $last['avg_temp'] ?? 0, 
            $last['avg_humidity'] ?? 0, 
            sin($angleNext), 
            cos($angleNext)
        ];
        
        $prediction = max(0, dot($xNext, $beta)) * $locationFactor;

        return [
            'prediction' => round($prediction, 2), 
            'method' => 'ols', 
            'coefficients' => $beta,
            'accuracy' => round($accuracy, 2)
        ];
        
    } catch (Exception $e) {
        // Fallback to weighted moving average
        return calculateWeightedMovingAverage($history, $locationFactor);
    }
}

function calculateWeightedMovingAverage(array $history, float $locationFactor = 1.0): array
{
    $total = 0;
    $weight = 0;
    $historyCount = count($history);
    
    for ($i = 0; $i < $historyCount; $i++) {
        // More weight to recent months (linear weighting)
        $currentWeight = ($i + 1) / $historyCount;
        $total += $history[$i]['total_cubic_meters'] * $currentWeight;
        $weight += $currentWeight;
    }
    
    $weightedAvg = ($total / max(1e-6, $weight)) * $locationFactor;
    $accuracy = calculateWeightedAccuracy($history, $weightedAvg);
    
    return [
        'prediction' => round($weightedAvg, 2), 
        'method' => 'weighted_moving_average_fallback',
        'accuracy' => round($accuracy, 2)
    ];
}

/**
 * Calculate model accuracy using R-squared and MAPE (Mean Absolute Percentage Error)
 */
/**
 * Action C4: Calculate honest model accuracy by holding out the last 3 months for MAE/MAPE validation
 */
function calculateModelAccuracy(array $X, array $y, array $coefficients): float
{
    $sampleCount = count($y);
    if ($sampleCount === 0) return 0.0;

    // Hold out the last 3 samples for validation
    $holdOutCount = min(3, max(1, (int)floor($sampleCount * 0.25)));
    $trainCount = $sampleCount - $holdOutCount;

    $trainBeta = $coefficients;
    if ($trainCount >= 6) {
        $Xtrain = array_slice($X, 0, $trainCount);
        $ytrain = array_slice($y, 0, $trainCount);
        try {
            $XT = transpose($Xtrain);
            $XTX = matmul($XT, $Xtrain);
            $inv = invertMatrix($XTX);
            if ($inv) {
                $trainBeta = matmulVec($inv, matmulVec($XT, $ytrain));
            }
        } catch (Exception $e) {
            $trainBeta = $coefficients;
        }
    }

    $Xtest = array_slice($X, -$holdOutCount);
    $ytest = array_slice($y, -$holdOutCount);

    $testPredictions = [];
    $testActuals = [];
    for ($i = 0; $i < count($ytest); $i++) {
        $testPredictions[] = max(0.0, dot($Xtest[$i], $trainBeta));
        $testActuals[] = $ytest[$i];
    }

    // Calculate MAPE on held-out validation set
    $mape = calculateMAPE($testActuals, $testPredictions);

    // Honest accuracy without arbitrary capping
    $accuracy = max(0.0, (1.0 - min($mape, 1.0))) * 100.0;
    return round($accuracy, 2);
}

/**
 * Calculate Mean Absolute Percentage Error
 */
function calculateMAPE(array $actuals, array $predictions): float
{
    $totalError = 0.0;
    $count = 0;
    
    for ($i = 0; $i < count($actuals); $i++) {
        if ($actuals[$i] != 0) { // Avoid division by zero
            $error = abs(($actuals[$i] - $predictions[$i]) / $actuals[$i]);
            $totalError += min($error, 1.0); // Cap error at 100%
            $count++;
        }
    }
    
    return $count > 0 ? ($totalError / $count) : 1.0;
}

/**
 * Action C4: Calculate honest hold-out accuracy for simple moving average
 */
function calculateSimpleAccuracy(array $history, float $prediction): float
{
    if (empty($history)) return 0.0;
    $values = array_column($history, 'total_cubic_meters');
    $count = count($values);
    if ($count === 0) return 0.0;

    $holdOutCount = min(3, max(1, (int)floor($count * 0.25)));
    $trainValues = array_slice($values, 0, $count - $holdOutCount);
    $testValues = array_slice($values, -$holdOutCount);

    $trainMean = !empty($trainValues) ? (array_sum($trainValues) / count($trainValues)) : $prediction;
    $errors = [];
    foreach ($testValues as $actual) {
        if ($actual > 0) {
            $errors[] = min(1.0, abs($actual - $trainMean) / $actual);
        }
    }

    $mape = !empty($errors) ? (array_sum($errors) / count($errors)) : 1.0;
    $accuracy = max(0.0, (1.0 - $mape)) * 100.0;
    return round($accuracy, 2);
}

/**
 * Action C4: Calculate honest hold-out accuracy for weighted moving average
 */
function calculateWeightedAccuracy(array $history, float $prediction): float
{
    $count = count($history);
    if ($count < 2) {
        return calculateSimpleAccuracy($history, $prediction);
    }

    $holdOutCount = min(3, max(1, (int)floor($count * 0.25)));
    $trainHistory = array_slice($history, 0, $count - $holdOutCount);
    $testHistory = array_slice($history, -$holdOutCount);

    if (empty($trainHistory)) {
        return calculateSimpleAccuracy($history, $prediction);
    }

    $total = 0.0;
    $weight = 0.0;
    $tCount = count($trainHistory);
    for ($i = 0; $i < $tCount; $i++) {
        $w = ($i + 1) / $tCount;
        $total += $trainHistory[$i]['total_cubic_meters'] * $w;
        $weight += $w;
    }
    $trainPred = $total / max(1e-6, $weight);

    $errors = [];
    foreach ($testHistory as $row) {
        $actual = (float)$row['total_cubic_meters'];
        if ($actual > 0) {
            $errors[] = min(1.0, abs($actual - $trainPred) / $actual);
        }
    }

    $mape = !empty($errors) ? (array_sum($errors) / count($errors)) : 1.0;
    $accuracy = max(0.0, (1.0 - $mape)) * 100.0;
    return round($accuracy, 2);
}

// Existing helper functions remain the same...
function transpose(array $A): array
{
    if (empty($A)) return [];
    $T = [];
    foreach ($A[0] as $i => $_) {
        $T[$i] = array_column($A, $i);
    }
    return $T;
}

function matmul(array $A, array $B): array
{
    $r = count($A);
    $c = count($B[0]);
    $C = [];
    for ($i = 0; $i < $r; $i++) {
        for ($j = 0; $j < $c; $j++) {
            $C[$i][$j] = array_sum(array_map(
                function($a, $b) { return $a * $b; }, 
                $A[$i], 
                array_column($B, $j)
            ));
        }
    }
    return $C;
}

function matmulVec(array $A, array $v): array
{
    return array_map(
        function($r) use ($v) { 
            return array_sum(array_map(function($a, $b) { return $a * $b; }, $r, $v)); 
        }, 
        $A
    );
}

function invertMatrix(array $A): ?array
{
    $n = count($A);
    $I = array_map(
        function($i) use ($n) { 
            return array_replace(array_fill(0, $n, 0), [$i => 1]); 
        }, 
        array_keys($A)
    );
    
    for ($i = 0; $i < $n; $i++) {
        // Partial pivoting: find maximum pivot in column i
        $maxRow = $i;
        for ($k = $i + 1; $k < $n; $k++) {
            if (abs($A[$k][$i]) > abs($A[$maxRow][$i])) {
                $maxRow = $k;
            }
        }
        if (abs($A[$maxRow][$i]) < 1e-10) {
            return null; // Singular matrix
        }
        
        // Swap rows in A and I
        if ($maxRow !== $i) {
            $tempA = $A[$i]; $A[$i] = $A[$maxRow]; $A[$maxRow] = $tempA;
            $tempI = $I[$i]; $I[$i] = $I[$maxRow]; $I[$maxRow] = $tempI;
        }
        
        $f = $A[$i][$i];
        for ($j = 0; $j < $n; $j++) {
            $A[$i][$j] /= $f;
            $I[$i][$j] /= $f;
        }
        
        for ($k = 0; $k < $n; $k++) {
            if ($k === $i) continue;
            $f = $A[$k][$i];
            for ($j = 0; $j < $n; $j++) {
                $A[$k][$j] -= $f * $A[$i][$j];
                $I[$k][$j] -= $f * $I[$i][$j];
            }
        }
    }
    return $I;
}

function dot(array $a, array $b): float
{
    return array_sum(array_map(function($x, $y) { return $x * $y; }, $a, $b));
}

function validateHospitalId($refid): bool
{
    return filter_var($refid, FILTER_VALIDATE_INT) !== false && $refid > 0;
}

function validateHistoryData(array $history): array
{
    $validated = [];
    
    foreach ($history as $record) {
        if (!isset($record['month']) || !isset($record['total_cubic_meters'])) {
            continue;
        }
        
        if (!preg_match('/^\d{4}-\d{2}$/', $record['month'])) {
            continue;
        }
        
        $total_cubic_meters = filter_var($record['total_cubic_meters'], FILTER_VALIDATE_FLOAT);
        if ($total_cubic_meters === false || $total_cubic_meters < 0) {
            continue;
        }
        
        $validated[] = [
            'month' => $record['month'],
            'total_cubic_meters' => $total_cubic_meters,
            'avg_temp' => isset($record['avg_temp']) ? 
                filter_var($record['avg_temp'], FILTER_VALIDATE_FLOAT) : 0,
            'avg_humidity' => isset($record['avg_humidity']) ? 
                filter_var($record['avg_humidity'], FILTER_VALIDATE_FLOAT) : 0
        ];
    }
    
    return $validated;
}

/**
 * Action C3: Provide meteorological statistics using monthly climatological averages.
 * Removed unused external geocoding HTTP call.
 */
function getWeatherStats(?string $month, ?string $city = 'Lagos', ?string $apiKey = null): array
{
    if ($month) {
        $parts = explode('-', $month);
        $monthNum = isset($parts[1]) ? (int)$parts[1] : (int)date('n');
    } else {
        $monthNum = (int)date('n');
    }

    if ($monthNum < 1 || $monthNum > 12) {
        $monthNum = 1;
    }

    // Historical monthly climatological averages for Nigerian states / West African climate
    $fallback = [
        'temperature' => [
            1 => 26.5, 2 => 27.2, 3 => 28.1, 4 => 28.9,
            5 => 29.3, 6 => 28.8, 7 => 28.2, 8 => 28.0,
            9 => 27.8, 10 => 27.5, 11 => 26.8, 12 => 26.2
        ],
        'humidity' => [
            1 => 75.0, 2 => 73.0, 3 => 72.0, 4 => 70.0,
            5 => 68.0, 6 => 70.0, 7 => 72.0, 8 => 74.0,
            9 => 76.0, 10 => 78.0, 11 => 77.0, 12 => 76.0
        ]
    ];

    return [
        'temperature' => $fallback['temperature'][$monthNum],
        'humidity'    => $fallback['humidity'][$monthNum]
    ];
}