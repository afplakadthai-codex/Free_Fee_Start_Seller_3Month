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

if (!function_exists('bv_admin_fee_update_is_admin')) {
    function bv_admin_fee_update_is_admin(): bool
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

if (!function_exists('bv_admin_fee_update_flash')) {
    function bv_admin_fee_update_flash(string $type, string $message): void
    {
        $_SESSION['seller_fee_control_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('bv_admin_fee_update_redirect')) {
    function bv_admin_fee_update_redirect(): void
    {
        header('Location: seller_fee_control.php');
        exit;
    }
}

if (!bv_admin_fee_update_is_admin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

if (!hash_equals((string)($_SESSION['seller_fee_control_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    bv_admin_fee_update_flash('error', 'Security token expired. Please try again.');
    bv_admin_fee_update_redirect();
}

if (!function_exists('bv_admin_fee_update_db')) {
    function bv_admin_fee_update_db()
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

if (!function_exists('bv_admin_fee_update_query_all')) {
    function bv_admin_fee_update_query_all($db, string $sql, array $params = []): array
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

if (!function_exists('bv_admin_fee_update_execute')) {
    function bv_admin_fee_update_execute($db, string $sql, array $params = []): bool
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return false;
            }
            foreach (array_values($params) as $index => $value) {
                $stmt->bindValue($index + 1, $value);
            }
            return $stmt->execute();
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return false;
            }
            if ($params !== []) {
                $types = '';
                $values = [];
                foreach (array_values($params) as $value) {
                    if ($value === null) {
                        $types .= 's';
                    } elseif (is_int($value)) {
                        $types .= 'i';
                    } elseif (is_float($value)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $values[] = $value;
                }
                $stmt->bind_param($types, ...$values);
            }
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        return false;
    }
}

if (!function_exists('bv_admin_fee_update_columns')) {
    function bv_admin_fee_update_columns($db): array
    {
        $rows = bv_admin_fee_update_query_all(
            $db,
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['users']
        );
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row['COLUMN_NAME']] = true;
        }
        return $columns;
    }
}

if (!function_exists('bv_admin_fee_update_datetime')) {
    function bv_admin_fee_update_datetime(string $fieldLabel, $value, array &$errors): ?string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $normalized = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized) === 1) {
            $normalized .= ':00';
        }
        $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $normalized);
        $dateErrors = DateTime::getLastErrors();
        if (!$dt || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $errors[] = $fieldLabel . ' must be a valid date and time.';
            return null;
        }
        return $dt->format('Y-m-d H:i:s');
    }
}

$db = bv_admin_fee_update_db();
if (!$db) {
    bv_admin_fee_update_flash('error', 'Database connection was not found.');
    bv_admin_fee_update_redirect();
}

$columns = bv_admin_fee_update_columns($db);
$managedColumns = [
    'seller_fee_free_until',
    'seller_fee_promo_note',
    'seller_fee_promo_starts_at',
    'seller_fee_percent_override',
    'seller_fee_override_note',
];

$sellerId = filter_var($_POST['seller_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$sellerId) {
    bv_admin_fee_update_flash('error', 'Invalid seller id.');
    bv_admin_fee_update_redirect();
}

$existing = bv_admin_fee_update_query_all($db, 'SELECT id FROM users WHERE id = ? LIMIT 1', [$sellerId]);
if ($existing === []) {
    bv_admin_fee_update_flash('error', 'Seller was not found.');
    bv_admin_fee_update_redirect();
}

$errors = [];
$startsAt = bv_admin_fee_update_datetime('Promo start date', $_POST['seller_fee_promo_starts_at'] ?? '', $errors);
$freeUntil = bv_admin_fee_update_datetime('Promo end date', $_POST['seller_fee_free_until'] ?? '', $errors);

$overrideRaw = trim((string)($_POST['seller_fee_percent_override'] ?? ''));
$override = null;
if ($overrideRaw !== '') {
    if (!is_numeric($overrideRaw)) {
        $errors[] = 'Fee percent override must be a number.';
    } else {
        $override = (float)$overrideRaw;
        if ($override < 0 || $override > 100) {
            $errors[] = 'Fee percent override must be between 0 and 100.';
        }
    }
}

$promoNote = trim((string)($_POST['seller_fee_promo_note'] ?? ''));
$overrideNote = trim((string)($_POST['seller_fee_override_note'] ?? ''));
if (strlen($promoNote) > 255) {
    $errors[] = 'Promo note must be 255 characters or fewer.';
}
if (strlen($overrideNote) > 255) {
    $errors[] = 'Override note must be 255 characters or fewer.';
}

if ($errors !== []) {
    bv_admin_fee_update_flash('error', implode(' ', $errors));
    bv_admin_fee_update_redirect();
}

$updates = [];
$params = [];
$fieldValues = [
    'seller_fee_promo_starts_at' => $startsAt,
    'seller_fee_free_until' => $freeUntil,
    'seller_fee_percent_override' => $override,
    'seller_fee_promo_note' => $promoNote === '' ? null : $promoNote,
    'seller_fee_override_note' => $overrideNote === '' ? null : $overrideNote,
];
foreach ($fieldValues as $column => $value) {
    if (isset($columns[$column])) {
        $updates[] = $column . ' = ?';
        $params[] = $value;
    }
}

if ($updates === []) {
    bv_admin_fee_update_flash('error', 'No seller fee columns are available to update. Run the migration shown on the command center page.');
    bv_admin_fee_update_redirect();
}

$params[] = $sellerId;
$ok = bv_admin_fee_update_execute(
    $db,
    'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ? LIMIT 1',
    $params
);

if (!$ok) {
    bv_admin_fee_update_flash('error', 'Seller fee settings could not be updated.');
    bv_admin_fee_update_redirect();
}

$skipped = array_values(array_diff(array_keys($fieldValues), array_keys(array_filter($columns))));
$extraMessage = $skipped === [] ? '' : ' Some controls were skipped because columns are missing: ' . implode(', ', $skipped) . '.';
bv_admin_fee_update_flash('success', 'Seller fee settings updated. Changes apply to future paid orders only. Old balances, transactions, refunds, and orders were not recalculated.' . $extraMessage);
bv_admin_fee_update_redirect();
