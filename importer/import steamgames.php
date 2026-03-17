<?php
// run it once manually each time you update the JSON file from the scraper 

require_once __DIR__ . '/vendor/autoload.php';

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

// ─── DB CONNECTION (match your index.php credentials) ────────────────────────
require_once __DIR__ . '/config.php';

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
        tags             TEXT          DEFAULT NULL, -- JSON object e.g. {\"Strategy\": 22}
        description      TEXT          DEFAULT NULL, -- short_description field
        positive         INT UNSIGNED  DEFAULT NULL, -- positive votes count
        negative         INT UNSIGNED  DEFAULT NULL, -- negative votes count
        banner           TEXT  DEFAULT NULL, -- header_image URL
        screenshots      TEXT          DEFAULT NULL  -- JSON array of screenshot URLs
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $conn->exec($sql);
    echo "Table 'steamgames' ready.\n";
}

// ─── IMPORT FUNCTION ──────────────────────────────────────────────────────────
function importSteamGamesFromJson(PDO $conn, string $jsonFilePath): void {
    if (!file_exists($jsonFilePath)) {
        die("File not found: $jsonFilePath\n");
    }

    $data = Items::fromFile($jsonFilePath, ['decoder' => new ExtJsonDecoder(true)]);

    // ON DUPLICATE KEY UPDATE = safe to re-run after each scraper update
    $sql = "INSERT INTO steamgames
                (id, name, publication_date, developer, store,
                 genres, tags, description, positive, negative, banner, screenshots)
            VALUES
                (:id, :name, :publication_date, :developer, :store,
                 :genres, :tags, :description, :positive, :negative, :banner, :screenshots)
            ON DUPLICATE KEY UPDATE
                name             = VALUES(name),
                publication_date = VALUES(publication_date),
                developer        = VALUES(developer),
                genres           = VALUES(genres),
                tags             = VALUES(tags),
                description      = VALUES(description),
                positive         = VALUES(positive),
                negative        = VALUES(negative),
                banner           = VALUES(banner),
                screenshots      = VALUES(screenshots)";

    $stmt     = $conn->prepare($sql);
    $inserted = 0;
    $updated  = 0;

    foreach ($data as $appId => $game) {

        $stmt->execute([
            ':id'               => (int)$appId,
            ':name'             => $game['name']              ?? '',
            ':publication_date' => $game['release_date']      ?? null,
            ':developer'        => isset($game['developers'])
                                    ? json_encode($game['developers'], JSON_UNESCAPED_UNICODE)
                                    : null,
            ':store'            => 'Steam',
            ':genres'           => isset($game['genres'])
                                    ? json_encode($game['genres'], JSON_UNESCAPED_UNICODE)
                                    : null,
            ':tags'             => isset($game['tags'])
                                    ? json_encode($game['tags'], JSON_UNESCAPED_UNICODE)
                                    : null,
            ':description'      => $game['short_description'] ?? null,
            ':positive'         => (int)($game['positive'] ?? 0),
            ':negative'         => (int)($game['negative'] ?? 0),
            ':banner'           => $game['header_image']      ?? null,
            ':screenshots'      => isset($game['screenshots'])
                                    ? json_encode($game['screenshots'], JSON_UNESCAPED_UNICODE)
                                    : null,
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
importSteamGamesFromJson($conn, dirname(__DIR__) . 'databases/games.json');
