<?php
session_start();
require_once __DIR__ . '/bootstrap.php';

// [GUARD] Listing endpoint accepts POST to stay aligned with app API pattern.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    favJsonResponse(405, ['error' => 'Method not allowed.']);
}

favVerifyCsrf();
$user = favRequireUser();

try {
    $conn = favOpenPdo();

    $stmt = $conn->prepare(
        'SELECT game_id FROM user_favorites WHERE user_id = :user_id ORDER BY created_at DESC'
    );
    $stmt->execute([':user_id' => (int)$user['user_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $gameIds = [];
    foreach ($rows as $row) {
        $gameIds[] = (int)$row['game_id'];
    }

    favJsonResponse(200, [
        'success' => true,
        'gameIds' => $gameIds,
    ]);
} catch (Throwable $e) {
    favJsonResponse(500, ['error' => 'Could not load favorites.']);
}
