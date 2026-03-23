<?php
// ─── DB CONNECTION ────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ─── FIXED GENRE LIST from shared JSON source ───────────────────────────────
$allGenres = [];
$genresJsonPath = __DIR__ . '/genres.json';
if (is_file($genresJsonPath)) {
    $decoded = json_decode((string)file_get_contents($genresJsonPath), true);
    if (is_array($decoded)) {
        $allGenres = array_values(array_filter($decoded, 'is_string'));
    }
}

// ─── BUILD TAG LIST from JSON arrays stored in DB ────────────────────────────
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Steam Games Database</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div id="sidebar">
    <h2>Filters</h2>

    <label for="search">Search title</label>
    <input type="text" id="search" placeholder="e.g. Touryst...">

    <label for="developer">Developer</label>
    <input type="text" id="developer" placeholder="e.g. Valve...">

    <!-- ── TAG FILTER COMPONENT ──────────────────────────────────── -->
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


    <label for="minScore">Min score: <span id="scoreValue">0</span>%</label>
    <input type="range" id="minScore" min="0" max="100" value="0">

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

<script src="script.js"></script>
<script>window.ALL_TAGS = <?= json_encode(array_values($allTags), JSON_UNESCAPED_UNICODE) ?>;</script>

</body>
</html>
