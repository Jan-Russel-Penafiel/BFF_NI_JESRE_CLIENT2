<?php
require_once __DIR__ . '/includes/auth_guard.php';

$pageTitle = 'Cashier Department';
$activePage = 'cashier';
$user = current_user();
$taxRate = 0.12;

function official_receipt_number($payment)
{
    $paidAtRaw = $payment['paid_at'] ?? '';
    $paidAt = strtotime((string) $paidAtRaw);
    if ($paidAt === false) {
        $paidAt = time();
    }

    return 'OR-' . date('Ymd', $paidAt) . '-' . str_pad((string) ((int) ($payment['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'process_payment') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
            $amountPaid = (float) ($_POST['amount_paid'] ?? 0);

            if ($orderId <= 0) {
                throw new Exception('Invalid sales order selected.');
            }

            $allowedMethods = ['cash', 'gcash', 'card'];
            if (!in_array($paymentMethod, $allowedMethods, true)) {
                throw new Exception('Invalid payment method selected.');
            }

            $order = db_select_one('SELECT * FROM sales_orders WHERE id = ? AND status = "ready_for_cashier" LIMIT 1', 'i', [$orderId]);
            if (!$order) {
                throw new Exception('Sales order is not ready for cashier payment.');
            }

            $items = db_select(
                'SELECT soi.*, p.part_name, p.stock_qty, p.threshold_qty FROM sales_order_items soi INNER JOIN parts p ON p.id = soi.part_id WHERE soi.sales_order_id = ?',
                'i',
                [$orderId]
            );

            if (empty($items)) {
                throw new Exception('Sales order has no line items.');
            }

            $subtotal = (float) $order['subtotal'];
            $tax = round($subtotal * $taxRate, 2);
            $total = round($subtotal + $tax, 2);

            if ($amountPaid < $total) {
                throw new Exception('Amount paid is lower than total amount due.');
            }

            $change = round($amountPaid - $total, 2);
            $totalCogs = 0;

            db_begin();

            db_exec_only(
                'UPDATE sales_orders SET status = "paid", tax = ?, total = ? WHERE id = ?',
                'ddi',
                [$tax, $total, $orderId]
            );

            db_insert(
                'INSERT INTO payments (sales_order_id, cashier_id, payment_method, amount_paid, change_amount) VALUES (?, ?, ?, ?, ?)',
                'iisdd',
                [$orderId, (int) $user['id'], $paymentMethod, $amountPaid, $change]
            );

            foreach ($items as $item) {
                $currentStock = (int) $item['stock_qty'];
                $qtySold = (int) $item['qty'];
                $newStock = $currentStock - $qtySold;

                if ($newStock < 0) {
                    throw new Exception('Insufficient stock while processing payment for ' . $item['part_name'] . '.');
                }

                db_exec_only('UPDATE parts SET stock_qty = ? WHERE id = ?', 'ii', [$newStock, (int) $item['part_id']]);

                insert_inventory_log(
                    (int) $item['part_id'],
                    'sale',
                    -$qtySold,
                    $newStock,
                    $order['order_number'],
                    'Auto deduct stock upon sale'
                );

                check_and_raise_low_stock((int) $item['part_id'], $order['order_number'], (int) $user['id']);
                $totalCogs += ((float) $item['cost_price'] * $qtySold);
            }

            log_digital(
                'Cashier Department',
                $order['order_number'],
                'Received sales order, computed total tax, processed payment, and issued receipt.',
                (int) $user['id']
            );

            log_digital(
                'Inventory System',
                $order['order_number'],
                'Stored transaction in inventory database and updated sales log in real time.',
                (int) $user['id']
            );

            post_ledger('SALE', $order['order_number'], 'Cash', $total, 0, 'Cash received from POS payment');
            post_ledger('SALE', $order['order_number'], 'Sales Revenue', 0, $subtotal, 'Revenue recognition from sale');
            post_ledger('SALE', $order['order_number'], 'Tax Payable', 0, $tax, 'Output tax from sale');
            post_ledger('SALE', $order['order_number'], 'Cost of Goods Sold', $totalCogs, 0, 'COGS from sold items');
            post_ledger('SALE', $order['order_number'], 'Inventory Asset', 0, $totalCogs, 'Inventory deduction after sale');

            log_digital(
                'Accounting Department',
                $order['order_number'],
                'Recorded transaction in general ledger and stored digital logs for validation.',
                (int) $user['id']
            );

            db_commit();

            set_flash('success', 'Payment processed successfully and receipt issued.');
            redirect('cashier.php');
        }
    }
} catch (Exception $exception) {
    db_rollback();
    set_flash('error', $exception->getMessage());
    redirect('cashier.php');
}

$orders = db_select(
    'SELECT s.*, u.full_name FROM sales_orders s LEFT JOIN users u ON s.created_by = u.id ORDER BY s.created_at DESC'
);

$payments = db_select('SELECT p.*, u.full_name AS cashier_name FROM payments p LEFT JOIN users u ON p.cashier_id = u.id ORDER BY p.paid_at DESC');
$paymentMap = [];
foreach ($payments as $payment) {
    $paymentMap[(int) $payment['sales_order_id']] = $payment;
}

$orderItemsRows = db_select(
    'SELECT soi.*, p.sku, p.part_name FROM sales_order_items soi INNER JOIN parts p ON p.id = soi.part_id ORDER BY soi.id DESC'
);
$orderItemsMap = [];
foreach ($orderItemsRows as $item) {
    $orderItemsMap[(int) $item['sales_order_id']][] = $item;
}

$receiptPayloadMap = [];
foreach ($orders as $order) {
    $orderId = (int) $order['id'];
    if (!isset($paymentMap[$orderId])) {
        continue;
    }

    $receipt = $paymentMap[$orderId];
    $receiptPayloadMap[$orderId] = [
        'orderId' => $orderId,
        'orderNumber' => $order['order_number'],
        'officialReceiptNo' => official_receipt_number($receipt),
        'customerName' => $order['customer_name'],
        'subtotal' => (float) $order['subtotal'],
        'tax' => (float) $order['tax'],
        'total' => (float) $order['total'],
        'amountPaid' => (float) $receipt['amount_paid'],
        'changeAmount' => (float) $receipt['change_amount'],
        'paymentMethod' => strtoupper((string) ($receipt['payment_method'] ?? '')),
        'cashierName' => $receipt['cashier_name'] ?? 'N/A',
        'paidAt' => $receipt['paid_at'] ?? '',
        'items' => array_map(function ($item) {
            return [
                'sku' => $item['sku'],
                'partName' => $item['part_name'],
                'qty' => (int) $item['qty'],
                'unitPrice' => (float) $item['unit_price'],
                'lineTotal' => (float) $item['line_total'],
            ];
        }, $orderItemsMap[$orderId] ?? []),
    ];
}

require_once __DIR__ . '/includes/layout_start.php';
?>
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-navy-900">Cashier Payment Processing</h2>
            <p class="text-sm text-slate-500">Receive sales order, compute tax, process payment, issue receipt, and sync logs in real time.</p>
        </div>
        <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800">Tax Rate: <?php echo e((string) ($taxRate * 100)); ?>%</span>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Sales Orders Queue</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Order No.</th>
                    <th class="px-3 py-2">Customer</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Subtotal</th>
                    <th class="px-3 py-2">Tax</th>
                    <th class="px-3 py-2">Total</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="7" class="px-3 py-4 text-center text-slate-500">No orders yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $statusClass = 'bg-slate-100 text-slate-700';
                        if ($order['status'] === 'ready_for_cashier') {
                            $statusClass = 'bg-blue-100 text-blue-700';
                        }
                        if ($order['status'] === 'paid') {
                            $statusClass = 'bg-emerald-100 text-emerald-700';
                        }
                        if ($order['status'] === 'pending_stock') {
                            $statusClass = 'bg-amber-100 text-amber-700';
                        }

                        $computedTax = round((float) $order['subtotal'] * $taxRate, 2);
                        $computedTotal = round((float) $order['subtotal'] + $computedTax, 2);
                        $hasPayment = isset($paymentMap[(int) $order['id']]);
                        ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2 font-semibold text-navy-900"><?php echo e($order['order_number']); ?></td>
                            <td class="px-3 py-2"><?php echo e($order['customer_name']); ?></td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $statusClass; ?>">
                                    <?php echo e(strtoupper(str_replace('_', ' ', $order['status']))); ?>
                                </span>
                            </td>
                            <td class="px-3 py-2"><?php echo e(format_currency($order['subtotal'])); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($order['status'] === 'paid' ? $order['tax'] : $computedTax)); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($order['status'] === 'paid' ? $order['total'] : $computedTotal)); ?></td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button data-modal-open="view-order-<?php echo e($order['id']); ?>" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">View</button>
                                    <?php if ($order['status'] === 'ready_for_cashier' && !$hasPayment): ?>
                                        <button
                                            data-modal-open="payment-modal"
                                            class="open-payment-btn rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50"
                                            data-order-id="<?php echo e($order['id']); ?>"
                                            data-order-number="<?php echo e($order['order_number']); ?>"
                                            data-subtotal="<?php echo e(number_format((float) $order['subtotal'], 2, '.', '')); ?>"
                                            data-tax="<?php echo e(number_format($computedTax, 2, '.', '')); ?>"
                                            data-total="<?php echo e(number_format($computedTotal, 2, '.', '')); ?>"
                                        >Process Payment</button>
                                    <?php endif; ?>
                                    <?php if ($hasPayment): ?>
                                        <button data-modal-open="receipt-modal-<?php echo e($order['id']); ?>" class="rounded border border-emerald-300 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Receipt</button>
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

