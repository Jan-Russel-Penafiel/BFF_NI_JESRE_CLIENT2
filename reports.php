<?php
require_once __DIR__ . '/includes/auth_guard.php';

$pageTitle = 'Financial Reporting';
$activePage = 'reports';

$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d');
}

if ($startDate > $endDate) {
    $tmp = $startDate;
    $startDate = $endDate;
    $endDate = $tmp;
}

$salesRevenue = sum_value(
    'SELECT COALESCE(SUM(subtotal), 0) FROM sales_orders WHERE status = "paid" AND DATE(created_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);
$taxCollected = sum_value(
    'SELECT COALESCE(SUM(tax), 0) FROM sales_orders WHERE status = "paid" AND DATE(created_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);
$cogs = sum_value(
    'SELECT COALESCE(SUM(debit), 0) FROM general_ledger WHERE account_title = "Cost of Goods Sold" AND DATE(posted_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);

$grossProfit = $salesRevenue - $cogs;
$operatingExpenses = sum_value(
    'SELECT COALESCE(SUM(debit - credit), 0) FROM general_ledger WHERE account_title LIKE "%Expense%" AND DATE(posted_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);
$netIncome = $grossProfit - $operatingExpenses;

$cashAsset = sum_value(
    'SELECT COALESCE(SUM(debit - credit), 0) FROM general_ledger WHERE account_title = "Cash" AND DATE(posted_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);
$inventoryAsset = sum_value('SELECT COALESCE(SUM(stock_qty * cost_price), 0) FROM parts');
$totalAssets = $cashAsset + $inventoryAsset;

$accountsPayable = sum_value(
    'SELECT COALESCE(SUM(credit - debit), 0) FROM general_ledger WHERE account_title = "Accounts Payable" AND DATE(posted_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);
$taxPayable = sum_value(
    'SELECT COALESCE(SUM(credit - debit), 0) FROM general_ledger WHERE account_title = "Tax Payable" AND DATE(posted_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);
$totalLiabilities = $accountsPayable + $taxPayable;
$totalEquity = $totalAssets - $totalLiabilities;

$postingSummary = db_select(
    'SELECT txn_type, COUNT(*) AS total_entries, COALESCE(SUM(debit), 0) AS debit_total, COALESCE(SUM(credit), 0) AS credit_total
     FROM general_ledger
     WHERE DATE(posted_at) BETWEEN ? AND ?
     GROUP BY txn_type
     ORDER BY txn_type ASC',
    'ss',
    [$startDate, $endDate]
);

$recentPostings = db_select(
    'SELECT * FROM general_ledger WHERE DATE(posted_at) BETWEEN ? AND ? ORDER BY posted_at DESC LIMIT 15',
    'ss',
    [$startDate, $endDate]
);

require_once __DIR__ . '/includes/layout_start.php';
?>
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="text-lg font-semibold text-navy-900">Automated Posting and Financial Reports</h2>
    <p class="text-sm text-slate-500">Generate Income Statement and Balance Sheet from centralized transaction data.</p>

    <form method="GET" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 md:grid-cols-4">
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500">Start Date</label>
            <input type="date" name="start_date" value="<?php echo e($startDate); ?>" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500">End Date</label>
            <input type="date" name="end_date" value="<?php echo e($endDate); ?>" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="md:col-span-2 flex items-end gap-2">
            <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Apply Date Range</button>
            <a href="reports.php" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Reset</a>
            <button type="button" id="print-report-pdf" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Print PDF</button>
        </div>
    </form>
</div>

<div class="grid gap-4 xl:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="text-base font-semibold text-navy-900">Income Statement</h3>
        <p class="text-xs text-slate-500">Period: <?php echo e($startDate); ?> to <?php echo e($endDate); ?></p>

        <div class="mt-4 space-y-2 text-sm">
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>Sales Revenue</span>
                <span class="font-semibold text-navy-900"><?php echo e(format_currency($salesRevenue)); ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>Cost of Goods Sold</span>
                <span class="font-semibold text-red-700">(<?php echo e(format_currency($cogs)); ?>)</span>
            </div>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>Gross Profit</span>
                <span class="font-semibold text-navy-900"><?php echo e(format_currency($grossProfit)); ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>Operating Expenses</span>
                <span class="font-semibold text-red-700">(<?php echo e(format_currency($operatingExpenses)); ?>)</span>
            </div>
            <div class="flex justify-between rounded-md bg-navy-50 px-2 py-2">
                <span class="font-semibold text-navy-900">Net Income</span>
                <span class="font-semibold text-navy-900"><?php echo e(format_currency($netIncome)); ?></span>
            </div>
            <div class="flex justify-between border-t border-slate-200 pt-2">
                <span class="text-slate-600">Tax Collected</span>
                <span class="font-semibold text-slate-700"><?php echo e(format_currency($taxCollected)); ?></span>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="text-base font-semibold text-navy-900">Balance Sheet</h3>
        <p class="text-xs text-slate-500">As of <?php echo e($endDate); ?></p>

        <div class="mt-4 space-y-2 text-sm">
            <p class="font-semibold text-slate-700">Assets</p>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>Cash</span>
                <span class="font-semibold text-navy-900"><?php echo e(format_currency($cashAsset)); ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>Inventory Asset</span>
                <span class="font-semibold text-navy-900"><?php echo e(format_currency($inventoryAsset)); ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span class="font-semibold">Total Assets</span>
                <span class="font-semibold text-navy-900"><?php echo e(format_currency($totalAssets)); ?></span>
            </div>

            <p class="pt-2 font-semibold text-slate-700">Liabilities</p>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>Accounts Payable</span>
                <span class="font-semibold text-slate-700"><?php echo e(format_currency($accountsPayable)); ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>Tax Payable</span>
                <span class="font-semibold text-slate-700"><?php echo e(format_currency($taxPayable)); ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span class="font-semibold">Total Liabilities</span>
                <span class="font-semibold text-slate-700"><?php echo e(format_currency($totalLiabilities)); ?></span>
            </div>

            <div class="mt-2 flex justify-between rounded-md bg-navy-50 px-2 py-2">
                <span class="font-semibold text-navy-900">Equity</span>
                <span class="font-semibold text-navy-900"><?php echo e(format_currency($totalEquity)); ?></span>
            </div>
        </div>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Automated Posting Summary</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Transaction Type</th>
                    <th class="px-3 py-2">Entries</th>
                    <th class="px-3 py-2">Debit Total</th>
                    <th class="px-3 py-2">Credit Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($postingSummary)): ?>
                    <tr>
                        <td colspan="4" class="px-3 py-4 text-center text-slate-500">No postings in selected period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($postingSummary as $item): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2 font-semibold text-navy-900"><?php echo e($item['txn_type']); ?></td>
                            <td class="px-3 py-2"><?php echo e($item['total_entries']); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($item['debit_total'])); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($item['credit_total'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Recent Ledger Posting Details</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Date</th>
                    <th class="px-3 py-2">Reference</th>
                    <th class="px-3 py-2">Account</th>
                    <th class="px-3 py-2">Debit</th>
                    <th class="px-3 py-2">Credit</th>
                    <th class="px-3 py-2">Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentPostings)): ?>
                    <tr>
                        <td colspan="6" class="px-3 py-4 text-center text-slate-500">No recent postings found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentPostings as $entry): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2"><?php echo e($entry['posted_at']); ?></td>
                            <td class="px-3 py-2"><?php echo e($entry['reference_no'] ?: '-'); ?></td>
                            <td class="px-3 py-2"><?php echo e($entry['account_title']); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($entry['debit'])); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($entry['credit'])); ?></td>
                            <td class="px-3 py-2"><?php echo e($entry['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
    (function () {
        const printButton = document.getElementById('print-report-pdf');
        if (!printButton) {
            return;
        }

        const startDate = <?php echo json_encode($startDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const endDate = <?php echo json_encode($endDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const summaryValues = {
            salesRevenue: Number(<?php echo json_encode((float) $salesRevenue); ?>),
            cogs: Number(<?php echo json_encode((float) $cogs); ?>),
            grossProfit: Number(<?php echo json_encode((float) $grossProfit); ?>),
            operatingExpenses: Number(<?php echo json_encode((float) $operatingExpenses); ?>),
            netIncome: Number(<?php echo json_encode((float) $netIncome); ?>),
            taxCollected: Number(<?php echo json_encode((float) $taxCollected); ?>),
            cashAsset: Number(<?php echo json_encode((float) $cashAsset); ?>),
            inventoryAsset: Number(<?php echo json_encode((float) $inventoryAsset); ?>),
            totalAssets: Number(<?php echo json_encode((float) $totalAssets); ?>),
            accountsPayable: Number(<?php echo json_encode((float) $accountsPayable); ?>),
            taxPayable: Number(<?php echo json_encode((float) $taxPayable); ?>),
            totalLiabilities: Number(<?php echo json_encode((float) $totalLiabilities); ?>),
            totalEquity: Number(<?php echo json_encode((float) $totalEquity); ?>)
        };

        const postingSummaryRows = <?php echo json_encode($postingSummary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || [];
        const recentPostingRows = <?php echo json_encode($recentPostings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || [];

        function formatCurrency(value) {
            return 'PHP ' + Number(value || 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        printButton.addEventListener('click', function () {
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

            if (typeof doc.autoTable !== 'function') {
                alert('PDF table plugin failed to load. Please refresh and try again.');
                return;
            }

            doc.setFontSize(16);
            doc.setTextColor(11, 31, 77);
            doc.text('Automated Posting and Financial Reports', 14, 15);

            doc.setFontSize(10);
            doc.setTextColor(71, 85, 105);
            doc.text('Period: ' + startDate + ' to ' + endDate, 14, 21);

            doc.autoTable({
                startY: 26,
                head: [['Income Statement', 'Amount']],
                body: [
                    ['Sales Revenue', formatCurrency(summaryValues.salesRevenue)],
                    ['Cost of Goods Sold', '(' + formatCurrency(summaryValues.cogs) + ')'],
                    ['Gross Profit', formatCurrency(summaryValues.grossProfit)],
                    ['Operating Expenses', '(' + formatCurrency(summaryValues.operatingExpenses) + ')'],
                    ['Net Income', formatCurrency(summaryValues.netIncome)],
                    ['Tax Collected', formatCurrency(summaryValues.taxCollected)]
                ],
                theme: 'grid',
                headStyles: { fillColor: [16, 44, 91] },
                styles: { fontSize: 9 }
            });

            doc.autoTable({
                startY: doc.lastAutoTable.finalY + 6,
                head: [['Balance Sheet', 'Amount']],
                body: [
                    ['Cash', formatCurrency(summaryValues.cashAsset)],
                    ['Inventory Asset', formatCurrency(summaryValues.inventoryAsset)],
                    ['Total Assets', formatCurrency(summaryValues.totalAssets)],
                    ['Accounts Payable', formatCurrency(summaryValues.accountsPayable)],
                    ['Tax Payable', formatCurrency(summaryValues.taxPayable)],
                    ['Total Liabilities', formatCurrency(summaryValues.totalLiabilities)],
                    ['Equity', formatCurrency(summaryValues.totalEquity)]
                ],
                theme: 'grid',
                headStyles: { fillColor: [16, 44, 91] },
                styles: { fontSize: 9 }
            });

            const postingBody = postingSummaryRows.length
                ? postingSummaryRows.map(function (item) {
                    return [
                        String(item.txn_type || '-'),
                        String(item.total_entries || '0'),
                        formatCurrency(item.debit_total),
                        formatCurrency(item.credit_total)
                    ];
                })
                : [['No postings in selected period.', '', '', '']];

            doc.autoTable({
                startY: doc.lastAutoTable.finalY + 6,
                head: [['Automated Posting Summary', 'Entries', 'Debit Total', 'Credit Total']],
                body: postingBody,
                theme: 'grid',
                headStyles: { fillColor: [16, 44, 91] },
                styles: { fontSize: 8 }
            });

            const recentBody = recentPostingRows.length
                ? recentPostingRows.map(function (entry) {
                    return [
                        String(entry.posted_at || '-'),
                        String(entry.reference_no || '-'),
                        String(entry.account_title || '-'),
                        formatCurrency(entry.debit),
                        formatCurrency(entry.credit),
                        String(entry.description || '-')
                    ];
                })
                : [['No recent postings found.', '', '', '', '', '']];

            doc.autoTable({
                startY: doc.lastAutoTable.finalY + 6,
                head: [['Date', 'Reference', 'Account', 'Debit', 'Credit', 'Description']],
                body: recentBody,
                theme: 'grid',
                headStyles: { fillColor: [16, 44, 91] },
                styles: { fontSize: 7 },
                columnStyles: {
                    5: { cellWidth: 45 }
                }
            });

            doc.save('financial-report-' + startDate + '-to-' + endDate + '.pdf');
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
