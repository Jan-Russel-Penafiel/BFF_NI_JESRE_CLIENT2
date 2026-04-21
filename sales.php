<?php
require_once __DIR__ . '/includes/auth_guard.php';

$pageTitle = 'Sales Department';
$activePage = 'sales';
$user = current_user();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_order') {
            $customerName = trim($_POST['customer_name'] ?? '');
            $partIds = $_POST['part_id'] ?? [];
            $quantities = $_POST['qty'] ?? [];

            if ($customerName === '') {
                throw new Exception('Customer name is required.');
            }

            $lineItems = [];
            foreach ($partIds as $index => $partIdRaw) {
                $partId = (int) $partIdRaw;
                $qty = (int) ($quantities[$index] ?? 0);

                if ($partId <= 0 || $qty <= 0) {
                    continue;
                }

                $part = db_select_one('SELECT * FROM parts WHERE id = ? LIMIT 1', 'i', [$partId]);
                if (!$part) {
                    continue;
                }

                $lineItems[] = [
                    'part_id' => (int) $part['id'],
                    'part_name' => $part['part_name'],
                    'stock_qty' => (int) $part['stock_qty'],
                    'threshold_qty' => (int) $part['threshold_qty'],
                    'qty' => $qty,
                    'unit_price' => (float) $part['unit_price'],
                    'cost_price' => (float) $part['cost_price'],
                ];
            }

            if (empty($lineItems)) {
                throw new Exception('Add at least one valid line item.');
            }

            $subtotal = 0;
            $shortages = [];
            foreach ($lineItems as &$item) {
                $lineTotal = $item['qty'] * $item['unit_price'];
                $item['line_total'] = $lineTotal;
                $subtotal += $lineTotal;

                if ($item['qty'] > $item['stock_qty']) {
                    $shortages[] = [
                        'part_id' => $item['part_id'],
                        'part_name' => $item['part_name'],
                        'short_qty' => $item['qty'] - $item['stock_qty'],
                    ];
                }
            }
            unset($item);

            $status = empty($shortages) ? 'ready_for_cashier' : 'pending_stock';
            $orderNumber = reference_number('SO');

            db_begin();

            $orderId = db_insert(
                'INSERT INTO sales_orders (order_number, customer_name, status, subtotal, created_by) VALUES (?, ?, ?, ?, ?)',
                'sssdi',
                [$orderNumber, $customerName, $status, $subtotal, (int) $user['id']]
            );

            foreach ($lineItems as $item) {
                db_insert(
                    'INSERT INTO sales_order_items (sales_order_id, part_id, qty, unit_price, cost_price, line_total) VALUES (?, ?, ?, ?, ?, ?)',
                    'iiiddd',
                    [$orderId, $item['part_id'], $item['qty'], $item['unit_price'], $item['cost_price'], $item['line_total']]
                );
            }

            log_digital(
                'Sales Department',
                $orderNumber,
                'Assist customer and confirm order, then input order to POS terminal.',
                (int) $user['id']
            );

            log_digital(
                'Inventory Check',
                $orderNumber,
                'Performed live inventory query and generated digital sales order.',
                (int) $user['id']
            );

            if (!empty($shortages)) {
                foreach ($shortages as $shortage) {
                    create_purchase_request(
                        (int) $shortage['part_id'],
                        (int) $shortage['short_qty'],
                        (int) $user['id'],
                        $orderNumber,
                        'Order shortage from Sales Order ' . $orderNumber . ' for ' . $shortage['part_name']
                    );
                }

                log_digital(
                    'Purchasing Department',
                    $orderNumber,
                    'Stock unavailable. Prepared order to supplier for shortage items.',
                    (int) $user['id']
                );
            }

            db_commit();

            if ($status === 'pending_stock') {
                set_flash('success', 'Sales order created with pending stock. Supplier request prepared automatically.');
            } else {
                set_flash('success', 'Sales order created and sent to cashier queue.');
            }

            redirect('sales.php');
        }

        if ($action === 'edit_order') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $customerName = trim($_POST['customer_name'] ?? '');
            $status = trim($_POST['status'] ?? 'ready_for_cashier');

            if ($orderId <= 0 || $customerName === '') {
                throw new Exception('Invalid order update request.');
            }

            $allowedStatus = ['pending_stock', 'ready_for_cashier', 'cancelled'];
            if (!in_array($status, $allowedStatus, true)) {
                throw new Exception('Invalid status selected.');
            }

            db_exec_only(
                'UPDATE sales_orders SET customer_name = ?, status = ? WHERE id = ? AND status != "paid"',
                'ssi',
                [$customerName, $status, $orderId]
            );

            set_flash('success', 'Sales order updated successfully.');
            redirect('sales.php');
        }

        if ($action === 'delete_order') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                throw new Exception('Invalid order selected for deletion.');
            }

            db_exec_only('DELETE FROM sales_orders WHERE id = ? AND status != "paid"', 'i', [$orderId]);
            set_flash('success', 'Sales order deleted successfully.');
            redirect('sales.php');
        }
    }
} catch (Exception $exception) {
    db_rollback();
    set_flash('error', $exception->getMessage());
    redirect('sales.php');
}

