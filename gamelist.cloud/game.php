<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// [SECURITY] Restrict content sources; DuckDuckGo included for the Steam store favicon.
// NOTE: unsafe-inline required for the embedded lightbox script and dynamic style attributes.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; style-src-attr 'unsafe-inline'; img-src 'self' https://shared.fastly.steamstatic.com https://shared.akamai.steamstatic.com https://external-content.duckduckgo.com; connect-src 'self'");

// [DB] Open database connection.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// [INPUT] Validate requested game id and fetch record.
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

// [DECODE] Normalize JSON columns into arrays for rendering.
$developers = json_decode($game['developer'], true) ?? [];
$tagsRaw = json_decode($game['tags'], true) ?? [];
$screenshots = json_decode($game['screenshots'], true) ?? [];
$tags = is_array($tagsRaw) ? array_values($tagsRaw) : [];
$genres = deriveGenresFromTags($tags, getGenreWhitelist());
$percentPositive = $game['percent_positive'] !== null ? (int) $game['percent_positive'] : null;
$reviewCount = $game['review_count'] !== null ? (int) $game['review_count'] : null;
$ratio       = $percentPositive !== null ? $percentPositive / 100 : null;  // NOTE: 0-1 ratio used by starGauge.
$currentUser = sessionUser();
$isFavorited = false;

if ($currentUser !== null) {
    $favoriteCheckStmt = $conn->prepare('SELECT 1 FROM user_favorites WHERE user_id = :user_id AND game_id = :game_id LIMIT 1');
    $favoriteCheckStmt->execute([
        ':user_id' => (int)$currentUser['user_id'],
        ':game_id' => (int)$game['id'],
    ]);
    $isFavorited = (bool)$favoriteCheckStmt->fetchColumn();
}

/**
 * Convert a stored media path to a Fastly URL.
 */
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

/**
 * Build the review score gauge HTML.
 *
 * @param float|null $ratio
 * @param int|null $reviewCount
 */
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

