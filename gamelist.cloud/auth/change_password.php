<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// [SECURITY] CSP matches app-wide posture; unsafe-inline required for browser extension compatibility (e.g. password managers).
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; style-src-attr 'unsafe-inline'; img-src 'self'");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// [GUARD] Require an authenticated session; redirect guests to login.
requireLogin();

$currentUser = sessionUser();
$error       = null;
// NOTE: Success state is signalled via query param after redirect to prevent re-submission on refresh.
$changed     = isset($_GET['changed']);

// [POST] Process password change.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyAuthCsrf();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password']      ?? '';
    $confirmPassword = $_POST['confirm_password']  ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'All fields are required.';
    }

    if ($error === null && $newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    }

    if ($error === null) {
        $error = validatePassword($newPassword);
    }

    if ($error === null) {
        try {
            $conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS
            );
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $currentUser['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                $error = 'Current password is incorrect.';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $conn->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id")
                     ->execute([':hash' => $newHash, ':id' => $currentUser['user_id']]);

                // NOTE: Regenerate session ID after credential change to invalidate any prior session tokens.
                session_regenerate_id(true);
                header('Location: change_password.php?changed=1');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Could not update password. Please try again.';
        }
    }
}

// NOTE: Bust asset cache on deploy by appending file modification timestamp.
$commonCssVersion = (string) (@filemtime(__DIR__ . '/../assets/css/common.css') ?: time());
$authCssVersion   = (string) (@filemtime(__DIR__ . '/../assets/css/auth.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password – Steam Games DB</title>
    <link rel="stylesheet" href="../assets/css/common.css?v=<?= htmlspecialchars($commonCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="../assets/css/auth.css?v=<?= htmlspecialchars($authCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="auth-page">

<div class="auth-card">
    <h1>Change Password</h1>
    <p class="auth-user-note">Signed in as <strong><?= htmlspecialchars($currentUser['username']) ?></strong></p>

    <?php if ($changed): ?>
        <p class="auth-success">Password updated successfully.</p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="auth-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="auth-field">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password"
                   autocomplete="current-password"
                   required>
        </div>

        <div class="auth-field">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password"
                   autocomplete="new-password"
                   required>
            <span class="auth-hint">At least 8 characters.</span>
        </div>

        <div class="auth-field">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   autocomplete="new-password"
                   required>
        </div>

        <button type="submit" class="auth-btn">Save password</button>
    </form>

    <p class="auth-alt"><a href="../index.php">← Back to games</a></p>
</div>

</body>
</html>
