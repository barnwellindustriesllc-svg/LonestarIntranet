<?php

require_once __DIR__ . '/balance_history.php';

if (!function_exists('retro_balance_week_start')) {
    function retro_balance_week_start(string $date): string {
        $dt = new DateTimeImmutable($date);
        return $dt->modify('-' . (int)$dt->format('w') . ' days')->format('Y-m-d');
    }
}

if (!function_exists('retro_balance_week_end')) {
    function retro_balance_week_end(string $weekStart): string {
        return (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
    }
}

if (!function_exists('retro_balance_vendor_label')) {
    function retro_balance_vendor_label(string $scope): string {
        return [
            'tss' => 'TSS',
            'detmar' => 'Detmar',
            'nickelrock' => 'Nickel Rock',
            'nextier' => 'NexTier',
            'rtex' => 'RTEX',
            'fuel' => 'Fuel Balance',
        ][strtolower($scope)] ?? strtoupper($scope);
    }
}

if (!function_exists('retro_balance_driver_first_date')) {
    function retro_balance_driver_first_date(mysqli $mysqli, int $driverId): string {
        $dates = [];
        $queries = [
            ["SELECT MIN(payout_date) FROM driver_payouts WHERE driver_contact_id=?", 'i'],
            ["SELECT MIN(payout_week_start) FROM tss_misc_adjustments WHERE driver_contact_id=?", 'i'],
            ["SELECT MIN(cost_date) FROM driver_gas_costs WHERE driver_id=?", 'i'],
            ["SELECT MIN(last_calculated_week_start) FROM driver_misc_adjustment_balances WHERE driver_id=?", 'i'],
            ["SELECT MIN(last_calculated_week_start) FROM driver_fuel_balances WHERE driver_id=?", 'i'],
        ];
        foreach ($queries as [$sql, $types]) {
            try {
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param($types, $driverId);
                $stmt->execute();
                $stmt->bind_result($dateValue);
                if ($stmt->fetch() && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateValue)) {
                    $dates[] = (string)$dateValue;
                }
                $stmt->close();
            } catch (Throwable $e) {
                // Optional legacy tables may not exist.
            }
        }
        if (!$dates) {
            return '';
        }
        sort($dates);
        return $dates[0];
    }
}

if (!function_exists('retro_balance_current_misc_total')) {
    function retro_balance_current_misc_total(
        mysqli $mysqli,
        int $driverId,
        string $weekStart,
        string $scope
    ): float {
        if (!lonestar_payout_table_exists($mysqli, 'tss_misc_adjustments')) {
            return 0.0;
        }
        $hasVendor = lonestar_payout_column_exists($mysqli, 'tss_misc_adjustments', 'payout_vendor');
        if (!$hasVendor && $scope !== 'tss') {
            return 0.0;
        }
        $vendor = lonestar_payout_vendor_code($scope);
        $vendorWhere = $hasVendor
            ? 'AND ' . lonestar_payout_vendor_sql_key('payout_vendor') . ' = ?'
            : '';
        $autoTrailerWhere = $hasVendor
            ? "AND NOT (
                 " . lonestar_payout_vendor_sql_key('payout_vendor') . " = 'NEXTIER'
                 AND adjustment_type = 'misc_deduction'
                 AND LOWER(COALESCE(comments, '')) LIKE 'auto nextier trailer rental deduction%'
               )"
            : '';
        $stmt = $mysqli->prepare(
            "SELECT COALESCE(SUM(
                CASE WHEN adjustment_type='misc_deduction' THEN -ABS(amount) ELSE ABS(amount) END
             ),0)
               FROM tss_misc_adjustments
              WHERE driver_contact_id=?
                AND payout_week_start=?
                {$vendorWhere}
                {$autoTrailerWhere}"
        );
        if (!$stmt) {
            return 0.0;
        }
        if ($hasVendor) {
            $stmt->bind_param('iss', $driverId, $weekStart, $vendor);
        } else {
            $stmt->bind_param('is', $driverId, $weekStart);
        }
        $stmt->execute();
        $stmt->bind_result($amount);
        $total = $stmt->fetch() ? (float)$amount : 0.0;
        $stmt->close();
        return round($total, 2);
    }
}

