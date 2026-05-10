<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$adminGuardCandidates = [
    __DIR__ . '/admin_guard.php',
    __DIR__ . '/auth_admin.php',
    __DIR__ . '/require_admin.php',
    __DIR__ . '/includes/admin_guard.php',
    dirname(__DIR__) . '/includes/admin_guard.php',
    dirname(__DIR__, 2) . '/includes/admin_guard.php',
    dirname(__DIR__, 2) . '/admin_guard.php',
];
foreach ($adminGuardCandidates as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
        break;
    }
}
unset($adminGuardCandidates, $guardFile);

if (!function_exists('bv_admin_fee_is_admin')) {
    function bv_admin_fee_is_admin(): bool
    {
        $flags = [
            $_SESSION['is_admin'] ?? null,
            $_SESSION['admin_logged_in'] ?? null,
            $_SESSION['admin']['is_admin'] ?? null,
            $_SESSION['user']['is_admin'] ?? null,
        ];
        foreach ($flags as $flag) {
            if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'yes') {
                return true;
            }
        }

        $roles = [
            $_SESSION['role'] ?? null,
            $_SESSION['user_role'] ?? null,
            $_SESSION['admin']['role'] ?? null,
            $_SESSION['user']['role'] ?? null,
        ];
        foreach ($roles as $role) {
            if (is_string($role) && in_array(strtolower($role), ['admin', 'super_admin', 'administrator'], true)) {
                return true;
            }
        }

        return isset($_SESSION['admin_id']) || isset($_SESSION['admin']['id']);
    }
}

