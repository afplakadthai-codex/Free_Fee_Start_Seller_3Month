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

if (!function_exists('bv_admin_fee_normalize_role')) {
    function bv_admin_fee_normalize_role($role): string
    {
        return is_string($role) ? str_replace('-', '_', strtolower(trim($role))) : '';
    }
}

if (!function_exists('bv_admin_fee_admin_roles')) {
    function bv_admin_fee_admin_roles(): array
    {
        return ['admin', 'super_admin', 'superadmin', 'administrator', 'owner'];
    }
}

if (!function_exists('bv_admin_fee_role_is_admin')) {
    function bv_admin_fee_role_is_admin($role): bool
    {
        return in_array(bv_admin_fee_normalize_role($role), bv_admin_fee_admin_roles(), true);
    }
}

if (!function_exists('bv_admin_fee_is_truthy_flag')) {
    function bv_admin_fee_is_truthy_flag($flag): bool
    {
        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'yes' || $flag === 'true';
    }
}

if (!function_exists('bv_admin_fee_is_admin')) {
    function bv_admin_fee_is_admin(): bool
    {
        foreach (['is_admin', 'admin_is_logged_in', 'bv_is_admin', 'btv_is_admin', 'current_user_is_admin'] as $adminCheck) {
            if (function_exists($adminCheck)) {
                $reflection = new ReflectionFunction($adminCheck);
                if ($reflection->getNumberOfRequiredParameters() === 0 && $adminCheck() === true) {
                    return true;
                }
            }
        }

        $flags = [
            $_SESSION['is_admin'] ?? null,
            $_SESSION['admin_logged_in'] ?? null,
            $_SESSION['admin']['is_admin'] ?? null,
            $_SESSION['user']['is_admin'] ?? null,
            $_SESSION['auth_user']['is_admin'] ?? null,
        ];
        foreach ($flags as $flag) {
            if (bv_admin_fee_is_truthy_flag($flag)) {
                return true;
            }
        }

        $roles = [
            $_SESSION['role'] ?? null,
            $_SESSION['user_role'] ?? null,
            $_SESSION['admin_role'] ?? null,
            $_SESSION['account_role'] ?? null,
            $_SESSION['admin']['role'] ?? null,
            $_SESSION['user']['role'] ?? null,
            $_SESSION['auth_user']['role'] ?? null,
            $_SESSION['member']['role'] ?? null,
        ];
        foreach ($roles as $role) {
            if (bv_admin_fee_role_is_admin($role)) {
                return true;
            }
        }

        if (isset($_SESSION['admin_id']) || isset($_SESSION['admin']['id'])) {
            return true;
        }

        if (isset($_SESSION['user']['id']) && bv_admin_fee_role_is_admin($_SESSION['user']['role'] ?? null)) {
            return true;
        }

        return isset($_SESSION['auth_user']['id']) && bv_admin_fee_role_is_admin($_SESSION['auth_user']['role'] ?? null);
    }
}

if (!function_exists('bv_admin_fee_is_sensitive_key')) {
    function bv_admin_fee_is_sensitive_key($key): bool
    {
        return preg_match('/password|token|csrf|secret|cookie|session/i', (string)$key) === 1;
    }
}

if (!function_exists('bv_admin_fee_debug_session_keys')) {
    function bv_admin_fee_debug_session_keys(array $session, string $prefix = ''): array
    {
        $keys = [];
        foreach ($session as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (bv_admin_fee_is_sensitive_key($path)) {
                continue;
            }
            $keys[] = $path;
            if (is_array($value)) {
                $keys = array_merge($keys, bv_admin_fee_debug_session_keys($value, $path));
            }
        }
        return $keys;
    }
}

