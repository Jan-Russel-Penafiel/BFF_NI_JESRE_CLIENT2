<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $user = db_select_one('SELECT * FROM users WHERE username = ? LIMIT 1', 's', [$username]);

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            set_flash('success', 'Welcome back to TOPSPOT Management System.');
            redirect('dashboard.php');
        }

        $error = 'Invalid login credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOPSPOT Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        navy: {
                            100: '#d7e4ff',
                            700: '#163873',
                            900: '#0b1f4d'
                        }
                    }
                }
            }
        };
    </script>
</head>
<body class="min-h-screen bg-slate-100">
<div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col overflow-hidden rounded-none bg-white md:my-8 md:min-h-[85vh] md:flex-row md:rounded-xl md:shadow-xl">
    <section class="flex-1 bg-navy-900 px-8 py-10 text-white">
        <p class="text-xs uppercase tracking-[0.3em] text-navy-100">TOPSPOT</p>
        <h1 class="mt-3 text-3xl font-semibold">Motorcycle Parts Trading</h1>
        <p class="mt-4 max-w-md text-sm leading-relaxed text-blue-100">
            Unified POS flow for Sales, Inventory, Cashier, Purchasing, Accounting, and Financial Reporting.
        </p>
        <div class="mt-8 space-y-3 text-sm text-blue-100">
            <p>- Real-time stock checking and auto supplier request</p>
            <p>- Tax computation, payments, and receipt issuance</p>
            <p>- General ledger and digital log validation</p>
            <p>- Income Statement and Balance Sheet generation</p>
        </div>
    </section>

    <section class="flex flex-1 items-center justify-center px-6 py-10 md:px-10">
        <div class="w-full max-w-md space-y-5">
            <div>
                <h2 class="text-2xl font-semibold text-navy-900">Sign in</h2>
                <p class="text-sm text-slate-500">Default account: admin / admin123</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-6">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Username</label>
                    <input type="text" name="username" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-navy-700 focus:outline-none" required>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Password</label>
                    <input type="password" name="password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-navy-700 focus:outline-none" required>
                </div>
                <button type="submit" class="w-full rounded-md bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700">
                    Login
                </button>
            </form>

            <p class="text-sm text-slate-600">
                Need a new user? <a href="register.php" class="font-semibold text-navy-900">Register account</a>
            </p>
        </div>
    </section>
</div>
</body>
</html>
