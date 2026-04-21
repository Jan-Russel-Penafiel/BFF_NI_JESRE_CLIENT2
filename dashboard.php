<?php
require_once __DIR__ . '/includes/auth_guard.php';

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

$totalSalesToday = sum_value('SELECT COALESCE(SUM(total), 0) FROM sales_orders WHERE status = "paid" AND DATE(created_at) = CURDATE()');
$pendingStockOrders = (int) sum_value('SELECT COUNT(*) FROM sales_orders WHERE status = "pending_stock"');
$readyForCashier = (int) sum_value('SELECT COUNT(*) FROM sales_orders WHERE status = "ready_for_cashier"');
$lowStockCount = (int) sum_value('SELECT COUNT(*) FROM parts WHERE stock_qty <= threshold_qty');
$pendingSupplierOrders = (int) sum_value('SELECT COUNT(*) FROM purchase_orders WHERE status = "requested"');
$pendingAuditLogs = (int) sum_value('SELECT COUNT(*) FROM digital_logs WHERE is_validated = 0');

$latestLogs = db_select(
    'SELECT d.*, u.full_name FROM digital_logs d LEFT JOIN users u ON d.created_by = u.id ORDER BY d.created_at DESC LIMIT 8'
);

require_once __DIR__ . '/includes/layout_start.php';
?>
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wider text-slate-500">Today Sales</p>
        <p class="mt-2 text-2xl font-semibold text-navy-900"><?php echo e(format_currency($totalSalesToday)); ?></p>
        <p class="mt-1 text-xs text-slate-500">Paid transactions today</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wider text-slate-500">Orders Ready for Cashier</p>
        <p class="mt-2 text-2xl font-semibold text-navy-900"><?php echo e($readyForCashier); ?></p>
        <p class="mt-1 text-xs text-slate-500">Waiting for payment processing</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wider text-slate-500">Pending Stock Orders</p>
        <p class="mt-2 text-2xl font-semibold text-amber-700"><?php echo e($pendingStockOrders); ?></p>
        <p class="mt-1 text-xs text-slate-500">Auto-routed to purchasing</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wider text-slate-500">Low Inventory Alerts</p>
        <p class="mt-2 text-2xl font-semibold text-red-700"><?php echo e($lowStockCount); ?></p>
        <p class="mt-1 text-xs text-slate-500">Parts below threshold</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wider text-slate-500">Pending Supplier Orders</p>
        <p class="mt-2 text-2xl font-semibold text-navy-900"><?php echo e($pendingSupplierOrders); ?></p>
        <p class="mt-1 text-xs text-slate-500">For purchasing follow-up</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wider text-slate-500">Pending Audit Logs</p>
        <p class="mt-2 text-2xl font-semibold text-navy-900"><?php echo e($pendingAuditLogs); ?></p>
        <p class="mt-1 text-xs text-slate-500">Needs accounting validation</p>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="text-lg font-semibold text-navy-900">Process Flow Snapshot</h2>
    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
            <p class="font-semibold text-navy-900">1. Sales Department</p>
            <p class="mt-1 text-slate-600">Assist customer, input order to POS, check stock availability.</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
            <p class="font-semibold text-navy-900">2. Inventory Check</p>
            <p class="mt-1 text-slate-600">Run live inventory query and generate digital sales order.</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
            <p class="font-semibold text-navy-900">3. Cashier Department</p>
            <p class="mt-1 text-slate-600">Compute tax, process payment, issue receipt, update real-time sales log.</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
            <p class="font-semibold text-navy-900">4. Inventory / Purchasing / Accounting</p>
            <p class="mt-1 text-slate-600">Auto-deduct stock, reorder if low, post to ledger, audit logs, generate reports.</p>
        </div>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="text-lg font-semibold text-navy-900">Recent Digital Logs</h2>
    <div class="mt-4 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Module</th>
                    <th class="px-3 py-2">Reference</th>
                    <th class="px-3 py-2">Message</th>
                    <th class="px-3 py-2">Created By</th>
                    <th class="px-3 py-2">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($latestLogs)): ?>
                    <tr>
                        <td colspan="5" class="px-3 py-4 text-center text-slate-500">No logs found yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($latestLogs as $log): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2"><?php echo e($log['module_name']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['reference_no'] ?? '-'); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['log_message']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['full_name'] ?? 'System'); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