if (!function_exists('bv_admin_fee_debug_role_candidates')) {
    function bv_admin_fee_debug_role_candidates(): array
    {
        $roles = [
            'role' => $_SESSION['role'] ?? null,
            'user_role' => $_SESSION['user_role'] ?? null,
            'admin_role' => $_SESSION['admin_role'] ?? null,
            'account_role' => $_SESSION['account_role'] ?? null,
            'admin.role' => $_SESSION['admin']['role'] ?? null,
            'user.role' => $_SESSION['user']['role'] ?? null,
            'auth_user.role' => $_SESSION['auth_user']['role'] ?? null,
            'member.role' => $_SESSION['member']['role'] ?? null,
        ];
        $debug = [];
        foreach ($roles as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $debug[$key] = [
                    'raw' => $value === null ? null : (string)$value,
                    'normalized' => bv_admin_fee_normalize_role($value),
                ];
            }
        }
        return $debug;
    }
}

if (!bv_admin_fee_is_admin()) {
    http_response_code(403);
    if (($_GET['debug_auth'] ?? '') === '1') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden\n\n";
        echo "Session keys:\n";
        foreach (bv_admin_fee_debug_session_keys($_SESSION) as $key) {
            echo '- ' . $key . "\n";
        }
        echo "\nRole candidates:\n";
        foreach (bv_admin_fee_debug_role_candidates() as $key => $role) {
            echo '- ' . $key . ': raw=' . var_export($role['raw'], true) . ', normalized=' . var_export($role['normalized'], true) . "\n";
        }
        exit;
    }
    echo 'Forbidden';
    exit;
}

