<?php
/**
 * Bettavaro Seller Fee Promotion Layer.
 *
 * This file intentionally stays independent from the core fee, refund,
 * checkout, Stripe, order-status, and seller-balance flows. Callers may pass
 * an already-built fee snapshot through bv_seller_fee_promo_apply_to_snapshot()
* to apply admin-controlled platform-fee promotions or percentage overrides
 * without changing checkout, Stripe, refund, or historical balance flows.
 */

if (!function_exists('bv_seller_fee_promo_boot')) {
    /**
     * Lightweight bootstrap hook kept safe for optional includes.
     */
    function bv_seller_fee_promo_boot(): void
    {
        // Intentionally no side effects. The database migration owns schema changes.
    }
}

if (!function_exists('bv_seller_fee_promo_db')) {
    /**
     * Return an existing database connection when the host application exposes one.
     *
     * @return PDO|mysqli|null
     */
    function bv_seller_fee_promo_db()
    {
        foreach (['pdo', 'db', 'conn', 'mysqli'] as $name) {
            if (isset($GLOBALS[$name]) && ($GLOBALS[$name] instanceof PDO || $GLOBALS[$name] instanceof mysqli)) {
                return $GLOBALS[$name];
            }
        }

        return null;
    }
}

if (!function_exists('bv_seller_fee_promo_query_one')) {
    /**
     * Fetch a single associative row using PDO or mysqli if available.
     */
    function bv_seller_fee_promo_query_one(string $sql, array $params = []): ?array
    {
        try {
            $db = bv_seller_fee_promo_db();
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    return null;
                }

               foreach (array_values($params) as $index => $value) {
                    $stmt->bindValue($index + 1, $value);
                }

               if (!$stmt->execute()) {
                    return null;
                }

                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return is_array($row) ? $row : null;
            }

            if ($db instanceof mysqli) {
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    return null;
                }

               $types = '';
                $values = [];
                foreach (array_values($params) as $value) {
                    if (is_int($value)) {
                        $types .= 'i';
                    } elseif (is_float($value)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $values[] = $value;
                }
                if ($values !== []) {
                    $stmt->bind_param($types, ...$values);
                }

              if (!$stmt->execute()) {
                    $stmt->close();
                    return null;
                } 

                 $result = $stmt->get_result();
                if (!$result instanceof mysqli_result) {
                    $stmt->close();
                    return null;
                }

                $row = $result->fetch_assoc();
                $result->free();
                $stmt->close();
               return is_array($row) ? $row : null;
            }

       } catch (Throwable) {
            return null; 
        }

        return null;
    }
}

if (!function_exists('bv_seller_fee_promo_get_seller')) {
    /**
     * Return promotion fields for a seller/user record.
     */
    function bv_seller_fee_promo_get_seller(int $sellerId): ?array
    {
        if ($sellerId <= 0) {
            return null;
        }

 
        return bv_seller_fee_promo_query_one(
             'SELECT id, seller_fee_promo_starts_at, seller_fee_free_until, seller_fee_percent_override, seller_fee_promo_note, seller_fee_override_note FROM users WHERE id = ? LIMIT 1',
            [$sellerId]
        );
    }
}