if (!bv_admin_fee_is_admin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if (!function_exists('bv_admin_fee_h')) {
    function bv_admin_fee_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('bv_admin_fee_db')) {
    function bv_admin_fee_db()
    {
        foreach (['pdo', 'db', 'conn', 'mysqli'] as $name) {
            if (isset($GLOBALS[$name]) && ($GLOBALS[$name] instanceof PDO || $GLOBALS[$name] instanceof mysqli)) {
                return $GLOBALS[$name];
            }
        }

        $root = dirname(__DIR__, 2);
        $candidates = [
            $root . '/includes/db.php',
            $root . '/config/database.php',
            $root . '/config.php',
            $root . '/includes/config.php',
            $root . '/bootstrap.php',
            $root . '/seller_fee_promotion.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                foreach (['pdo', 'db', 'conn', 'mysqli'] as $name) {
                    if (isset($GLOBALS[$name]) && ($GLOBALS[$name] instanceof PDO || $GLOBALS[$name] instanceof mysqli)) {
                        return $GLOBALS[$name];
                    }
                }
                if (function_exists('bv_seller_fee_promo_db')) {
                    $promoDb = bv_seller_fee_promo_db();
                    if ($promoDb instanceof PDO || $promoDb instanceof mysqli) {
                        return $promoDb;
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('bv_admin_fee_query_all')) {
    function bv_admin_fee_query_all($db, string $sql, array $params = []): array
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            foreach (array_values($params) as $index => $value) {
                $stmt->bindValue($index + 1, $value);
            }
            if (!$stmt->execute()) {
                return [];
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            if ($params !== []) {
                $types = '';
                $values = [];
                foreach (array_values($params) as $value) {
                    $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
                    $values[] = $value;
                }
                $stmt->bind_param($types, ...$values);
            }
            if (!$stmt->execute()) {
                $stmt->close();
                return [];
            }
            $result = $stmt->get_result();
            if (!$result instanceof mysqli_result) {
                $stmt->close();
                return [];
            }
            $rows = $result->fetch_all(MYSQLI_ASSOC) ?: [];
            $result->free();
            $stmt->close();
            return $rows;
        }

        return [];
    }
}

if (!function_exists('bv_admin_fee_table_columns')) {
    function bv_admin_fee_table_columns($db, string $table): array
    {
        $rows = bv_admin_fee_query_all(
            $db,
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row['COLUMN_NAME']] = true;
        }
        return $columns;
    }
}

if (!function_exists('bv_admin_fee_datetime_local')) {
    function bv_admin_fee_datetime_local($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $time = strtotime((string)$value);
        return $time === false ? '' : date('Y-m-d\TH:i', $time);
    }
}

if (!function_exists('bv_admin_fee_sql_migration')) {
    function bv_admin_fee_sql_migration(array $missingColumns): string
    {
        $definitions = [
            'seller_fee_free_until' => 'ADD COLUMN seller_fee_free_until DATETIME NULL',
            'seller_fee_promo_note' => 'ADD COLUMN seller_fee_promo_note VARCHAR(255) NULL',
            'seller_fee_promo_starts_at' => 'ADD COLUMN seller_fee_promo_starts_at DATETIME NULL',
            'seller_fee_percent_override' => 'ADD COLUMN seller_fee_percent_override DECIMAL(6,3) NULL',
            'seller_fee_override_note' => 'ADD COLUMN seller_fee_override_note VARCHAR(255) NULL',
        ];
        $parts = [];
        foreach ($missingColumns as $column) {
            if (isset($definitions[$column])) {
                $parts[] = $definitions[$column];
            }
        }
        return $parts === [] ? '' : 'ALTER TABLE users ' . implode(",\n  ", $parts) . ';';
    }
}

if (empty($_SESSION['seller_fee_control_csrf'])) {
    $_SESSION['seller_fee_control_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['seller_fee_control_csrf'];
$flash = $_SESSION['seller_fee_control_flash'] ?? null;
unset($_SESSION['seller_fee_control_flash']);

$db = bv_admin_fee_db();
$columns = $db ? bv_admin_fee_table_columns($db, 'users') : [];
$managedColumns = [
    'seller_fee_free_until',
    'seller_fee_promo_note',
    'seller_fee_promo_starts_at',
    'seller_fee_percent_override',
    'seller_fee_override_note',
];
$missingColumns = [];
foreach ($managedColumns as $column) {
    if (!isset($columns[$column])) {
        $missingColumns[] = $column;
    }
}

$nameExpr = "CAST(id AS CHAR)";
if (isset($columns['name'])) {
    $nameExpr = 'name';
} elseif (isset($columns['full_name'])) {
    $nameExpr = 'full_name';
} elseif (isset($columns['display_name'])) {
    $nameExpr = 'display_name';
} elseif (isset($columns['username'])) {
    $nameExpr = 'username';
} elseif (isset($columns['first_name']) && isset($columns['last_name'])) {
    $nameExpr = "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";
} elseif (isset($columns['first_name'])) {
    $nameExpr = 'first_name';
}

$emailExpr = isset($columns['email']) ? 'email' : "''";
$statusExpr = isset($columns['account_status']) ? 'account_status' : "''";
$selects = [
    'id',
    $nameExpr . ' AS seller_name',
    $emailExpr . ' AS email',
    $statusExpr . ' AS account_status',
];
foreach ($managedColumns as $column) {
    $selects[] = isset($columns[$column]) ? $column : 'NULL AS ' . $column;
}

$where = [];
$params = [];
if (isset($columns['role'])) {
    $where[] = 'LOWER(role) IN (?, ?, ?)';
    array_push($params, 'seller', 'vendor', 'merchant');
} elseif (isset($columns['user_type'])) {
    $where[] = 'LOWER(user_type) IN (?, ?, ?)';
    array_push($params, 'seller', 'vendor', 'merchant');
} elseif (isset($columns['is_seller'])) {
    $where[] = 'is_seller = ?';
    $params[] = 1;
}

$filter = (string)($_GET['filter'] ?? 'all');
$now = date('Y-m-d H:i:s');
if ($filter === 'active' && isset($columns['seller_fee_free_until'])) {
    $where[] = 'seller_fee_free_until IS NOT NULL AND seller_fee_free_until >= ?';
    $params[] = $now;
} elseif ($filter === 'expired' && isset($columns['seller_fee_free_until'])) {
    $where[] = 'seller_fee_free_until IS NOT NULL AND seller_fee_free_until < ?';
    $params[] = $now;
} elseif ($filter === 'custom' && isset($columns['seller_fee_percent_override'])) {
    $where[] = 'seller_fee_percent_override IS NOT NULL';
} elseif ($filter === 'none') {
    if (isset($columns['seller_fee_free_until'])) {
        $where[] = 'seller_fee_free_until IS NULL';
    }
    if (isset($columns['seller_fee_percent_override'])) {
        $where[] = 'seller_fee_percent_override IS NULL';
    }
}

$sql = 'SELECT ' . implode(', ', $selects) . ' FROM users';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY id DESC LIMIT 500';
$sellers = $db && isset($columns['id']) ? bv_admin_fee_query_all($db, $sql, $params) : [];

$totalSellers = count($sellers);
$activeFree = 0;
$customFee = 0;
$expiredPromos = 0;
foreach ($sellers as $seller) {
    $until = $seller['seller_fee_free_until'] ?? null;
    if ($until !== null && $until !== '') {
        if (strtotime((string)$until) >= time()) {
            $activeFree++;
        } else {
            $expiredPromos++;
        }
    }
    if (($seller['seller_fee_percent_override'] ?? null) !== null && $seller['seller_fee_percent_override'] !== '') {
        $customFee++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bettavaro Seller Fee Command Center</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f6f7fb;color:#172033;margin:0;padding:24px}.wrap{max-width:1440px;margin:0 auto}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}.card,.panel{background:#fff;border:1px solid #dfe3ec;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.card{padding:18px}.card strong{display:block;font-size:28px;margin-top:6px}.panel{padding:16px;overflow:auto}.alert{padding:12px 14px;border-radius:10px;margin:14px 0}.alert-success{background:#e9f8ef;border:1px solid #9ddbb6}.alert-error{background:#ffecec;border:1px solid #f3a0a0}.alert-warning{background:#fff8e5;border:1px solid #e8c968}pre{white-space:pre-wrap;background:#202637;color:#fff;padding:12px;border-radius:8px}table{width:100%;border-collapse:collapse;min-width:1280px}th,td{border-bottom:1px solid #e6e9f0;padding:10px;text-align:left;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#5d6678;background:#fbfcff}.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700}.active{background:#def7e7;color:#136b35}.expired{background:#ffe3e3;color:#8b1e1e}.unset{background:#eef1f6;color:#475064}.custom{background:#e7edff;color:#23439b}.filters a{display:inline-block;margin:0 8px 8px 0;padding:8px 12px;background:#fff;border:1px solid #ccd3df;border-radius:999px;color:#172033;text-decoration:none}.filters a.current{background:#172033;color:#fff}input{box-sizing:border-box;width:170px;max-width:100%;padding:7px;border:1px solid #cbd2df;border-radius:8px}button{padding:8px 12px;border:0;border-radius:8px;background:#172033;color:#fff;cursor:pointer}.muted{color:#687386;font-size:12px}.notes input{width:220px}</style>
</head>
<body>
<div class="wrap">
    <h1>Bettavaro Seller Balance Fee Command Center</h1>
    <p class="muted">Manual seller fee promotions and future-order platform fee controls.</p>

    <?php if ($flash && is_array($flash)): ?>
        <div class="alert alert-<?php echo bv_admin_fee_h($flash['type'] ?? 'success'); ?>"><?php echo bv_admin_fee_h($flash['message'] ?? ''); ?></div>
    <?php endif; ?>

    <?php if (!$db): ?>
        <div class="alert alert-error">Database connection was not found. Load the platform database bootstrap before opening this page.</div>
    <?php endif; ?>

    <?php if ($missingColumns !== []): ?>
        <div class="alert alert-warning">
            <strong>Seller fee control columns are missing.</strong> Run this SQL migration to enable all controls:
            <pre><?php echo bv_admin_fee_h(bv_admin_fee_sql_migration($missingColumns)); ?></pre>
        </div>
    <?php endif; ?>

    <div class="cards">
        <div class="card">Total Sellers<strong><?php echo bv_admin_fee_h($totalSellers); ?></strong></div>
        <div class="card">Active Fee-Free Sellers<strong><?php echo bv_admin_fee_h($activeFree); ?></strong></div>
        <div class="card">Custom Fee Sellers<strong><?php echo bv_admin_fee_h($customFee); ?></strong></div>
        <div class="card">Expired Promotions<strong><?php echo bv_admin_fee_h($expiredPromos); ?></strong></div>
    </div>

    <div class="filters">
        <?php foreach (['all' => 'All Sellers', 'active' => 'Active Promo', 'expired' => 'Expired Promo', 'custom' => 'Custom Fee', 'none' => 'No Promo'] as $key => $label): ?>
            <a class="<?php echo $filter === $key ? 'current' : ''; ?>" href="?filter=<?php echo bv_admin_fee_h($key); ?>"><?php echo bv_admin_fee_h($label); ?></a>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <table>
            <thead><tr><th>ID</th><th>Seller</th><th>Email</th><th>Status</th><th>Promo Status</th><th>Promo Start</th><th>Promo End</th><th>Fee Override</th><th>Effective Fee</th><th>Notes</th><th>Edit</th></tr></thead>
            <tbody>
            <?php foreach ($sellers as $seller): ?>
                <?php
                $until = (string)($seller['seller_fee_free_until'] ?? '');
                $isActive = $until !== '' && strtotime($until) >= time();
                $isExpired = $until !== '' && !$isActive;
                $promoStatus = $isActive ? 'Active' : ($isExpired ? 'Expired' : 'Not Set');
                $promoClass = $isActive ? 'active' : ($isExpired ? 'expired' : 'unset');
                $override = $seller['seller_fee_percent_override'] ?? null;
                $hasOverride = $override !== null && $override !== '';
                $effectiveLabel = $isActive ? 'Free Fee' : ($hasOverride ? 'Custom Fee ' . rtrim(rtrim(number_format((float)$override, 3), '0'), '.') . '%' : 'Default Marketplace Fee');
                ?>
                <tr>
                    <td><?php echo bv_admin_fee_h($seller['id']); ?></td>
                    <td><?php echo bv_admin_fee_h($seller['seller_name']); ?></td>
                    <td><?php echo bv_admin_fee_h($seller['email']); ?></td>
                    <td><?php echo bv_admin_fee_h($seller['account_status']); ?></td>
                    <td><span class="badge <?php echo bv_admin_fee_h($promoClass); ?>"><?php echo bv_admin_fee_h($promoStatus); ?></span></td>
                    <td><?php echo bv_admin_fee_h($seller['seller_fee_promo_starts_at']); ?></td>
                    <td><?php echo bv_admin_fee_h($seller['seller_fee_free_until']); ?></td>
                    <td><?php echo $hasOverride ? '<span class="badge custom">' . bv_admin_fee_h(rtrim(rtrim(number_format((float)$override, 3), '0'), '.') . '%') . '</span>' : ''; ?></td>
                    <td><?php echo bv_admin_fee_h($effectiveLabel); ?></td>
                    <td><?php echo bv_admin_fee_h(trim((string)($seller['seller_fee_promo_note'] ?? '') . ' ' . (string)($seller['seller_fee_override_note'] ?? ''))); ?></td>
                    <td>
                        <form method="post" action="seller_fee_control_update.php">
                            <input type="hidden" name="csrf_token" value="<?php echo bv_admin_fee_h($csrfToken); ?>">
                            <input type="hidden" name="seller_id" value="<?php echo bv_admin_fee_h($seller['id']); ?>">
                            <div><label>Start<br><input type="datetime-local" name="seller_fee_promo_starts_at" value="<?php echo bv_admin_fee_h(bv_admin_fee_datetime_local($seller['seller_fee_promo_starts_at'])); ?>"></label></div>
                            <div><label>End<br><input type="datetime-local" name="seller_fee_free_until" value="<?php echo bv_admin_fee_h(bv_admin_fee_datetime_local($seller['seller_fee_free_until'])); ?>"></label></div>
                            <div><label>Override %<br><input type="number" step="0.001" min="0" max="100" name="seller_fee_percent_override" value="<?php echo bv_admin_fee_h($seller['seller_fee_percent_override']); ?>"></label></div>
                            <div class="notes"><label>Promo Note<br><input maxlength="255" name="seller_fee_promo_note" value="<?php echo bv_admin_fee_h($seller['seller_fee_promo_note']); ?>"></label></div>
                            <div class="notes"><label>Override Note<br><input maxlength="255" name="seller_fee_override_note" value="<?php echo bv_admin_fee_h($seller['seller_fee_override_note']); ?>"></label></div>
                            <p class="muted">Applies to future paid orders only.</p>
                            <button type="submit">Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($sellers === []): ?>
                <tr><td colspan="11">No sellers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
