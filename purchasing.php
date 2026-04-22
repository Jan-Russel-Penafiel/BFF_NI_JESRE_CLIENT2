<?php
require_once __DIR__ . '/includes/auth_guard.php';

$pageTitle = 'Purchasing Department';
$activePage = 'purchasing';
$user = current_user();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_purchase_order') {
            $partId = (int) ($_POST['part_id'] ?? 0);
            $qtyOrdered = (int) ($_POST['qty_ordered'] ?? 0);
            $sourceReference = trim($_POST['source_reference'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($partId <= 0 || $qtyOrdered <= 0) {
                throw new Exception('Part and quantity are required to create a purchase order.');
            }

            $poNumber = reference_number('PO');
            db_insert(
                'INSERT INTO purchase_orders (po_number, part_id, qty_ordered, status, source_reference, notes, requested_by) VALUES (?, ?, ?, "requested", ?, ?, ?)',
                'siissi',
                [$poNumber, $partId, $qtyOrdered, $sourceReference, $notes, (int) $user['id']]
            );

            log_digital('Purchasing Department', $poNumber, 'Prepared order to supplier.', (int) $user['id']);
            set_flash('success', 'Purchase order created successfully.');
            redirect('purchasing.php');
        }

        if ($action === 'edit_purchase_order') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $partId = (int) ($_POST['part_id'] ?? 0);
            $qtyOrdered = (int) ($_POST['qty_ordered'] ?? 0);
            $status = trim($_POST['status'] ?? 'requested');
            $sourceReference = trim($_POST['source_reference'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($poId <= 0 || $partId <= 0 || $qtyOrdered <= 0) {
                throw new Exception('Invalid purchase order update data.');
            }

            $allowedStatus = ['requested', 'cancelled'];
            if (!in_array($status, $allowedStatus, true)) {
                throw new Exception('Invalid purchase order status selected. Use receive action when goods arrive.');
            }

            db_exec_only(
                'UPDATE purchase_orders SET part_id = ?, qty_ordered = ?, status = ?, source_reference = ?, notes = ? WHERE id = ?',
                'iisssi',
                [$partId, $qtyOrdered, $status, $sourceReference, $notes, $poId]
            );

            set_flash('success', 'Purchase order updated successfully.');
            redirect('purchasing.php');
        }

        if ($action === 'delete_purchase_order') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            if ($poId <= 0) {
                throw new Exception('Invalid purchase order selected for deletion.');
            }

            db_exec_only('DELETE FROM purchase_orders WHERE id = ? AND status != "received"', 'i', [$poId]);
            set_flash('success', 'Purchase order deleted successfully.');
            redirect('purchasing.php');
        }

        if ($action === 'receive_goods') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $qtyReceived = (int) ($_POST['qty_received'] ?? 0);

            if ($poId <= 0 || $qtyReceived <= 0) {
                throw new Exception('Invalid receive goods request.');
            }

            $purchaseOrder = db_select_one(
                'SELECT po.*, p.part_name, p.cost_price, p.stock_qty FROM purchase_orders po INNER JOIN parts p ON p.id = po.part_id WHERE po.id = ? LIMIT 1',
                'i',
                [$poId]
            );

            if (!$purchaseOrder) {
                throw new Exception('Purchase order not found.');
            }

            if ($purchaseOrder['status'] === 'received') {
                throw new Exception('This purchase order is already marked as received.');
            }

            if ($purchaseOrder['status'] !== 'requested') {
                throw new Exception('Only requested purchase orders can be received.');
            }

            $newStock = (int) $purchaseOrder['stock_qty'] + $qtyReceived;
            $inventoryValue = (float) $purchaseOrder['cost_price'] * $qtyReceived;

            db_begin();

            db_exec_only('UPDATE parts SET stock_qty = ? WHERE id = ?', 'ii', [$newStock, (int) $purchaseOrder['part_id']]);
            db_exec_only(
                'UPDATE purchase_orders SET status = "received", qty_ordered = ?, received_by = ?, received_at = NOW() WHERE id = ?',
                'iii',
                [$qtyReceived, (int) $user['id'], $poId]
            );

            insert_inventory_log(
                (int) $purchaseOrder['part_id'],
                'restock',
                $qtyReceived,
                $newStock,
                $purchaseOrder['po_number'],
                'Receive goods and update inventory database'
            );

            log_digital(
                'Purchasing Department',
                $purchaseOrder['po_number'],
                'Received goods from supplier for ' . $purchaseOrder['part_name'],
                (int) $user['id']
            );

            log_digital(
                'Inventory System',
                $purchaseOrder['po_number'],
                'Inventory updated after goods receiving.',
                (int) $user['id']
            );

            post_ledger('PURCHASE', $purchaseOrder['po_number'], 'Inventory Asset', $inventoryValue, 0, 'Inventory increase from supplier receiving');
            post_ledger('PURCHASE', $purchaseOrder['po_number'], 'Accounts Payable', 0, $inventoryValue, 'Liability for supplier order receiving');

            $releasedOrders = release_pending_sales_orders_for_part((int) $purchaseOrder['part_id'], (int) $user['id']);
            check_and_raise_low_stock((int) $purchaseOrder['part_id'], $purchaseOrder['po_number'], (int) $user['id']);

            db_commit();

            $successMessage = 'Goods received and inventory updated.';
            if ($releasedOrders > 0) {
                $successMessage .= ' ' . $releasedOrders . ' pending sales order(s) moved to cashier queue.';
            }

            set_flash('success', $successMessage);
            redirect('purchasing.php');
        }
    }
} catch (Exception $exception) {
    db_rollback();
    set_flash('error', $exception->getMessage());
    redirect('purchasing.php');
}

