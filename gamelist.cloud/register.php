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

// [POST] Process registration submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyAuthCsrf();

    $username        = trim($_POST['username']        ?? '');
    $password        = $_POST['password']             ?? '';
    $passwordConfirm = $_POST['password_confirm']     ?? '';

    $error = validateUsername($username);

    if ($error === null) {
        $error = validatePassword($password);
    }

    if ($error === null && $password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    }

    if ($error === null) {
        try {
            $conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS
            );
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // [CHECK] Verify the username is not already registered.
            $check = $conn->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
            $check->execute([':username' => $username]);
            if ($check->fetch()) {
                $error = 'That username is already taken.';
            } else {
                $hash   = password_hash($password, PASSWORD_BCRYPT);
                $insert = $conn->prepare(
                    "INSERT INTO users (username, password_hash) VALUES (:username, :hash)"
                );
                $insert->execute([':username' => $username, ':hash' => $hash]);
                $userId = (int)$conn->lastInsertId();

                // NOTE: Auto-login after successful registration to reduce friction.
                loginUser($userId, $username);
                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Registration failed due to a server error. Please try again.';
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
    <title>Register – Steam Games DB</title>
    <link rel="stylesheet" href="style.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="auth-page">

<div class="auth-card">
    <h1>Create an account</h1>

    <div class="auth-notice" role="note" aria-label="Important account notice">
        <p class="auth-notice-title">Before you register</p>
        <ul class="auth-notice-list">
            <li>This app is a portfolio project and will not guarantee the security of your personal data.</li>
            <li>Security and privacy controls are limited compared with production-grade services.</li>
            <li>Use a unique password here. Do not reuse credentials from your email, banking, or other important accounts.</li>
            <li>Account and password recovery is not available yet.</li>
        </ul>
    </div>

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
                   maxlength="30"
                   required>
            <span class="auth-hint">3–30 characters. Letters, numbers, _ and - only.</span>
        </div>

        <div class="auth-field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"
                   autocomplete="new-password"
                   required>
            <span class="auth-hint">At least 8 characters.</span>
        </div>

        <div class="auth-field">
            <label for="password_confirm">Confirm password</label>
            <input type="password" id="password_confirm" name="password_confirm"
                   autocomplete="new-password"
                   required>
        </div>

        <button type="submit" class="auth-btn">Register</button>
    </form>

    <p class="auth-alt">Already have an account? <a href="login.php">Sign in</a></p>
</div>

</body>
</html>