if (!function_exists('bv_seller_fee_promo_empty_to_null')) {
    function bv_seller_fee_promo_empty_to_null($value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}

if (!function_exists('bv_seller_fee_promo_parse_percent')) {
    function bv_seller_fee_promo_parse_percent($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return max(0.0, (float) $value);
    }
}

if (!function_exists('bv_seller_fee_promo_settings_from_row')) {
    function bv_seller_fee_promo_settings_from_row(?array $seller, int $sellerId, ?string $now = null): array
    {
		        if (!is_array($seller)) {
            $seller = [];
        }

        $startsAt = bv_seller_fee_promo_empty_to_null($seller['seller_fee_promo_starts_at'] ?? null);
        $freeUntil = bv_seller_fee_promo_empty_to_null($seller['seller_fee_free_until'] ?? null);
        $percentOverride = bv_seller_fee_promo_parse_percent($seller['seller_fee_percent_override'] ?? null);
        $currentTime = strtotime($now ?? 'now');
        $startsAtTime = $startsAt === null ? null : strtotime($startsAt);
        $freeUntilTime = $freeUntil === null ? null : strtotime($freeUntil);

        $startsOk = $startsAt === null || ($currentTime !== false && $startsAtTime !== false && $currentTime >= $startsAtTime);
        $freeUntilOk = $freeUntil !== null && $currentTime !== false && $freeUntilTime !== false && $currentTime <= $freeUntilTime;
        $isActiveWindow = $startsOk && $freeUntilOk;
        $hasPercentOverride = $percentOverride !== null;
        $effectivePercent = null;
        $effectiveMode = 'default';

        if ($hasPercentOverride) {
            $effectivePercent = $percentOverride;
            $effectiveMode = $percentOverride <= 0.0 ? 'free' : 'custom';
       } elseif ($isActiveWindow) { 
            $effectivePercent = 0.0;
            $effectiveMode = 'free';
        }

        return [
            'seller_id' => $sellerId,
            'starts_at' => $startsAt,
            'free_until' => $freeUntil,
            'percent_override' => $percentOverride,
            'promo_note' => (string)($seller['seller_fee_promo_note'] ?? ''),
            'override_note' => (string)($seller['seller_fee_override_note'] ?? ''),
            'is_active_window' => $isActiveWindow,
            'has_percent_override' => $hasPercentOverride,
            'effective_percent' => $effectivePercent,
            'effective_mode' => $effectiveMode,
        ];
    }
}

if (!function_exists('bv_seller_fee_promo_get_settings')) {
    /**
     * Return effective admin-controlled seller fee settings.
     */
     function bv_seller_fee_promo_get_settings(int $sellerId, ?string $now = null): array
    {
         return bv_seller_fee_promo_settings_from_row(bv_seller_fee_promo_get_seller($sellerId), $sellerId, $now); 
    }
}

if (!function_exists('bv_seller_fee_promo_is_active')) {
    /**
     * Determine whether the seller currently has a free platform-fee mode.
     */
    function bv_seller_fee_promo_is_active(int $sellerId, ?string $now = null): bool
    {
       if ($sellerId <= 0) {
            return false;
        }

        $settings = bv_seller_fee_promo_settings_from_row(bv_seller_fee_promo_get_seller($sellerId), $sellerId, $now);
        return ($settings['effective_mode'] ?? 'default') === 'free';
    }
}

if (!function_exists('bv_seller_fee_promo_effective_percent')) {
    /**
     * Return the effective seller platform-fee percentage for future fee deductions.
     */
    function bv_seller_fee_promo_effective_percent(int $sellerId, ?float $defaultPercent = null, ?string $now = null): ?float
    {
        if ($sellerId <= 0) {
            return $defaultPercent;
        }

        $settings = bv_seller_fee_promo_settings_from_row(bv_seller_fee_promo_get_seller($sellerId), $sellerId, $now);
        if (($settings['effective_mode'] ?? 'default') === 'free') {
            return 0.0;
        }

        if (($settings['effective_mode'] ?? 'default') === 'custom') {
            return (float)($settings['effective_percent'] ?? 0.0);
        }

        return $defaultPercent;
    }
}

if (!function_exists('bv_seller_fee_promo_apply_fee_amount')) {
    /**
     * Calculate a future seller-balance platform fee with current admin overrides.
     */
    function bv_seller_fee_promo_apply_fee_amount(float $grossAmount, float $defaultPercent, int $sellerId, ?string $now = null): array
    {
         $grossAmount = max(0.0, $grossAmount);
        $defaultPercent = max(0.0, $defaultPercent);
        $settings = bv_seller_fee_promo_get_settings($sellerId, $now);
        $mode = (string)($settings['effective_mode'] ?? 'default');
        $percentUsed = $mode === 'default' ? $defaultPercent : (float)($settings['effective_percent'] ?? 0.0);
        $percentUsed = max(0.0, $percentUsed);

        return [
            'fee_amount' => round($grossAmount * $percentUsed / 100, 2),
            'percent_used' => $percentUsed,
            'mode' => in_array($mode, ['default', 'free', 'custom'], true) ? $mode : 'default',
            'settings' => $settings,
        ]; 
    }
}

if (!function_exists('bv_seller_fee_promo_apply_to_snapshot')) {
    /**
     * Apply active free or custom platform-fee settings to a fee snapshot.
     */
    function bv_seller_fee_promo_apply_to_snapshot(array $snapshot, int $sellerId): array
    {
        $settings = bv_seller_fee_promo_get_settings($sellerId);
        $mode = (string)($settings['effective_mode'] ?? 'default');

         if ($mode === 'default') {
            return $snapshot;
        }

        $originalPlatformFee = bv_seller_fee_promo_number($snapshot['platform_fee_total'] ?? 0.0);
       $percentUsed = (float)($settings['effective_percent'] ?? 0.0);

        if ($mode === 'free') {
            $snapshot['platform_fee_total'] = 0.00;
            $snapshot['platform_fee_refundable'] = 0.00;
            $snapshot['platform_fee_non_refundable'] = 0.00;
        } elseif ($mode === 'custom' && array_key_exists('gross_paid_amount', $snapshot)) {
            $customPlatformFee = round(bv_seller_fee_promo_number($snapshot['gross_paid_amount']) * $percentUsed / 100, 2);
            $snapshot['platform_fee_total'] = $customPlatformFee;
            $snapshot['platform_fee_non_refundable'] = $customPlatformFee;
            if (!array_key_exists('platform_fee_refundable', $snapshot)) {
                $snapshot['platform_fee_refundable'] = 0.00;
            }
        } else {
            return $snapshot;
        }

        $newPlatformFee = bv_seller_fee_promo_number($snapshot['platform_fee_total'] ?? 0.0);
        $snapshot = bv_seller_fee_promo_recalculate_seller_net($snapshot, $originalPlatformFee - $newPlatformFee);
        $snapshot = bv_seller_fee_promo_add_metadata($snapshot, $settings, $percentUsed, $mode);

        return $snapshot;
    }
}

if (!function_exists('bv_seller_fee_promo_number')) {
    function bv_seller_fee_promo_number($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

if (!function_exists('bv_seller_fee_promo_recalculate_seller_net')) {
    function bv_seller_fee_promo_recalculate_seller_net(array $snapshot, float $removedPlatformFee): array
    {
        foreach (['gross_paid_amount', 'gross_amount_snapshot', 'order_item_total', 'item_total', 'subtotal', 'gross_total'] as $grossKey) {
            if (array_key_exists($grossKey, $snapshot)) {
                $snapshot['seller_net_amount_snapshot'] = round(
                    bv_seller_fee_promo_number($snapshot[$grossKey])
                    - bv_seller_fee_promo_number($snapshot['payment_gateway_fee_total'] ?? 0.0) 
                    - bv_seller_fee_promo_number($snapshot['platform_fee_total'] ?? 0.0),
                    2
                );
                return $snapshot;
            }
        }
		
		        if (array_key_exists('seller_net_amount_snapshot', $snapshot)) {
            $snapshot['seller_net_amount_snapshot'] = round(
                bv_seller_fee_promo_number($snapshot['seller_net_amount_snapshot']) + $removedPlatformFee,
                2
            );
        }

        return $snapshot;
    }
}

if (!function_exists('bv_seller_fee_promo_add_metadata')) {
    function bv_seller_fee_promo_add_metadata(array $snapshot, array $settings, float $percentUsed, string $mode): array
    {
        $promoMetadata = [
            'seller_fee_effective_mode' => $mode,
            'seller_fee_percent_used' => $percentUsed,
            'seller_fee_promo_applied' => $mode !== 'default',
            'seller_fee_free_until' => $settings['free_until'] ?? null,
        ];

        if (isset($snapshot['fee_policy_snapshot'])) {
            if (is_array($snapshot['fee_policy_snapshot'])) {
                $snapshot['fee_policy_snapshot'] = array_merge($snapshot['fee_policy_snapshot'], $promoMetadata);
                return $snapshot;
            }

            if (is_string($snapshot['fee_policy_snapshot']) && $snapshot['fee_policy_snapshot'] !== '') {
                $decoded = json_decode($snapshot['fee_policy_snapshot'], true);
                if (is_array($decoded)) {
                    $snapshot['fee_policy_snapshot'] = json_encode(array_merge($decoded, $promoMetadata));
                    return $snapshot;
                }
            }
        }

        $snapshot['seller_fee_effective_mode'] = $mode;
        $snapshot['seller_fee_percent_used'] = $percentUsed;
        $snapshot['seller_fee_promo_applied'] = $mode !== 'default';
        $snapshot['seller_fee_free_until'] = $settings['free_until'] ?? null;
        return $snapshot;
    }
}

bv_seller_fee_promo_boot();
