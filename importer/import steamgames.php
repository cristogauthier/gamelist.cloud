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
    $inserted = 0;
    $updated  = 0;

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

        // Banner link from appid
        $banner = "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appId}/header.jpg";

        // Screenshots: extract filename(s) and map to steamstatic URLs.
        $screenshotUrls = [];
        $screenshotsData = null;
        if (isset($game['screenshots']) && is_array($game['screenshots'])) {
            // Some payload uses all_ages_screenshots array
            if (isset($game['screenshots']['all_ages_screenshots']) && is_array($game['screenshots']['all_ages_screenshots'])) {
                foreach ($game['screenshots']['all_ages_screenshots'] as $shot) {
                    if (is_array($shot) && !empty($shot['filename'])) {
                        $screenshotUrls[] = "https://shared.akamai.steamstatic.com/store_item_assets/{$shot['filename']}";
                    }
                }
            } else {
                foreach ($game['screenshots'] as $shot) {
                    if (is_array($shot) && !empty($shot['filename'])) {
                        $screenshotUrls[] = "https://shared.akamai.steamstatic.com/store_item_assets/{$shot['filename']}";
                    } elseif (is_string($shot) && trim($shot) !== '') {
                        $screenshotUrls[] = "https://shared.akamai.steamstatic.com/store_item_assets/{$shot}";
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
    }

    echo "Done — $inserted inserted, $updated updated.\n";
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