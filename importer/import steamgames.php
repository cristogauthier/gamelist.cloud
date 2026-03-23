<?php
// run it once manually each time you update the JSON file from the scraper 

require_once __DIR__ . '/vendor/autoload.php';

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

// ─── DB CONNECTION (match your index.php credentials) ────────────────────────
require_once __DIR__ . '/../gamelist.cloud/config.php';

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ─── CREATE TABLE ─────────────────────────────────────────────────────────────
function createSteamGamesTable(PDO $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS steamgames (
        id               INT UNSIGNED  PRIMARY KEY,  -- Steam AppID (JSON key)
        name             TEXT          NOT NULL,
        publication_date VARCHAR(50)   DEFAULT NULL, -- stored as string e.g. 'Nov 6, 2018'
        developer        TEXT          DEFAULT NULL, -- JSON array  e.g. [\"Artur Podzorski\"]
        store            VARCHAR(50)   DEFAULT 'Steam',
        genres           TEXT          DEFAULT NULL, -- JSON array  e.g. [\"Strategy\"]
        tags             TEXT          DEFAULT NULL, -- JSON array of tag names
        description      TEXT          DEFAULT NULL, -- short_description field
        percent_positive INT UNSIGNED  DEFAULT NULL, -- percent positive review score
        review_count     INT UNSIGNED  DEFAULT NULL, -- total reviews count
        banner           TEXT  DEFAULT NULL, -- header_image URL
        screenshots      TEXT          DEFAULT NULL  -- JSON array of screenshot URLs
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $conn->exec($sql);

    echo "Table 'steamgames' ready.\n";
}

function loadTagIdToNameMap(string $taglistPath): array {
    if (!file_exists($taglistPath)) {
        return [];
    }

    $json = file_get_contents($taglistPath);
    if ($json === false) {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !isset($decoded['response']['tags']) || !is_array($decoded['response']['tags'])) {
        return [];
    }

    $map = [];
    foreach ($decoded['response']['tags'] as $tag) {
        if (!is_array($tag)) {
            continue;
        }
        if (isset($tag['tagid']) && isset($tag['name'])) {
            $map[(int)$tag['tagid']] = $tag['name'];
        }
    }
    return $map;
}

function mapGameTagsToNames(array $game, array $tagMap): array {
    $tagNames = [];

    if (isset($game['tags']) && is_array($game['tags'])) {
        foreach ($game['tags'] as $tag) {
            if (is_array($tag) && isset($tag['tagid'])) {
                $tagId = (int)$tag['tagid'];
                if (isset($tagMap[$tagId])) {
                    $tagNames[] = $tagMap[$tagId];
                }
            } elseif (is_int($tag) || ctype_digit((string)$tag)) {
                $id = (int)$tag;
                if (isset($tagMap[$id])) {
                    $tagNames[] = $tagMap[$id];
                }
            } elseif (is_string($tag)) {
                $tagNames[] = $tag;
            }
        }
    } elseif (isset($game['tagids']) && is_array($game['tagids'])) {
        foreach ($game['tagids'] as $tagId) {
            $id = (int)$tagId;
            if (isset($tagMap[$id])) {
                $tagNames[] = $tagMap[$id];
            }
        }
    }

    return array_values(array_unique($tagNames));
}

function getTopTwoWeightedGenreNames(array $game, array $tagMap): array {
    $weightedTags = [];
    if (isset($game['tags']) && is_array($game['tags'])) {
        foreach ($game['tags'] as $tag) {
            if (is_array($tag) && isset($tag['tagid'])) {
                $tagId = (int)$tag['tagid'];
                $weight = isset($tag['weight']) ? (int)$tag['weight'] : 0;
                if (isset($tagMap[$tagId])) {
                    $weightedTags[] = ['name' => $tagMap[$tagId], 'weight' => $weight];
                }
            }
        }
    } elseif (isset($game['tagids']) && is_array($game['tagids'])) {
        foreach ($game['tagids'] as $tagId) {
            $id = (int)$tagId;
            if (isset($tagMap[$id])) {
                $weightedTags[] = ['name' => $tagMap[$id], 'weight' => 0];
            }
        }
    }

    usort($weightedTags, fn($a, $b) => $b['weight'] <=> $a['weight']);
    $top = array_slice($weightedTags, 0, 2);
    return array_values(array_unique(array_column($top, 'name')));
}

function buildAssetPathFromFormat(?string $assetUrlFormat, string $filename): ?string {
    $filename = trim($filename);
    if ($filename === '') {
        return null;
    }

    $filename = ltrim($filename, '/');
    if ($assetUrlFormat !== null && $assetUrlFormat !== '') {
        $path = str_replace(['${filename}', '${FILENAME}'], $filename, $assetUrlFormat);
        $path = ltrim($path, '/');

        // Safety normalization: if a full steam/apps/... filename slips into the template,
        // collapse duplicate app path segments and duplicated ?t query parameters.
        $path = preg_replace('#(steam/apps/\d+/)(?:steam/apps/\d+/)+#', '$1', $path) ?? $path;
        if (preg_match('/\?t=\d+\?t=\d+$/', $path)) {
            $path = preg_replace('/\?t=(\d+)\?t=\d+$/', '?t=$1', $path) ?? $path;
        }

        return $path;
    }

    return $filename;
}

function extractAssetLeafFilename(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $path = parse_url($value, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
        $value = $path;
    }

    return basename($value);
}

function startParallelJsonCounter(string $jsonFilePath): ?array {
    if (!function_exists('proc_open')) {
        return null;
    }

    $tmpDir = sys_get_temp_dir();
    $token = uniqid('steamgames_counter_', true);
    $counterScriptPath = $tmpDir . DIRECTORY_SEPARATOR . $token . '.php';
    $counterOutputPath = $tmpDir . DIRECTORY_SEPARATOR . $token . '.count';

    $counterScript = <<<'PHP'
<?php
if ($argc < 4) {
    exit(1);
}

require_once $argv[1];

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

$jsonFile = $argv[2];
$outputFile = $argv[3];

$count = 0;
foreach (Items::fromFile($jsonFile, ['decoder' => new ExtJsonDecoder(true)]) as $_key => $_value) {
    $count++;
}

file_put_contents($outputFile, (string)$count);
PHP;

    if (file_put_contents($counterScriptPath, $counterScript) === false) {
        return null;
    }

    $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBinary)
        . ' ' . escapeshellarg($counterScriptPath)
        . ' ' . escapeshellarg(__DIR__ . '/vendor/autoload.php')
        . ' ' . escapeshellarg($jsonFilePath)
        . ' ' . escapeshellarg($counterOutputPath);

    $nullDev = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['file', $nullDev, 'w'],
        2 => ['file', $nullDev, 'w'],
    ];
    $proc = proc_open($cmd, $desc, $pipes, __DIR__);
    if (!is_resource($proc)) {
        @unlink($counterScriptPath);
        return null;
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    return [
        'proc' => $proc,
        'countFile' => $counterOutputPath,
        'scriptFile' => $counterScriptPath,
        'total' => null,
    ];
}

function refreshParallelJsonCounterTotal(?array &$counter): ?int {
    if ($counter === null) {
        return null;
    }

    if (isset($counter['total']) && is_int($counter['total'])) {
        return $counter['total'];
    }

    $countFile = $counter['countFile'] ?? '';
    if ($countFile !== '' && is_file($countFile)) {
        $raw = trim((string)@file_get_contents($countFile));
        if ($raw !== '' && ctype_digit($raw)) {
            $counter['total'] = (int)$raw;
            return $counter['total'];
        }
    }

    return null;
}

function stopParallelJsonCounter(?array $counter): void {
    if ($counter === null) {
        return;
    }

    if (isset($counter['proc']) && is_resource($counter['proc'])) {
        proc_close($counter['proc']);
    }

    if (!empty($counter['countFile']) && is_file($counter['countFile'])) {
        @unlink($counter['countFile']);
    }
    if (!empty($counter['scriptFile']) && is_file($counter['scriptFile'])) {
        @unlink($counter['scriptFile']);
    }
}

function waitParallelJsonCounter(?array &$counter): void {
    if ($counter === null) {
        return;
    }

    if (isset($counter['proc']) && is_resource($counter['proc'])) {
        proc_close($counter['proc']);
        $counter['proc'] = null;
    }
}

function formatDurationSeconds(int $seconds): string {
    if ($seconds < 60) {
        return $seconds . 's';
    }

    $minutes = intdiv($seconds, 60);
    $remSeconds = $seconds % 60;
    if ($minutes < 60) {
        return $minutes . 'm ' . $remSeconds . 's';
    }

    $hours = intdiv($minutes, 60);
    $remMinutes = $minutes % 60;
    return $hours . 'h ' . $remMinutes . 'm ' . $remSeconds . 's';
}

// ─── IMPORT FUNCTION ──────────────────────────────────────────────────────────
function importSteamGamesFromJson(PDO $conn, string $jsonFilePath, array $tagMap): void {
    if (!file_exists($jsonFilePath)) {
        die("File not found: $jsonFilePath\n");
    }

    $data = Items::fromFile($jsonFilePath, ['decoder' => new ExtJsonDecoder(true)]);

    // ON DUPLICATE KEY UPDATE = safe to re-run after each scraper update
    $sql = "INSERT INTO steamgames
                (id, name, publication_date, developer, store,
                 genres, tags, description, percent_positive, review_count, banner, screenshots)
            VALUES
                (:id, :name, :publication_date, :developer, :store,
                 :genres, :tags, :description, :percent_positive, :review_count, :banner, :screenshots)
            ON DUPLICATE KEY UPDATE
                name             = VALUES(name),
                publication_date = VALUES(publication_date),
                developer        = VALUES(developer),
                genres           = VALUES(genres),
                tags             = VALUES(tags),
                description      = VALUES(description),
                percent_positive = VALUES(percent_positive),
                review_count     = VALUES(review_count),
                banner           = VALUES(banner),
                screenshots      = VALUES(screenshots)";

    $stmt     = $conn->prepare($sql);
    $processed = 0;
    $inserted = 0;
    $updated  = 0;
    $startedAt = microtime(true);
    $reportEvery = 500;
    $parallelCounter = startParallelJsonCounter($jsonFilePath);

    $printProgress = static function (int $processed, int $inserted, int $updated, float $startedAt, ?int $knownTotal, bool $final = false): void {
        $elapsed = max(0.001, microtime(true) - $startedAt);
        $rate = $processed / $elapsed;

        $leftText = 'calculating...';
        $totalText = '?';
        $etaText = 'ETA: calculating...';
        if ($knownTotal !== null) {
            $left = max(0, $knownTotal - $processed);
            $leftText = (string)$left;
            $totalText = (string)$knownTotal;
            $etaSeconds = (int)ceil($left / max($rate, 0.001));
            $etaText = 'ETA: ' . formatDurationSeconds($etaSeconds);
        }

        if ($final) {
            if ($knownTotal !== null) {
                echo "\rProcessed: {$processed}/{$knownTotal} | Inserted: {$inserted} | Updated: {$updated} | Left: 0 | ETA: 0s";
            } else {
                echo "\rProcessed: {$processed} | Inserted: {$inserted} | Updated: {$updated} | Left: 0";
            }
            echo "\n";
            return;
        }

        echo "\rProcessed: {$processed}/{$totalText} | Inserted: {$inserted} | Updated: {$updated} | Left: {$leftText} | " . number_format($rate, 1) . " rows/s | {$etaText}";
    };

    foreach ($data as $appId => $game) {
        if (!is_array($game)) {
            continue;
        }

        $releaseDate = null;
        if (isset($game['release']) && is_array($game['release'])) {
            if (!empty($game['release']['steam_release_date'])) {
                $releaseDate = (int) $game['release']['steam_release_date'];
            } elseif (!empty($game['release']['original_release_date'])) {
                $releaseDate = (int) $game['release']['original_release_date'];
            }
        }

        $publishedAt = null;
        if ($releaseDate > 0) {
            $publishedAt = date('Y-m-d', $releaseDate);
        }

        // Developer from basic_info.developers if available.
        $developerNames = [];
        if (isset($game['basic_info']['developers']) && is_array($game['basic_info']['developers'])) {
            foreach ($game['basic_info']['developers'] as $dev) {
                if (is_array($dev) && isset($dev['name'])) {
                    $developerNames[] = $dev['name'];
                } elseif (is_string($dev)) {
                    $developerNames[] = $dev;
                }
            }
        } elseif (isset($game['developers']) && is_array($game['developers'])) {
            foreach ($game['developers'] as $dev) {
                if (is_array($dev) && isset($dev['name'])) {
                    $developerNames[] = $dev['name'];
                } elseif (is_string($dev)) {
                    $developerNames[] = $dev;
                }
            }
        }
        $developer = !empty($developerNames) ? json_encode(array_values(array_unique($developerNames)), JSON_UNESCAPED_UNICODE) : null;

        // Genres as top 2 weighted tag names.
        $genreNames = getTopTwoWeightedGenreNames($game, $tagMap);
        $genres = !empty($genreNames) ? json_encode($genreNames, JSON_UNESCAPED_UNICODE) : null;

        $tagNames = mapGameTagsToNames($game, $tagMap);
        $tagsData = !empty($tagNames) ? json_encode($tagNames, JSON_UNESCAPED_UNICODE) : null;

        $description = $game['basic_info']['short_description'] ?? $game['short_description'] ?? $game['full_description'] ?? null;

        $assetUrlFormat = isset($game['assets_without_overrides']['asset_url_format'])
            ? (string)$game['assets_without_overrides']['asset_url_format']
            : null;

        // Store only relative asset path; webapp prepends CDN domain.
        $bannerFilename = isset($game['assets_without_overrides']['header'])
            ? (string)$game['assets_without_overrides']['header']
            : '';
        $banner = buildAssetPathFromFormat($assetUrlFormat, $bannerFilename);
        if ($banner === null) {
            $banner = "steam/apps/{$appId}/header.jpg";
        }

        // Screenshots: store relative asset paths built from asset_url_format.
        $screenshotUrls = [];
        $screenshotsData = null;
        if (isset($game['screenshots']) && is_array($game['screenshots'])) {
            $appendScreenshotPath = static function (string $bucket, string $filename) use (&$screenshotUrls, $assetUrlFormat): void {
                $raw = ltrim(trim($filename), '/');
                if ($raw === '') {
                    return;
                }

                // If source already provides full relative screenshot path, keep it as-is.
                if (strpos($raw, 'steam/apps/') === 0) {
                    $screenshotUrls[] = $raw;
                    return;
                }

                $leafName = extractAssetLeafFilename($raw);
                if ($leafName === '') {
                    return;
                }

                // Build from normalized leaf filename only; asset_url_format provides the surrounding path.
                $candidate = $leafName;

                $built = buildAssetPathFromFormat($assetUrlFormat, $candidate);
                if ($built !== null) {
                    $screenshotUrls[] = $built;
                }
            };

            // Some payload uses all_ages_screenshots array
            if (isset($game['screenshots']['all_ages_screenshots']) && is_array($game['screenshots']['all_ages_screenshots'])) {
                foreach ($game['screenshots']['all_ages_screenshots'] as $shot) {
                    if (is_array($shot) && !empty($shot['filename'])) {
                        $appendScreenshotPath('all_ages_screenshots', (string)$shot['filename']);
                    }
                }
            }

            if (isset($game['screenshots']['mature_content_screenshots']) && is_array($game['screenshots']['mature_content_screenshots'])) {
                foreach ($game['screenshots']['mature_content_screenshots'] as $shot) {
                    if (is_array($shot) && !empty($shot['filename'])) {
                        $appendScreenshotPath('mature_content_screenshots', (string)$shot['filename']);
                    }
                }
            }

            if (empty($screenshotUrls)) {
                foreach ($game['screenshots'] as $shot) {
                    if (is_array($shot) && !empty($shot['filename'])) {
                        $appendScreenshotPath('all_ages_screenshots', (string)$shot['filename']);
                    } elseif (is_string($shot) && trim($shot) !== '') {
                        $appendScreenshotPath('all_ages_screenshots', $shot);
                    }
                }
            }
        }
        if (!empty($screenshotUrls)) {
            $screenshotsData = json_encode(array_values(array_unique($screenshotUrls)), JSON_UNESCAPED_UNICODE);
        }

        $percentPositive = null;
        $reviewCount = null;
        if (isset($game['reviews']['summary_filtered']) && is_array($game['reviews']['summary_filtered'])) {
            $percentPositive = isset($game['reviews']['summary_filtered']['percent_positive'])
                ? (int)$game['reviews']['summary_filtered']['percent_positive']
                : null;
            $reviewCount = isset($game['reviews']['summary_filtered']['review_count'])
                ? (int)$game['reviews']['summary_filtered']['review_count']
                : null;
        }

        $stmt->execute([
            ':id'               => (int)$appId,
            ':name'             => $game['name'] ?? '',
            ':publication_date' => $publishedAt,
            ':developer'        => $developer,
            ':store'            => 'Steam',
            ':genres'           => $genres,
            ':tags'             => $tagsData,
            ':description'      => $description,
            ':percent_positive' => $percentPositive,
            ':review_count'     => $reviewCount,
            ':banner'           => $banner,
            ':screenshots'      => $screenshotsData,
        ]);

        // MySQL rowCount: 1 = INSERT, 2 = UPDATE, 0 = no change
        match ($stmt->rowCount()) {
            1       => $inserted++,
            2       => $updated++,
            default => null
        };

        $processed++;
        if (($processed % $reportEvery) === 0) {
            $total = refreshParallelJsonCounterTotal($parallelCounter);
            $printProgress($processed, $inserted, $updated, $startedAt, $total);
        }
    }

    $total = refreshParallelJsonCounterTotal($parallelCounter);
    if ($total === null) {
        waitParallelJsonCounter($parallelCounter);
        $total = refreshParallelJsonCounterTotal($parallelCounter);
    }

    $printProgress($processed, $inserted, $updated, $startedAt, $total, true);
    stopParallelJsonCounter($parallelCounter);

    echo "Done - $inserted inserted, $updated updated.\n";
}

// ─── RUN ──────────────────────────────────────────────────────────────────────
createSteamGamesTable($conn);
$gamesFile = __DIR__ . '/databases/games.json';
#importSteamGamesFromJson($conn, dirname(__DIR__) . '\importer\databases\games.json');
if (!file_exists($gamesFile)) {
    $gamesFile = __DIR__ . '/databases/SteamGames_Sample.json';
}
$tagMap = loadTagIdToNameMap(__DIR__ . '/taglist.json');
importSteamGamesFromJson($conn, $gamesFile, $tagMap);