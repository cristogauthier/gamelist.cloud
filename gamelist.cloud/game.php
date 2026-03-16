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

// ─── DECODE JSON COLUMNS ──────────────────────────────────────────────────────
$developers  = json_decode($game['developer'],   true) ?? [];
$genres      = json_decode($game['genres'],      true) ?? [];
$tagsRaw     = json_decode($game['tags'],        true) ?? [];
$screenshots = json_decode($game['screenshots'], true) ?? [];
$tags        = array_keys($tagsRaw); // just the names, not the vote counts
$positive = (int)($game['positive'] ?? 0);
$negative = (int)($game['negative'] ?? 0);
$total    = $positive + $negative;
$ratio    = $total > 0 ? $positive / $total : null;  // 0–1 float for starGauge

function starGauge($note) {
    if ($note === null) {
        return '<span class="stars-na">No score</span>';
    }
    $pct   = round($note * 100);
    $width = number_format($note * 100, 2);
    return '<span class="stars-gauge" title="' . $pct . '%">
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
        <img src="<?= htmlspecialchars($game['banner'] ?? '') ?>"
             alt="<?= htmlspecialchars($game['name']) ?>"
             class="detail-banner">

        <div class="detail-hero-info">
            <h1><?= htmlspecialchars($game['name']) ?></h1>

            <p class="detail-meta">
                <span>📅 <?= htmlspecialchars($game['publication_date'] ?? 'Unknown') ?></span>

                <a href="https://store.steampowered.com/app/<?= (int)$game['id'] ?>/"
                target="_blank"
                rel="noopener noreferrer"
                class="steam-link"
                title="View on Steam">
                    <img src="https://external-content.duckduckgo.com/ip3/store.steampowered.com.ico"
                        alt="Steam"
                        class="steam-icon">
                    Steam Store
                </a>    


                <span class="detail-score"><?= starGauge($ratio) ?></span>
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
                    <a href="index.php?tag=<?= urlencode($t) ?>"
                       class="pill pill-tag"><?= htmlspecialchars($t) ?></a>
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
                <img src="<?= htmlspecialchars($shot) ?>"
                     alt="Screenshot <?= $i + 1 ?>"
                     loading="lazy"
                     class="screenshot-thumb"
                     data-src="<?= htmlspecialchars($shot) ?>">
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
    const thumbs   = Array.from(document.querySelectorAll('.screenshot-thumb'));
    const lightbox = document.getElementById('lightbox');
    const lbImg    = document.getElementById('lightboxImg');
    let current    = 0;

    function open(index) {
        current = index;
        lbImg.src = thumbs[current].dataset.src;
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        lightbox.classList.remove('active');
        // Clear src after fade-out transition
        setTimeout(function() { lbImg.src = ''; }, 250);
        document.body.style.overflow = '';
    }

    function navigate(dir) {
        current = (current + dir + thumbs.length) % thumbs.length;
        lbImg.src = thumbs[current].dataset.src;
    }

    thumbs.forEach(function(img, i) {
        img.addEventListener('click', function() { open(i); });
    });

    document.getElementById('lightboxClose').addEventListener('click', close);
    document.getElementById('lightboxPrev').addEventListener('click', function(e) {
        e.stopPropagation();
        navigate(-1);
    });
    document.getElementById('lightboxNext').addEventListener('click', function(e) {
        e.stopPropagation();
        navigate(1);
    });

    // Click on backdrop (not on image/buttons) closes
    lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox || e.target === lbImg) close();
    });

    document.addEventListener('keydown', function(e) {
        if (!lightbox.classList.contains('active')) return;
        if (e.key === 'Escape')     close();
        if (e.key === 'ArrowLeft')  navigate(-1);
        if (e.key === 'ArrowRight') navigate(1);
    });
})();
</script>

</body>
</html>
                