<?php
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Manila');

function bind_params($stmt, $types, &$params)
{
    $references = [];
    foreach ($params as $key => $value) {
        $references[$key] = &$params[$key];
    }

    array_unshift($references, $types);
    array_unshift($references, $stmt);

    call_user_func_array('mysqli_stmt_bind_param', $references);
}

function db_execute($sql, $types = '', $params = [])
{
    $connection = db();
    $stmt = mysqli_prepare($connection, $sql);

    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . mysqli_error($connection));
    }

    if ($types !== '' && !empty($params)) {
        bind_params($stmt, $types, $params);
    }

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to execute query: ' . mysqli_stmt_error($stmt));
    }

    return $stmt;
}

function db_select($sql, $types = '', $params = [])
{
    $stmt = db_execute($sql, $types, $params);
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

function db_select_one($sql, $types = '', $params = [])
{
    $rows = db_select($sql, $types, $params);
    return $rows[0] ?? null;
}

function db_insert($sql, $types = '', $params = [])
{
    $stmt = db_execute($sql, $types, $params);
    mysqli_stmt_close($stmt);
    return mysqli_insert_id(db());
}

function db_exec_only($sql, $types = '', $params = [])
{
    $stmt = db_execute($sql, $types, $params);
    mysqli_stmt_close($stmt);
}

function db_begin()
{
    mysqli_begin_transaction(db());
}

function db_commit()
{
    mysqli_commit(db());
}

function db_rollback()
{
    @mysqli_rollback(db());
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect($path)
{
    header('Location: ' . $path);
    exit;
}

function set_flash($type, $message)
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash()
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function is_logged_in()
{
    return !empty($_SESSION['user_id']);
}

function require_login()
{
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to continue.');
        redirect('index.php');
    }
}

function page_access_rules()
{
    return [
        'dashboard.php' => ['admin', 'sales', 'cashier', 'purchasing', 'accounting', 'inventory'],
        'register.php' => ['admin'],
        'sales.php' => ['admin', 'sales'],
        'cashier.php' => ['admin', 'cashier'],
        'inventory.php' => ['admin', 'inventory'],
        'purchasing.php' => ['admin', 'purchasing'],
        'accounting.php' => ['admin', 'accounting'],
        'reports.php' => ['admin', 'accounting'],
    ];
}

function can_access_page($role, $pageFile)
{
    $rules = page_access_rules();
    if (!isset($rules[$pageFile])) {
        return true;
    }

    return in_array((string) $role, $rules[$pageFile], true);
}

function require_page_access($pageFile)
{
    $user = current_user();
    if (!$user) {
        require_login();
        return;
    }

    if (!can_access_page($user['role'] ?? '', $pageFile)) {
        $username = (string) ($user['username'] ?? 'unknown');
        $role = (string) ($user['role'] ?? 'unknown');
        $referenceNo = 'DENIED-' . substr((string) $pageFile, 0, 42);
        $logMessage = 'Access denied to ' . $pageFile . ' for user ' . $username . ' (' . $role . ').';

        try {
            log_digital('Security', $referenceNo, $logMessage, (int) $user['id']);
        } catch (Exception $exception) {
            // Continue redirect flow even if audit logging fails.
        }

        set_flash('error', 'Access denied: your department role cannot open this page.');
        redirect('dashboard.php');
    }
}

function current_user()
{
    if (!is_logged_in()) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'full_name' => $_SESSION['full_name'] ?? 'User',
        'username' => $_SESSION['username'] ?? '',
        'role' => $_SESSION['role'] ?? 'sales',
    ];
}

function format_currency($amount)
{
    return 'PHP ' . number_format((float) $amount, 2);
}

function reference_number($prefix)
{
    return $prefix . '-' . date('YmdHis') . '-' . random_int(100, 999);
}

