<?php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';


try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// ─── INPUTS ───────────────────────────────────────────────────────────────────
$search    = trim($_POST['search']    ?? '');
$developer = trim($_POST['developer'] ?? '');
$genre     = trim($_POST['genre']     ?? '');
$minScore  = (float)($_POST['minScore'] ?? 0) / 100;
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
    $conditions[] = "positive / NULLIF(positive + negative, 0) >= :minScore";
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
// Weighted formula simplifies to: positive / (positive + negative + m)
// A game with 1 vote at 100% ranks lower than 50 votes at 90%
$m = 10; // minimum vote threshold
$sortMap = [
    'weighted' => "positive / NULLIF(positive + negative + $m, 0)",
    'score'    => "positive / NULLIF(positive + negative, 0)",
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
               description, positive, negative, banner, screenshots
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
    $game['developer']   = json_decode($game['developer'],   true) ?? [];
    $game['genres']      = json_decode($game['genres'],      true) ?? [];
    $game['tags']        = $game['tags'] ? array_keys(json_decode($game['tags'], true)) : [];
    $game['screenshots'] = json_decode($game['screenshots'], true) ?? [];
    // FIX: correct null-check before casting
    $game['positive']    = $game['positive'] !== null ? (int)$game['positive'] : 0;
    $game['negative']    = $game['negative'] !== null ? (int)$game['negative'] : 0;
}
unset($game);

echo json_encode(['total' => $total, 'games' => $games]);