<div id="payment-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="payment-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Process Payment</h3>
            <button data-modal-close="payment-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
            <p><span class="font-semibold text-slate-600">Order:</span> <span id="pay-order-number">-</span></p>
            <p><span class="font-semibold text-slate-600">Subtotal:</span> <span id="pay-subtotal">0.00</span></p>
            <p><span class="font-semibold text-slate-600">Tax:</span> <span id="pay-tax">0.00</span></p>
            <p><span class="font-semibold text-slate-600">Total:</span> <span id="pay-total" class="font-semibold text-navy-900">0.00</span></p>
        </div>

        <form method="POST" class="mt-4 space-y-4">
            <input type="hidden" name="action" value="process_payment">
            <input type="hidden" id="pay-order-id" name="order_id">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Payment Method</label>
                <select name="payment_method" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                    <option value="card">Card</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Amount Paid</label>
                <input type="number" step="0.01" min="0" name="amount_paid" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-modal-close="payment-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($orders as $order): ?>
    <div id="view-order-<?php echo e($order['id']); ?>" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
        <div data-modal-overlay="view-order-<?php echo e($order['id']); ?>" class="absolute inset-0 bg-slate-900/60"></div>
        <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-navy-900">Sales Order Detail</h3>
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

    <?php if (isset($paymentMap[(int) $order['id']])): ?>
        <?php $receipt = $paymentMap[(int) $order['id']]; ?>
        <div id="receipt-modal-<?php echo e($order['id']); ?>" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
            <div data-modal-overlay="receipt-modal-<?php echo e($order['id']); ?>" class="absolute inset-0 bg-slate-900/60"></div>
            <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-navy-900">Official Receipt</h3>
                    <button data-modal-close="receipt-modal-<?php echo e($order['id']); ?>" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
                </div>

                <div class="mt-3 space-y-1 text-sm">
                    <p><span class="font-semibold text-slate-600">Official Receipt No:</span> <?php echo e(official_receipt_number($receipt)); ?></p>
                    <p><span class="font-semibold text-slate-600">Order No:</span> <?php echo e($order['order_number']); ?></p>
                    <p><span class="font-semibold text-slate-600">Customer:</span> <?php echo e($order['customer_name']); ?></p>
                    <p><span class="font-semibold text-slate-600">Subtotal:</span> <?php echo e(format_currency($order['subtotal'])); ?></p>
                    <p><span class="font-semibold text-slate-600">Tax:</span> <?php echo e(format_currency($order['tax'])); ?></p>
                    <p><span class="font-semibold text-slate-600">Total:</span> <?php echo e(format_currency($order['total'])); ?></p>
                    <p><span class="font-semibold text-slate-600">Amount Paid:</span> <?php echo e(format_currency($receipt['amount_paid'])); ?></p>
                    <p><span class="font-semibold text-slate-600">Change:</span> <?php echo e(format_currency($receipt['change_amount'])); ?></p>
                    <p><span class="font-semibold text-slate-600">Payment Method:</span> <?php echo e(strtoupper($receipt['payment_method'])); ?></p>
                    <p><span class="font-semibold text-slate-600">Cashier:</span> <?php echo e($receipt['cashier_name'] ?? 'N/A'); ?></p>
                    <p><span class="font-semibold text-slate-600">Paid At:</span> <?php echo e($receipt['paid_at']); ?></p>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="receipt-print-btn rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                        data-order-id="<?php echo e($order['id']); ?>"
                    >Print</button>
                    <button
                        type="button"
                        class="receipt-pdf-btn rounded-md bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                        data-order-id="<?php echo e($order['id']); ?>"
                    >Download PDF</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script id="receipt-payload-map" type="application/json"><?php echo json_encode($receiptPayloadMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script>
    (function () {
        const payloadElement = document.getElementById('receipt-payload-map');
        let receiptPayloadMap = {};

        if (payloadElement) {
            try {
                receiptPayloadMap = JSON.parse(payloadElement.textContent || '{}');
            } catch (error) {
                receiptPayloadMap = {};
            }
        }

        function formatCurrency(value) {
            return 'PHP ' + Number(value || 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function getReceiptData(orderId) {
            return receiptPayloadMap[String(orderId)] || null;
        }

        function printOfficialReceipt(orderId) {
            const receipt = getReceiptData(orderId);
            if (!receipt) {
                alert('Receipt details are unavailable.');
                return;
            }

            const itemRows = (receipt.items || []).map(function (item, index) {
                return '<tr>' +
                    '<td style="padding:6px 4px;border-bottom:1px solid #e2e8f0;">' + (index + 1) + '</td>' +
                    '<td style="padding:6px 4px;border-bottom:1px solid #e2e8f0;">' + escapeHtml(item.sku) + '</td>' +
                    '<td style="padding:6px 4px;border-bottom:1px solid #e2e8f0;">' + escapeHtml(item.partName) + '</td>' +
                    '<td style="padding:6px 4px;border-bottom:1px solid #e2e8f0;text-align:right;">' + escapeHtml(item.qty) + '</td>' +
                    '<td style="padding:6px 4px;border-bottom:1px solid #e2e8f0;text-align:right;">' + escapeHtml(formatCurrency(item.unitPrice)) + '</td>' +
                    '<td style="padding:6px 4px;border-bottom:1px solid #e2e8f0;text-align:right;">' + escapeHtml(formatCurrency(item.lineTotal)) + '</td>' +
                    '</tr>';
            }).join('');

            const popup = window.open('', '_blank', 'width=900,height=900');
            if (!popup) {
                alert('Allow pop-ups to print receipts.');
                return;
            }

            const printableHtml = '<!DOCTYPE html>' +
                '<html><head><meta charset="UTF-8"><title>Official Receipt ' + escapeHtml(receipt.orderNumber) + '</title>' +
                '<style>body{font-family:Arial,sans-serif;color:#0f172a;padding:24px;}h1{margin:0 0 4px;font-size:20px;}h2{margin:0 0 14px;font-size:14px;color:#334155;}table{width:100%;border-collapse:collapse;font-size:12px;}th{padding:6px 4px;text-align:left;border-bottom:2px solid #cbd5e1;background:#f8fafc;} .totals{margin-top:12px;font-size:12px;} .totals p{margin:4px 0;} .sign{margin-top:24px;font-size:11px;color:#475569;}</style>' +
                '</head><body>' +
                '<h1>TOPSPOT Motorcycle Parts Trading</h1>' +
                '<h2>Official Receipt</h2>' +
                '<p><strong>OR No:</strong> ' + escapeHtml(receipt.officialReceiptNo) + '</p>' +
                '<p><strong>Order No:</strong> ' + escapeHtml(receipt.orderNumber) + '</p>' +
                '<p><strong>Customer:</strong> ' + escapeHtml(receipt.customerName) + '</p>' +
                '<p><strong>Cashier:</strong> ' + escapeHtml(receipt.cashierName) + '</p>' +
                '<p><strong>Payment Method:</strong> ' + escapeHtml(receipt.paymentMethod) + '</p>' +
                '<p><strong>Paid At:</strong> ' + escapeHtml(receipt.paidAt) + '</p>' +
                '<table><thead><tr><th>#</th><th>SKU</th><th>Part</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Unit Price</th><th style="text-align:right;">Line Total</th></tr></thead><tbody>' +
                (itemRows || '<tr><td colspan="6" style="padding:8px 4px;color:#64748b;">No item lines found.</td></tr>') +
                '</tbody></table>' +
                '<div class="totals">' +
                '<p><strong>Subtotal:</strong> ' + escapeHtml(formatCurrency(receipt.subtotal)) + '</p>' +
                '<p><strong>Tax:</strong> ' + escapeHtml(formatCurrency(receipt.tax)) + '</p>' +
                '<p><strong>Total:</strong> ' + escapeHtml(formatCurrency(receipt.total)) + '</p>' +
                '<p><strong>Amount Paid:</strong> ' + escapeHtml(formatCurrency(receipt.amountPaid)) + '</p>' +
                '<p><strong>Change:</strong> ' + escapeHtml(formatCurrency(receipt.changeAmount)) + '</p>' +
                '</div>' +
                '<p class="sign">This document serves as the official receipt for this transaction.</p>' +
                '</body></html>';

            popup.document.open();
            popup.document.write(printableHtml);
            popup.document.close();
            popup.focus();
            popup.print();
        }

        function downloadOfficialReceiptPdf(orderId) {
            const receipt = getReceiptData(orderId);
            if (!receipt) {
                alert('Receipt details are unavailable.');
                return;
            }

            if (!window.jspdf || typeof window.jspdf.jsPDF !== 'function') {
                alert('PDF library failed to load. Please refresh and try again.');
                return;
            }

            const jsPDF = window.jspdf.jsPDF;
            const doc = new jsPDF({
                orientation: 'portrait',
                unit: 'mm',
                format: 'a4'
            });

            let y = 14;
            const lineHeight = 5;
            const pageBottom = 285;
            const contentWidth = 182;

            function ensureSpace(extraHeight) {
                if (y + extraHeight > pageBottom) {
                    doc.addPage();
                    y = 14;
                }
            }

            function writeLabelValue(label, value) {
                ensureSpace(lineHeight);
                doc.setFont('helvetica', 'bold');
                doc.text(label, 14, y);
                doc.setFont('helvetica', 'normal');
                doc.text(String(value || ''), 62, y);
                y += lineHeight;
            }

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(14);
            doc.setTextColor(11, 31, 77);
            doc.text('TOPSPOT Motorcycle Parts Trading', 14, y);
            y += 6;

            doc.setFontSize(12);
            doc.text('Official Receipt', 14, y);
            y += 8;

            doc.setTextColor(15, 23, 42);
            doc.setFontSize(10);
            writeLabelValue('OR No:', receipt.officialReceiptNo);
            writeLabelValue('Order No:', receipt.orderNumber);
            writeLabelValue('Customer:', receipt.customerName);
            writeLabelValue('Cashier:', receipt.cashierName);
            writeLabelValue('Payment Method:', receipt.paymentMethod);
            writeLabelValue('Paid At:', receipt.paidAt);

            y += 2;
            ensureSpace(8);
            doc.setFont('helvetica', 'bold');
            doc.text('Items', 14, y);
            y += 5;

            doc.setFont('helvetica', 'normal');
            if (!receipt.items || receipt.items.length === 0) {
                doc.text('No item lines found.', 14, y);
                y += lineHeight;
            } else {
                receipt.items.forEach(function (item, index) {
                    const lineText = (index + 1) + '. ' + String(item.sku || '-') + ' | ' +
                        String(item.partName || '-') + ' | Qty: ' + String(item.qty || 0) +
                        ' | Unit: ' + formatCurrency(item.unitPrice) +
                        ' | Total: ' + formatCurrency(item.lineTotal);

                    const wrapped = doc.splitTextToSize(lineText, contentWidth);
                    ensureSpace(wrapped.length * lineHeight);
                    doc.text(wrapped, 14, y);
                    y += wrapped.length * lineHeight;
                });
            }

            y += 3;
            writeLabelValue('Subtotal:', formatCurrency(receipt.subtotal));
            writeLabelValue('Tax:', formatCurrency(receipt.tax));
            writeLabelValue('Total:', formatCurrency(receipt.total));
            writeLabelValue('Amount Paid:', formatCurrency(receipt.amountPaid));
            writeLabelValue('Change:', formatCurrency(receipt.changeAmount));

            y += 6;
            ensureSpace(lineHeight);
            doc.setFont('helvetica', 'italic');
            doc.setFontSize(9);
            doc.text('This document serves as the official receipt for this transaction.', 14, y);

            const fileSafeOrder = String(receipt.orderNumber || 'receipt').replace(/[^a-z0-9\-]+/gi, '_');
            doc.save('official-receipt-' + fileSafeOrder + '.pdf');
        }

        document.querySelectorAll('.open-payment-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('pay-order-id').value = button.dataset.orderId;
                document.getElementById('pay-order-number').textContent = button.dataset.orderNumber;
                document.getElementById('pay-subtotal').textContent = 'PHP ' + Number(button.dataset.subtotal).toFixed(2);
                document.getElementById('pay-tax').textContent = 'PHP ' + Number(button.dataset.tax).toFixed(2);
                document.getElementById('pay-total').textContent = 'PHP ' + Number(button.dataset.total).toFixed(2);
            });
        });

        document.querySelectorAll('.receipt-print-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                printOfficialReceipt(button.dataset.orderId);
            });
        });

        document.querySelectorAll('.receipt-pdf-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                downloadOfficialReceiptPdf(button.dataset.orderId);
            });
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
