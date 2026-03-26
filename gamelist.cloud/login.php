<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// [SECURITY] CSP matches app-wide posture; unsafe-inline required for browser extension compatibility (e.g. password managers).
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; style-src-attr 'unsafe-inline'; img-src 'self'");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// [GUARD] Redirect already-authenticated users to the main page.
if (sessionUser() !== null) {
    header('Location: index.php');
    exit;
}

$error    = null;
$username = '';

// [POST] Process login submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyAuthCsrf();

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkLoginRateLimit($clientIp)) {
        $error = 'Too many login attempts. Please wait a moment and try again.';
    }

    if ($error === null) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username and password are required.';
        }
    }

    if ($error === null) {
        try {
            $conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS
            );
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $conn->prepare(
                "SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1"
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // NOTE: Generic message avoids disclosing whether the username exists.
            // TODO: Pre-compute a dummy bcrypt hash to fully neutralize timing-based username enumeration.
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $error = 'Invalid username or password.';
            } else {
                $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")
                     ->execute([':id' => $user['id']]);
                loginUser((int)$user['id'], $user['username']);
                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Login failed due to a server error. Please try again.';
        }
    }
}

// NOTE: Bust CSS cache on deploy by appending file modification timestamp.
$cssVersion = (string) (@filemtime(__DIR__ . '/style.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In – Steam Games DB</title>
    <link rel="stylesheet" href="style.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="auth-page">

<div class="auth-card">
    <h1>Sign in</h1>

    <?php if ($error !== null): ?>
        <p class="auth-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="auth-field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username"
                   value="<?= htmlspecialchars($username) ?>"
                   autocomplete="username"
                   required>
        </div>

        <div class="auth-field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password"
                   required>
        </div>

        <button type="submit" class="auth-btn">Sign in</button>
    </form>

    <p class="auth-alt">No account? <a href="register.php">Register</a></p>
</div>

</body>
</html>
