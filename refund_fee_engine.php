<?php
declare(strict_types=1);

/**
 * Bettavaro - Refund Fee Engine
 *
 * Purpose:
 * - detect and load refund fee policy
 * - build order-paid fee snapshot
 * - save paid snapshot to orders
 * - rebuild refund fee summary from order snapshot
 * - allocate fee loss to refund items
 * - provide detailed debug logging
 */

if (!function_exists('bv_refund_fee_engine_boot')) {
    function bv_refund_fee_engine_boot(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $candidates = [
            dirname(__DIR__) . '/config/db.php',
            dirname(__DIR__) . '/includes/db.php',
            dirname(__DIR__) . '/db.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                break;
            }
        }

        $booted = true;
    }
}

$sellerFeePromoFile = __DIR__ . '/seller_fee_promotion.php';
if (is_file($sellerFeePromoFile)) {
    require_once $sellerFeePromoFile;
}


if (!function_exists('bv_refund_fee_engine_db')) {
    function bv_refund_fee_engine_db()
    {
        bv_refund_fee_engine_boot();

        $candidates = [
            $GLOBALS['bv_order_refund_db'] ?? null,
			$GLOBALS['pdo'] ?? null,
			$GLOBALS['PDO'] ?? null,
			$GLOBALS['db'] ?? null,
			$GLOBALS['conn'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof PDO || $candidate instanceof mysqli) {
                return $candidate;
            }
        }

        throw new RuntimeException('Database connection not found.');
    }
}

if (!function_exists('bv_refund_fee_engine_is_pdo')) {
    function bv_refund_fee_engine_is_pdo($db): bool
    {
        return $db instanceof PDO;
    }
}

if (!function_exists('bv_refund_fee_engine_is_mysqli')) {
    function bv_refund_fee_engine_is_mysqli($db): bool
    {
        return $db instanceof mysqli;
    }
}

if (!function_exists('bv_refund_fee_log')) {
    function bv_refund_fee_log(string $event, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $candidates = [
            dirname(__DIR__) . '/private_html/refund_fee_engine.log',
            dirname(__DIR__) . '/logs/refund_fee_engine.log',
            dirname(__DIR__) . '/refund_fee_engine.log',
            __DIR__ . '/../refund_fee_engine.log',
        ];

        foreach ($candidates as $file) {
            $dir = dirname($file);
            if (is_dir($dir) || @mkdir($dir, 0775, true)) {
                @file_put_contents($file, $line, FILE_APPEND);
                break;
            }
        }

        @error_log(trim($line));
    }
}

if (!function_exists('bv_refund_fee_now')) {
    function bv_refund_fee_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('bv_refund_fee_round')) {
    function bv_refund_fee_round($amount): float
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return 0.0;
        }

        return round((float) $amount, 2);
    }
}

if (!function_exists('bv_refund_fee_positive')) {
    function bv_refund_fee_positive($amount): float
    {
        $value = bv_refund_fee_round($amount);
        return $value > 0 ? $value : 0.0;
    }
}

if (!function_exists('bv_refund_fee_normalize_scope')) {
    function bv_refund_fee_normalize_scope(string $feeScope, string $feeType = ''): string
    {
        $scope = strtolower(trim($feeScope));
        $type = strtolower(trim($feeType));

        if ($scope === '') {
            $scope = 'platform';
        }

        if (in_array($scope, ['gateway', 'payment_gateway', 'gateway_fee'], true)) {
            return 'gateway';
        }

        if (in_array($scope, ['shipping', 'shipping_refund', 'shipping_fee'], true)) {
            return 'shipping';
        }

        if (in_array($scope, ['tax', 'tax_refund', 'tax_fee'], true)) {
            return 'tax';
        }

        if (
            in_array($type, ['gateway_percent', 'gateway_fixed', 'payment_gateway_percent', 'payment_gateway_fixed'], true)
            || strpos($type, 'gateway') !== false
        ) {
            return 'gateway';
        }

        if (strpos($type, 'shipping') !== false) {
            return 'shipping';
        }

        if (strpos($type, 'tax') !== false) {
            return 'tax';
        }

        return 'platform';
    }
}

if (!function_exists('bv_refund_fee_query_all')) {
    function bv_refund_fee_query_all(string $sql, array $params = []): array
    {
        $db = bv_refund_fee_engine_db();

        if (bv_refund_fee_engine_is_pdo($db)) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Prepare failed.');
            }
            if (!$stmt->execute(array_values($params))) {
                $err = $stmt->errorInfo();
                throw new RuntimeException('Execute failed: ' . ($err[2] ?? 'Unknown PDO error'));
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        }

        if (bv_refund_fee_engine_is_mysqli($db)) {
            $stmt = mysqli_prepare($db, $sql);
            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . mysqli_error($db));
            }

            if ($params) {
                $types = '';
                $bindValues = [];
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $bindValues[] = $param;
                }

                $refs = [];
                foreach ($bindValues as $k => $v) {
                    $refs[$k] = &$bindValues[$k];
                }

                array_unshift($refs, $types);
                call_user_func_array([$stmt, 'bind_param'], $refs);
            }

            if (!mysqli_stmt_execute($stmt)) {
                $err = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new RuntimeException('Execute failed: ' . $err);
            }

            $result = mysqli_stmt_get_result($stmt);
            if ($result === false) {
                mysqli_stmt_close($stmt);
                return [];
            }

            $rows = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }

            mysqli_free_result($result);
            mysqli_stmt_close($stmt);

            return $rows;
        }

        throw new RuntimeException('Unsupported database driver');
    }
}

