<?php
require_once __DIR__ . '/includes/auth_guard.php';

$pageTitle = 'Inventory System';
$activePage = 'inventory';
$user = current_user();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_part') {
            $sku = trim($_POST['sku'] ?? '');
            $partName = trim($_POST['part_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $supplierName = trim($_POST['supplier_name'] ?? '');
            $costPrice = (float) ($_POST['cost_price'] ?? 0);
            $unitPrice = (float) ($_POST['unit_price'] ?? 0);
            $stockQty = (int) ($_POST['stock_qty'] ?? 0);
            $thresholdQty = (int) ($_POST['threshold_qty'] ?? 0);

            if ($sku === '' || $partName === '' || $supplierName === '') {
                throw new Exception('SKU, part name, and supplier are required.');
            }

            $partId = db_insert(
                'INSERT INTO parts (sku, part_name, description, supplier_name, cost_price, unit_price, stock_qty, threshold_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                'ssssddii',
                [$sku, $partName, $description, $supplierName, $costPrice, $unitPrice, $stockQty, $thresholdQty]
            );

            check_and_raise_low_stock((int) $partId, 'INV-' . $sku, (int) $user['id']);

            log_digital('Inventory System', $sku, 'Created new inventory item ' . $partName, (int) $user['id']);
            set_flash('success', 'Part created successfully.');
            redirect('inventory.php');
        }

        if ($action === 'edit_part') {
            $partId = (int) ($_POST['part_id'] ?? 0);
            $sku = trim($_POST['sku'] ?? '');
            $partName = trim($_POST['part_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $supplierName = trim($_POST['supplier_name'] ?? '');
            $costPrice = (float) ($_POST['cost_price'] ?? 0);
            $unitPrice = (float) ($_POST['unit_price'] ?? 0);
            $stockQty = (int) ($_POST['stock_qty'] ?? 0);
            $thresholdQty = (int) ($_POST['threshold_qty'] ?? 0);

            if ($partId <= 0) {
                throw new Exception('Invalid part selected for update.');
            }

            db_exec_only(
                'UPDATE parts SET sku = ?, part_name = ?, description = ?, supplier_name = ?, cost_price = ?, unit_price = ?, stock_qty = ?, threshold_qty = ? WHERE id = ?',
                'ssssddiii',
                [$sku, $partName, $description, $supplierName, $costPrice, $unitPrice, $stockQty, $thresholdQty, $partId]
            );

            check_and_raise_low_stock((int) $partId, 'INV-' . $sku, (int) $user['id']);

            log_digital('Inventory System', $sku, 'Updated inventory item ' . $partName, (int) $user['id']);
            set_flash('success', 'Part updated successfully.');
            redirect('inventory.php');
        }

        if ($action === 'delete_part') {
            $partId = (int) ($_POST['part_id'] ?? 0);
            if ($partId <= 0) {
                throw new Exception('Invalid part selected for deletion.');
            }

            db_exec_only('DELETE FROM parts WHERE id = ?', 'i', [$partId]);
            set_flash('success', 'Part deleted successfully.');
            redirect('inventory.php');
        }

        if ($action === 'prepare_supplier') {
            $partId = (int) ($_POST['part_id'] ?? 0);
            $part = db_select_one('SELECT * FROM parts WHERE id = ? LIMIT 1', 'i', [$partId]);

            if (!$part) {
                throw new Exception('Part not found for supplier order request.');
            }

            $qtyToOrder = ((int) $part['threshold_qty'] * 2) - (int) $part['stock_qty'];
            if ($qtyToOrder < 1) {
                $qtyToOrder = (int) $part['threshold_qty'];
            }

            create_purchase_request(
                (int) $part['id'],
                $qtyToOrder,
                (int) $user['id'],
                'INV-' . $part['sku'],
                'Manual prepare order from inventory threshold review',
                'set_max'
            );

            set_flash('success', 'Supplier order prepared for ' . $part['part_name'] . '.');
            redirect('inventory.php');
        }
    }
} catch (Exception $exception) {
    set_flash('error', $exception->getMessage());
    redirect('inventory.php');
}

run_inventory_threshold_monitor((int) $user['id'], 'INV-SCAN-' . date('Ymd'));

$parts = db_select('SELECT * FROM parts ORDER BY part_name ASC');
$inventoryLogs = db_select(
    'SELECT il.*, p.sku, p.part_name FROM inventory_logs il INNER JOIN parts p ON p.id = il.part_id ORDER BY il.created_at DESC LIMIT 20'
);

$lowStockParts = array_values(array_filter($parts, function ($part) {
    return (int) $part['stock_qty'] <= (int) $part['threshold_qty'];
}));

require_once __DIR__ . '/includes/layout_start.php';
?>
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-navy-900">Inventory Database Management</h2>
            <p class="text-sm text-slate-500">Maintain stock records, thresholds, and low-inventory supplier requests.</p>
        </div>
        <button data-modal-open="create-part-modal" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">
            Create Part
        </button>
    </div>
</div>

<div class="grid gap-4 md:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="text-base font-semibold text-navy-900">Low Inventory Update</h3>
        <div class="mt-3 space-y-2 text-sm">
            <?php if (empty($lowStockParts)): ?>
                <p class="rounded-md bg-emerald-50 px-3 py-2 text-emerald-700">All parts are above threshold.</p>
            <?php else: ?>
                <?php foreach ($lowStockParts as $part): ?>
                    <div class="flex items-center justify-between rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
                        <div>
                            <p class="font-semibold text-amber-900"><?php echo e($part['part_name']); ?></p>
                            <p class="text-xs text-amber-700">Stock <?php echo e($part['stock_qty']); ?> / Threshold <?php echo e($part['threshold_qty']); ?></p>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="prepare_supplier">
                            <input type="hidden" name="part_id" value="<?php echo e($part['id']); ?>">
                            <button type="submit" class="rounded border border-amber-400 px-2 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-100">Prepare Order</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="text-base font-semibold text-navy-900">Inventory Flow Checkpoints</h3>
        <ul class="mt-3 space-y-2 text-sm text-slate-600">
            <li>- Store transaction in inventory database</li>
            <li>- Auto-deduct stock upon sale</li>
            <li>- Check available stock threshold</li>
            <li>- If below threshold, prepare order to supplier</li>
            <li>- Send low inventory update to department users</li>
        </ul>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Parts Master List</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">SKU</th>
                    <th class="px-3 py-2">Part Name</th>
                    <th class="px-3 py-2">Supplier</th>
                    <th class="px-3 py-2">Cost</th>
                    <th class="px-3 py-2">Price</th>
                    <th class="px-3 py-2">Stock</th>
                    <th class="px-3 py-2">Threshold</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($parts)): ?>
                    <tr>
                        <td colspan="8" class="px-3 py-4 text-center text-slate-500">No parts found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($parts as $part): ?>
                        <?php $isLow = (int) $part['stock_qty'] <= (int) $part['threshold_qty']; ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2 font-semibold text-navy-900"><?php echo e($part['sku']); ?></td>
                            <td class="px-3 py-2"><?php echo e($part['part_name']); ?></td>
                            <td class="px-3 py-2"><?php echo e($part['supplier_name']); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($part['cost_price'])); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($part['unit_price'])); ?></td>
                            <td class="px-3 py-2">
                                <span class="rounded px-2 py-1 text-xs font-semibold <?php echo $isLow ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'; ?>">
                                    <?php echo e($part['stock_qty']); ?>
                                </span>
                            </td>
                            <td class="px-3 py-2"><?php echo e($part['threshold_qty']); ?></td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button data-modal-open="view-part-<?php echo e($part['id']); ?>" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">View</button>
                                    <button
                                        data-modal-open="edit-part-modal"
                                        class="edit-part-btn rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50"
                                        data-part-id="<?php echo e($part['id']); ?>"
                                        data-sku="<?php echo e($part['sku']); ?>"
                                        data-part-name="<?php echo e($part['part_name']); ?>"
                                        data-description="<?php echo e($part['description']); ?>"
                                        data-supplier-name="<?php echo e($part['supplier_name']); ?>"
                                        data-cost-price="<?php echo e($part['cost_price']); ?>"
                                        data-unit-price="<?php echo e($part['unit_price']); ?>"
                                        data-stock-qty="<?php echo e($part['stock_qty']); ?>"
                                        data-threshold-qty="<?php echo e($part['threshold_qty']); ?>"
                                    >Edit</button>
                                    <button
                                        data-modal-open="delete-part-modal"
                                        class="delete-part-btn rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                                        data-part-id="<?php echo e($part['id']); ?>"
                                        data-part-name="<?php echo e($part['part_name']); ?>"
                                    >Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Recent Inventory Logs</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Date</th>
                    <th class="px-3 py-2">Reference</th>
                    <th class="px-3 py-2">Part</th>
                    <th class="px-3 py-2">Type</th>
                    <th class="px-3 py-2">Qty Change</th>
                    <th class="px-3 py-2">Resulting Stock</th>
                    <th class="px-3 py-2">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventoryLogs)): ?>
                    <tr>
                        <td colspan="7" class="px-3 py-4 text-center text-slate-500">No inventory logs yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventoryLogs as $log): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2"><?php echo e($log['created_at']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['reference_no']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['sku'] . ' - ' . $log['part_name']); ?></td>
                            <td class="px-3 py-2"><?php echo e(strtoupper($log['log_type'])); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['qty_change']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['resulting_stock']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['notes']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="create-part-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="create-part-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Create Part</h3>
            <button data-modal-close="create-part-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <form method="POST" class="mt-4 grid gap-3 md:grid-cols-2">
            <input type="hidden" name="action" value="create_part">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">SKU</label>
                <input type="text" name="sku" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Part Name</label>
                <input type="text" name="part_name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Description</label>
                <textarea name="description" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Supplier</label>
                <input type="text" name="supplier_name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Cost Price</label>
                <input type="number" step="0.01" min="0" name="cost_price" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Unit Price</label>
                <input type="number" step="0.01" min="0" name="unit_price" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Stock Qty</label>
                <input type="number" min="0" name="stock_qty" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Threshold Qty</label>
                <input type="number" min="0" name="threshold_qty" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>

            <div class="md:col-span-2 flex justify-end gap-2 pt-1">
                <button type="button" data-modal-close="create-part-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Create</button>
            </div>
        </form>
    </div>