if (!function_exists('bv_admin_fee_h')) {
    function bv_admin_fee_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$root = dirname(__DIR__);
$bvAdminFeeLoadedBootstrapFiles = [];
$bvAdminFeeBootstrapCandidates = [
    $root . '/config/db.php',
    $root . '/includes/db.php',
    $root . '/includes/config.php',
    $root . '/includes/bootstrap.php',
    $root . '/includes/init.php',
];
foreach ($bvAdminFeeBootstrapCandidates as $bootstrapFile) {
    if (is_file($bootstrapFile)) {
        require_once $bootstrapFile;
        $bvAdminFeeLoadedBootstrapFiles[] = $bootstrapFile;
    }
}
unset($bvAdminFeeBootstrapCandidates, $bootstrapFile);

if (!function_exists('bv_admin_fee_db_variable_names')) {
    function bv_admin_fee_db_variable_names(): array
    {
        $names = [];
        foreach (['pdo', 'db', 'database', 'connPdo', 'conn', 'mysqli', 'dbConn'] as $name) {
            if (isset($GLOBALS[$name]) && ($GLOBALS[$name] instanceof PDO || $GLOBALS[$name] instanceof mysqli)) {
                $names[] = '$' . $name;
            }
        }
        return $names;
    }
}

if (!function_exists('bv_admin_fee_db_type')) {
    function bv_admin_fee_db_type($db): string
    {
        if ($db instanceof PDO) {
            return 'PDO';
        }
        if ($db instanceof mysqli) {
            return 'MySQLi';
        }
        return 'none';
    }
}

if (!function_exists('bv_admin_fee_db')) {
    function bv_admin_fee_db()
    {
        foreach (['pdo', 'db', 'database', 'connPdo'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                return $GLOBALS[$name];
            }
        }

        foreach (['conn', 'mysqli', 'dbConn'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof mysqli) {
                return $GLOBALS[$name];
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

if (!function_exists('bv_admin_fee_table_exists')) {
    function bv_admin_fee_table_exists($db, string $table): bool
    {
        return bv_admin_fee_query_all(
            $db,
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
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

if (!function_exists('bv_admin_fee_setting_write_path_candidates')) {
    function bv_admin_fee_setting_write_path_candidates(): array
    {
        $publicHtml = dirname(__DIR__);
        return [
            $publicHtml . '/private_html/seller_fee_override_engine.json',
            $publicHtml . '/storage/seller_fee_override_engine.json',
        ];
    }
}

if (!function_exists('bv_admin_fee_setting_read_engine_enabled')) {
    function bv_admin_fee_setting_read_engine_enabled($db): array
    {
        if (($db instanceof PDO || $db instanceof mysqli) && bv_admin_fee_table_exists($db, 'site_settings')) {
            $rows = bv_admin_fee_query_all(
                $db,
                'SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1',
                ['seller_fee_override_engine_enabled']
            );
            if ($rows !== []) {
                return ['enabled' => (string)($rows[0]['setting_value'] ?? '') === '1', 'source' => 'site_settings'];
            }
            return ['enabled' => false, 'source' => 'site_settings'];
        }

        foreach (bv_admin_fee_setting_write_path_candidates() as $path) {
            if (!is_file($path)) {
                continue;
            }
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded) && array_key_exists('enabled', $decoded)) {
                return ['enabled' => (bool)$decoded['enabled'], 'source' => $path];
            }
        }

        return ['enabled' => false, 'source' => 'file fallback pending'];
    }
}

if (!function_exists('bv_admin_fee_human_fee_label')) {
    function bv_admin_fee_human_fee_label($value, bool $activeFree = false): string
    {
        if ($activeFree) {
            return 'Free Fee';
        }
        if ($value === null || $value === '') {
            return 'Default';
        }
        $formatted = rtrim(rtrim(number_format((float)$value, 3), '0'), '.');
        return ((float)$value === 0.0) ? 'Free Fee' : 'Custom ' . $formatted . '%';
    }
}

if (!function_exists('bv_admin_fee_badge')) {
    function bv_admin_fee_badge(string $label, string $type = 'default'): string
    {
        return '<span class="badge badge-' . bv_admin_fee_h($type) . '">' . bv_admin_fee_h($label) . '</span>';
    }
}

if (!function_exists('bv_admin_fee_dashboard_url')) {
    function bv_admin_fee_dashboard_url(): string
    {
        return '/admin/index.php';
    }
}

if (empty($_SESSION['seller_fee_control_csrf'])) {
    $_SESSION['seller_fee_control_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['seller_fee_control_csrf'];
$flash = $_SESSION['seller_fee_control_flash'] ?? null;
unset($_SESSION['seller_fee_control_flash']);

$db = bv_admin_fee_db();
$debugDbEnabled = (string)($_GET['debug_db'] ?? '') === '1';
$debugDbType = bv_admin_fee_db_type($db);
$debugDbVariableNames = bv_admin_fee_db_variable_names();
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

$nameExpr = 'CAST(id AS CHAR)';
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

$farmStoreExpr = "''";
foreach (['farm_name', 'store_name', 'business_name', 'company_name', 'shop_name'] as $farmStoreColumn) {
    if (isset($columns[$farmStoreColumn])) {
        $farmStoreExpr = $farmStoreColumn;
        break;
    }
}

$emailExpr = isset($columns['email']) ? 'email' : "''";
$statusExpr = isset($columns['account_status']) ? 'account_status' : (isset($columns['status']) ? 'status' : "''");
$selects = [
    'id',
    $nameExpr . ' AS seller_name',
    $farmStoreExpr . ' AS farm_store_name',
    $emailExpr . ' AS email',
    $statusExpr . ' AS account_status',
];
foreach ($managedColumns as $column) {
    $selects[] = isset($columns[$column]) ? $column : 'NULL AS ' . $column;
}

$baseWhere = [];
$baseParams = [];
if (isset($columns['role'])) {
    $baseWhere[] = 'LOWER(role) IN (?, ?, ?)';
    array_push($baseParams, 'seller', 'breeder', 'vendor');
} elseif (isset($columns['user_type'])) {
    $baseWhere[] = 'LOWER(user_type) IN (?, ?, ?)';
    array_push($baseParams, 'seller', 'breeder', 'vendor');
} elseif (isset($columns['is_seller'])) {
    $baseWhere[] = 'is_seller = ?';
    $baseParams[] = 1;
}

$allowedFilters = ['all', 'active', 'custom', 'default', 'expired', 'none'];
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}
$now = date('Y-m-d H:i:s');
$where = $baseWhere;
$params = $baseParams;
if ($filter === 'active' && isset($columns['seller_fee_free_until'])) {
    $where[] = 'seller_fee_free_until IS NOT NULL AND seller_fee_free_until >= ?';
    $params[] = $now;
} elseif ($filter === 'expired' && isset($columns['seller_fee_free_until'])) {
    $where[] = 'seller_fee_free_until IS NOT NULL AND seller_fee_free_until < ?';
    $params[] = $now;
} elseif ($filter === 'custom' && isset($columns['seller_fee_percent_override'])) {
    $where[] = 'seller_fee_percent_override IS NOT NULL';
} elseif ($filter === 'default') {
    if (isset($columns['seller_fee_percent_override'])) {
        $where[] = 'seller_fee_percent_override IS NULL';
    }
    if (isset($columns['seller_fee_free_until'])) {
        $where[] = '(seller_fee_free_until IS NULL OR seller_fee_free_until < ?)';
        $params[] = $now;
    }
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

$summarySql = 'SELECT ' . implode(', ', $selects) . ' FROM users';
if ($baseWhere !== []) {
    $summarySql .= ' WHERE ' . implode(' AND ', $baseWhere);
}
$summarySql .= ' ORDER BY id DESC LIMIT 500';
$summarySellers = $db && isset($columns['id']) ? bv_admin_fee_query_all($db, $summarySql, $baseParams) : [];

$totalSellers = count($summarySellers);
$activeFree = 0;
$customFee = 0;
$expiredPromos = 0;
$defaultFee = 0;
foreach ($summarySellers as $seller) {
    $until = $seller['seller_fee_free_until'] ?? null;
    $isActive = $until !== null && $until !== '' && strtotime((string)$until) >= time();
    $isExpired = $until !== null && $until !== '' && !$isActive;
    $hasOverride = ($seller['seller_fee_percent_override'] ?? null) !== null && $seller['seller_fee_percent_override'] !== '';
    if ($isActive) {
        $activeFree++;
    }
    if ($isExpired) {
        $expiredPromos++;
    }
    if ($hasOverride) {
        $customFee++;
    }
    if (!$isActive && !$hasOverride) {
        $defaultFee++;
    }
}
$engineSetting = bv_admin_fee_setting_read_engine_enabled($db);
$engineEnabled = (bool)$engineSetting['enabled'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seller Balance Fee Command Center</title>
    <style>
        :root{--bg:#f4f7fb;--card:#fff;--ink:#172033;--muted:#64748b;--line:#dbe3ef;--navy:#10233f;--green:#15803d;--green-bg:#dcfce7;--amber:#b45309;--amber-bg:#fef3c7;--red:#b91c1c;--red-bg:#fee2e2;--blue:#1d4ed8;--blue-bg:#dbeafe;--shadow:0 14px 35px rgba(15,35,63,.08)}
        *{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--ink);margin:0;padding:28px 0}.wrap{width:96%;max-width:1600px;margin:0 auto}.hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:20px}.eyebrow{color:var(--muted);font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin:0 0 8px}.hero h1{font-size:34px;line-height:1.1;margin:0 0 10px}.subtitle{color:var(--muted);font-size:16px;margin:0}.btn,.button-link,button{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:10px;background:var(--navy);color:#fff;cursor:pointer;font-weight:800;padding:10px 14px;text-decoration:none;white-space:nowrap}.btn-secondary{background:#eef2f7;color:var(--navy);border:1px solid var(--line)}.btn-danger{background:var(--red)}.grid{display:grid;gap:16px}.top-grid{grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);margin-bottom:16px}.card,.panel,.notice{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}.card{padding:20px}.notice{padding:20px}.notice h2,.panel h2{margin:0 0 10px;font-size:18px}.notice ul{margin:10px 0 0;padding-left:22px;color:#334155;line-height:1.55}.engine{padding:20px}.engine-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}.engine-title{font-size:16px;font-weight:900}.engine-actions{display:flex;gap:10px;flex-wrap:wrap}.cards{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px;margin:18px 0}.metric{padding:18px}.metric span{display:block;color:var(--muted);font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.metric strong{display:block;font-size:30px;margin-top:8px}.panel{padding:18px;margin-top:16px}.alert{padding:13px 15px;border-radius:14px;margin:14px 0;font-weight:700}.alert-success{background:var(--green-bg);border:1px solid #86efac;color:#14532d}.alert-error{background:var(--red-bg);border:1px solid #fca5a5;color:#7f1d1d}.alert-warning{background:var(--amber-bg);border:1px solid #fcd34d;color:#78350f}pre{white-space:pre-wrap;background:#202637;color:#fff;padding:12px;border-radius:10px;overflow:auto}.filters{display:flex;flex-wrap:wrap;gap:10px;margin:8px 0 0}.filters a{display:inline-flex;padding:9px 13px;background:#fff;border:1px solid var(--line);border-radius:999px;color:var(--ink);font-weight:800;text-decoration:none}.filters a.current{background:var(--navy);color:#fff;border-color:var(--navy);box-shadow:0 8px 18px rgba(16,35,63,.18)}.table-scroll{overflow-x:auto;border-radius:14px;border:1px solid var(--line)}table{width:100%;border-collapse:separate;border-spacing:0;min-width:1450px;background:#fff}th,td{border-bottom:1px solid #e8edf5;padding:13px 12px;text-align:left;vertical-align:top}tr:last-child td{border-bottom:0}th{font-size:12px;text-transform:uppercase;color:#536173;background:#f8fafc;letter-spacing:.04em;position:sticky;top:0;z-index:1}.seller-name{font-weight:900}.seller-meta,.muted{color:var(--muted);font-size:12px}.seller-store{margin-top:5px;color:#334155}.badge{display:inline-flex;align-items:center;border-radius:999px;font-size:12px;font-weight:900;padding:5px 9px;margin:2px 4px 2px 0}.badge-active,.badge-free{background:var(--green-bg);color:var(--green)}.badge-expired,.badge-disabled{background:var(--red-bg);color:var(--red)}.badge-unset,.badge-default{background:#eef2f7;color:#475569}.badge-custom,.badge-warning{background:var(--amber-bg);color:var(--amber)}.badge-enabled{background:var(--green-bg);color:var(--green)}.badge-info{background:var(--blue-bg);color:var(--blue)}.edit-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:12px;min-width:330px}.edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.edit-card label{display:block;color:#334155;font-size:12px;font-weight:900}.edit-card input{box-sizing:border-box;width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;margin-top:4px}.edit-card .wide{grid-column:1 / -1}.helper{color:#64748b;font-size:12px;line-height:1.45;margin:10px 0}.notes-cell{max-width:260px;line-height:1.45}.status-pill{display:inline-flex;padding:5px 9px;background:#f1f5f9;border-radius:999px;font-weight:800;color:#475569;font-size:12px}@media (max-width:1100px){.hero,.top-grid{grid-template-columns:1fr;display:grid}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:640px){body{padding:18px 0}.hero h1{font-size:26px}.cards{grid-template-columns:1fr}.engine-actions{display:grid}.btn,.button-link,button{width:100%}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div>
            <p class="eyebrow">Admin Controls</p>
            <h1>Seller Balance Fee Command Center</h1>
            <p class="subtitle">Manage seller fee promotions, custom platform fee rates, and future-order fee controls.</p>
        </div>
        <a class="button-link" href="<?php echo bv_admin_fee_h(bv_admin_fee_dashboard_url()); ?>">← Back to Dashboard</a>
    </div>

    <?php if ($flash && is_array($flash)): ?>
        <div class="alert alert-<?php echo bv_admin_fee_h($flash['type'] ?? 'success'); ?>"><?php echo bv_admin_fee_h($flash['message'] ?? ''); ?></div>
    <?php endif; ?>

    <?php if ($debugDbEnabled): ?>
        <div class="alert alert-warning">
            <strong>Database debug info</strong>
            <pre><?php echo bv_admin_fee_h(implode("\n", [
                'Detected root path: ' . $root,
                'Loaded bootstrap files: ' . ($bvAdminFeeLoadedBootstrapFiles === [] ? 'none' : implode(', ', $bvAdminFeeLoadedBootstrapFiles)),
                'Detected DB type: ' . $debugDbType,
                'Available DB variable names: ' . ($debugDbVariableNames === [] ? 'none' : implode(', ', $debugDbVariableNames)),
            ])); ?></pre>
        </div>
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

    <div class="top-grid grid">
        <div class="notice">
            <h2>Safety Notice</h2>
            <ul>
                <li>Changes apply only to future paid orders.</li>
                <li>Old balance entries are not changed.</li>
                <li>Stripe/payment gateway fees are not affected.</li>
                <li>Platform fee override controls Bettavaro marketplace fee only.</li>
            </ul>
            <div class="alert alert-warning">Engine switch display is ready. Runtime enforcement requires seller_balance.php integration.</div>
        </div>
        <div class="engine card">
            <div class="engine-head">
                <div>
                    <div class="engine-title">Seller Fee Override Engine:</div>
                    <div class="muted">Setting source: <?php echo bv_admin_fee_h($engineSetting['source'] ?? 'unknown'); ?></div>
                </div>
                <?php echo bv_admin_fee_badge($engineEnabled ? 'Enabled' : 'Disabled', $engineEnabled ? 'enabled' : 'disabled'); ?>
            </div>
            <div class="engine-actions">
                <form method="post" action="seller_fee_control_update.php">
                    <input type="hidden" name="csrf_token" value="<?php echo bv_admin_fee_h($csrfToken); ?>">
                    <input type="hidden" name="action" value="toggle_fee_override_engine">
                    <input type="hidden" name="enabled" value="1">
                    <button type="submit">Enable Seller Fee Override</button>
                </form>
                <form method="post" action="seller_fee_control_update.php">
                    <input type="hidden" name="csrf_token" value="<?php echo bv_admin_fee_h($csrfToken); ?>">
                    <input type="hidden" name="action" value="toggle_fee_override_engine">
                    <input type="hidden" name="enabled" value="0">
                    <button class="btn-danger" type="submit">Disable Seller Fee Override</button>
                </form>
            </div>
        </div>
    </div>

    <div class="cards">
        <div class="card metric"><span>Total Sellers</span><strong><?php echo bv_admin_fee_h($totalSellers); ?></strong></div>
        <div class="card metric"><span>Fee-Free Active</span><strong><?php echo bv_admin_fee_h($activeFree); ?></strong></div>
        <div class="card metric"><span>Custom Fee Active</span><strong><?php echo bv_admin_fee_h($customFee); ?></strong></div>
        <div class="card metric"><span>Default Fee Sellers</span><strong><?php echo bv_admin_fee_h($defaultFee); ?></strong></div>
        <div class="card metric"><span>Expired Promo</span><strong><?php echo bv_admin_fee_h($expiredPromos); ?></strong></div>
        <div class="card metric"><span>Engine Status</span><strong><?php echo bv_admin_fee_h($engineEnabled ? 'Enabled' : 'Disabled'); ?></strong></div>
    </div>

    <div class="panel">
        <h2>Seller Filters</h2>
        <div class="filters">
            <?php foreach (['all' => 'All Sellers', 'active' => 'Active Free Promo', 'custom' => 'Custom Fee', 'default' => 'Default Fee', 'expired' => 'Expired Promo', 'none' => 'No Promo'] as $key => $label): ?>
                <a class="<?php echo $filter === $key ? 'current' : ''; ?>" href="?filter=<?php echo bv_admin_fee_h($key); ?>"><?php echo bv_admin_fee_h($label); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel">
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Seller</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Promo Status</th>
                        <th>Promo Window</th>
                        <th>Fee Override</th>
                        <th>Effective Fee</th>
                        <th>Notes</th>
                        <th>Edit Controls</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sellers as $seller): ?>
                    <?php
                    $until = (string)($seller['seller_fee_free_until'] ?? '');
                    $startsAt = (string)($seller['seller_fee_promo_starts_at'] ?? '');
                    $startOk = $startsAt === '' || strtotime($startsAt) <= time();
                    $isActive = $until !== '' && strtotime($until) >= time() && $startOk;
                    $isExpired = $until !== '' && !$isActive && strtotime($until) < time();
                    $promoStatus = $isActive ? 'Active' : ($isExpired ? 'Expired' : 'Not Set');
                    $promoClass = $isActive ? 'active' : ($isExpired ? 'expired' : 'unset');
                    $override = $seller['seller_fee_percent_override'] ?? null;
                    $hasOverride = $override !== null && $override !== '';
                    $overrideLabel = $hasOverride ? bv_admin_fee_human_fee_label($override) : 'Default';
                    $overrideClass = !$hasOverride ? 'default' : (((float)$override === 0.0) ? 'free' : 'custom');
                    $effectiveLabel = bv_admin_fee_human_fee_label($hasOverride ? $override : null, $isActive);
                    $effectiveClass = $isActive || ($hasOverride && (float)$override === 0.0) ? 'free' : ($hasOverride ? 'custom' : 'default');
                    $farmStore = trim((string)($seller['farm_store_name'] ?? ''));
                    $promoNote = trim((string)($seller['seller_fee_promo_note'] ?? ''));
                    $overrideNote = trim((string)($seller['seller_fee_override_note'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <div class="seller-name"><?php echo bv_admin_fee_h($seller['seller_name']); ?></div>
                            <div class="seller-meta">ID #<?php echo bv_admin_fee_h($seller['id']); ?></div>
                            <?php if ($farmStore !== ''): ?><div class="seller-store"><?php echo bv_admin_fee_h($farmStore); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo bv_admin_fee_h($seller['email']); ?></td>
                        <td><span class="status-pill"><?php echo bv_admin_fee_h($seller['account_status'] ?: 'Unknown'); ?></span></td>
                        <td><?php echo bv_admin_fee_badge($promoStatus, $promoClass); ?></td>
                        <td>
                            <div><strong>Start:</strong> <?php echo bv_admin_fee_h($startsAt !== '' ? $startsAt : 'Not Set'); ?></div>
                            <div><strong>End:</strong> <?php echo bv_admin_fee_h($until !== '' ? $until : 'Not Set'); ?></div>
                        </td>
                        <td><?php echo bv_admin_fee_badge($overrideLabel, $overrideClass); ?></td>
                        <td><?php echo bv_admin_fee_badge($effectiveLabel, $effectiveClass); ?></td>
                        <td class="notes-cell">
                            <div><strong>Promo:</strong> <?php echo bv_admin_fee_h($promoNote !== '' ? $promoNote : '—'); ?></div>
                            <div><strong>Override:</strong> <?php echo bv_admin_fee_h($overrideNote !== '' ? $overrideNote : '—'); ?></div>
                        </td>
                        <td>
                            <form class="edit-card" method="post" action="seller_fee_control_update.php">
                                <input type="hidden" name="csrf_token" value="<?php echo bv_admin_fee_h($csrfToken); ?>">
                                <input type="hidden" name="seller_id" value="<?php echo bv_admin_fee_h($seller['id']); ?>">
                                <div class="edit-grid">
                                    <label>Start date/time<input type="datetime-local" name="seller_fee_promo_starts_at" value="<?php echo bv_admin_fee_h(bv_admin_fee_datetime_local($seller['seller_fee_promo_starts_at'])); ?>"></label>
                                    <label>End date/time<input type="datetime-local" name="seller_fee_free_until" value="<?php echo bv_admin_fee_h(bv_admin_fee_datetime_local($seller['seller_fee_free_until'])); ?>"></label>
                                    <label>Override %<input type="number" step="0.001" min="0" max="100" name="seller_fee_percent_override" value="<?php echo bv_admin_fee_h($seller['seller_fee_percent_override']); ?>"></label>
                                    <label>Promo note<input maxlength="255" name="seller_fee_promo_note" value="<?php echo bv_admin_fee_h($seller['seller_fee_promo_note']); ?>"></label>
                                    <label class="wide">Override note<input maxlength="255" name="seller_fee_override_note" value="<?php echo bv_admin_fee_h($seller['seller_fee_override_note']); ?>"></label>
                                </div>
                                <div class="helper">Empty Override % = default fee unless active free promo window<br>0 = free fee<br>5 = custom 5% fee<br>Applies to future paid orders only</div>
                                <button type="submit">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($sellers === []): ?>
                    <tr><td colspan="9">No sellers found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>