if (!function_exists('bv_refund_fee_query_one')) {
    function bv_refund_fee_query_one(string $sql, array $params = []): ?array
    {
        $rows = bv_refund_fee_query_all($sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bv_refund_fee_execute')) {
    function bv_refund_fee_execute(string $sql, array $params = []): array
    {
        $db = bv_refund_fee_engine_db();

        if (bv_refund_fee_engine_is_pdo($db)) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Prepare failed.');
            }
            if (!$stmt->execute(array_values($params))) {
                $err = $stmt->errorInfo();
                throw new RuntimeException('Execute failed: ' . ($err[2] ?? 'Unknown PDO error'));
            }

            return [
                'affected_rows' => (int) $stmt->rowCount(),
                'insert_id' => (int) $db->lastInsertId(),
            ];
        }

        if (bv_refund_fee_engine_is_mysqli($db)) {
            $stmt = mysqli_prepare($db, $sql);
            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . mysqli_error($db));
            }

            if ($params) {
                $types = '';
                $bindValues = [];
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $bindValues[] = $param;
                }

                $refs = [];
                foreach ($bindValues as $k => $v) {
                    $refs[$k] = &$bindValues[$k];
                }

                array_unshift($refs, $types);
                call_user_func_array([$stmt, 'bind_param'], $refs);
            }

            if (!mysqli_stmt_execute($stmt)) {
                $err = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new RuntimeException('Execute failed: ' . $err);
            }

            $affected = mysqli_stmt_affected_rows($stmt);
            $insertId = mysqli_insert_id($db);
            mysqli_stmt_close($stmt);

            return [
                'affected_rows' => (int) $affected,
                'insert_id' => (int) $insertId,
            ];
        }

        throw new RuntimeException('Unsupported database driver');
    }
}

if (!function_exists('bv_refund_fee_table_exists')) {
    function bv_refund_fee_table_exists(string $table): bool
    {
        static $cache = [];

        $table = trim(str_replace('`', '', $table));
        if ($table === '') {
            return false;
        }

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $db = bv_refund_fee_engine_db();

            if (bv_refund_fee_engine_is_pdo($db)) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $stmt->execute([$table]);
                $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
                return $cache[$table];
            }

            if (bv_refund_fee_engine_is_mysqli($db)) {
                $sql = "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?";
                $rows = bv_refund_fee_query_all($sql, [$table]);
                $cache[$table] = ((int) ($rows[0]['c'] ?? 0)) > 0;
                return $cache[$table];
            }
        } catch (Throwable $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}

if (!function_exists('bv_refund_fee_columns')) {
    function bv_refund_fee_columns(string $table): array
    {
        static $cache = [];

        $table = trim(str_replace('`', '', $table));
        if ($table === '') {
            return [];
        }

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];

        try {
            $db = bv_refund_fee_engine_db();

            if (bv_refund_fee_engine_is_pdo($db)) {
                $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['Field'])) {
                        $columns[(string) $row['Field']] = true;
                    }
                }
            } elseif (bv_refund_fee_engine_is_mysqli($db)) {
                $result = mysqli_query($db, "SHOW COLUMNS FROM `{$table}`");
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        if (!empty($row['Field'])) {
                            $columns[(string) $row['Field']] = true;
                        }
                    }
                    mysqli_free_result($result);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        $cache[$table] = $columns;
        return $columns;
    }
}

if (!function_exists('bv_refund_fee_has_col')) {
    function bv_refund_fee_has_col(string $table, string $column): bool
    {
        $cols = bv_refund_fee_columns($table);
        return isset($cols[$column]);
    }
}

