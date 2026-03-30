<?php
// [GUARD] Start session and generate a CSRF token for this page load.
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// [SECURITY] Restrict content sources to avoid XSS via injected scripts or framing.
// NOTE: unsafe-inline required for the inline ALL_TAGS script and dynamic style attributes.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; style-src-attr 'unsafe-inline'; img-src 'self' https://shared.fastly.steamstatic.com https://shared.akamai.steamstatic.com; connect-src 'self'");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// [DB] Open database connection.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';

$currentUser = sessionUser();

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// [DATA] Load canonical genres from shared JSON source.
$allGenres = [];
$genresJsonPath = __DIR__ . '/genres.json';
if (is_file($genresJsonPath)) {
    $decoded = json_decode((string)file_get_contents($genresJsonPath), true);
    if (is_array($decoded)) {
        $allGenres = array_values(array_filter($decoded, 'is_string'));
    }
}

// [DATA] Build tag list from JSON arrays stored in database.
$tagQuery = $conn->query("SELECT tags FROM steamgames WHERE tags IS NOT NULL");
$allTags  = [];
while ($row = $tagQuery->fetch(PDO::FETCH_ASSOC)) {
    $tags = json_decode($row['tags'], true);
    if (is_array($tags)) {
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $allTags[] = trim($tag);
            }
        }
    }
}
$allTags = array_unique($allTags);
sort($allTags);

// NOTE: Bust asset cache on deploy by appending file modification timestamp.
$commonCssVersion = (string) (@filemtime(__DIR__ . '/assets/css/common.css') ?: time());
$sharedCssVersion = (string) (@filemtime(__DIR__ . '/assets/css/shared-elements.css') ?: time());
$indexCssVersion  = (string) (@filemtime(__DIR__ . '/assets/css/index.css') ?: time());
$appJsVersion     = (string) (@filemtime(__DIR__ . '/assets/js/app.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>Steam Games Database</title>
    <link rel="stylesheet" href="assets/css/common.css?v=<?= htmlspecialchars($commonCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/shared-elements.css?v=<?= htmlspecialchars($sharedCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/index.css?v=<?= htmlspecialchars($indexCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>

<div id="sidebar">
    <!-- Auth navigation -->
    <div class="auth-nav">
        <?php if ($currentUser !== null): ?>
            <span class="auth-username"><?= htmlspecialchars($currentUser['username']) ?></span>
            <div class="auth-links">
                <a href="auth/change_password.php" class="auth-link">Change Password</a>
                <form method="POST" action="auth/logout.php" class="auth-logout-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <button type="submit" class="auth-logout-btn">Sign Out</button>
                </form>
            </div>
        <?php else: ?>
            <div class="auth-links">
                <a href="auth/login.php" class="auth-link">Sign In</a>
                <a href="auth/register.php" class="auth-link auth-register-link">Register</a>
            </div>
        <?php endif; ?>
    </div>

    <h2>Filters</h2>
    <!-- Mobile filter close control -->
    <button id="closeFilters" class="mobile-filter-close" type="button" aria-label="Close filters">×</button>

    <label for="search">Search title</label>
    <input type="text" id="search" placeholder="e.g. Touryst...">

    <label for="developer">Developer</label>
    <input type="text" id="developer" placeholder="e.g. Valve...">

    <!-- Tag filter component -->
    <label>Tags</label>
    <div class="tag-filter">
        <input type="text" id="tagSearch" placeholder="Search tags…" autocomplete="off">
        <div id="tagList" class="tag-list"></div>
        <div class="tag-pills-section">
            <span class="tag-pills-label tag-inc-label">✓ Included</span>
            <div id="includedTagsContainer" class="tag-pills-row"></div>
            <span class="tag-pills-label tag-exc-label">✕ Excluded</span>
            <div id="excludedTagsContainer" class="tag-pills-row"></div>
        </div>
    </div>

    <?php if ($currentUser !== null): ?>
        <!-- Favorites filter available for authenticated users only -->
        <label class="fav-filter-label" for="favoritesOnly">
            <input type="checkbox" id="favoritesOnly">
            Favorites only
        </label>
    <?php endif; ?>


    <label for="minScore">Min score: <span id="scoreValue">0</span>%</label>
    <input type="range" id="minScore" min="0" max="100" value="0">

    <label for="minVotes">Min votes: <span id="votesValue">500</span></label>
    <input type="range" id="minVotes" min="0" max="10000" step="100" value="500">

    <!-- Sort field -->
    <label for="sortBy">Sort by</label>
    <div class="sort-row">
        <select id="sortBy">
            <option value="weighted" selected>Weighted rating</option>
            <option value="score">Raw score</option>
            <option value="name">Name</option>
            <option value="date">Date</option>
        </select>
        <button id="sortDir" class="sort-dir-btn" title="Toggle order">↓</button>
    </div>

    <!-- Items per page -->
    <label for="perPage">Per page</label>
    <select id="perPage">
        <option value="10">10</option>
        <option value="20" selected>20</option>
        <option value="50">50</option>
        <option value="100">100</option>
    </select>

    <button id="applyFilters">Apply</button>
    <button id="resetFilters">Reset</button>

</div>

<div id="main">
        <div id="main-toolbar">
        <h1>Games <span id="resultCount"></span></h1>
        <div class="genre-bar">
            <button id="openFilters" class="mobile-filter-btn" type="button" aria-expanded="false" aria-controls="sidebar">Filters</button>
            <select id="genre">
                <option value="">All genres</option>
                <?php foreach ($allGenres as $g): ?>
                    <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div id="gamesContainer">Loading games...</div>
    <div id="pagination"></div>
</div>

<script src="assets/js/app.js?v=<?= htmlspecialchars($appJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>window.ALL_TAGS = <?= json_encode(array_values($allTags), JSON_UNESCAPED_UNICODE) ?>;</script>

</body>
</html>