</div>

<div id="edit-part-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="edit-part-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Edit Part</h3>
            <button data-modal-close="edit-part-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <form method="POST" class="mt-4 grid gap-3 md:grid-cols-2">
            <input type="hidden" name="action" value="edit_part">
            <input type="hidden" id="edit-part-id" name="part_id">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">SKU</label>
                <input type="text" id="edit-sku" name="sku" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Part Name</label>
                <input type="text" id="edit-part-name" name="part_name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Description</label>
                <textarea id="edit-description" name="description" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Supplier</label>
                <input type="text" id="edit-supplier-name" name="supplier_name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Cost Price</label>
                <input type="number" step="0.01" min="0" id="edit-cost-price" name="cost_price" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Unit Price</label>
                <input type="number" step="0.01" min="0" id="edit-unit-price" name="unit_price" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Stock Qty</label>
                <input type="number" min="0" id="edit-stock-qty" name="stock_qty" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Threshold Qty</label>
                <input type="number" min="0" id="edit-threshold-qty" name="threshold_qty" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>

            <div class="md:col-span-2 flex justify-end gap-2 pt-1">
                <button type="button" data-modal-close="edit-part-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Update</button>
            </div>
        </form>
    </div>
</div>

<div id="delete-part-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="delete-part-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
        <h3 class="text-lg font-semibold text-red-700">Delete Part</h3>
        <p class="mt-2 text-sm text-slate-600">Delete <span id="delete-part-name" class="font-semibold"></span> from inventory?</p>

        <form method="POST" class="mt-4 flex justify-end gap-2">
            <input type="hidden" name="action" value="delete_part">
            <input type="hidden" id="delete-part-id" name="part_id">
            <button type="button" data-modal-close="delete-part-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
            <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600">Delete</button>
        </form>
    </div>
