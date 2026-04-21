<?php
$pageTitle = $pageTitle ?? 'TOPSPOT Management System';
$activePage = $activePage ?? 'dashboard';
$flash = consume_flash();
$user = current_user();

$menuItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php'],
    ['key' => 'sales', 'label' => 'Sales Department', 'href' => 'sales.php'],
    ['key' => 'cashier', 'label' => 'Cashier Department', 'href' => 'cashier.php'],
    ['key' => 'inventory', 'label' => 'Inventory System', 'href' => 'inventory.php'],
    ['key' => 'purchasing', 'label' => 'Purchasing Department', 'href' => 'purchasing.php'],
    ['key' => 'accounting', 'label' => 'Accounting Department', 'href' => 'accounting.php'],
    ['key' => 'users', 'label' => 'User Management', 'href' => 'register.php'],
    ['key' => 'reports', 'label' => 'Financial Reports', 'href' => 'reports.php'],
];

$userRole = $user['role'] ?? '';
$menuItems = array_values(array_filter($menuItems, function ($item) use ($userRole) {
    return can_access_page($userRole, $item['href']);
}));

$flashClass = 'border border-blue-200 bg-blue-50 text-blue-900';
if ($flash && $flash['type'] === 'success') {
    $flashClass = 'border border-emerald-200 bg-emerald-50 text-emerald-900';
}
if ($flash && $flash['type'] === 'error') {
    $flashClass = 'border border-red-200 bg-red-50 text-red-900';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        navy: {
                            50: '#edf3ff',
                            100: '#d7e4ff',
                            500: '#264f9d',
                            700: '#163873',
                            800: '#102c5b',
                            900: '#0b1f4d'
                        }
                    }
                }
            }
        };
    </script>
    <style>
        body {
            font-family: 'Trebuchet MS', 'Segoe UI', sans-serif;
        }

        [data-modal-box] {
            overflow-y: auto;
        }

        [data-modal-box] > [data-modal-overlay] {
            position: fixed !important;
            inset: 0 !important;
        }

        [data-modal-box] > *:not([data-modal-overlay]) {
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">
<div class="min-h-screen md:flex">
    <aside class="w-full bg-navy-900 p-4 text-white md:sticky md:top-0 md:h-screen md:w-64 md:shrink-0 md:overflow-y-auto">
        <div class="mb-6 rounded-lg border border-white/20 bg-white/10 p-3">
            <p class="text-sm text-blue-100">TOPSPOT</p>
            <p class="text-lg font-semibold">Parts Trading POS</p>
        </div>
        <nav class="space-y-1">
            <?php foreach ($menuItems as $item): ?>
                <?php $isActive = $activePage === $item['key']; ?>
                <a href="<?php echo e($item['href']); ?>"
                   class="block rounded-md px-3 py-2 text-sm <?php echo $isActive ? 'bg-white text-navy-900 font-semibold' : 'text-blue-100 hover:bg-blue-700'; ?>">
                    <?php echo e($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <section class="flex-1">
        <header class="flex flex-col gap-3 border-b border-slate-200 bg-white px-4 py-4 md:flex-row md:items-center md:justify-between md:px-6">
            <div>
                <h1 class="text-lg font-semibold text-navy-900"><?php echo e($pageTitle); ?></h1>
            </div>
            <div class="flex items-center gap-3">
                <span class="rounded-full bg-navy-50 px-3 py-1 text-xs font-semibold text-navy-900">
                    <?php echo e($user['full_name'] ?? 'Guest'); ?> | <?php echo e(strtoupper($user['role'] ?? '')); ?>
                </span>
                <a href="logout.php" class="rounded-md bg-navy-900 px-3 py-2 text-xs font-semibold text-white hover:bg-navy-700">Logout</a>
            </div>
        </header>

        <main class="space-y-4 p-4 md:p-6">
            <?php if ($flash): ?>
                <div class="rounded-md px-4 py-3 text-sm <?php echo $flashClass; ?>">
                    <?php echo e($flash['message']); ?>
                </div>
            <?php endif; ?>