function log_digital($moduleName, $referenceNo, $message, $createdBy = null)
{
    if ($createdBy === null && is_logged_in()) {
        $createdBy = (int) $_SESSION['user_id'];
    }

    db_insert(
        'INSERT INTO digital_logs (module_name, reference_no, log_message, created_by) VALUES (?, ?, ?, ?)',
        'sssi',
        [$moduleName, $referenceNo, $message, $createdBy]
    );
}

function post_ledger($txnType, $referenceNo, $accountTitle, $debit, $credit, $description)
{
    db_insert(
        'INSERT INTO general_ledger (txn_type, reference_no, account_title, debit, credit, description) VALUES (?, ?, ?, ?, ?, ?)',
        'sssdds',
        [$txnType, $referenceNo, $accountTitle, $debit, $credit, $description]
    );
}

function insert_inventory_log($partId, $logType, $qtyChange, $resultingStock, $referenceNo, $notes)
{
    db_insert(
        'INSERT INTO inventory_logs (part_id, log_type, qty_change, resulting_stock, reference_no, notes) VALUES (?, ?, ?, ?, ?, ?)',
        'isiiss',
        [$partId, $logType, $qtyChange, $resultingStock, $referenceNo, $notes]
    );
}

function create_purchase_request($partId, $qty, $requestedBy, $sourceReference, $notes, $mergeMode = 'append')
{
    if ($qty <= 0) {
        return;
    }

    $allowedMergeModes = ['append', 'set_max', 'keep_existing'];
    if (!in_array($mergeMode, $allowedMergeModes, true)) {
        $mergeMode = 'append';
    }

    $existing = db_select_one(
        'SELECT id, po_number, qty_ordered FROM purchase_orders WHERE part_id = ? AND status = "requested" LIMIT 1',
        'i',
        [$partId]
    );

    if ($existing) {
        $existingQty = (int) $existing['qty_ordered'];
        $newQty = $existingQty;

        if ($mergeMode === 'append') {
            $newQty = $existingQty + $qty;
        }

        if ($mergeMode === 'set_max') {
            $newQty = max($existingQty, $qty);
        }

        if ($newQty !== $existingQty) {
            db_exec_only('UPDATE purchase_orders SET qty_ordered = ? WHERE id = ?', 'ii', [$newQty, (int) $existing['id']]);

            log_digital(
                'Purchasing Department',
                (string) $existing['po_number'],
                'Updated existing supplier order quantity for part ID ' . $partId,
                $requestedBy
            );
        }

        return;
    }

    $poNumber = reference_number('PO');
    db_insert(
        'INSERT INTO purchase_orders (po_number, part_id, qty_ordered, status, source_reference, notes, requested_by) VALUES (?, ?, ?, "requested", ?, ?, ?)',
        'siissi',
        [$poNumber, $partId, $qty, $sourceReference, $notes, $requestedBy]
    );

    log_digital('Purchasing Department', $poNumber, 'Prepared order to supplier: ' . $notes, $requestedBy);
}

function sales_order_has_available_stock($salesOrderId)
{
    $items = db_select(
        'SELECT soi.qty, p.stock_qty
         FROM sales_order_items soi
         INNER JOIN parts p ON p.id = soi.part_id
         WHERE soi.sales_order_id = ?',
        'i',
        [$salesOrderId]
    );

    if (empty($items)) {
        return false;
    }

    foreach ($items as $item) {
        if ((int) $item['qty'] > (int) $item['stock_qty']) {
            return false;
        }
    }

    return true;
}

function sync_sales_order_stock_status($salesOrderId, $updatedBy = null)
{
    $order = db_select_one('SELECT id, order_number, status FROM sales_orders WHERE id = ? LIMIT 1', 'i', [$salesOrderId]);
    if (!$order) {
        return false;
    }

    $currentStatus = (string) $order['status'];
    if ($currentStatus === 'paid' || $currentStatus === 'cancelled') {
        return false;
    }

    $targetStatus = sales_order_has_available_stock((int) $order['id']) ? 'ready_for_cashier' : 'pending_stock';
    if ($targetStatus === $currentStatus) {
        return false;
    }

    db_exec_only('UPDATE sales_orders SET status = ? WHERE id = ?', 'si', [$targetStatus, (int) $order['id']]);

    if ($updatedBy !== null) {
        $message = $targetStatus === 'ready_for_cashier'
            ? 'Stock replenished. Sales order moved to cashier queue automatically.'
            : 'Sales order moved back to pending stock due to unavailable quantity.';

        log_digital('Inventory Check', (string) $order['order_number'], $message, (int) $updatedBy);
    }

    return true;
}

