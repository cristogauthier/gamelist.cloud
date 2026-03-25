<?php
session_start(); // NOTE: Must precede any output; required for CSRF token access.
header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/common.php';

// [GUARD] Request validation — runs before DB connection or any expensive work.

/**
 * Verify the CSRF token from POST matches the session-stored token.
 *
 * NOTE: This endpoint is read-only; token is optional for compatibility.
 * Terminates with HTTP 403 only when request is cross-site and token validation fails.
 */
function verifyCsrfToken(): void {
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected  = $_SESSION['csrf_token'] ?? '';

    if ($submitted !== '' && $expected !== '' && hash_equals($expected, $submitted)) {
        return;
    }

    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $originHost = strtolower((string)parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST));
    $refererHost = strtolower((string)parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST));

    // NOTE: Allow same-origin browser requests even when token is missing/stale (e.g., cached page).
    if (($originHost !== '' && $originHost === $host) || ($refererHost !== '' && $refererHost === $host)) {
        return;
    }

    if ($submitted === '') {
        // NOTE: For clients that omit Origin/Referer, preserve existing behavior by requiring token.
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or expired request token.']);
        exit;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Invalid or expired request token.']);
    exit;
}

/**
 * Enforce a sliding-window request rate limit per IP address.
 *
 * Requires the APCu extension; silently skips enforcement if unavailable.
 *
 * @param string $clientIp      Caller IP used as the rate-limit key.
 * @param int    $maxRequests   Maximum requests allowed per window.
 * @param int    $windowSeconds Window length in seconds.
 */
function checkRateLimit(string $clientIp, int $maxRequests = 30, int $windowSeconds = 60): void {
    if (!function_exists('apcu_fetch')) {
        // NOTE: APCu not available; rate limiting is inactive on this server.
        return;
    }
    // NOTE: Hash IP before caching to avoid storing raw user network data.
    $key     = 'api_rl:' . hash('sha256', $clientIp);
    $current = apcu_fetch($key);
    if ($current === false) {
        apcu_store($key, 1, $windowSeconds);
        return;
    }
    if ((int)$current >= $maxRequests) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please slow down.']);
        exit;
    }
    apcu_inc($key);
}

/**
 * Validate and truncate free-text search inputs to safe lengths.
 *
 * Terminates with HTTP 400 when the hard limit is exceeded (likely automated probing).
 * Silently truncates values within the soft limit to avoid user-visible errors.
 *
 * @param string $search    Passed by reference; truncated to soft limit.
 * @param string $developer Passed by reference; truncated to soft limit.
 */
