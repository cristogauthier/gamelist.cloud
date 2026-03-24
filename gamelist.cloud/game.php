<?php
// ─── DB CONNECTION ────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ─── VALIDATE & FETCH ─────────────────────────────────────────────────────────
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM steamgames WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    header('Location: index.php');
    exit;
}

function getGenreWhitelist(): array
{
    $genresJsonPath = __DIR__ . '/genres.json';
    if (!is_file($genresJsonPath)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($genresJsonPath), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, 'is_string'));
}

function lowerSafe(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function deriveGenresFromTags(array $tags, array $genreWhitelist): array
{
    $tagLookup = [];

    $genres = [];
    foreach ($tags as $tag) {
        if (!is_string($tag)) {
            continue;
        }
        $key = lowerSafe(trim($tag));
        if ($key !== '') {
            $tagLookup[$key] = true;
        }
    }

    foreach ($genreWhitelist as $genre) {
        if (isset($tagLookup[lowerSafe($genre)])) {
            $genres[] = $genre;
        }
    }

    return $genres;
}

// ─── DECODE JSON COLUMNS ──────────────────────────────────────────────────────
$developers = json_decode($game['developer'], true) ?? [];
$tagsRaw = json_decode($game['tags'], true) ?? [];
$screenshots = json_decode($game['screenshots'], true) ?? [];
$tags = is_array($tagsRaw) ? array_values($tagsRaw) : [];
$genres = deriveGenresFromTags($tags, getGenreWhitelist());
$percentPositive = $game['percent_positive'] !== null ? (int) $game['percent_positive'] : null;
$reviewCount = $game['review_count'] !== null ? (int) $game['review_count'] : null;
$ratio = $percentPositive !== null ? $percentPositive / 100 : null;  // 0–1 float for starGauge

function mediaFastlyUrl(?string $path): string
{
    $path = is_string($path) ? trim($path) : '';
    if ($path === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }
    return 'https://shared.fastly.steamstatic.com/store_item_assets/' . ltrim($path, '/');
}

function starGauge($ratio, $reviewCount)
{
    if ($ratio === null) {
        return '<span class="stars-na">No score</span>';
    }
    $pct = round($ratio * 100);
    $width = number_format($ratio * 100, 2);
    $title = $reviewCount !== null ? "$pct% ({$reviewCount} votes)" : "$pct%";
    return '<span class="stars-gauge" title="' . $title . '">
                <span class="stars-empty">☆☆☆☆☆</span>
                <span class="stars-filled" style="width:' . $width . '%">★★★★★</span>
            </span>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($game['name']) ?> – Steam Games DB</title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="detail-page">

    <div id="detail-wrapper">

        <!-- ── BACK BUTTON ───────────────────────────────────────────────────── -->
        <a href="index.php" class="back-btn">← Back to list</a>

        <!-- ── HERO ──────────────────────────────────────────────────────────── -->
        <div class="detail-hero">
            <img src="<?= htmlspecialchars(mediaFastlyUrl($game['banner'] ?? '')) ?>"
                alt="<?= htmlspecialchars($game['name']) ?>" onerror="fallbackToAkamai(this)" class="detail-banner">

            <div class="detail-hero-info">
                <h1><?= htmlspecialchars($game['name']) ?></h1>

                <p class="detail-meta">
                    <span>📅 <?= htmlspecialchars($game['publication_date'] ?? 'Unknown') ?></span>

                    <a href="https://store.steampowered.com/app/<?= (int) $game['id'] ?>/" target="_blank"
                        rel="noopener noreferrer" class="steam-link" title="View on Steam">
                        <img src="https://external-content.duckduckgo.com/ip3/store.steampowered.com.ico" alt="Steam"
                            class="steam-icon">
                        Steam Store
                    </a>


                    <span class="detail-score"><?= starGauge($ratio, $reviewCount) ?></span>
                </p>

                <!-- Genres → link to index with genre filter -->
                <div class="detail-pills">
                    <?php foreach ($genres as $g): ?>
                        <a href="index.php?genre=<?= urlencode($g) ?>"
                            class="pill pill-genre"><?= htmlspecialchars($g) ?></a>
                    <?php endforeach; ?>
                </div>

                <!-- Tags → link to index with tag filter -->
                <div class="detail-pills">
                    <?php foreach ($tags as $t): ?>
                        <a href="index.php?tag=<?= urlencode($t) ?>" class="pill pill-tag"><?= htmlspecialchars($t) ?></a>
                    <?php endforeach; ?>
                </div>

                <!-- Developers → link to index with developer filter -->
                <p class="detail-developer">
                    Developed by
                    <?php foreach ($developers as $i => $dev): ?>
                        <a href="index.php?developer=<?= urlencode($dev) ?>"
                            class="dev-link"><?= htmlspecialchars($dev) ?></a><?= $i < count($developers) - 1 ? ', ' : '' ?>
                    <?php endforeach; ?>
                    <?php if (empty($developers)): ?>Unknown<?php endif; ?>
                </p>
            </div>
        </div>


        <!-- ── DESCRIPTION ───────────────────────────────────────────────────── -->
        <?php if (!empty($game['description'])): ?>
            <section class="detail-section">
                <h2>About</h2>
                <p><?= nl2br(htmlspecialchars($game['description'])) ?></p>
            </section>
        <?php endif; ?>

        <!-- ── SCREENSHOTS ───────────────────────────────────────────────────── -->
        <?php if (!empty($screenshots)): ?>
            <section class="detail-section">
                <h2>Screenshots</h2>
                <div class="screenshots-strip">
                    <?php foreach ($screenshots as $i => $shot): ?>
                        <?php $shotUrl = mediaFastlyUrl(is_string($shot) ? $shot : ''); ?>
                        <img src="<?= htmlspecialchars($shotUrl) ?>" alt="Screenshot <?= $i + 1 ?>" loading="lazy"
                            class="screenshot-thumb" onerror="fallbackToAkamai(this)"
                            data-src="<?= htmlspecialchars($shotUrl) ?>">
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    </div><!-- /#detail-wrapper -->

    <!-- ── LIGHTBOX ──────────────────────────────────────────────────────────── -->
    <div id="lightbox" class="lightbox">
        <button class="lightbox-close" id="lightboxClose">✕</button>
        <button class="lightbox-prev" id="lightboxPrev">&#8592;</button>
        <button class="lightbox-next" id="lightboxNext">&#8594;</button>
        <img id="lightboxImg" src="" alt="Full screenshot">
    </div>

    <script>
        (function () {
            const FASTLY_BASE = 'https://shared.fastly.steamstatic.com/store_item_assets/';
            const AKAMAI_BASE = 'https://shared.akamai.steamstatic.com/store_item_assets/';

            window.fallbackToAkamai = function (img) {
                if (!img || !img.src || img.dataset.cdnFallbackDone === '1') {
                    return;
                }
                if (img.src.indexOf(FASTLY_BASE) !== 0) {
                    return;
                }
                img.dataset.cdnFallbackDone = '1';
                img.src = AKAMAI_BASE + img.src.substring(FASTLY_BASE.length);
            };

            const thumbs = Array.from(document.querySelectorAll('.screenshot-thumb'));
            const lightbox = document.getElementById('lightbox');
            const lbImg = document.getElementById('lightboxImg');
            let current = 0;

            lbImg.addEventListener('error', function () {
                window.fallbackToAkamai(lbImg);
            });

            function open(index) {
                current = index;
                lbImg.src = thumbs[current].dataset.src;
                lightbox.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function close() {
                lightbox.classList.remove('active');
                // Clear src after fade-out transition
                setTimeout(function () { lbImg.src = ''; }, 250);
                document.body.style.overflow = '';
            }

            function navigate(dir) {
                current = (current + dir + thumbs.length) % thumbs.length;
                lbImg.src = thumbs[current].dataset.src;
            }

            // Screenshot thumb click listeners
            thumbs.forEach((thumb, idx) => {
                thumb.addEventListener('click', function () {
                    open(idx);
                });
            });

            // Lightbox controls
            document.getElementById('lightboxClose').addEventListener('click', close);
            document.getElementById('lightboxPrev').addEventListener('click', () => navigate(-1));
            document.getElementById('lightboxNext').addEventListener('click', () => navigate(1));

            // Keyboard navigation
            document.addEventListener('keydown', function (e) {
                if (!lightbox.classList.contains('active')) return;
                if (e.key === 'Escape') close();
                if (e.key === 'ArrowLeft') navigate(-1);
                if (e.key === 'ArrowRight') navigate(1);
            });

            // Click outside to close
            lightbox.addEventListener('click', function (e) {
                if (e.target === lightbox) close();
            });
        })();
    </script>

</body>

</html>