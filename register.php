<?php
require_once __DIR__ . '/includes/auth_guard.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? 'sales');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $allowedRoles = ['admin', 'sales', 'cashier', 'purchasing', 'accounting', 'inventory'];

    if ($fullName === '' || $username === '' || $password === '') {
        $error = 'Please complete all required fields.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Invalid role selected.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $existing = db_select_one('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$username]);
        if ($existing) {
            $error = 'Username already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            db_insert(
                'INSERT INTO users (full_name, username, role, password_hash) VALUES (?, ?, ?, ?)',
                'ssss',
                [$fullName, $username, $role, $hash]
            );

            set_flash('success', 'User account created successfully.');
            redirect('dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register User</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 py-8">
<div class="mx-auto w-full max-w-lg rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h1 class="text-2xl font-semibold text-slate-800">Create User Account</h1>
    <p class="mt-1 text-sm text-slate-500">Add a system user for departmental access.</p>

    <?php if ($error !== ''): ?>
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="mt-5 space-y-4">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Full Name</label>
            <input type="text" name="full_name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Username</label>
            <input type="text" name="username" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Department Role</label>
            <select name="role" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
                <option value="sales">Sales</option>
                <option value="cashier">Cashier</option>
                <option value="inventory">Inventory</option>
                <option value="purchasing">Purchasing</option>
                <option value="accounting">Accounting</option>
                <option value="admin">Admin</option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Password</label>
            <input type="password" name="password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Confirm Password</label>
            <input type="password" name="confirm_password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
        </div>

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="dashboard.php" class="text-sm font-medium text-slate-600 hover:text-slate-900">Back to dashboard</a>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Register</button>
        </div>
    </form>
</div>
</body>
</html>
