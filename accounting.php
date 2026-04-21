<?php
require_once __DIR__ . '/includes/auth_guard.php';

$pageTitle = 'Accounting Department';
$activePage = 'accounting';
$user = current_user();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_ledger_entry') {
            $txnType = trim($_POST['txn_type'] ?? 'MANUAL');
            $referenceNo = trim($_POST['reference_no'] ?? '');
            $accountTitle = trim($_POST['account_title'] ?? '');
            $debit = (float) ($_POST['debit'] ?? 0);
            $credit = (float) ($_POST['credit'] ?? 0);
            $description = trim($_POST['description'] ?? '');

            if ($accountTitle === '') {
                throw new Exception('Account title is required.');
            }

            if ($debit <= 0 && $credit <= 0) {
                throw new Exception('Either debit or credit must be greater than zero.');
            }

            db_insert(
                'INSERT INTO general_ledger (txn_type, reference_no, account_title, debit, credit, description) VALUES (?, ?, ?, ?, ?, ?)',
                'sssdds',
                [$txnType, $referenceNo, $accountTitle, $debit, $credit, $description]
            );

            log_digital('Accounting Department', $referenceNo, 'Manual ledger entry recorded.', (int) $user['id']);
            set_flash('success', 'General ledger entry created successfully.');
            redirect('accounting.php');
        }

        if ($action === 'edit_ledger_entry') {
            $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
            $txnType = trim($_POST['txn_type'] ?? 'MANUAL');
            $referenceNo = trim($_POST['reference_no'] ?? '');
            $accountTitle = trim($_POST['account_title'] ?? '');
            $debit = (float) ($_POST['debit'] ?? 0);
            $credit = (float) ($_POST['credit'] ?? 0);
            $description = trim($_POST['description'] ?? '');

            if ($ledgerId <= 0) {
                throw new Exception('Invalid ledger entry selected for update.');
            }

            db_exec_only(
                'UPDATE general_ledger SET txn_type = ?, reference_no = ?, account_title = ?, debit = ?, credit = ?, description = ? WHERE id = ?',
                'sssddsi',
                [$txnType, $referenceNo, $accountTitle, $debit, $credit, $description, $ledgerId]
            );

            set_flash('success', 'Ledger entry updated successfully.');
            redirect('accounting.php');
        }

        if ($action === 'delete_ledger_entry') {
            $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
            if ($ledgerId <= 0) {
                throw new Exception('Invalid ledger entry selected for deletion.');
            }

            db_exec_only('DELETE FROM general_ledger WHERE id = ?', 'i', [$ledgerId]);
            set_flash('success', 'Ledger entry deleted successfully.');
            redirect('accounting.php');
        }

        if ($action === 'validate_log') {
            $logId = (int) ($_POST['log_id'] ?? 0);
            if ($logId <= 0) {
                throw new Exception('Invalid log selected for validation.');
            }

            db_exec_only(
                'UPDATE digital_logs SET is_validated = 1, validated_at = NOW(), validated_by = ? WHERE id = ?',
                'ii',
                [(int) $user['id'], $logId]
            );

            set_flash('success', 'Digital log validated successfully.');
            redirect('accounting.php');
        }

        if ($action === 'validate_ledger') {
            $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
            if ($ledgerId <= 0) {
                throw new Exception('Invalid ledger entry selected for validation.');
            }

            db_exec_only(
                'UPDATE general_ledger SET is_validated = 1, validated_by = ? WHERE id = ?',
                'ii',
                [(int) $user['id'], $ledgerId]
            );

            set_flash('success', 'Ledger posting validated successfully.');
            redirect('accounting.php');
        }
    }
} catch (Exception $exception) {
    set_flash('error', $exception->getMessage());
    redirect('accounting.php');
}

$ledgerEntries = db_select(
    'SELECT gl.*, u.full_name AS validated_by_name
     FROM general_ledger gl
     LEFT JOIN users u ON u.id = gl.validated_by
     ORDER BY gl.posted_at DESC'
);

$digitalLogs = db_select(
    'SELECT d.*, u.full_name AS created_by_name, uv.full_name AS validated_by_name
     FROM digital_logs d
     LEFT JOIN users u ON u.id = d.created_by
     LEFT JOIN users uv ON uv.id = d.validated_by
     ORDER BY d.created_at DESC'
);

