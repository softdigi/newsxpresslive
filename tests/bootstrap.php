<?php
/**
 * PHPUnit bootstrap for NewsXpressLive backend tests.
 *
 * Stubs the minimal globals expected by the app (PDO, config, helpers)
 * so individual test files can include the source under test without
 * starting a real web server or database.
 */

// ── Minimal superglobal stubs ────────────────────────────────────────────────
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

// ── Autoloader (Composer, if present) ────────────────────────────────────────
$composer = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composer)) {
    require $composer;
}

// ── Helper: create an in-memory SQLite PDO that mimics the MySQL schema ───────
function createTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            firebase_uid TEXT    NOT NULL UNIQUE,
            name         TEXT    NOT NULL DEFAULT 'Test User',
            role         TEXT    NOT NULL DEFAULT 'reporter',
            status       TEXT    NOT NULL DEFAULT 'active',
            agency_id    INTEGER,
            country_id   INTEGER,
            state_id     INTEGER,
            district_id  INTEGER
        );

        CREATE TABLE IF NOT EXISTS news (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            title            TEXT,
            slug             TEXT,
            description      TEXT,
            category_id      INTEGER,
            language_id      INTEGER,
            country_id       INTEGER,
            state_id         INTEGER,
            district_id      INTEGER,
            user_id          INTEGER,
            status           TEXT DEFAULT 'pending',
            meta_title       TEXT,
            meta_description TEXT,
            moderation_flag  INTEGER DEFAULT 0,
            created_at       TEXT
        );

        CREATE TABLE IF NOT EXISTS rate_limits (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier  TEXT    NOT NULL,
            action      TEXT    NOT NULL DEFAULT 'default',
            count       INTEGER NOT NULL DEFAULT 0,
            window_end  INTEGER NOT NULL,
            UNIQUE(identifier, action)
        );

        CREATE TABLE IF NOT EXISTS comments (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            news_id     INTEGER,
            author_name TEXT,
            content     TEXT,
            status      TEXT DEFAULT 'pending',
            created_at  TEXT
        );
    SQL);

    return $pdo;
}

// ── Stub jsonResponse() helper used by API files ─────────────────────────────
if (!function_exists('jsonResponse')) {
    function jsonResponse(bool $success, array $data = [], string $msg = ''): void
    {
        // In test context just throw so callers can catch it.
        throw new \RuntimeException(json_encode([
            'success' => $success,
            'data'    => $data,
            'message' => $msg,
        ]));
    }
}
