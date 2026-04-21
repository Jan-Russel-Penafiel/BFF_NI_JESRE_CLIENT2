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

$pdfReportData = [
    'period' => [
        'start' => $startDate,
        'end' => $endDate,
    ],
    'incomeStatement' => [
        'salesRevenue' => (float) $salesRevenue,
        'cogs' => (float) $cogs,
        'grossProfit' => (float) $grossProfit,
        'operatingExpenses' => (float) $operatingExpenses,
        'netIncome' => (float) $netIncome,
        'taxCollected' => (float) $taxCollected,
    ],
    'balanceSheet' => [
        'cashAsset' => (float) $cashAsset,
        'inventoryAsset' => (float) $inventoryAsset,
        'totalAssets' => (float) $totalAssets,
        'accountsPayable' => (float) $accountsPayable,
        'taxPayable' => (float) $taxPayable,
        'totalLiabilities' => (float) $totalLiabilities,
        'totalEquity' => (float) $totalEquity,
    ],
    'postingSummary' => $postingSummary,
];

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
            <button type="button" id="print-report-pdf" class="rounded-md border border-navy-900 px-4 py-2 text-sm font-semibold text-navy-900 hover:bg-navy-50">Print PDF</button>
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
<script>
    (function () {
        const printButton = document.getElementById('print-report-pdf');
        if (!printButton) {
            return;
        }

        const reportData = <?php echo json_encode($pdfReportData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function formatMoney(value) {
            return 'PHP ' + Number(value || 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function ensurePage(doc, currentY) {
            if (currentY <= 780) {
                return currentY;
            }

            doc.addPage();
            return 48;
        }

        printButton.addEventListener('click', function () {
            if (!window.jspdf || !window.jspdf.jsPDF) {
                window.alert('Unable to load jsPDF. Please check internet connection and try again.');
                return;
            }

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ unit: 'pt', format: 'a4' });
            const left = 42;
            const right = 553;
            let y = 48;

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(16);
            doc.setTextColor(11, 31, 77);
            doc.text('TOPSPOT Financial Report', left, y);

            y += 20;
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10);
            doc.setTextColor(71, 85, 105);
            doc.text('Period: ' + reportData.period.start + ' to ' + reportData.period.end, left, y);

            y += 28;
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(13);
            doc.setTextColor(11, 31, 77);
            doc.text('Income Statement', left, y);

            y += 18;
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(11);
            doc.setTextColor(15, 23, 42);

            const incomeRows = [
                ['Sales Revenue', formatMoney(reportData.incomeStatement.salesRevenue)],
                ['Cost of Goods Sold', '(' + formatMoney(reportData.incomeStatement.cogs) + ')'],
                ['Gross Profit', formatMoney(reportData.incomeStatement.grossProfit)],
                ['Operating Expenses', '(' + formatMoney(reportData.incomeStatement.operatingExpenses) + ')'],
                ['Net Income', formatMoney(reportData.incomeStatement.netIncome)],
                ['Tax Collected', formatMoney(reportData.incomeStatement.taxCollected)]
            ];

            incomeRows.forEach(function (row, index) {
                y = ensurePage(doc, y);
                doc.setFont('helvetica', index === 4 ? 'bold' : 'normal');
                doc.text(row[0], left, y);
                doc.text(row[1], right, y, { align: 'right' });
                y += 18;
            });

            y += 10;
            y = ensurePage(doc, y);
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(13);
            doc.setTextColor(11, 31, 77);
            doc.text('Balance Sheet', left, y);

            y += 18;
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(11);
            doc.setTextColor(15, 23, 42);

            const balanceRows = [
                ['Cash', formatMoney(reportData.balanceSheet.cashAsset)],
                ['Inventory Asset', formatMoney(reportData.balanceSheet.inventoryAsset)],
                ['Total Assets', formatMoney(reportData.balanceSheet.totalAssets)],
                ['Accounts Payable', formatMoney(reportData.balanceSheet.accountsPayable)],
                ['Tax Payable', formatMoney(reportData.balanceSheet.taxPayable)],
                ['Total Liabilities', formatMoney(reportData.balanceSheet.totalLiabilities)],
                ['Equity', formatMoney(reportData.balanceSheet.totalEquity)]
            ];

            balanceRows.forEach(function (row, index) {
                y = ensurePage(doc, y);
                doc.setFont('helvetica', index === 2 || index === 5 || index === 6 ? 'bold' : 'normal');
                doc.text(row[0], left, y);
                doc.text(row[1], right, y, { align: 'right' });
                y += 18;
            });

            y += 10;
            y = ensurePage(doc, y);
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(13);
            doc.setTextColor(11, 31, 77);
            doc.text('Posting Summary', left, y);

            y += 18;
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10);
            doc.setTextColor(15, 23, 42);

            if (!Array.isArray(reportData.postingSummary) || reportData.postingSummary.length === 0) {
                y = ensurePage(doc, y);
                doc.text('No postings in selected period.', left, y);
            } else {
                reportData.postingSummary.forEach(function (row) {
                    y = ensurePage(doc, y);
                    const line = String(row.txn_type) + ': Entries ' + String(row.total_entries) + ', Debit ' + formatMoney(row.debit_total) + ', Credit ' + formatMoney(row.credit_total);
                    doc.text(line, left, y);
                    y += 15;
                });
            }

            const filename = 'TOPSPOT_Financial_Report_' + reportData.period.start + '_to_' + reportData.period.end + '.pdf';
            doc.save(filename);
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