require_once __DIR__ . '/includes/layout_start.php';
?>
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-navy-900">General Ledger and Audit Validation</h2>
            <p class="text-sm text-slate-500">Record transactions, store digital logs, and validate posting entries.</p>
        </div>
        <button data-modal-open="create-ledger-modal" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">
            Create Ledger Entry
        </button>
    </div>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">General Ledger Entries</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Date</th>
                    <th class="px-3 py-2">Type</th>
                    <th class="px-3 py-2">Reference</th>
                    <th class="px-3 py-2">Account</th>
                    <th class="px-3 py-2">Debit</th>
                    <th class="px-3 py-2">Credit</th>
                    <th class="px-3 py-2">Validation</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledgerEntries)): ?>
                    <tr>
                        <td colspan="8" class="px-3 py-4 text-center text-slate-500">No ledger entries found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ledgerEntries as $entry): ?>
                        <?php $validated = (int) $entry['is_validated'] === 1; ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2"><?php echo e($entry['posted_at']); ?></td>
                            <td class="px-3 py-2"><?php echo e($entry['txn_type']); ?></td>
                            <td class="px-3 py-2"><?php echo e($entry['reference_no'] ?: '-'); ?></td>
                            <td class="px-3 py-2"><?php echo e($entry['account_title']); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($entry['debit'])); ?></td>
                            <td class="px-3 py-2"><?php echo e(format_currency($entry['credit'])); ?></td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $validated ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                    <?php echo $validated ? 'VALIDATED' : 'PENDING'; ?>
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button data-modal-open="view-ledger-<?php echo e($entry['id']); ?>" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">View</button>
                                    <button
                                        data-modal-open="edit-ledger-modal"
                                        class="edit-ledger-btn rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50"
                                        data-ledger-id="<?php echo e($entry['id']); ?>"
                                        data-txn-type="<?php echo e($entry['txn_type']); ?>"
                                        data-reference-no="<?php echo e($entry['reference_no']); ?>"
                                        data-account-title="<?php echo e($entry['account_title']); ?>"
                                        data-debit="<?php echo e($entry['debit']); ?>"
                                        data-credit="<?php echo e($entry['credit']); ?>"
                                        data-description="<?php echo e($entry['description']); ?>"
                                    >Edit</button>
                                    <button
                                        data-modal-open="delete-ledger-modal"
                                        class="delete-ledger-btn rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                                        data-ledger-id="<?php echo e($entry['id']); ?>"
                                        data-reference-no="<?php echo e($entry['reference_no']); ?>"
                                    >Delete</button>
                                    <?php if (!$validated): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="validate_ledger">
                                            <input type="hidden" name="ledger_id" value="<?php echo e($entry['id']); ?>">
                                            <button type="submit" class="rounded border border-emerald-300 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Validate</button>
                                        </form>
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

<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="text-base font-semibold text-navy-900">Digital Logs for Validation and Audit</h3>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Date</th>
                    <th class="px-3 py-2">Module</th>
                    <th class="px-3 py-2">Reference</th>
                    <th class="px-3 py-2">Message</th>
                    <th class="px-3 py-2">Created By</th>
                    <th class="px-3 py-2">Validation</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($digitalLogs)): ?>
                    <tr>
                        <td colspan="7" class="px-3 py-4 text-center text-slate-500">No digital logs found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($digitalLogs as $log): ?>
                        <?php $validated = (int) $log['is_validated'] === 1; ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2"><?php echo e($log['created_at']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['module_name']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['reference_no'] ?: '-'); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['log_message']); ?></td>
                            <td class="px-3 py-2"><?php echo e($log['created_by_name'] ?? 'System'); ?></td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $validated ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                    <?php echo $validated ? 'VALIDATED' : 'PENDING'; ?>
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button data-modal-open="view-log-<?php echo e($log['id']); ?>" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">View</button>
                                    <?php if (!$validated): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="validate_log">
                                            <input type="hidden" name="log_id" value="<?php echo e($log['id']); ?>">
                                            <button type="submit" class="rounded border border-emerald-300 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Validate</button>
                                        </form>
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

<div id="create-ledger-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="create-ledger-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Create Ledger Entry</h3>
            <button data-modal-close="create-ledger-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <form method="POST" class="mt-4 grid gap-3 md:grid-cols-2">
            <input type="hidden" name="action" value="create_ledger_entry">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Transaction Type</label>
                <input type="text" name="txn_type" value="MANUAL" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Reference No.</label>
                <input type="text" name="reference_no" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Account Title</label>
                <input type="text" name="account_title" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Debit</label>
                <input type="number" step="0.01" min="0" name="debit" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="0">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Credit</label>
                <input type="number" step="0.01" min="0" name="credit" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="0">
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Description</label>
                <textarea name="description" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <div class="md:col-span-2 flex justify-end gap-2">
                <button type="button" data-modal-close="create-ledger-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Create</button>
            </div>
        </form>
    </div>
</div>

<div id="edit-ledger-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="edit-ledger-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-navy-900">Edit Ledger Entry</h3>
            <button data-modal-close="edit-ledger-modal" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
        </div>

        <form method="POST" class="mt-4 grid gap-3 md:grid-cols-2">
            <input type="hidden" name="action" value="edit_ledger_entry">
            <input type="hidden" id="edit-ledger-id" name="ledger_id">

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Transaction Type</label>
                <input type="text" id="edit-txn-type" name="txn_type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Reference No.</label>
                <input type="text" id="edit-reference-no" name="reference_no" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Account Title</label>
                <input type="text" id="edit-account-title" name="account_title" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Debit</label>
                <input type="number" step="0.01" min="0" id="edit-debit" name="debit" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="0">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Credit</label>
                <input type="number" step="0.01" min="0" id="edit-credit" name="credit" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="0">
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Description</label>
                <textarea id="edit-description" name="description" rows="2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <div class="md:col-span-2 flex justify-end gap-2">
                <button type="button" data-modal-close="edit-ledger-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
                <button type="submit" class="rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">Update</button>
            </div>
        </form>
    </div>