function release_pending_sales_orders_for_part($partId, $updatedBy = null)
{
    $rows = db_select(
        'SELECT DISTINCT so.id
         FROM sales_orders so
         INNER JOIN sales_order_items soi ON soi.sales_order_id = so.id
         WHERE so.status = "pending_stock" AND soi.part_id = ?',
        'i',
        [$partId]
    );

    $releasedCount = 0;
    foreach ($rows as $row) {
        if (sync_sales_order_stock_status((int) $row['id'], $updatedBy)) {
            $releasedCount++;
        }
    }

    return $releasedCount;
}

function digital_log_exists($moduleName, $referenceNo, $message)
{
    $existing = db_select_one(
        'SELECT id FROM digital_logs WHERE module_name = ? AND reference_no = ? AND log_message = ? LIMIT 1',
        'sss',
        [$moduleName, $referenceNo, $message]
    );

    return $existing !== null;
}

function threshold_inventory_log_exists($partId, $referenceNo)
{
    $existing = db_select_one(
        'SELECT id FROM inventory_logs WHERE part_id = ? AND log_type = "threshold" AND reference_no = ? LIMIT 1',
        'is',
        [$partId, $referenceNo]
    );

    return $existing !== null;
}

function check_and_raise_low_stock($partId, $referenceNo, $requestedBy)
{
    $part = db_select_one('SELECT * FROM parts WHERE id = ? LIMIT 1', 'i', [$partId]);

    if (!$part) {
        return false;
    }

    if ((int) $part['stock_qty'] > (int) $part['threshold_qty']) {
        return false;
    }

    $qtyToOrder = ((int) $part['threshold_qty'] * 2) - (int) $part['stock_qty'];
    if ($qtyToOrder < 1) {
        $qtyToOrder = (int) $part['threshold_qty'];
    }

    create_purchase_request(
        (int) $part['id'],
        $qtyToOrder,
        $requestedBy,
        $referenceNo,
        'Automatic low-stock reorder for ' . $part['part_name'],
        'set_max'
    );

    if (!threshold_inventory_log_exists((int) $part['id'], $referenceNo)) {
        insert_inventory_log(
            (int) $part['id'],
            'threshold',
            0,
            (int) $part['stock_qty'],
            $referenceNo,
            'Low inventory update sent to purchasing'
        );
    }

    $message = 'Low inventory update: ' . $part['part_name'] . ' reached threshold';
    if (!digital_log_exists('Inventory System', $referenceNo, $message)) {
        log_digital('Inventory System', $referenceNo, $message, $requestedBy);
    }

    return true;
}

function run_inventory_threshold_monitor($requestedBy = null, $referenceNo = null)
{
    $lowStockParts = db_select('SELECT id FROM parts WHERE stock_qty <= threshold_qty ORDER BY id ASC');
    if (empty($lowStockParts)) {
        return 0;
    }

    if ($referenceNo === null || trim((string) $referenceNo) === '') {
        $referenceNo = 'LOW-STOCK-' . date('Ymd');
    }

    $triggeredCount = 0;
    foreach ($lowStockParts as $part) {
        if (check_and_raise_low_stock((int) $part['id'], $referenceNo, $requestedBy)) {
            $triggeredCount++;
        }
    }

    return $triggeredCount;
}

function sum_value($sql, $types = '', $params = [])
{
    $row = db_select_one($sql, $types, $params);
    if (!$row) {
        return 0;
    }

    $values = array_values($row);
    return (float) ($values[0] ?? 0);
}