$parts = db_select('SELECT id, sku, part_name, stock_qty FROM parts ORDER BY part_name ASC');
$purchaseOrders = db_select(
    'SELECT po.*, p.sku, p.part_name, p.supplier_name, u.full_name AS requested_by_name, ur.full_name AS received_by_name
     FROM purchase_orders po
     INNER JOIN parts p ON p.id = po.part_id
     LEFT JOIN users u ON u.id = po.requested_by
     LEFT JOIN users ur ON ur.id = po.received_by
     ORDER BY po.requested_at DESC'
);

require_once __DIR__ . '/includes/layout_start.php';
?>
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-navy-900">Supplier Order Management</h2>
            <p class="text-sm text-slate-500">Prepare order to supplier, receive goods, and update inventory database.</p>
        </div>
        <button data-modal-open="create-po-modal" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">
            Create Purchase Order
        </button>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Purchase Orders</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">PO Number</th>
                    <th class="px-3 py-2">Part</th>
                    <th class="px-3 py-2">Supplier</th>
                    <th class="px-3 py-2">Qty</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Source Ref</th>
                    <th class="px-3 py-2">Requested At</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchaseOrders)): ?>
                    <tr>
                        <td colspan="8" class="px-3 py-4 text-center text-slate-500">No purchase orders found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($purchaseOrders as $po): ?>
                        <?php
                        $statusClass = 'bg-slate-100 text-slate-700';
                        if ($po['status'] === 'requested') {
                            $statusClass = 'bg-amber-100 text-amber-700';
                        }
                        if ($po['status'] === 'received') {
                            $statusClass = 'bg-emerald-100 text-emerald-700';
                        }
                        if ($po['status'] === 'cancelled') {
                            $statusClass = 'bg-red-100 text-red-700';
                        }
                        ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2 font-semibold text-navy-900"><?php echo e($po['po_number']); ?></td>
                            <td class="px-3 py-2"><?php echo e($po['sku'] . ' - ' . $po['part_name']); ?></td>
                            <td class="px-3 py-2"><?php echo e($po['supplier_name']); ?></td>
                            <td class="px-3 py-2"><?php echo e($po['qty_ordered']); ?></td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $statusClass; ?>">
                                    <?php echo e(strtoupper($po['status'])); ?>
                                </span>
                            </td>
                            <td class="px-3 py-2"><?php echo e($po['source_reference'] ?: '-'); ?></td>
                            <td class="px-3 py-2"><?php echo e($po['requested_at']); ?></td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button data-modal-open="view-po-<?php echo e($po['id']); ?>" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">View</button>

                                    <?php if ($po['status'] !== 'received'): ?>
                                        <button
                                            data-modal-open="edit-po-modal"
                                            class="edit-po-btn rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50"
                                            data-po-id="<?php echo e($po['id']); ?>"
                                            data-part-id="<?php echo e($po['part_id']); ?>"
                                            data-qty-ordered="<?php echo e($po['qty_ordered']); ?>"
                                            data-status="<?php echo e($po['status']); ?>"
                                            data-source-reference="<?php echo e($po['source_reference']); ?>"
                                            data-notes="<?php echo e($po['notes']); ?>"
                                        >Edit</button>

                                        <?php if ($po['status'] === 'requested'): ?>
                                            <button
                                                data-modal-open="receive-po-modal"
                                                class="receive-po-btn rounded border border-emerald-300 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
                                                data-po-id="<?php echo e($po['id']); ?>"
                                                data-po-number="<?php echo e($po['po_number']); ?>"
                                                data-qty-ordered="<?php echo e($po['qty_ordered']); ?>"
                                            >Receive</button>
                                        <?php endif; ?>

                                        <button
                                            data-modal-open="delete-po-modal"
                                            class="delete-po-btn rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                                            data-po-id="<?php echo e($po['id']); ?>"
                                            data-po-number="<?php echo e($po['po_number']); ?>"
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