if (!function_exists('retro_balance_history_map')) {
    function retro_balance_history_map(
        mysqli $mysqli,
        int $driverId,
        string $firstWeek,
        string $lastWeek
    ): array {
        lonestar_driver_misc_balance_history_ensure_table($mysqli);
        $map = [];
        $stmt = $mysqli->prepare(
            "SELECT vendor_scope, week_start, ending_balance, source, updated_at
               FROM driver_misc_balance_history
              WHERE driver_id=?
                AND week_start BETWEEN ? AND ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iss', $driverId, $firstWeek, $lastWeek);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $key = strtolower((string)$row['vendor_scope']) . '|' . (string)$row['week_start'];
            $map[$key] = [
                'balance' => round((float)$row['ending_balance'], 2),
                'source' => (string)$row['source'],
                'updated_at' => (string)$row['updated_at'],
            ];
        }
        $stmt->close();
        return $map;
    }
}

if (!function_exists('retro_balance_current_map')) {
    function retro_balance_current_map(mysqli $mysqli, int $driverId): array {
        lonestar_driver_misc_balance_ensure_table($mysqli);
        $map = [];
        $stmt = $mysqli->prepare(
            "SELECT vendor_scope, balance, last_calculated_week_start, updated_at
               FROM driver_misc_adjustment_balances
              WHERE driver_id=?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $map[strtolower((string)$row['vendor_scope'])] = [
                'balance' => round((float)$row['balance'], 2),
                'week_start' => (string)($row['last_calculated_week_start'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        $stmt->close();
        return $map;
    }
}

if (!function_exists('retro_balance_fuel_history_map')) {
    function retro_balance_fuel_history_map(
        mysqli $mysqli,
        int $driverId,
        string $firstWeek,
        string $lastWeek
    ): array {
        lonestar_driver_fuel_balance_history_ensure_table($mysqli);
        $map = [];
        $stmt = $mysqli->prepare(
            "SELECT week_start, ending_balance, source, updated_at
               FROM driver_fuel_balance_history
              WHERE driver_id=?
                AND week_start BETWEEN ? AND ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iss', $driverId, $firstWeek, $lastWeek);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $map[(string)$row['week_start']] = [
                'balance' => round((float)$row['ending_balance'], 2),
                'source' => (string)$row['source'],
                'updated_at' => (string)$row['updated_at'],
            ];
        }
        $stmt->close();
        return $map;
    }
}

if (!function_exists('retro_balance_fuel_current')) {
    function retro_balance_fuel_current(mysqli $mysqli, int $driverId): array {
        lonestar_driver_fuel_balance_ensure_table($mysqli);
        $stmt = $mysqli->prepare(
            "SELECT balance, last_calculated_week_start, updated_at
               FROM driver_fuel_balances
              WHERE driver_id=?"
        );
        if (!$stmt) {
            return ['balance' => 0.0, 'week_start' => '', 'updated_at' => ''];
        }
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        $stmt->bind_result($balance, $weekStart, $updatedAt);
        $row = $stmt->fetch()
            ? [
                'balance' => round((float)$balance, 2),
                'week_start' => (string)($weekStart ?? ''),
                'updated_at' => (string)($updatedAt ?? ''),
            ]
            : ['balance' => 0.0, 'week_start' => '', 'updated_at' => ''];
        $stmt->close();
        return $row;
    }
}
if (!function_exists('retro_balance_run')) {
    function retro_balance_run(mysqli $mysqli, int $driverId, string $throughDate): array {
        $scopes = lonestar_payout_vendor_order();
        $throughWeek = retro_balance_week_start($throughDate);
        $firstDate = retro_balance_driver_first_date($mysqli, $driverId);
        if ($firstDate === '') {
            return [
                'rows' => [],
                'corrections' => [],
                'first_week' => '',
                'through_week' => $throughWeek,
                'weeks' => 0,
                'truncated' => false,
            ];
        }
        $firstWeek = retro_balance_week_start($firstDate);
        $history = retro_balance_history_map($mysqli, $driverId, $firstWeek, $throughWeek);
        $current = retro_balance_current_map($mysqli, $driverId);
        $fuelHistory = retro_balance_fuel_history_map($mysqli, $driverId, $firstWeek, $throughWeek);
        $fuelCurrent = retro_balance_fuel_current($mysqli, $driverId);
        $opening = array_fill_keys($scopes, 0.0);
        $historyMismatchCounts = array_fill_keys(array_merge($scopes, ['fuel']), 0);
        // Track debt reconstructed from statements but never recognized in the
        // stored ledger. Later historical earnings were already paid without
        // this opening balance, so they must not erase the missed collection.
        $unrecordedDebt = array_fill_keys(array_merge($scopes, ['fuel']), 0.0);
        $previousExpectedDebt = array_fill_keys(array_merge($scopes, ['fuel']), 0.0);
        $previousStoredDebt = array_fill_keys(array_merge($scopes, ['fuel']), 0.0);
        $fuelCarry = 0.0;
        $rows = [];
        $weekCursor = new DateTimeImmutable($firstWeek);
        $last = new DateTimeImmutable($throughWeek);
        $weekCount = 0;
        $truncated = false;

        $insuranceTotal = 0.0;
        try {
            $stmt = $mysqli->prepare('SELECT COALESCE(amount,0) FROM driver_insurance_costs WHERE driver_id=?');
            if ($stmt) {
                $stmt->bind_param('i', $driverId);
                $stmt->execute();
                $stmt->bind_result($insuranceAmount);
                if ($stmt->fetch()) {
                    $insuranceTotal = max(0.0, (float)$insuranceAmount);
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            $insuranceTotal = 0.0;
        }

        while ($weekCursor <= $last) {
            if (++$weekCount > 800) {
                $truncated = true;
                break;
            }
            $weekStart = $weekCursor->format('Y-m-d');
            $weekEnd = retro_balance_week_end($weekStart);
            $components = [];

            foreach ($scopes as $scope) {
                $gross = lonestar_driver_vendor_week_gross($mysqli, $driverId, $weekStart, $weekEnd, $scope);
                $trailer = $scope === 'tss'
                    ? lonestar_driver_tss_week_trailer_fee_total($mysqli, $driverId, $weekStart, $weekEnd)
                    : lonestar_driver_trailer_fee_estimate($mysqli, $driverId, $gross, $scope, $weekStart, $weekEnd);
                $broker = 0.0;
                if ($scope === 'tss') {
                    $settings = lonestar_vendor_broker_fee_settings($mysqli, 'tss');
                    $broker = lonestar_tss_driver_week_broker_fee_amount(
                        $mysqli,
                        $driverId,
                        $weekStart,
                        $weekEnd,
                        $gross,
                        $settings
                    );
                } else {
                    $broker = lonestar_vendor_broker_fee_amount(
                        $mysqli,
                        $scope,
                        $gross,
                        $scope === 'rtex'
                            ? lonestar_rtex_driver_week_hours_total($mysqli, $driverId, $weekStart, $weekEnd)
                            : 0.0
                    );
                }
                if ($scope === 'rtex') {
                    $trailer = 0.0;
                }
                $misc = retro_balance_current_misc_total($mysqli, $driverId, $weekStart, $scope);
                $fuelSurcharge = $scope === 'tss'
                    ? lonestar_driver_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd)
                    : ($scope === 'nextier'
                        ? lonestar_driver_nextier_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd)
                        : 0.0);
                $allocationBase = round(
                    $gross - $trailer - $broker + $misc + $fuelSurcharge + (float)$opening[$scope],
                    2
                );
                // RTEX uses the broker charge to determine how much insurance/fuel
                // can be collected, but the RTEX statement does not subtract that
                // broker charge from displayed net pay.
                $base = $scope === 'rtex'
                    ? round($allocationBase + $broker, 2)
                    : $allocationBase;
                $components[$scope] = [
                    'gross' => round($gross, 2),
                    'trailer' => round($trailer, 2),
                    'broker' => $scope === 'rtex' ? 0.0 : round($broker, 2),
                    'allocation_base' => $allocationBase,
                    'misc' => round($misc, 2),
                    'fuel_surcharge' => round($fuelSurcharge, 2),
                    'opening' => round((float)$opening[$scope], 2),
                    'base' => $base,
                    'has_payout' => abs($gross) > 0.005,
                    'insurance' => 0.0,
                    'fuel' => 0.0,
                ];
            }

            $insuranceRemaining = round($insuranceTotal, 2);
            $singleInsuranceScope = '';
            foreach ($scopes as $scope) {
                if ($components[$scope]['allocation_base'] >= $insuranceRemaining && $insuranceRemaining > 0.005) {
                    $singleInsuranceScope = $scope;
                    break;
                }
            }
            if ($singleInsuranceScope !== '') {
                $components[$singleInsuranceScope]['insurance'] = $insuranceRemaining;
                $insuranceRemaining = 0.0;
            } else {
                foreach ($scopes as $scope) {
                    $deduction = round(min(
                        $insuranceRemaining,
                        max(0.0, (float)$components[$scope]['allocation_base'])
                    ), 2);
                    $components[$scope]['insurance'] = $deduction;
                    $insuranceRemaining = round(max(0.0, $insuranceRemaining - $deduction), 2);
                }
            }

            $fuelOpening = $fuelCarry;
            $currentWeekFuel = lonestar_driver_week_total_fuel($mysqli, $driverId, $weekStart, $weekEnd);
            $fuelBeforeCollection = round($fuelOpening + $currentWeekFuel, 2);
            $fuelCarry = $fuelBeforeCollection;
            foreach ($scopes as $scope) {
                $available = max(
                    0.0,
                    (float)$components[$scope]['allocation_base'] - (float)$components[$scope]['insurance']
                );
                $deduction = round(min($fuelCarry, $available), 2);
                $components[$scope]['fuel'] = $deduction;
                $fuelCarry = round(max(0.0, $fuelCarry - $deduction), 2);
            }

            foreach ($scopes as $scope) {
                $part = $components[$scope];
                $net = round($part['base'] - $part['insurance'] - $part['fuel'], 2);
                $ending = !empty($part['has_payout']) ? min(0.0, $net) : $net;
                if (abs($ending) < 0.005) {
                    $ending = 0.0;
                }
                $historyRow = $history[$scope . '|' . $weekStart] ?? null;
                $stored = $historyRow === null ? null : (float)$historyRow['balance'];

                // Preserve debt introduced by an earlier retro correction so
                // rerunning the audit cannot immediately erase that correction.
                if (
                    $historyRow !== null
                    && (string)($historyRow['source'] ?? '') === 'retro_audit'
                    && $stored < $ending - 0.005
                ) {
                    $ending = round($stored, 2);
                }

                $expectedDebt = max(0.0, -$ending);
                $storedDebt = max(0.0, -($stored ?? 0.0));
                $expectedIncrease = max(
                    0.0,
                    $expectedDebt - (float)$previousExpectedDebt[$scope]
                );
                $storedIncrease = max(
                    0.0,
                    $storedDebt - (float)$previousStoredDebt[$scope]
                );
                $unrecordedDebt[$scope] = round(max(
                    0.0,
                    (float)$unrecordedDebt[$scope] + $expectedIncrease - $storedIncrease
                ), 2);
                $previousExpectedDebt[$scope] = round($expectedDebt, 2);
                $previousStoredDebt[$scope] = round($storedDebt, 2);

                $variance = $stored === null ? $ending : round($ending - $stored, 2);
                $isMismatch = $stored === null
                    ? abs($ending) > 0.005
                    : abs($variance) > 0.005;
                if ($isMismatch) {
                    $historyMismatchCounts[$scope]++;
                }
                $hasRelevantActivity =
                    abs((float)$part['opening']) > 0.005
                    || abs((float)$part['gross']) > 0.005
                    || abs((float)$part['misc']) > 0.005
                    || abs((float)$part['trailer']) > 0.005
                    || abs((float)$part['broker']) > 0.005
                    || abs((float)$part['fuel_surcharge']) > 0.005
                    || (float)$part['insurance'] > 0.005
                    || (float)$part['fuel'] > 0.005
                    || abs($ending) > 0.005
                    || $historyRow !== null;
                if ($hasRelevantActivity) {
                    $rows[] = [
                        'driver_id' => $driverId,
                        'kind' => 'vendor',
                        'scope' => $scope,
                        'week_start' => $weekStart,
                        'week_end' => $weekEnd,
                        'opening' => (float)$part['opening'],
                        'gross' => (float)$part['gross'],
                        'deductions' => round(
                            (float)$part['trailer']
                            + (float)$part['broker']
                            + (float)$part['insurance']
                            + (float)$part['fuel'],
                            2
                        ),
                        'misc' => (float)$part['misc'],
                        'fuel_surcharge' => (float)$part['fuel_surcharge'],
                        'expected_ending' => round($ending, 2),
                        'stored_ending' => $stored,
                        'variance' => $variance,
                        'history_source' => $historyRow['source'] ?? '',
                        'mismatch' => $isMismatch,
                    ];
                }
                $opening[$scope] = round($ending, 2);
            }

            $fuelCollected = round(max(0.0, $fuelBeforeCollection - $fuelCarry), 2);
            $fuelHistoryRow = $fuelHistory[$weekStart] ?? null;
            $storedFuel = $fuelHistoryRow === null ? null : (float)$fuelHistoryRow['balance'];

            // Applied retro fuel debt becomes a real opening balance next week.
            if (
                $fuelHistoryRow !== null
                && (string)($fuelHistoryRow['source'] ?? '') === 'retro_audit'
                && $storedFuel > $fuelCarry + 0.005
            ) {
                $fuelCarry = round($storedFuel, 2);
            }

            $expectedFuelDebt = max(0.0, $fuelCarry);
            $storedFuelDebt = max(0.0, (float)($storedFuel ?? 0.0));
            $expectedFuelIncrease = max(
                0.0,
                $expectedFuelDebt - (float)$previousExpectedDebt['fuel']
            );
            $storedFuelIncrease = max(
                0.0,
                $storedFuelDebt - (float)$previousStoredDebt['fuel']
            );
            $unrecordedDebt['fuel'] = round(max(
                0.0,
                (float)$unrecordedDebt['fuel'] + $expectedFuelIncrease - $storedFuelIncrease
            ), 2);
            $previousExpectedDebt['fuel'] = round($expectedFuelDebt, 2);
            $previousStoredDebt['fuel'] = round($storedFuelDebt, 2);

            $fuelVariance = $storedFuel === null
                ? $fuelCarry
                : round($fuelCarry - $storedFuel, 2);
            $fuelMismatch = $storedFuel === null
                ? $fuelCarry > 0.005
                : abs($fuelVariance) > 0.005;
            if ($fuelMismatch) {
                $historyMismatchCounts['fuel']++;
            }
            if (
                $fuelOpening > 0.005
                || $currentWeekFuel > 0.005
                || $fuelCollected > 0.005
                || $fuelCarry > 0.005
                || $fuelHistoryRow !== null
            ) {
                $rows[] = [
                    'driver_id' => $driverId,
                    'kind' => 'fuel',
                    'scope' => 'fuel',
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'opening' => round($fuelOpening, 2),
                    'gross' => $fuelCollected,
                    'deductions' => round($currentWeekFuel, 2),
                    'misc' => 0.0,
                    'fuel_surcharge' => 0.0,
                    'expected_ending' => round($fuelCarry, 2),
                    'stored_ending' => $storedFuel,
                    'variance' => $fuelVariance,
                    'history_source' => $fuelHistoryRow['source'] ?? '',
                    'mismatch' => $fuelMismatch,
                ];
            }
            $weekCursor = $weekCursor->modify('+7 days');
        }

        $corrections = [];
        foreach ($scopes as $scope) {
            $reconstructedExpected = round((float)$opening[$scope], 2);
            $currentInfo = $current[$scope] ?? [
                'balance' => 0.0,
                'week_start' => '',
                'updated_at' => '',
            ];
            $cutoffHistory = $history[$scope . '|' . $throughWeek] ?? null;
            $currentWeek = (string)$currentInfo['week_start'];
            $hasStoredAtCutoff = $cutoffHistory !== null || $currentWeek === $throughWeek;
            $storedAtCutoff = $cutoffHistory !== null
                ? round((float)$cutoffHistory['balance'], 2)
                : ($currentWeek === $throughWeek ? round((float)$currentInfo['balance'], 2) : 0.0);
            $currentExpectedDebt = max(0.0, -$reconstructedExpected);
            $currentStoredDebt = max(0.0, -$storedAtCutoff);
            $currentUnrecordedGap = max(0.0, $currentExpectedDebt - $currentStoredDebt);
            $historicalMissed = round(max(
                0.0,
                (float)$unrecordedDebt[$scope] - $currentUnrecordedGap
            ), 2);
            $expected = round($reconstructedExpected - $historicalMissed, 2);
            $corrections[$scope] = [
                'scope' => $scope,
                'expected' => $expected,
                'reconstructed_expected' => $reconstructedExpected,
                'historical_missed' => $historicalMissed,
                'stored' => $storedAtCutoff,
                'variance' => round($expected - $storedAtCutoff, 2),
                'stored_week' => $hasStoredAtCutoff ? $throughWeek : '',
                'stored_updated_at' => $cutoffHistory['updated_at'] ?? (string)$currentInfo['updated_at'],
                'current_balance' => round((float)$currentInfo['balance'], 2),
                'current_week' => $currentWeek,
                'history_mismatches' => (int)$historyMismatchCounts[$scope],
                'needs_correction' => abs($expected - $storedAtCutoff) > 0.005
                    || (int)$historyMismatchCounts[$scope] > 0,
                'can_apply' => true,
            ];
        }

        $fuelCutoffHistory = $fuelHistory[$throughWeek] ?? null;
        $fuelCurrentWeek = (string)($fuelCurrent['week_start'] ?? '');
        $hasFuelAtCutoff = $fuelCutoffHistory !== null || $fuelCurrentWeek === $throughWeek;
        $storedFuelAtCutoff = $fuelCutoffHistory !== null
            ? round((float)$fuelCutoffHistory['balance'], 2)
            : ($fuelCurrentWeek === $throughWeek ? round((float)$fuelCurrent['balance'], 2) : 0.0);
        $reconstructedFuelExpected = round($fuelCarry, 2);
        $currentFuelGap = max(0.0, $reconstructedFuelExpected - max(0.0, $storedFuelAtCutoff));
        $historicalFuelMissed = round(max(
            0.0,
            (float)$unrecordedDebt['fuel'] - $currentFuelGap
        ), 2);
        $expectedFuelBalance = round($reconstructedFuelExpected + $historicalFuelMissed, 2);
        $corrections['fuel'] = [
            'scope' => 'fuel',
            'expected' => $expectedFuelBalance,
            'reconstructed_expected' => $reconstructedFuelExpected,
            'historical_missed' => $historicalFuelMissed,
            'stored' => $storedFuelAtCutoff,
            'variance' => round($expectedFuelBalance - $storedFuelAtCutoff, 2),
            'stored_week' => $hasFuelAtCutoff ? $throughWeek : '',
            'stored_updated_at' => $fuelCutoffHistory['updated_at'] ?? (string)($fuelCurrent['updated_at'] ?? ''),
            'current_balance' => round((float)($fuelCurrent['balance'] ?? 0), 2),
            'current_week' => $fuelCurrentWeek,
            'history_mismatches' => (int)$historyMismatchCounts['fuel'],
            'needs_correction' => abs($expectedFuelBalance - $storedFuelAtCutoff) > 0.005
                || (int)$historyMismatchCounts['fuel'] > 0,
            'can_apply' => true,
        ];

        $statementDebt = -1 * $expectedFuelBalance;
        foreach ($scopes as $scope) {
            $statementDebt += min(0.0, (float)$corrections[$scope]['expected']);
        }

        return [
            'rows' => $rows,
            'corrections' => $corrections,
            'first_week' => $firstWeek,
            'through_week' => $throughWeek,
            'weeks' => $weekCount,
            'truncated' => $truncated,
            'fuel_balance_expected' => $expectedFuelBalance,
            'statement_debt_expected' => round($statementDebt, 2),
        ];
    }
}

