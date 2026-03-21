<?php
header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/config.php';

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ─── INPUTS ───────────────────────────────────────────────────────────────────
    $search    = trim($_POST['search']    ?? '');
$developer = trim($_POST['developer'] ?? '');
$genre     = trim($_POST['genre']     ?? '');
$minScore  = (int)($_POST['minScore'] ?? 0);
$sortBy    = $_POST['sortBy']  ?? 'weighted';
$sortDir   = strtoupper($_POST['sortDir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$page      = max(1, (int)($_POST['page']    ?? 1));
$perPage   = in_array((int)($_POST['perPage'] ?? 24), [12, 24, 48, 96])
             ? (int)$_POST['perPage'] : 24;
$offset    = ($page - 1) * $perPage;

// Decode multi-tag arrays — sanitize to plain strings only
$tagsIncluded = json_decode($_POST['tagsIncluded'] ?? '[]', true);
$tagsExcluded = json_decode($_POST['tagsExcluded'] ?? '[]', true);
$tagsIncluded = is_array($tagsIncluded)
    ? array_values(array_filter($tagsIncluded, 'is_string'))
    : [];
$tagsExcluded = is_array($tagsExcluded)
    ? array_values(array_filter($tagsExcluded, 'is_string'))
    : [];

// ─── WHERE ────────────────────────────────────────────────────────────────────
$conditions = [];
$params     = [];

if ($search !== '') {
    $conditions[] = "name LIKE :search";
    $params[':search'] = '%' . $search . '%';
}
if ($developer !== '') {
    $conditions[] = "developer LIKE :developer";
    $params[':developer'] = '%' . $developer . '%';
}
if ($genre !== '') {
    $conditions[] = "genres LIKE :genre";
    $params[':genre'] = '%' . $genre . '%';
}
if ($minScore > 0) {
    $conditions[] = "percent_positive >= :minScore";
    $params[':minScore'] = $minScore;
}

// Each included tag must be present (AND)
foreach ($tagsIncluded as $i => $tag) {
    $key = ":tagInc{$i}";
    $conditions[] = "tags LIKE $key";
    // Match JSON key format: "TagName": — strips any quotes from user input first
    $params[$key] = '%"' . str_replace('"', '', $tag) . '"%';
}

// Each excluded tag must be absent (AND NOT)
foreach ($tagsExcluded as $i => $tag) {
    $key = ":tagExc{$i}";
    $conditions[] = "tags NOT LIKE $key";
    $params[$key] = '%"' . str_replace('"', '', $tag) . '"%';
}

$where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';


// ─── SORT ─────────────────────────────────────────────────────────────────────
// Rank by percent_positive and review_count bias.
$sortMap = [
    'weighted' => "percent_positive * (1 + LEAST(review_count, 200) / 200)",
    'score'    => "percent_positive",
    'name'     => "name",
    'date'     => "publication_date",
];
$sortExpr = $sortMap[$sortBy] ?? $sortMap['weighted'];
$order    = "$sortExpr $sortDir";

// ─── TOTAL COUNT ──────────────────────────────────────────────────────────────
$countStmt = $conn->prepare("SELECT COUNT(*) FROM steamgames $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// ─── FETCH PAGE ───────────────────────────────────────────────────────────────
$sql = "SELECT id, name, publication_date, developer, genres, tags,
               description, percent_positive, review_count, banner, screenshots
        FROM steamgames
        $where
        ORDER BY $order
        LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── DECODE ───────────────────────────────────────────────────────────────────
foreach ($games as &$game) {
    $game['developer']   = is_string($game['developer']) ? (json_decode($game['developer'], true) ?? []) : [];
    $game['genres']      = is_string($game['genres']) ? (json_decode($game['genres'], true) ?? []) : [];
    $tagsArr             = is_string($game['tags']) ? json_decode($game['tags'], true) : [];
    $game['tags']        = is_array($tagsArr) ? array_values($tagsArr) : [];
    $game['screenshots'] = is_string($game['screenshots']) ? (json_decode($game['screenshots'], true) ?? []) : [];
    $game['percent_positive'] = $game['percent_positive'] !== null ? (int)$game['percent_positive'] : 0;
    $game['review_count']     = $game['review_count'] !== null ? (int)$game['review_count'] : 0;
}
unset($game);

    $payload = json_encode(['total' => $total, 'games' => $games], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        http_response_code(500);
        echo json_encode(['error' => 'JSON encode failed']);
        exit;
    }
    echo $payload;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