<div id="create-po-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="create-po-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Create Purchase Order</h3>
            <button data-modal-close="create-po-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <form method="POST" class="mt-4 grid gap-3 md:grid-cols-2">
            <input type="hidden" name="action" value="create_purchase_order">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Part</label>
                <select name="part_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
                    <option value="">Select part</option>
                    <?php foreach ($parts as $part): ?>
                        <option value="<?php echo e($part['id']); ?>">
                            <?php echo e($part['sku'] . ' - ' . $part['part_name'] . ' (Stock: ' . $part['stock_qty'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Quantity Ordered</label>
                <input type="number" min="1" name="qty_ordered" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Source Reference</label>
                <input type="text" name="source_reference" placeholder="SO-... or INV-..." class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Notes</label>
                <textarea name="notes" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <div class="md:col-span-2 flex justify-end gap-2">
                <button type="button" data-modal-close="create-po-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Create</button>
            </div>
        </form>
    </div>
</div>

<div id="edit-po-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="edit-po-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Edit Purchase Order</h3>
            <button data-modal-close="edit-po-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <form method="POST" class="mt-4 grid gap-3 md:grid-cols-2">
            <input type="hidden" name="action" value="edit_purchase_order">
            <input type="hidden" id="edit-po-id" name="po_id">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Part</label>
                <select id="edit-part-id" name="part_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
                    <?php foreach ($parts as $part): ?>
                        <option value="<?php echo e($part['id']); ?>"><?php echo e($part['sku'] . ' - ' . $part['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Quantity Ordered</label>
                <input type="number" min="1" id="edit-qty-ordered" name="qty_ordered" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
                <select id="edit-status" name="status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
                    <option value="requested">Requested</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Source Reference</label>
                <input type="text" id="edit-source-reference" name="source_reference" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Notes</label>
                <textarea id="edit-notes" name="notes" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <div class="md:col-span-2 flex justify-end gap-2">
                <button type="button" data-modal-close="edit-po-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Update</button>
            </div>
        </form>
    </div>
</div>

<div id="receive-po-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="receive-po-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
        <h3 class="text-lg font-semibold text-navy-900">Receive Goods</h3>
        <p class="mt-2 text-sm text-slate-600">Confirm receiving for <span id="receive-po-number" class="font-semibold"></span>.</p>

        <form method="POST" class="mt-4 space-y-3">
            <input type="hidden" name="action" value="receive_goods">
            <input type="hidden" id="receive-po-id" name="po_id">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Quantity Received</label>
                <input type="number" min="1" id="receive-qty" name="qty_received" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-modal-close="receive-po-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600">Receive</button>
            </div>
        </form>
    </div>
</div>

<div id="delete-po-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="delete-po-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
        <h3 class="text-lg font-semibold text-red-700">Delete Purchase Order</h3>
        <p class="mt-2 text-sm text-slate-600">Delete <span id="delete-po-number" class="font-semibold"></span>?</p>

        <form method="POST" class="mt-4 flex justify-end gap-2">
            <input type="hidden" name="action" value="delete_purchase_order">
            <input type="hidden" id="delete-po-id" name="po_id">
            <button type="button" data-modal-close="delete-po-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
            <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600">Delete</button>
        </form>
    </div>
</div>

<?php foreach ($purchaseOrders as $po): ?>
    <div id="view-po-<?php echo e($po['id']); ?>" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
        <div data-modal-overlay="view-po-<?php echo e($po['id']); ?>" class="absolute inset-0 bg-slate-900/60"></div>
        <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-navy-900">Purchase Order Details</h3>
                <button data-modal-close="view-po-<?php echo e($po['id']); ?>" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
            </div>
            <div class="mt-3 grid gap-3 text-sm md:grid-cols-2">
                <p><span class="font-semibold text-slate-600">PO Number:</span> <?php echo e($po['po_number']); ?></p>
                <p><span class="font-semibold text-slate-600">Part:</span> <?php echo e($po['sku'] . ' - ' . $po['part_name']); ?></p>
                <p><span class="font-semibold text-slate-600">Supplier:</span> <?php echo e($po['supplier_name']); ?></p>
                <p><span class="font-semibold text-slate-600">Qty Ordered:</span> <?php echo e($po['qty_ordered']); ?></p>
                <p><span class="font-semibold text-slate-600">Status:</span> <?php echo e(strtoupper($po['status'])); ?></p>
                <p><span class="font-semibold text-slate-600">Source Ref:</span> <?php echo e($po['source_reference'] ?: '-'); ?></p>
                <p><span class="font-semibold text-slate-600">Requested By:</span> <?php echo e($po['requested_by_name'] ?? 'System'); ?></p>
                <p><span class="font-semibold text-slate-600">Requested At:</span> <?php echo e($po['requested_at']); ?></p>
                <p><span class="font-semibold text-slate-600">Received By:</span> <?php echo e($po['received_by_name'] ?? '-'); ?></p>
                <p><span class="font-semibold text-slate-600">Received At:</span> <?php echo e($po['received_at'] ?? '-'); ?></p>
                <p class="md:col-span-2"><span class="font-semibold text-slate-600">Notes:</span> <?php echo e($po['notes'] ?: '-'); ?></p>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    document.querySelectorAll('.edit-po-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('edit-po-id').value = button.dataset.poId;
            document.getElementById('edit-part-id').value = button.dataset.partId;
            document.getElementById('edit-qty-ordered').value = button.dataset.qtyOrdered;
            document.getElementById('edit-status').value = button.dataset.status;
            document.getElementById('edit-source-reference').value = button.dataset.sourceReference;
            document.getElementById('edit-notes').value = button.dataset.notes;
        });
    });

    document.querySelectorAll('.receive-po-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('receive-po-id').value = button.dataset.poId;
            document.getElementById('receive-po-number').textContent = button.dataset.poNumber;
            document.getElementById('receive-qty').value = button.dataset.qtyOrdered;
        });
    });

    document.querySelectorAll('.delete-po-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('delete-po-id').value = button.dataset.poId;
            document.getElementById('delete-po-number').textContent = button.dataset.poNumber;
        });
    });
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