// NOTE: Bust asset cache on deploy by appending file modification timestamp.
$commonCssVersion = (string) (@filemtime(__DIR__ . '/assets/css/common.css') ?: time());
$sharedCssVersion = (string) (@filemtime(__DIR__ . '/assets/css/shared-elements.css') ?: time());
$detailCssVersion = (string) (@filemtime(__DIR__ . '/assets/css/detail.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title><?= htmlspecialchars($game['name']) ?> – Steam Games DB</title>
    <link rel="stylesheet" href="assets/css/common.css?v=<?= htmlspecialchars($commonCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/shared-elements.css?v=<?= htmlspecialchars($sharedCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/detail.css?v=<?= htmlspecialchars($detailCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>

<body class="detail-page">

    <div id="detail-wrapper">

        <div class="detail-nav">
            <!-- Back navigation -->
            <a href="index.php" class="back-btn">&#8592; Back to list</a>
        </div>

        <!-- Hero section -->
        <div class="detail-hero">
            <img src="<?= htmlspecialchars(mediaFastlyUrl($game['banner'] ?? '')) ?>"
                alt="<?= htmlspecialchars($game['name']) ?>" onerror="fallbackToAkamai(this)" class="detail-banner">

            <div class="detail-hero-info">
                <h1><?= htmlspecialchars($game['name']) ?></h1>

                <?php if ($currentUser !== null): ?>
                    <div class="favorite-controls">
                        <button type="button"
                            id="favoriteToggle"
                            class="favorite-toggle<?= $isFavorited ? ' is-active' : '' ?>"
                            data-game-id="<?= (int)$game['id'] ?>"
                            data-is-favorited="<?= $isFavorited ? '1' : '0' ?>"
                            aria-label="Toggle favorite"
                            title="Toggle favorite for this game">
                            <span class="favorite-icon" aria-hidden="true"><?= $isFavorited ? '♥' : '♡' ?></span>
                        </button>
                        <span id="favoriteFeedback" class="favorite-feedback" aria-live="polite"></span>
                    </div>
                <?php else: ?>
                    <p class="favorite-guest-note">
                        <a href="auth/login.php" class="auth-link">Sign in</a> to save this game to your favorites.
                    </p>
                <?php endif; ?>

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

                <!-- Genre links route to list filter -->
                <div class="detail-pills">
                    <?php foreach ($genres as $g): ?>
                        <a href="index.php?genre=<?= urlencode($g) ?>"
                            class="pill pill-genre"><?= htmlspecialchars($g) ?></a>
                    <?php endforeach; ?>
                </div>

                <!-- Tag links route to list filter -->
                <div class="detail-pills">
                    <?php foreach ($tags as $t): ?>
                        <a href="index.php?tag=<?= urlencode($t) ?>" class="pill pill-tag"><?= htmlspecialchars($t) ?></a>
                    <?php endforeach; ?>
                </div>

                <!-- Developer links route to list filter -->
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


        <!-- Description -->
        <?php if (!empty($game['description'])): ?>
            <section class="detail-section">
                <h2>About</h2>
                <p><?= nl2br(htmlspecialchars($game['description'])) ?></p>
            </section>
        <?php endif; ?>

        <!-- Screenshots -->
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

    <!-- Screenshot lightbox -->
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
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

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
                // NOTE: Clear image source after fade-out to avoid stale frame flashes.
                setTimeout(function () { lbImg.src = ''; }, 250);
                document.body.style.overflow = '';
            }

            function navigate(dir) {
                current = (current + dir + thumbs.length) % thumbs.length;
                lbImg.src = thumbs[current].dataset.src;
            }

            // [EVENTS] Open lightbox from screenshot thumbnails.
            thumbs.forEach((thumb, idx) => {
                thumb.addEventListener('click', function () {
                    open(idx);
                });
            });

            // [EVENTS] Wire lightbox control buttons.
            document.getElementById('lightboxClose').addEventListener('click', close);
            document.getElementById('lightboxPrev').addEventListener('click', () => navigate(-1));
            document.getElementById('lightboxNext').addEventListener('click', () => navigate(1));

            // [EVENTS] Support keyboard navigation while lightbox is active.
            document.addEventListener('keydown', function (e) {
                if (!lightbox.classList.contains('active')) return;
                if (e.key === 'Escape') close();
                if (e.key === 'ArrowLeft') navigate(-1);
                if (e.key === 'ArrowRight') navigate(1);
            });

            // [EVENTS] Close when clicking outside the image.
            lightbox.addEventListener('click', function (e) {
                if (e.target === lightbox) close();
            });

            // [EVENTS] Toggle favorite state for authenticated users.
            const favoriteToggle = document.getElementById('favoriteToggle');
            const favoriteFeedback = document.getElementById('favoriteFeedback');

            function setFavoriteVisualState(isFavorited) {
                if (!favoriteToggle) {
                    return;
                }
                favoriteToggle.dataset.isFavorited = isFavorited ? '1' : '0';
                favoriteToggle.classList.toggle('is-active', isFavorited);
                const icon = favoriteToggle.querySelector('.favorite-icon');
                if (icon) {
                    icon.textContent = isFavorited ? '♥' : '♡';
                }
                favoriteToggle.setAttribute(
                    'title',
                    isFavorited ? 'Click to remove from favorites' : 'Click to add to favorites'
                );
            }

            function setFavoriteFeedback(message, isError) {
                if (!favoriteFeedback) {
                    return;
                }
                favoriteFeedback.textContent = message;
                favoriteFeedback.classList.toggle('is-error', !!isError);
            }

            favoriteToggle?.addEventListener('click', function () {
                const gameId = favoriteToggle.dataset.gameId;
                const isFavorited = favoriteToggle.dataset.isFavorited === '1';
                const endpoint = isFavorited ? 'fav/remove.php' : 'fav/add.php';

                favoriteToggle.disabled = true;

                const fd = new FormData();
                fd.append('game_id', gameId || '0');
                fd.append('csrf_token', csrfToken);

                fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: fd,
                })
                    .then(async (res) => {
                        const text = await res.text();
                        let payload = null;
                        try {
                            payload = JSON.parse(text);
                        } catch (e) {
                            throw new Error('Invalid favorites response.');
                        }
                        if (!res.ok || !payload || payload.success !== true) {
                            throw new Error(payload?.error || 'Could not update favorite state.');
                        }
                        setFavoriteVisualState(!!payload.isFavorited);
                        setFavoriteFeedback(payload.isFavorited ? 'Added to favorites.' : 'Removed from favorites.', false);
                    })
                    .catch((err) => {
                        setFavoriteFeedback(err.message || 'Could not update favorite state.', true);
                    })
                    .finally(() => {
                        favoriteToggle.disabled = false;
                    });
            });
        })();
    </script>

</body>

</html>