function validateInputLengths(string &$search, string &$developer): void {
    $softLimit = 100;
    $hardLimit = 500; // NOTE: Payloads beyond this are unlikely to be legitimate queries.
    if (strlen($search) > $hardLimit || strlen($developer) > $hardLimit) {
        http_response_code(400);
        echo json_encode(['error' => 'Input exceeds maximum allowed length.']);
        exit;
    }
    if (function_exists('mb_substr')) {
        $search    = mb_substr($search,    0, $softLimit, 'UTF-8');
        $developer = mb_substr($developer, 0, $softLimit, 'UTF-8');
    } else {
        // NOTE: Fallback keeps app functional on hosts without mbstring.
        $search    = substr($search,    0, $softLimit);
        $developer = substr($developer, 0, $softLimit);
    }
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
checkRateLimit($clientIp);
verifyCsrfToken();

/**
 * Build a case-insensitive lookup map of allowed genres.
 *
 * @param array<int, string> $genres
 * @return array<string, string>
 */
function buildGenreLookupMap(array $genres): array {
    $lookup = [];
    foreach ($genres as $genre) {
        $lookup[lowerSafe($genre)] = $genre;
    }
    return $lookup;
}

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // [INPUTS] Read and normalize request filters.
    $search    = trim($_POST['search']    ?? '');
    $developer = trim($_POST['developer'] ?? '');
    $genre     = trim($_POST['genre']     ?? '');
    $minScore  = (int)($_POST['minScore'] ?? 0);
    $minVotes  = (int)($_POST['minVotes'] ?? 500);
    $sortBy    = $_POST['sortBy']  ?? 'weighted';
    $sortDir   = strtoupper($_POST['sortDir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $page      = max(1, (int)($_POST['page']    ?? 1));
    $perPage   = in_array((int)($_POST['perPage'] ?? 20), [10, 20, 50, 100])
                 ? (int)$_POST['perPage'] : 20;
    $offset    = ($page - 1) * $perPage;
    validateInputLengths($search, $developer); // NOTE: Reject or truncate oversized inputs before query construction.

$genreWhitelist = getGenreWhitelist();
$genreLookup = buildGenreLookupMap($genreWhitelist);

$maxReviewCount = (int)$conn->query("SELECT COALESCE(MAX(review_count), 0) FROM steamgames")->fetchColumn();
$maxReviewCountDivisor = max(1, $maxReviewCount);

// NOTE: Keep only plain string tags from JSON arrays.
$tagsIncluded = json_decode($_POST['tagsIncluded'] ?? '[]', true);
$tagsExcluded = json_decode($_POST['tagsExcluded'] ?? '[]', true);
$tagsIncluded = is_array($tagsIncluded)
    ? array_values(array_filter($tagsIncluded, 'is_string'))
    : [];
$tagsExcluded = is_array($tagsExcluded)
    ? array_values(array_filter($tagsExcluded, 'is_string'))
    : [];

// [WHERE] Build SQL predicates from active filters.
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
    $genreKey = lowerSafe($genre);
    if (isset($genreLookup[$genreKey])) {
        $conditions[] = "tags LIKE :genre";
        $params[':genre'] = '%"' . str_replace('"', '', $genreLookup[$genreKey]) . '"%';
    }
}
if ($minScore > 0) {
    $conditions[] = "percent_positive >= :minScore";
    $params[':minScore'] = $minScore;
}
if ($minVotes > 0) {
    $conditions[] = "review_count >= :minVotes";
    $params[':minVotes'] = $minVotes;
}

// NOTE: Included tags use AND logic (all must match).
foreach ($tagsIncluded as $i => $tag) {
    $key = ":tagInc{$i}";
    $conditions[] = "tags LIKE $key";
    // NOTE: Strip quotes to keep the LIKE pattern stable.
    $params[$key] = '%"' . str_replace('"', '', $tag) . '"%';
}

// NOTE: Excluded tags must all be absent.
foreach ($tagsExcluded as $i => $tag) {
    $key = ":tagExc{$i}";
    $conditions[] = "tags NOT LIKE $key";
    $params[$key] = '%"' . str_replace('"', '', $tag) . '"%';
}

$where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';


// [SORT] Use weighted ranking by default.
// NOTE: Weighted score biases toward strong rating plus review volume.
// NOTE: Vote bonus is 0.01 * floor(log10(review_count)), capped in expression.
$sortMap = [
    'weighted' => "LEAST(2, percent_positive/100 * (1 + LEAST(review_count, {$maxReviewCountDivisor}) / {$maxReviewCountDivisor}) +  0.01 * FLOOR(LOG10(GREATEST(1, review_count))) )",
    'score'    => "percent_positive",
    'name'     => "name",
    'date'     => "publication_date",
];
$sortExpr = $sortMap[$sortBy] ?? $sortMap['weighted'];
$order    = "$sortExpr $sortDir";

// [COUNT] Read total rows for pagination.
$countStmt = $conn->prepare("SELECT COUNT(*) FROM steamgames $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// [PAGE] Fetch a single page of games.
$sql = "SELECT id, name, publication_date, developer, tags,
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

// [DECODE] Normalize JSON columns to typed response fields.
foreach ($games as &$game) {
    $game['developer']   = is_string($game['developer']) ? (json_decode($game['developer'], true) ?? []) : [];
    $tagsArr             = is_string($game['tags']) ? json_decode($game['tags'], true) : [];
    $game['tags']        = is_array($tagsArr) ? array_values($tagsArr) : [];
    $game['genres']      = deriveGenresFromTags($game['tags'], $genreWhitelist);
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
