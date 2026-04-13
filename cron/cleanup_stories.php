<?php
/**
 * cron/cleanup_stories.php
 * CLI-only cron — delete expired stories and their media files.
 *
 * Run hourly: 0 * * * * php /path/to/cron/cleanup_stories.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

declare(strict_types=1);

require_once __DIR__ . '/../web/includes/config.php';

$uploads_dir = __DIR__ . '/../uploads/stories/';

// ── Fetch expired stories ─────────────────────────────────────────────────────
$stmt = $pdo->query("SELECT id, media_url FROM news_stories WHERE expires_at < NOW()");
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($expired)) {
    echo "No expired stories to clean up.\n";
    exit(0);
}

$ids     = array_column($expired, 'id');
$deleted = 0;
$files   = 0;

// ── Delete media files ────────────────────────────────────────────────────────
foreach ($expired as $story) {
    if ($story['media_url']) {
        // Derive local path from URL
        $filename  = basename(parse_url($story['media_url'], PHP_URL_PATH));
        $file_path = $uploads_dir . $filename;
        if ($file_path && is_file($file_path)) {
            unlink($file_path);
            $files++;
        }
    }
}

// ── Delete story_views first (FK respect) ────────────────────────────────────
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$pdo->prepare("DELETE FROM story_views WHERE story_id IN ({$placeholders})")->execute($ids);

// ── Delete stories ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("DELETE FROM news_stories WHERE id IN ({$placeholders})");
$stmt->execute($ids);
$deleted = $stmt->rowCount();

echo date('Y-m-d H:i:s') . " — Deleted {$deleted} expired stories, removed {$files} media files.\n";
