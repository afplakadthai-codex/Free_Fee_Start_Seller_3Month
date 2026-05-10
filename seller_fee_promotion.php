<?php
/**
 * Bettavaro Seller Fee Promotion Layer.
 *
 * This file intentionally stays independent from the core fee, refund,
 * checkout, Stripe, order-status, and seller-balance flows. Callers may pass
 * an already-built fee snapshot through bv_seller_fee_promo_apply_to_snapshot()
 * to zero only Bettavaro platform fees for sellers whose users record has an
 * active seller_fee_free_until value.
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
            if ($params === []) {
                $result = $db->query($sql);
                if (!$result instanceof mysqli_result) {
                    return null;
                }

                $row = $result->fetch_assoc();
                $result->free();
                return is_array($row) ? $row : null;
            }

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
            'SELECT id, seller_fee_free_until, seller_fee_promo_note FROM users WHERE id = ? LIMIT 1',
            [$sellerId]
        );
    }
}

if (!function_exists('bv_seller_fee_promo_is_active')) {
    /**
     * Determine whether the seller has an unexpired free platform-fee window.
     */
    function bv_seller_fee_promo_is_active(int $sellerId, ?string $now = null): bool
    {
        $seller = bv_seller_fee_promo_get_seller($sellerId);
        if (!$seller || empty($seller['seller_fee_free_until'])) {
            return false;
        }

        $currentTime = strtotime($now ?? 'now');
        $freeUntil = strtotime((string) $seller['seller_fee_free_until']);

        return $currentTime !== false && $freeUntil !== false && $freeUntil >= $currentTime;
    }
}

if (!function_exists('bv_seller_fee_promo_apply_to_snapshot')) {
    /**
     * Zero only Bettavaro platform fees in a fee snapshot for active promotions.
     */
    function bv_seller_fee_promo_apply_to_snapshot(array $snapshot, int $sellerId): array
    {
        $seller = bv_seller_fee_promo_get_seller($sellerId);
        if (!$seller || empty($seller['seller_fee_free_until'])) {
            return $snapshot;
        }

        $freeUntilTime = strtotime((string) $seller['seller_fee_free_until']);
        if ($freeUntilTime === false || $freeUntilTime < time()) {
            return $snapshot;
        }

        $originalPlatformFee = bv_seller_fee_promo_number($snapshot['platform_fee_total'] ?? 0.0);

        $snapshot['platform_fee_total'] = 0.00;
        $snapshot['platform_fee_refundable'] = 0.00;
        $snapshot['platform_fee_non_refundable'] = 0.00;

        $snapshot = bv_seller_fee_promo_recalculate_seller_net($snapshot, $originalPlatformFee);
        $snapshot = bv_seller_fee_promo_add_metadata($snapshot, (string) $seller['seller_fee_free_until']);

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
        if (array_key_exists('seller_net_amount_snapshot', $snapshot)) {
            $snapshot['seller_net_amount_snapshot'] = round(
                bv_seller_fee_promo_number($snapshot['seller_net_amount_snapshot']) + $removedPlatformFee,
                2
            );
            return $snapshot;
        }

        foreach (['gross_amount_snapshot', 'order_item_total', 'item_total', 'subtotal', 'gross_total'] as $grossKey) {
            if (array_key_exists($grossKey, $snapshot)) {
                $snapshot['seller_net_amount_snapshot'] = round(
                    bv_seller_fee_promo_number($snapshot[$grossKey])
                    - bv_seller_fee_promo_number($snapshot['payment_gateway_fee_total'] ?? 0.0),
                    2
                );
                return $snapshot;
            }
        }

        return $snapshot;
    }
}

if (!function_exists('bv_seller_fee_promo_add_metadata')) {
    function bv_seller_fee_promo_add_metadata(array $snapshot, string $freeUntil): array
    {
        $promoMetadata = [
            'seller_fee_promo_applied' => true,
            'seller_fee_free_until' => $freeUntil,
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

        $snapshot['seller_fee_promo_applied'] = true;
        $snapshot['seller_fee_free_until'] = $freeUntil;
        return $snapshot;
    }
}

bv_seller_fee_promo_boot();