</div>

<?php foreach ($parts as $part): ?>
    <div id="view-part-<?php echo e($part['id']); ?>" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
        <div data-modal-overlay="view-part-<?php echo e($part['id']); ?>" class="absolute inset-0 bg-slate-900/60"></div>
        <div class="relative z-10 w-full max-w-xl rounded-xl bg-white p-5 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-navy-900">Part Details</h3>
                <button data-modal-close="view-part-<?php echo e($part['id']); ?>" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
            </div>
            <div class="mt-3 grid gap-3 text-sm md:grid-cols-2">
                <p><span class="font-semibold text-slate-600">SKU:</span> <?php echo e($part['sku']); ?></p>
                <p><span class="font-semibold text-slate-600">Part Name:</span> <?php echo e($part['part_name']); ?></p>
                <p><span class="font-semibold text-slate-600">Supplier:</span> <?php echo e($part['supplier_name']); ?></p>
                <p><span class="font-semibold text-slate-600">Cost Price:</span> <?php echo e(format_currency($part['cost_price'])); ?></p>
                <p><span class="font-semibold text-slate-600">Unit Price:</span> <?php echo e(format_currency($part['unit_price'])); ?></p>
                <p><span class="font-semibold text-slate-600">Stock Qty:</span> <?php echo e($part['stock_qty']); ?></p>
                <p><span class="font-semibold text-slate-600">Threshold:</span> <?php echo e($part['threshold_qty']); ?></p>
                <p class="md:col-span-2"><span class="font-semibold text-slate-600">Description:</span> <?php echo e($part['description']); ?></p>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    document.querySelectorAll('.edit-part-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('edit-part-id').value = button.dataset.partId;
            document.getElementById('edit-sku').value = button.dataset.sku;
            document.getElementById('edit-part-name').value = button.dataset.partName;
            document.getElementById('edit-description').value = button.dataset.description;
            document.getElementById('edit-supplier-name').value = button.dataset.supplierName;
            document.getElementById('edit-cost-price').value = button.dataset.costPrice;
            document.getElementById('edit-unit-price').value = button.dataset.unitPrice;
            document.getElementById('edit-stock-qty').value = button.dataset.stockQty;
            document.getElementById('edit-threshold-qty').value = button.dataset.thresholdQty;
        });
    });

    document.querySelectorAll('.delete-part-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('delete-part-id').value = button.dataset.partId;
            document.getElementById('delete-part-name').textContent = button.dataset.partName;
        });
    });
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