if (!function_exists('bv_refund_fee_policy_detect_from_order')) {
    function bv_refund_fee_policy_detect_from_order(array $order): string
    {
        foreach ([
            'fee_policy_code_snapshot',
            'fee_policy_code',
            'refund_fee_policy_code',
            'policy_code',
        ] as $field) {
            $value = trim((string) ($order[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'MARKETPLACE_STD';
    }
}

if (!function_exists('bv_refund_fee_policy_get')) {
    function bv_refund_fee_policy_get(
        string $policyCode,
        string $channel = 'shop',
        ?string $paymentProvider = null,
        string $refundMode = 'both'
    ): array {
        $policyCode = trim($policyCode);
        $channel = trim($channel) !== '' ? trim($channel) : 'shop';
        $paymentProvider = trim((string) $paymentProvider);
        $refundMode = trim($refundMode) !== '' ? trim($refundMode) : 'both';

        bv_refund_fee_log('policy_get_input', [
            'policy_code' => $policyCode,
            'channel' => $channel,
            'payment_provider' => $paymentProvider,
            'payment_method' => '',
            'refund_mode' => $refundMode,
        ]);

        if ($policyCode === '' || !bv_refund_fee_table_exists('refund_fee_policies')) {
            bv_refund_fee_log('policy_get_result', [
                'policy_code' => $policyCode,
                'channel' => $channel,
                'payment_provider' => $paymentProvider,
                'refund_mode' => $refundMode,
                'count' => 0,
                'source' => 'missing_table_or_policy',
            ]);
            return [];
        }

        $hasPaymentProvider = bv_refund_fee_has_col('refund_fee_policies', 'payment_provider');
        $hasRefundMode = bv_refund_fee_has_col('refund_fee_policies', 'refund_mode');
        $hasIsActive = bv_refund_fee_has_col('refund_fee_policies', 'is_active');
        $hasPriority = bv_refund_fee_has_col('refund_fee_policies', 'priority');

        $sql = "SELECT * FROM refund_fee_policies WHERE policy_code = ? AND channel = ?";
        $params = [$policyCode, $channel];

        if ($hasIsActive) {
            $sql .= " AND is_active = 1";
        }

        if ($hasPaymentProvider) {
            $sql .= " AND (payment_provider IS NULL OR payment_provider = '' OR payment_provider = ?)";
            $params[] = $paymentProvider;
        }

        if ($hasRefundMode) {
            $sql .= " AND (refund_mode = 'both' OR refund_mode = ?)";
            $params[] = $refundMode;
        }

        $sql .= $hasPriority ? " ORDER BY priority ASC, id ASC" : " ORDER BY id ASC";

        try {
            $rows = bv_refund_fee_query_all($sql, $params);
            bv_refund_fee_log('policy_get_result', [
                'policy_code' => $policyCode,
                'channel' => $channel,
                'payment_provider' => $paymentProvider,
                'refund_mode' => $refundMode,
                'count' => count($rows),
                'source' => 'db',
            ]);
            return $rows;
        } catch (Throwable $e) {
            bv_refund_fee_log('policy_get_failed', [
                'policy_code' => $policyCode,
                'channel' => $channel,
                'payment_provider' => $paymentProvider,
                'refund_mode' => $refundMode,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}

if (!function_exists('bv_refund_fee_snapshot_from_order')) {
    function bv_refund_fee_snapshot_from_order(array $order): array
    {
        $gross = bv_refund_fee_positive($order['gross_paid_amount'] ?? 0);
        if ($gross <= 0) {
            $gross = bv_refund_fee_positive($order['total'] ?? 0);
        }

        $refundableGross = bv_refund_fee_positive($order['refundable_gross_amount'] ?? 0);
        if ($refundableGross <= 0) {
            $refundableGross = $gross;
        }

        $platformFeeTotal = bv_refund_fee_positive($order['platform_fee_total'] ?? 0);
        $platformFeeRefundable = bv_refund_fee_positive($order['platform_fee_refundable'] ?? 0);
        $platformFeeNonRefundable = bv_refund_fee_positive($order['platform_fee_non_refundable'] ?? ($platformFeeTotal - $platformFeeRefundable));

        $gatewayFeeTotal = bv_refund_fee_positive($order['payment_gateway_fee_total'] ?? 0);
        $gatewayFeeRefundable = bv_refund_fee_positive($order['payment_gateway_fee_refundable'] ?? 0);
        $gatewayFeeNonRefundable = bv_refund_fee_positive($order['payment_gateway_fee_non_refundable'] ?? ($gatewayFeeTotal - $gatewayFeeRefundable));

        $manualDeduction = bv_refund_fee_positive($order['manual_deduction_amount'] ?? 0);
        $manualReason = trim((string) ($order['manual_deduction_reason'] ?? ''));

        $policyCode = bv_refund_fee_policy_detect_from_order($order);
        $policySnapshot = (string) ($order['fee_policy_snapshot'] ?? '');

        $sellerNet = bv_refund_fee_positive(
            $order['seller_net_amount_snapshot']
                ?? ($gross - $platformFeeNonRefundable - $gatewayFeeNonRefundable - $manualDeduction)
        );

        return [
            'gross_paid_amount' => $gross,
            'refundable_gross_amount' => $refundableGross,
            'platform_fee_total' => $platformFeeTotal,
            'platform_fee_refundable' => $platformFeeRefundable,
            'platform_fee_non_refundable' => $platformFeeNonRefundable,
            'payment_gateway_fee_total' => $gatewayFeeTotal,
            'payment_gateway_fee_refundable' => $gatewayFeeRefundable,
            'payment_gateway_fee_non_refundable' => $gatewayFeeNonRefundable,
            'manual_deduction_amount' => $manualDeduction,
            'manual_deduction_reason' => $manualReason !== '' ? $manualReason : null,
            'fee_policy_code' => $policyCode,
            'fee_policy_snapshot' => $policySnapshot,
            'seller_net_amount_snapshot' => $sellerNet,
        ];
    }
}

if (!function_exists('bv_refund_fee_build_lines')) {
    function bv_refund_fee_build_lines(array $order, array $refundHeader, array $policyRows): array
    {
        $commissionBase = bv_refund_fee_positive(
            $order['commission_base']
                ?? $order['subtotal']
                ?? $order['subtotal_after_discount']
                ?? $order['total']
                ?? 0
        );

        $grossPaidAmount = bv_refund_fee_positive($refundHeader['gross_paid_amount'] ?? 0);
        if ($grossPaidAmount <= 0) {
            $grossPaidAmount = bv_refund_fee_positive($order['gross_paid_amount'] ?? 0);
        }
        if ($grossPaidAmount <= 0) {
            $grossPaidAmount = bv_refund_fee_positive($order['total'] ?? 0);
        }

        $refundableGross = bv_refund_fee_positive($refundHeader['refundable_gross_amount'] ?? 0);
        if ($refundableGross <= 0) {
            $refundableGross = $grossPaidAmount;
        }

        $lines = [];

        foreach ($policyRows as $row) {
            $feeType = trim((string) ($row['fee_type'] ?? ''));
            if ($feeType === '') {
                continue;
            }

            $feeScope = bv_refund_fee_normalize_scope((string) ($row['fee_scope'] ?? 'platform'), $feeType);
            $calcMode = strtolower(trim((string) ($row['calculation_mode'] ?? 'fixed')));
            $isRefundable = (int) ($row['is_refundable'] ?? 0) === 1;
            $fixedAmount = bv_refund_fee_positive($row['fixed_amount'] ?? 0);
            $percentRate = is_numeric($row['percent_rate'] ?? null) ? (float) $row['percent_rate'] : 0.0;
            $snapshotField = trim((string) ($row['snapshot_field'] ?? ''));

            $baseAmount = $commissionBase;

            if ($feeScope === 'shipping' || in_array($feeType, ['shipping_refund', 'shipping_fee', 'shipping'], true)) {
                $baseAmount = bv_refund_fee_positive($order['shipping_amount'] ?? 0);
            } elseif ($feeScope === 'tax' || in_array($feeType, ['tax_refund', 'tax_fee', 'tax'], true)) {
                $baseAmount = bv_refund_fee_positive($order['tax_amount'] ?? $order['tax'] ?? 0);
            } elseif ($feeScope === 'gateway' || in_array($feeType, ['gateway_percent', 'gateway_fixed', 'payment_gateway_percent', 'payment_gateway_fixed'], true)) {
                $baseAmount = $grossPaidAmount;
            } elseif ($snapshotField !== '' && isset($order[$snapshotField]) && is_numeric($order[$snapshotField])) {
                $baseAmount = bv_refund_fee_positive($order[$snapshotField]);
            }

            if ($calcMode === 'snapshot') {
                $amount = $snapshotField !== '' && isset($order[$snapshotField]) && is_numeric($order[$snapshotField])
                    ? bv_refund_fee_positive($order[$snapshotField])
                    : 0.0;
            } elseif ($calcMode === 'percent') {
                $amount = bv_refund_fee_round($baseAmount * ($percentRate / 100));
            } else {
                $amount = $fixedAmount;
            }

            $amount = bv_refund_fee_positive($amount);

            $lines[] = [
                'fee_type' => $feeType,
                'fee_scope' => $feeScope,
                'calculation_mode' => $calcMode,
                'base_amount' => bv_refund_fee_round($baseAmount),
                'percent_rate' => round($percentRate, 4),
                'fixed_amount' => $fixedAmount,
                'fee_amount' => $amount,
                'is_refundable' => $isRefundable ? 1 : 0,
                'fee_loss_amount' => $isRefundable ? 0.0 : $amount,
                'refund_ratio' => 1.0,
                'refundable_gross_amount' => $refundableGross,
            ];
        }

        return $lines;
    }
}

if (!function_exists('bv_refund_fee_calculate_summary')) {
    function bv_refund_fee_calculate_summary(array $refundHeader, array $feeLines): array
    {
        $requestedRefundAmount = bv_refund_fee_positive($refundHeader['approved_refund_amount'] ?? 0);
        if ($requestedRefundAmount <= 0) {
            $requestedRefundAmount = bv_refund_fee_positive($refundHeader['requested_refund_amount'] ?? 0);
        }
        if ($requestedRefundAmount <= 0) {
            $requestedRefundAmount = bv_refund_fee_positive($refundHeader['refundable_gross_amount'] ?? 0);
        }

        $grossSnapshot = bv_refund_fee_positive($refundHeader['gross_paid_amount'] ?? 0);
        if ($grossSnapshot <= 0) {
            $grossSnapshot = $requestedRefundAmount;
        }

        $refundableGross = bv_refund_fee_positive($refundHeader['refundable_gross_amount'] ?? 0);
        if ($refundableGross <= 0) {
            $refundableGross = $grossSnapshot;
        }

        $ratio = 0.0;
        if ($refundableGross > 0) {
            $ratio = min(1, max(0, $requestedRefundAmount / $refundableGross));
        }

        $platformFeeTotal = 0.0;
        $platformFeeRefundable = 0.0;
        $platformFeeNonRefundable = 0.0;
        $gatewayFeeTotal = 0.0;
        $gatewayFeeRefundable = 0.0;
        $gatewayFeeNonRefundable = 0.0;
        $manualDeductionAmount = bv_refund_fee_positive($refundHeader['manual_deduction_amount'] ?? 0);
        $feeLossAmount = 0.0;

        foreach ($feeLines as $line) {
            $scope = bv_refund_fee_normalize_scope((string) ($line['fee_scope'] ?? 'platform'), (string) ($line['fee_type'] ?? ''));
            $feeAmount = bv_refund_fee_positive($line['fee_amount'] ?? 0);
            $isRefundable = (int) ($line['is_refundable'] ?? 0) === 1;

            $effectiveLoss = $isRefundable ? 0.0 : bv_refund_fee_round($feeAmount * $ratio);
            $feeLossAmount += $effectiveLoss;

            if ($scope === 'gateway') {
                $gatewayFeeTotal += $feeAmount;
                if ($isRefundable) {
                    $gatewayFeeRefundable += $feeAmount;
                } else {
                    $gatewayFeeNonRefundable += $feeAmount;
                }
            } else {
                $platformFeeTotal += $feeAmount;
                if ($isRefundable) {
                    $platformFeeRefundable += $feeAmount;
                } else {
                    $platformFeeNonRefundable += $feeAmount;
                }
            }
        }

        $feeLossAmount = bv_refund_fee_round($feeLossAmount + $manualDeductionAmount);
        $actualRefundAmount = bv_refund_fee_round(max(0, $requestedRefundAmount - $feeLossAmount));

        return [
            'refund_ratio' => round($ratio, 8),
            'requested_refund_amount' => $requestedRefundAmount,
            'approved_refund_amount' => $requestedRefundAmount,
            'gross_paid_amount' => $grossSnapshot,
            'refundable_gross_amount' => $refundableGross,
            'platform_fee_total' => bv_refund_fee_round($platformFeeTotal),
            'platform_fee_refundable' => bv_refund_fee_round($platformFeeRefundable),
            'platform_fee_non_refundable' => bv_refund_fee_round($platformFeeNonRefundable),
            'payment_gateway_fee_total' => bv_refund_fee_round($gatewayFeeTotal),
            'payment_gateway_fee_refundable' => bv_refund_fee_round($gatewayFeeRefundable),
            'payment_gateway_fee_non_refundable' => bv_refund_fee_round($gatewayFeeNonRefundable),
            'manual_deduction_amount' => $manualDeductionAmount,
            'fee_loss_amount' => $feeLossAmount,
            'platform_fee_loss' => bv_refund_fee_round($platformFeeNonRefundable * $ratio),
            'gateway_fee_loss' => bv_refund_fee_round($gatewayFeeNonRefundable * $ratio),
            'actual_refund_amount' => $actualRefundAmount,
        ];
    }
}

if (!function_exists('bv_refund_fee_build_order_paid_snapshot')) {
    function bv_refund_fee_build_order_paid_snapshot(array $order, array $policyRows): array
    {
        $orderId = (int) ($order['id'] ?? 0);

        $grossPaidAmount = bv_refund_fee_positive($order['gross_paid_amount'] ?? 0);
        if ($grossPaidAmount <= 0) {
            $grossPaidAmount = bv_refund_fee_positive($order['total'] ?? 0);
        }

        bv_refund_fee_log('build_order_paid_snapshot_started', [
            'order_id' => $orderId,
            'policy_rows' => is_array($policyRows) ? count($policyRows) : 0,
            'order_total' => bv_refund_fee_positive($order['total'] ?? 0),
            'gross_paid_amount_before' => bv_refund_fee_positive($order['gross_paid_amount'] ?? 0),
        ]);

        $header = [
            'gross_paid_amount' => $grossPaidAmount,
            'refundable_gross_amount' => $grossPaidAmount,
            'requested_refund_amount' => $grossPaidAmount,
            'approved_refund_amount' => $grossPaidAmount,
            'manual_deduction_amount' => 0.0,
        ];

        $feeLines = bv_refund_fee_build_lines($order, $header, $policyRows);
        $summary = bv_refund_fee_calculate_summary($header, $feeLines);

        $snapshot = array_merge($summary, [
            'gross_paid_amount' => $grossPaidAmount,
            'refundable_gross_amount' => $grossPaidAmount,
            'manual_deduction_reason' => null,
            'fee_policy_code' => bv_refund_fee_policy_detect_from_order($order),
            'fee_policy_snapshot' => json_encode([
                'policy_code' => bv_refund_fee_policy_detect_from_order($order),
                'channel' => (string) ($order['order_source'] ?? $order['source'] ?? 'shop'),
                'payment_provider' => (string) ($order['payment_provider'] ?? ''),
                'captured_at' => bv_refund_fee_now(),
                'policy_rows' => $policyRows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $snapshot['seller_net_amount_snapshot'] = bv_refund_fee_round(
            max(
                0,
                $grossPaidAmount
                - ($snapshot['platform_fee_non_refundable'] ?? 0)
                - ($snapshot['payment_gateway_fee_non_refundable'] ?? 0)
                - ($snapshot['manual_deduction_amount'] ?? 0)
            )
        );
		
        if (function_exists('bv_seller_fee_promo_apply_to_snapshot')) {
            try {
                $sellerId = (int) ($order['seller_id'] ?? 0);
                $detectedSellerIds = [];

                if ($sellerId > 0) {
                    $detectedSellerIds[$sellerId] = true;
                } elseif ($orderId > 0 && bv_refund_fee_table_exists('order_items')) {
                    $orderItemCols = bv_refund_fee_columns('order_items');
                    $listingCols = bv_refund_fee_table_exists('listings') ? bv_refund_fee_columns('listings') : [];

                    if (isset($orderItemCols['seller_id'])) {
                        $rows = bv_refund_fee_query_all(
                            'SELECT DISTINCT seller_id FROM order_items WHERE order_id = ? AND seller_id IS NOT NULL AND seller_id > 0',
                            [$orderId]
                        );

                        foreach ($rows as $row) {
                            $rowSellerId = (int) ($row['seller_id'] ?? 0);
                            if ($rowSellerId > 0) {
                                $detectedSellerIds[$rowSellerId] = true;
                            }
                        }
                    }

                    if (isset($orderItemCols['listing_id']) && isset($listingCols['id'], $listingCols['seller_id'])) {
                        $rows = bv_refund_fee_query_all(
                            'SELECT DISTINCT l.seller_id FROM order_items oi INNER JOIN listings l ON l.id = oi.listing_id WHERE oi.order_id = ? AND l.seller_id IS NOT NULL AND l.seller_id > 0',
                            [$orderId]
                        );

                        foreach ($rows as $row) {
                            $rowSellerId = (int) ($row['seller_id'] ?? 0);
                            if ($rowSellerId > 0) {
                                $detectedSellerIds[$rowSellerId] = true;
                            }
                        }
                    }
                }

                if (count($detectedSellerIds) === 1) {
                    $sellerId = (int) key($detectedSellerIds);
                    if ($sellerId > 0) {
                        $snapshot = bv_seller_fee_promo_apply_to_snapshot($snapshot, $sellerId);
                        bv_refund_fee_log('seller_fee_promo_applied', [
                            'order_id' => $orderId,
                            'seller_id' => $sellerId,
                        ]);
                    }
                } elseif (count($detectedSellerIds) > 1) {
                    bv_refund_fee_log('seller_fee_promo_skipped_multi_seller', [
                        'order_id' => $orderId,
                        'seller_ids' => array_map('intval', array_keys($detectedSellerIds)),
                    ]);
                }
            } catch (Throwable $e) {
                bv_refund_fee_log('seller_fee_promo_failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
		

        bv_refund_fee_log('build_order_paid_snapshot_completed', [
            'order_id' => $orderId,
            'gross_paid_amount' => $snapshot['gross_paid_amount'],
            'platform_fee_total' => $snapshot['platform_fee_total'],
            'platform_fee_non_refundable' => $snapshot['platform_fee_non_refundable'],
            'payment_gateway_fee_total' => $snapshot['payment_gateway_fee_total'],
            'payment_gateway_fee_non_refundable' => $snapshot['payment_gateway_fee_non_refundable'],
            'manual_deduction_amount' => $snapshot['manual_deduction_amount'],
            'seller_net_amount_snapshot' => $snapshot['seller_net_amount_snapshot'],
            'policy_code' => $snapshot['fee_policy_code'],
        ]);

        return $snapshot;
    }
}

if (!function_exists('bv_refund_fee_snapshot_save_to_order')) {
    function bv_refund_fee_snapshot_save_to_order(int $orderId, array $snapshot, $db = null): bool
    {
        if ($orderId <= 0 || !bv_refund_fee_table_exists('orders')) {
            return false;
        }

        $db = $db ?: bv_refund_fee_engine_db();
        $cols = bv_refund_fee_columns('orders');

        $allowed = [
            'gross_paid_amount',
            'refundable_gross_amount',
            'platform_fee_total',
            'platform_fee_refundable',
            'platform_fee_non_refundable',
            'payment_gateway_fee_total',
            'payment_gateway_fee_refundable',
            'payment_gateway_fee_non_refundable',
            'manual_deduction_amount',
            'manual_deduction_reason',
            'fee_policy_code',
            'fee_policy_snapshot',
            'seller_net_amount_snapshot',
        ];

        $map = [
            'fee_policy_code' => 'fee_policy_code_snapshot',
            'fee_policy_snapshot' => 'fee_policy_snapshot',
        ];

        $sets = [];
        $params = [];

        foreach ($allowed as $key) {
            $column = $map[$key] ?? $key;
            if (!isset($cols[$column])) {
                continue;
            }

            $sets[] = "`{$column}` = ?";
            $params[] = $snapshot[$key] ?? null;
        }

        if (isset($cols['updated_at'])) {
            $sets[] = "`updated_at` = ?";
            $params[] = bv_refund_fee_now();
        }

        if (!$sets) {
            return false;
        }

        $params[] = $orderId;
        $sql = "UPDATE orders SET " . implode(', ', $sets) . " WHERE id = ?";

        try {
            if (bv_refund_fee_engine_is_pdo($db)) {
                $stmt = $db->prepare($sql);
                $ok = $stmt->execute($params);
            } elseif (bv_refund_fee_engine_is_mysqli($db)) {
                $result = bv_refund_fee_execute($sql, $params);
                $ok = is_array($result);
            } else {
                $ok = false;
            }

            bv_refund_fee_log('snapshot_save_to_order', [
                'order_id' => $orderId,
                'saved' => $ok ? 1 : 0,
                'fields' => $sets,
            ]);

            return (bool) $ok;
        } catch (Throwable $e) {
            bv_refund_fee_log('snapshot_save_to_order_failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

if (!function_exists('bv_refund_fee_get_order_by_id')) {
    function bv_refund_fee_get_order_by_id(int $orderId): ?array
    {
        if ($orderId <= 0 || !bv_refund_fee_table_exists('orders')) {
            return null;
        }

        return bv_refund_fee_query_one("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
    }
}

if (!function_exists('bv_refund_fee_get_refund_by_id')) {
    function bv_refund_fee_get_refund_by_id(int $refundId): ?array
    {
        if ($refundId <= 0 || !bv_refund_fee_table_exists('order_refunds')) {
            return null;
        }

        return bv_refund_fee_query_one("SELECT * FROM order_refunds WHERE id = ? LIMIT 1", [$refundId]);
    }
}

if (!function_exists('bv_refund_fee_get_refund_items')) {
    function bv_refund_fee_get_refund_items(int $refundId): array
    {
        if ($refundId <= 0 || !bv_refund_fee_table_exists('order_refund_items')) {
            return [];
        }

        return bv_refund_fee_query_all("SELECT * FROM order_refund_items WHERE refund_id = ? ORDER BY id ASC", [$refundId]);
    }
}

if (!function_exists('bv_refund_fee_sync_summary_to_refund')) {
    function bv_refund_fee_sync_summary_to_refund(int $refundId, array $summary): bool
    {
        if ($refundId <= 0 || !bv_refund_fee_table_exists('order_refunds')) {
            return false;
        }

        $cols = bv_refund_fee_columns('order_refunds');

        $fields = [
            'requested_refund_amount',
            'approved_refund_amount',
            'gross_paid_amount',
            'refundable_gross_amount',
            'platform_fee_total',
            'platform_fee_refundable',
            'platform_fee_non_refundable',
            'payment_gateway_fee_total',
            'payment_gateway_fee_refundable',
            'payment_gateway_fee_non_refundable',
            'manual_deduction_amount',
            'fee_loss_amount',
            'platform_fee_loss',
            'gateway_fee_loss',
            'actual_refund_amount',
        ];

        $sets = [];
        $params = [];

        foreach ($fields as $field) {
            if (!isset($cols[$field])) {
                continue;
            }

            $sets[] = "`{$field}` = ?";
            $params[] = $summary[$field] ?? 0;
        }

        if (isset($cols['updated_at'])) {
            $sets[] = "`updated_at` = ?";
            $params[] = bv_refund_fee_now();
        }

        if (!$sets) {
            return false;
        }

        $params[] = $refundId;

        try {
            bv_refund_fee_execute(
                "UPDATE order_refunds SET " . implode(', ', $sets) . " WHERE id = ?",
                $params
            );

            return true;
        } catch (Throwable $e) {
            bv_refund_fee_log('sync_summary_to_refund_failed', [
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

if (!function_exists('bv_refund_fee_allocate_item_fee_loss')) {
    function bv_refund_fee_allocate_item_fee_loss(array $refundHeader, array $refundItems, array $summary): array
    {
        if (!$refundItems) {
            return [];
        }

        $approvedTotal = bv_refund_fee_positive($refundHeader['approved_refund_amount'] ?? 0);
        if ($approvedTotal <= 0) {
            $approvedTotal = bv_refund_fee_positive($refundHeader['requested_refund_amount'] ?? 0);
        }

        if ($approvedTotal <= 0) {
            $sum = 0.0;
            foreach ($refundItems as $item) {
                $sum += bv_refund_fee_positive(
                    $item['approved_refund_amount']
                        ?? $item['refund_line_amount']
                        ?? $item['requested_refund_amount']
                        ?? $item['item_refundable_amount']
                        ?? $item['line_total_snapshot']
                        ?? 0
                );
            }
            $approvedTotal = bv_refund_fee_round($sum);
        }

        $feeLossTotal = bv_refund_fee_positive($summary['fee_loss_amount'] ?? 0);
        $actualTotal = bv_refund_fee_positive($summary['actual_refund_amount'] ?? 0);

        $allocated = [];
        $runningLoss = 0.0;
        $runningActual = 0.0;
        $lastIndex = count($refundItems) - 1;

        foreach ($refundItems as $index => $item) {
            $itemApproved = bv_refund_fee_positive(
                $item['approved_refund_amount']
                    ?? $item['refund_line_amount']
                    ?? $item['requested_refund_amount']
                    ?? $item['item_refundable_amount']
                    ?? $item['line_total_snapshot']
                    ?? 0
            );

            $ratio = $approvedTotal > 0 ? ($itemApproved / $approvedTotal) : 0.0;
            $ratio = min(1, max(0, $ratio));

            if ($index === $lastIndex) {
                $itemLoss = bv_refund_fee_round($feeLossTotal - $runningLoss);
                $itemActual = bv_refund_fee_round($actualTotal - $runningActual);
            } else {
                $itemLoss = bv_refund_fee_round($feeLossTotal * $ratio);
                $itemActual = bv_refund_fee_round(max(0, $itemApproved - $itemLoss));
                $runningLoss += $itemLoss;
                $runningActual += $itemActual;
            }

            $allocated[] = [
                'id' => (int) ($item['id'] ?? 0),
                'approved_refund_amount' => $itemApproved,
                'allocated_fee_loss_amount' => max(0, $itemLoss),
                'actual_refund_after_fee' => max(0, $itemActual),
            ];
        }

        return $allocated;
    }
}

if (!function_exists('bv_refund_fee_sync_items_to_refund')) {
    function bv_refund_fee_sync_items_to_refund(int $refundId, array $allocations): bool
    {
        if ($refundId <= 0 || !$allocations || !bv_refund_fee_table_exists('order_refund_items')) {
            return false;
        }

        $cols = bv_refund_fee_columns('order_refund_items');
        $hasAllocatedLoss = isset($cols['allocated_fee_loss_amount']);
        $hasActualAfterFee = isset($cols['actual_refund_after_fee']);
        $hasApprovedAmount = isset($cols['approved_refund_amount']);

        if (!$hasAllocatedLoss && !$hasActualAfterFee && !$hasApprovedAmount) {
            return false;
        }

        try {
            foreach ($allocations as $row) {
                $itemId = (int) ($row['id'] ?? 0);
                if ($itemId <= 0) {
                    continue;
                }

                $sets = [];
                $params = [];

                if ($hasApprovedAmount) {
                    $sets[] = "`approved_refund_amount` = ?";
                    $params[] = $row['approved_refund_amount'] ?? 0;
                }
                if ($hasAllocatedLoss) {
                    $sets[] = "`allocated_fee_loss_amount` = ?";
                    $params[] = $row['allocated_fee_loss_amount'] ?? 0;
                }
                if ($hasActualAfterFee) {
                    $sets[] = "`actual_refund_after_fee` = ?";
                    $params[] = $row['actual_refund_after_fee'] ?? 0;
                }
                if (isset($cols['updated_at'])) {
                    $sets[] = "`updated_at` = ?";
                    $params[] = bv_refund_fee_now();
                }

                if (!$sets) {
                    continue;
                }

                $params[] = $itemId;

                bv_refund_fee_execute(
                    "UPDATE order_refund_items SET " . implode(', ', $sets) . " WHERE id = ?",
                    $params
                );
            }

            return true;
        } catch (Throwable $e) {
            bv_refund_fee_log('sync_items_to_refund_failed', [
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

if (!function_exists('bv_refund_fee_rebuild_for_refund')) {
    function bv_refund_fee_rebuild_for_refund(int $refundId): array
    {
        bv_refund_fee_log('rebuild_for_refund_started', [
            'refund_id' => $refundId,
        ]);

        $refund = bv_refund_fee_get_refund_by_id($refundId);
        if (!$refund) {
            $result = [
                'ok' => false,
                'refund_id' => $refundId,
                'error' => 'refund_not_found',
                'summary' => [],
                'items' => [],
            ];

            bv_refund_fee_log('rebuild_for_refund_failed', $result);
            return $result;
        }

        $orderId = (int) ($refund['order_id'] ?? 0);
        $order = bv_refund_fee_get_order_by_id($orderId);
        if (!$order) {
            $result = [
                'ok' => false,
                'refund_id' => $refundId,
                'order_id' => $orderId,
                'error' => 'order_not_found',
                'summary' => [],
                'items' => [],
            ];

            bv_refund_fee_log('rebuild_for_refund_failed', $result);
            return $result;
        }

        $snapshot = bv_refund_fee_snapshot_from_order($order);

        $refundHeader = array_merge($refund, $snapshot, [
            'gross_paid_amount' => $snapshot['gross_paid_amount'],
            'refundable_gross_amount' => $snapshot['refundable_gross_amount'],
            'manual_deduction_amount' => $snapshot['manual_deduction_amount'],
            'requested_refund_amount' => bv_refund_fee_positive($refund['requested_refund_amount'] ?? 0),
            'approved_refund_amount' => bv_refund_fee_positive($refund['approved_refund_amount'] ?? 0),
        ]);

        if (($refundHeader['requested_refund_amount'] ?? 0) <= 0) {
            $refundHeader['requested_refund_amount'] = bv_refund_fee_positive($refund['approved_refund_amount'] ?? 0);
        }
        if (($refundHeader['requested_refund_amount'] ?? 0) <= 0) {
            $refundHeader['requested_refund_amount'] = bv_refund_fee_positive($snapshot['refundable_gross_amount'] ?? 0);
        }

        if (($refundHeader['approved_refund_amount'] ?? 0) <= 0) {
            $refundHeader['approved_refund_amount'] = bv_refund_fee_positive($refund['requested_refund_amount'] ?? 0);
        }
        if (($refundHeader['approved_refund_amount'] ?? 0) <= 0) {
            $refundHeader['approved_refund_amount'] = bv_refund_fee_positive($snapshot['refundable_gross_amount'] ?? 0);
        }

        $policyCode = trim((string) ($snapshot['fee_policy_code'] ?? bv_refund_fee_policy_detect_from_order($order)));
        $channel = trim((string) ($order['order_source'] ?? $order['source'] ?? 'shop'));
        $paymentProvider = trim((string) ($order['payment_provider'] ?? ''));
        $refundMode = trim((string) ($refund['refund_mode'] ?? 'full'));

        $policyRows = bv_refund_fee_policy_get($policyCode, $channel, $paymentProvider, $refundMode);
        $feeLines = bv_refund_fee_build_lines($order, $refundHeader, $policyRows);
        $summary = array_merge(
            $snapshot,
            bv_refund_fee_calculate_summary($refundHeader, $feeLines)
        );

        if (isset($refund['requested_refund_amount']) && is_numeric($refund['requested_refund_amount'])) {
            $summary['requested_refund_amount'] = bv_refund_fee_positive($refund['requested_refund_amount']);
        }
        if (isset($refund['approved_refund_amount']) && is_numeric($refund['approved_refund_amount']) && (float) $refund['approved_refund_amount'] > 0) {
            $summary['approved_refund_amount'] = bv_refund_fee_positive($refund['approved_refund_amount']);
            $summary['actual_refund_amount'] = bv_refund_fee_round(max(0, $summary['approved_refund_amount'] - $summary['fee_loss_amount']));
        }

        $refundItems = bv_refund_fee_get_refund_items($refundId);
        $allocations = bv_refund_fee_allocate_item_fee_loss($refundHeader, $refundItems, $summary);

        $summarySaved = bv_refund_fee_sync_summary_to_refund($refundId, $summary);
        $itemsSaved = bv_refund_fee_sync_items_to_refund($refundId, $allocations);

        $result = [
            'ok' => $summarySaved,
            'refund_id' => $refundId,
            'order_id' => $orderId,
            'policy_code' => $policyCode,
            'summary_saved' => $summarySaved,
            'items_saved' => $itemsSaved,
            'summary' => $summary,
            'items' => $allocations,
            'fee_lines' => $feeLines,
            'error' => null,
        ];

        bv_refund_fee_log('rebuild_for_refund_completed', [
            'refund_id' => $refundId,
            'order_id' => $orderId,
            'policy_code' => $policyCode,
            'fee_loss_amount' => $summary['fee_loss_amount'] ?? 0,
            'actual_refund_amount' => $summary['actual_refund_amount'] ?? 0,
            'items' => count($allocations),
            'summary_saved' => $summarySaved ? 1 : 0,
            'items_saved' => $itemsSaved ? 1 : 0,
        ]);

        return $result;
    }
}

if (!function_exists('bv_refund_fee_summary_safe')) {
    function bv_refund_fee_summary_safe(int $refundId): array
    {
        try {
            $result = bv_refund_fee_rebuild_for_refund($refundId);
            return is_array($result['summary'] ?? null) ? $result['summary'] : [];
        } catch (Throwable $e) {
            bv_refund_fee_log('summary_safe_failed', [
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}