$parts = db_select('SELECT * FROM parts ORDER BY part_name ASC');
$orders = db_select(
    'SELECT s.*, u.full_name FROM sales_orders s LEFT JOIN users u ON s.created_by = u.id ORDER BY s.created_at DESC'
);
$orderItemsRows = db_select(
    'SELECT soi.*, p.sku, p.part_name FROM sales_order_items soi INNER JOIN parts p ON p.id = soi.part_id ORDER BY soi.id DESC'
);

$orderItemsMap = [];
foreach ($orderItemsRows as $item) {
    $orderId = (int) $item['sales_order_id'];
    if (!isset($orderItemsMap[$orderId])) {
        $orderItemsMap[$orderId] = [];
    }
    $orderItemsMap[$orderId][] = $item;
}

require_once __DIR__ . '/includes/layout_start.php';
?>
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-navy-900">Sales and POS Order Entry</h2>
            <p class="text-sm text-slate-500">Assist customer, input order, and run live stock availability check.</p>
        </div>
        <button data-modal-open="create-order-modal" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">
            Create Sales Order
        </button>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Live Inventory Query</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">SKU</th>
                    <th class="px-3 py-2">Part Name</th>
                    <th class="px-3 py-2">Stock</th>
                    <th class="px-3 py-2">Threshold</th>
                    <th class="px-3 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parts as $part): ?>
                    <?php $isLow = (int) $part['stock_qty'] <= (int) $part['threshold_qty']; ?>
                    <tr class="border-b border-slate-100">
                        <td class="px-3 py-2"><?php echo e($part['sku']); ?></td>
                        <td class="px-3 py-2"><?php echo e($part['part_name']); ?></td>
                        <td class="px-3 py-2"><?php echo e($part['stock_qty']); ?></td>
                        <td class="px-3 py-2"><?php echo e($part['threshold_qty']); ?></td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $isLow ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'; ?>">
                                <?php echo $isLow ? 'Low Stock' : 'Available'; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Digital Sales Orders</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Order No.</th>
                    <th class="px-3 py-2">Customer</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Subtotal</th>
                    <th class="px-3 py-2">Created By</th>
                    <th class="px-3 py-2">Date</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="7" class="px-3 py-4 text-center text-slate-500">No sales orders yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $statusClass = 'bg-slate-100 text-slate-700';
                        if ($order['status'] === 'pending_stock') {
                            $statusClass = 'bg-amber-100 text-amber-700';
                        }
                        if ($order['status'] === 'ready_for_cashier') {
                            $statusClass = 'bg-blue-100 text-blue-700';
                        }
                        if ($order['status'] === 'paid') {
                            $statusClass = 'bg-emerald-100 text-emerald-700';
                        }
                        ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2 font-semibold text-navy-900"><?php echo e($order['order_number']); ?></td>
                            <td class="px-3 py-2"><?php echo e($order['customer_name']); ?></td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $statusClass; ?>">
                                    <?php echo e(str_replace('_', ' ', strtoupper($order['status']))); ?>
                                </span>
                            </td>
                            <td class="px-3 py-2"><?php echo e(format_currency($order['subtotal'])); ?></td>
                            <td class="px-3 py-2"><?php echo e($order['full_name'] ?? 'System'); ?></td>
                            <td class="px-3 py-2"><?php echo e($order['created_at']); ?></td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button data-modal-open="view-order-<?php echo e($order['id']); ?>" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">View</button>
                                    <?php if ($order['status'] !== 'paid'): ?>
                                        <button
                                            data-modal-open="edit-order-modal"
                                            class="edit-order-btn rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50"
                                            data-order-id="<?php echo e($order['id']); ?>"
                                            data-customer-name="<?php echo e($order['customer_name']); ?>"
                                            data-status="<?php echo e($order['status']); ?>"
                                        >Edit</button>
                                        <button
                                            data-modal-open="delete-order-modal"
                                            class="delete-order-btn rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                                            data-order-id="<?php echo e($order['id']); ?>"
                                            data-order-number="<?php echo e($order['order_number']); ?>"
                                        >Delete</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="create-order-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="create-order-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-3xl rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Create Sales Order</h3>
            <button data-modal-close="create-order-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <form method="POST" class="mt-4 space-y-4">
            <input type="hidden" name="action" value="create_order">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Customer Name</label>
                <input type="text" name="customer_name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>

            <div class="rounded-lg border border-slate-200 p-3">
                <p class="text-xs font-semibold uppercase text-slate-500">Line Items</p>
                <div class="mt-2 space-y-2">
                    <?php for ($row = 0; $row < 5; $row++): ?>
                        <div class="grid gap-2 md:grid-cols-3">
                            <div class="md:col-span-2">
                                <select name="part_id[]" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">Select part</option>
                                    <?php foreach ($parts as $part): ?>
                                        <option value="<?php echo e($part['id']); ?>">
                                            <?php echo e($part['sku'] . ' - ' . $part['part_name'] . ' (Stock: ' . $part['stock_qty'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <input type="number" min="1" name="qty[]" placeholder="Qty" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-modal-close="create-order-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Save Order</button>
            </div>
        </form>
    </div>
</div>

<div id="edit-order-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="edit-order-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Edit Sales Order</h3>
            <button data-modal-close="edit-order-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <form method="POST" class="mt-4 space-y-4">
            <input type="hidden" name="action" value="edit_order">
            <input type="hidden" id="edit-order-id" name="order_id">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Customer Name</label>
                <input type="text" id="edit-customer-name" name="customer_name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
                <select id="edit-status" name="status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
                    <option value="pending_stock">Pending Stock</option>
                    <option value="ready_for_cashier">Ready for Cashier</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-modal-close="edit-order-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Update</button>
            </div>
        </form>
    </div>
</div>

<div id="delete-order-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="delete-order-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
        <h3 class="text-lg font-semibold text-red-700">Delete Sales Order</h3>
        <p class="mt-2 text-sm text-slate-600">Are you sure you want to delete <span id="delete-order-number" class="font-semibold"></span>?</p>

        <form method="POST" class="mt-4 flex justify-end gap-2">
            <input type="hidden" name="action" value="delete_order">
            <input type="hidden" id="delete-order-id" name="order_id">
            <button type="button" data-modal-close="delete-order-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
            <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600">Delete</button>
        </form>
    </div>
</div>

<?php foreach ($orders as $order): ?>
    <div id="view-order-<?php echo e($order['id']); ?>" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
        <div data-modal-overlay="view-order-<?php echo e($order['id']); ?>" class="absolute inset-0 bg-slate-900/60"></div>
        <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-navy-900">Sales Order Details</h3>
                <button data-modal-close="view-order-<?php echo e($order['id']); ?>" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
            </div>

            <div class="mt-3 grid gap-3 text-sm md:grid-cols-2">
                <p><span class="font-semibold text-slate-600">Order No:</span> <?php echo e($order['order_number']); ?></p>
                <p><span class="font-semibold text-slate-600">Customer:</span> <?php echo e($order['customer_name']); ?></p>
                <p><span class="font-semibold text-slate-600">Status:</span> <?php echo e(strtoupper(str_replace('_', ' ', $order['status']))); ?></p>
                <p><span class="font-semibold text-slate-600">Subtotal:</span> <?php echo e(format_currency($order['subtotal'])); ?></p>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                            <th class="px-2 py-2">SKU</th>
                            <th class="px-2 py-2">Part</th>
                            <th class="px-2 py-2">Qty</th>
                            <th class="px-2 py-2">Price</th>
                            <th class="px-2 py-2">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItemsMap[$order['id']] ?? [] as $item): ?>
                            <tr class="border-b border-slate-100">
                                <td class="px-2 py-2"><?php echo e($item['sku']); ?></td>
                                <td class="px-2 py-2"><?php echo e($item['part_name']); ?></td>
                                <td class="px-2 py-2"><?php echo e($item['qty']); ?></td>
                                <td class="px-2 py-2"><?php echo e(format_currency($item['unit_price'])); ?></td>
                                <td class="px-2 py-2"><?php echo e(format_currency($item['line_total'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    document.querySelectorAll('.edit-order-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('edit-order-id').value = button.dataset.orderId;
            document.getElementById('edit-customer-name').value = button.dataset.customerName;
            document.getElementById('edit-status').value = button.dataset.status;
        });
    });

    document.querySelectorAll('.delete-order-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('delete-order-id').value = button.dataset.orderId;
            document.getElementById('delete-order-number').textContent = button.dataset.orderNumber;
        });
    });
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
