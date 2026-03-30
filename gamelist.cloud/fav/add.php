<?php
session_start();
require_once __DIR__ . '/bootstrap.php';

// [GUARD] Mutation endpoints accept POST only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    favJsonResponse(405, ['error' => 'Method not allowed.']);
}

favVerifyCsrf();
$user = favRequireUser();
$gameId = favReadGameIdFromPost();

try {
    $conn = favOpenPdo();
    favAssertGameExists($conn, $gameId);

    // NOTE: INSERT IGNORE keeps add operation idempotent.
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO user_favorites (user_id, game_id) VALUES (:user_id, :game_id)'
    );
    $stmt->execute([
        ':user_id' => (int)$user['user_id'],
        ':game_id' => $gameId,
    ]);

    favJsonResponse(200, [
        'success' => true,
        'isFavorited' => true,
    ]);
} catch (Throwable $e) {
    favJsonResponse(500, ['error' => 'Could not add favorite.']);
}
