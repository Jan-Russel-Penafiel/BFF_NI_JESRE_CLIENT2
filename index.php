<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
$username = '';
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Sora:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink-950: #061633;
            --ink-900: #0b1f4d;
            --ink-800: #12336b;
            --ink-700: #1a4f91;
            --surface: #ffffff;
            --surface-soft: #f4f8ff;
            --info-bg: #e9f1ff;
            --info-border: #9fb9e3;
            --info-text: #1d3f74;
            --line: #c2d4ee;
            --field-bg: #f7faff;
            --text-main: #10233f;
            --text-soft: #405e86;
            --shadow: 0 28px 64px rgba(7, 24, 58, 0.2);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Sora', 'Segoe UI', Tahoma, sans-serif;
            color: var(--text-main);
            background:
                radial-gradient(circle at 12% 18%, rgba(12, 48, 99, 0.24) 0%, rgba(12, 48, 99, 0) 38%),
                radial-gradient(circle at 84% 80%, rgba(17, 41, 86, 0.2) 0%, rgba(17, 41, 86, 0) 42%),
                linear-gradient(135deg, #f7fbff 0%, #e4efff 45%, #f2f7ff 100%);
            display: grid;
            place-items: center;
            padding: 1.5rem;
        }

        .login-wrap {
            width: min(100%, 460px);
            position: relative;
        }

        .login-wrap::before,
        .login-wrap::after {
            content: '';
            position: absolute;
            z-index: -1;
            border-radius: 999px;
            filter: blur(8px);
        }

        .login-wrap::before {
            width: 170px;
            height: 170px;
            left: -44px;
            top: -36px;
            background: linear-gradient(145deg, rgba(14, 52, 108, 0.42), rgba(14, 52, 108, 0.08));
            animation: drift 8s ease-in-out infinite;
        }

        .login-wrap::after {
            width: 150px;
            height: 150px;
            right: -34px;
            bottom: -42px;
            background: linear-gradient(145deg, rgba(9, 33, 73, 0.34), rgba(9, 33, 73, 0.08));
            animation: drift 10s ease-in-out infinite reverse;
        }

        .login-card {
            border: 1px solid rgba(16, 35, 63, 0.08);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96) 0%, rgba(246, 250, 255, 0.95) 100%);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 2rem;
            backdrop-filter: blur(4px);
            animation: rise 560ms ease-out;
        }

        .brand-tag {
            margin: 0;
            font-family: 'IBM Plex Mono', 'Consolas', monospace;
            font-size: 0.75rem;
            letter-spacing: 0.22em;
            color: var(--ink-700);
            text-transform: uppercase;
        }

        h1 {
            margin: 0.6rem 0 0;
            font-size: clamp(1.6rem, 1.35rem + 0.9vw, 2rem);
            line-height: 1.2;
            color: var(--ink-900);
        }

        .subtitle {
            margin: 0.8rem 0 1.3rem;
            color: var(--text-soft);
            font-size: 0.93rem;
            line-height: 1.6;
        }

        .error-box {
            margin-bottom: 1rem;
            border: 1px solid var(--info-border);
            background: var(--info-bg);
            color: var(--info-text);
            border-radius: 12px;
            padding: 0.75rem 0.9rem;
            font-size: 0.88rem;
        }

        form {
            display: grid;
            gap: 1rem;
        }

        .field {
            display: grid;
            gap: 0.4rem;
        }

        label {
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--ink-700);
        }

        input {
            width: 100%;
            border: 1px solid var(--line);
            background: var(--field-bg);
            border-radius: 10px;
            padding: 0.72rem 0.82rem;
            font-size: 0.93rem;
            color: var(--text-main);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        input:focus {
            outline: none;
            border-color: rgba(26, 79, 145, 0.9);
            box-shadow: 0 0 0 3px rgba(26, 79, 145, 0.2);
            transform: translateY(-1px);
        }

        .submit-btn {
            margin-top: 0.25rem;
            border: 0;
            border-radius: 12px;
            padding: 0.78rem 1rem;
            color: #fff;
            background: linear-gradient(135deg, var(--ink-800) 0%, var(--ink-900) 100%);
            font-size: 0.92rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(8, 29, 67, 0.3);
            filter: saturate(1.1);
        }

        .meta {
            margin-top: 1rem;
            font-size: 0.84rem;
            color: var(--text-soft);
        }

        .meta strong {
            color: var(--ink-800);
            font-weight: 700;
        }

        .meta a {
            color: var(--ink-900);
            font-weight: 700;
            text-decoration-thickness: 2px;
            text-underline-offset: 3px;
        }

        @keyframes rise {
            from {
                opacity: 0;
                transform: translateY(16px) scale(0.985);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes drift {
            0%,
            100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        @media (max-width: 520px) {
            body {
                padding: 1rem;
            }

            .login-card {
                border-radius: 18px;
                padding: 1.35rem;
            }

            .subtitle {
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
<main class="login-wrap">
    <section class="login-card" aria-labelledby="login-title">
        <p class="brand-tag">TOPSPOT MANAGEMENT SYSTEM</p>
        <h1 id="login-title">Sign in to continue</h1>

        <?php if ($error !== ''): ?>
            <div class="error-box" role="alert">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="field">
                <label for="username">Username</label>
                <input
                    id="username"
                    type="text"
                    name="username"
                    value="<?php echo e($username); ?>"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button class="submit-btn" type="submit">Log In</button>
        </form>

        <p class="meta">
            <strong>Default account:</strong> admin / admin123
        </p>
    </section>
</main>
</body>
</html>