</div>

<div id="delete-ledger-modal" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
    <div data-modal-overlay="delete-ledger-modal" class="absolute inset-0 bg-slate-900/60"></div>
    <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
        <h3 class="text-lg font-semibold text-red-700">Delete Ledger Entry</h3>
        <p class="mt-2 text-sm text-slate-600">Delete ledger reference <span id="delete-reference-no" class="font-semibold"></span>?</p>

        <form method="POST" class="mt-4 flex justify-end gap-2">
            <input type="hidden" name="action" value="delete_ledger_entry">
            <input type="hidden" id="delete-ledger-id" name="ledger_id">
            <button type="button" data-modal-close="delete-ledger-modal" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
            <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600">Delete</button>
        </form>
    </div>
</div>

<?php foreach ($ledgerEntries as $entry): ?>
    <div id="view-ledger-<?php echo e($entry['id']); ?>" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
        <div data-modal-overlay="view-ledger-<?php echo e($entry['id']); ?>" class="absolute inset-0 bg-slate-900/60"></div>
        <div class="relative z-10 w-full max-w-xl rounded-xl bg-white p-5 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-navy-900">Ledger Entry Detail</h3>
                <button data-modal-close="view-ledger-<?php echo e($entry['id']); ?>" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
            </div>
            <div class="mt-3 space-y-1 text-sm">
                <p><span class="font-semibold text-slate-600">Transaction Type:</span> <?php echo e($entry['txn_type']); ?></p>
                <p><span class="font-semibold text-slate-600">Reference:</span> <?php echo e($entry['reference_no'] ?: '-'); ?></p>
                <p><span class="font-semibold text-slate-600">Account Title:</span> <?php echo e($entry['account_title']); ?></p>
                <p><span class="font-semibold text-slate-600">Debit:</span> <?php echo e(format_currency($entry['debit'])); ?></p>
                <p><span class="font-semibold text-slate-600">Credit:</span> <?php echo e(format_currency($entry['credit'])); ?></p>
                <p><span class="font-semibold text-slate-600">Description:</span> <?php echo e($entry['description'] ?: '-'); ?></p>
                <p><span class="font-semibold text-slate-600">Validated:</span> <?php echo (int) $entry['is_validated'] === 1 ? 'Yes' : 'No'; ?></p>
                <p><span class="font-semibold text-slate-600">Validated By:</span> <?php echo e($entry['validated_by_name'] ?: '-'); ?></p>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($digitalLogs as $log): ?>
    <div id="view-log-<?php echo e($log['id']); ?>" data-modal-box class="fixed inset-0 z-50 hidden items-center justify-center px-3">
        <div data-modal-overlay="view-log-<?php echo e($log['id']); ?>" class="absolute inset-0 bg-slate-900/60"></div>
        <div class="relative z-10 w-full max-w-xl rounded-xl bg-white p-5 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-navy-900">Digital Log Detail</h3>
                <button data-modal-close="view-log-<?php echo e($log['id']); ?>" class="rounded bg-slate-100 px-2 py-1 text-sm">Close</button>
            </div>
            <div class="mt-3 space-y-1 text-sm">
                <p><span class="font-semibold text-slate-600">Module:</span> <?php echo e($log['module_name']); ?></p>
                <p><span class="font-semibold text-slate-600">Reference:</span> <?php echo e($log['reference_no'] ?: '-'); ?></p>
                <p><span class="font-semibold text-slate-600">Message:</span> <?php echo e($log['log_message']); ?></p>
                <p><span class="font-semibold text-slate-600">Created By:</span> <?php echo e($log['created_by_name'] ?: 'System'); ?></p>
                <p><span class="font-semibold text-slate-600">Created At:</span> <?php echo e($log['created_at']); ?></p>
                <p><span class="font-semibold text-slate-600">Validated:</span> <?php echo (int) $log['is_validated'] === 1 ? 'Yes' : 'No'; ?></p>
                <p><span class="font-semibold text-slate-600">Validated By:</span> <?php echo e($log['validated_by_name'] ?: '-'); ?></p>
                <p><span class="font-semibold text-slate-600">Validated At:</span> <?php echo e($log['validated_at'] ?: '-'); ?></p>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    document.querySelectorAll('.edit-ledger-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('edit-ledger-id').value = button.dataset.ledgerId;
            document.getElementById('edit-txn-type').value = button.dataset.txnType;
            document.getElementById('edit-reference-no').value = button.dataset.referenceNo;
            document.getElementById('edit-account-title').value = button.dataset.accountTitle;
            document.getElementById('edit-debit').value = button.dataset.debit;
            document.getElementById('edit-credit').value = button.dataset.credit;
            document.getElementById('edit-description').value = button.dataset.description;
        });
    });

    document.querySelectorAll('.delete-ledger-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('delete-ledger-id').value = button.dataset.ledgerId;
            document.getElementById('delete-reference-no').textContent = button.dataset.referenceNo || '(no reference)';
        });
    });
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
