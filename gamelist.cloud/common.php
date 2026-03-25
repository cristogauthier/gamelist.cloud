<?php
/**
 * Shared utility functions for gamelist.cloud.
 *
 * This file contains common functions used across multiple pages to avoid code duplication.
 */

/**
 * Load and parse the canonical genres whitelist from genres.json.
 *
 * @return array<int, string> List of allowed genre names (empty if file not found/invalid)
 */
function getGenreWhitelist(): array {
    $genresJsonPath = __DIR__ . '/genres.json';
    if (!is_file($genresJsonPath)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($genresJsonPath), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, 'is_string'));
}

/**
 * Lowercase a string safely in UTF-8 environments.
 *
 * Uses mb_strtolower if available for proper UTF-8 support, falls back to strtolower.
 *
 * @param string $value
 * @return string
 */
function lowerSafe(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/**
 * Derive game genres from tag names using whitelist matching.
 *
 * Matches tag names against the canonical genre whitelist (case-insensitive).
 * Useful for deriving structured genre data from flexible tag arrays.
 *
 * @param array<int, string> $tags Raw tag names or identifiers from game record
 * @param array<int, string> $genreWhitelist Allowed genre names (from getGenreWhitelist)
 * @return array<int, string> Matched genre names in whitelist order
 */
function deriveGenresFromTags(array $tags, array $genreWhitelist): array {
    $tagLookup = [];

    foreach ($tags as $tag) {
        if (!is_string($tag)) {
            continue;
        }
        $key = lowerSafe(trim($tag));
        if ($key !== '') {
            $tagLookup[$key] = true;
        }
    }

    $genres = [];
    foreach ($genreWhitelist as $genre) {
        if (isset($tagLookup[lowerSafe($genre)])) {
            $genres[] = $genre;
        }
    }

    return $genres;
}
