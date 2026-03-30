<?php
/**
 * Shared bootstrap helpers for favorites endpoints.
 */

header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

/**
 * Emit a JSON response and terminate execution.
 *
 * @param int $statusCode
 * @param array<string, mixed> $payload
 */
function favJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        http_response_code(500);
        echo '{"error":"JSON encode failed"}';
        exit;
    }
    echo $json;
    exit;
}

/**
 * Validate a strict CSRF token for mutation endpoints.
 */
function favVerifyCsrf(): void
{
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        favJsonResponse(403, ['error' => 'Invalid or expired request token.']);
    }
}

/**
 * Return the authenticated user payload or terminate with 401.
 *
 * @return array{user_id: int, username: string, issued_at: int}
 */
function favRequireUser(): array
{
    $user = sessionUser();
    if ($user === null) {
        favJsonResponse(401, ['error' => 'Authentication required.']);
    }
    return $user;
}

/**
 * Parse and validate game id from POST input.
 */
function favReadGameIdFromPost(): int
{
    $gameId = (int)($_POST['game_id'] ?? 0);
    if ($gameId <= 0) {
        favJsonResponse(400, ['error' => 'Invalid game id.']);
    }
    return $gameId;
}

/**
 * Build a PDO connection.
 */
function favOpenPdo(): PDO
{
    $conn = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $conn;
}

/**
 * Verify that the given game exists.
 *
 * @param PDO $conn
 * @param int $gameId
 */
function favAssertGameExists(PDO $conn, int $gameId): void
{
    $stmt = $conn->prepare('SELECT id FROM steamgames WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $gameId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        favJsonResponse(404, ['error' => 'Game not found.']);
    }
}
