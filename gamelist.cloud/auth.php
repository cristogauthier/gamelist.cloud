<?php
/**
 * Shared auth helpers for gamelist.cloud.
 *
 * Provides session management, login/logout, and credential validation.
 * The account model stores email as nullable so email-based password recovery
 * can be added later without a schema change.
 *
 * WARN: session_start() must be called by the including page before this file is loaded.
 */

// [CONFIG] Auth policy constants.
define('AUTH_MIN_PASSWORD_LENGTH',  8);
define('AUTH_MAX_PASSWORD_LENGTH',  72); // NOTE: bcrypt silently truncates at 72 bytes; reject at input boundary.
define('AUTH_MIN_USERNAME_LENGTH',  3);
define('AUTH_MAX_USERNAME_LENGTH',  30);
define('AUTH_LOGIN_MAX_ATTEMPTS',   10);
define('AUTH_LOGIN_WINDOW_SECONDS', 60);

/**
 * Return the authenticated user payload from the session, or null if not signed in.
 *
 * @return array{user_id: int, username: string, issued_at: int}|null
 */
function sessionUser(): ?array
{
    $auth = $_SESSION['auth'] ?? null;
    if (!is_array($auth) || !isset($auth['user_id'], $auth['username'])) {
        return null;
    }
    return $auth;
}

/**
 * Redirect to the login page if no authenticated session exists.
 *
 * @param string $redirect  Destination URL when unauthenticated.
 */
function requireLogin(string $redirect = 'login.php'): void
{
    if (sessionUser() === null) {
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Establish an authenticated session for the given user.
 *
 * Regenerates the session ID to prevent session-fixation attacks.
 *
 * @param int    $userId
 * @param string $username
 */
function loginUser(int $userId, string $username): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = [
        'user_id'   => $userId,
        'username'  => $username,
        'issued_at' => time(),
    ];
}

/**
 * Destroy the current session entirely.
 *
 * NOTE: Also expires the session cookie so the browser discards the session ID.
 */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Validate a candidate username against naming policy.
 *
 * Permits letters, digits, underscores, and hyphens only.
 *
 * @param string $username
 * @return string|null  Error message on violation, null if valid.
 */
function validateUsername(string $username): ?string
{
    $len = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);
    if ($len < AUTH_MIN_USERNAME_LENGTH || $len > AUTH_MAX_USERNAME_LENGTH) {
        return 'Username must be between ' . AUTH_MIN_USERNAME_LENGTH . ' and ' . AUTH_MAX_USERNAME_LENGTH . ' characters.';
    }
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        return 'Username may only contain letters, numbers, underscores, and hyphens.';
    }
    return null;
}

/**
 * Validate a candidate password against policy rules.
 *
 * @param string $password
 * @return string|null  Error message on violation, null if valid.
 */
function validatePassword(string $password): ?string
{
    $len = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    if ($len < AUTH_MIN_PASSWORD_LENGTH) {
        return 'Password must be at least ' . AUTH_MIN_PASSWORD_LENGTH . ' characters.';
    }
    if ($len > AUTH_MAX_PASSWORD_LENGTH) {
        return 'Password must not exceed ' . AUTH_MAX_PASSWORD_LENGTH . ' characters.';
    }
    return null;
}

/**
 * Verify the CSRF token submitted with an auth form POST.
 *
 * Terminates with HTTP 403 when the token is missing or does not match the session.
 * NOTE: Uses hash_equals to prevent timing-based token comparison attacks.
 */
function verifyAuthCsrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo 'Invalid or expired request token.';
        exit;
    }
}

/**
 * Enforce a sliding-window rate limit per IP address for login attempts.
 *
 * Requires APCu; silently allows all requests when APCu is unavailable.
 *
 * @param string $clientIp
 * @param int    $maxAttempts
 * @param int    $windowSeconds
 * @return bool  False if the request should be blocked.
 */
function checkLoginRateLimit(
    string $clientIp,
    int $maxAttempts  = AUTH_LOGIN_MAX_ATTEMPTS,
    int $windowSeconds = AUTH_LOGIN_WINDOW_SECONDS
): bool {
    if (!function_exists('apcu_fetch')) {
        // NOTE: APCu unavailable; login throttling is inactive on this server.
        return true;
    }
    // NOTE: Hash the IP to avoid caching raw user network identifiers.
    $key     = 'login_rl:' . hash('sha256', $clientIp);
    $current = apcu_fetch($key);
    if ($current === false) {
        apcu_store($key, 1, $windowSeconds);
        return true;
    }
    if ((int)$current >= $maxAttempts) {
        return false;
    }
    apcu_inc($key);
    return true;
}
