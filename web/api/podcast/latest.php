<?php
/**
 * web/api/podcast/latest.php
 * Public API — latest audio digest.
 *
 * GET /web/api/podcast/latest.php?language=hi[&date=today]
 *
 * Response: { success, date, title, language, duration, file_url,
 *             play_count, articles: [...] }
 * Cache: 1 hour.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../helpers/cache.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$language = preg_replace('/[^a-z]/', '', strtolower($_GET['language'] ?? 'hi')) ?: 'hi';
$date_raw = trim($_GET['date'] ?? 'today');
$date     = $date_raw === 'today' ? date('Y-m-d') : date('Y-m-d', strtotime($date_raw));

$cache_key = "podcast:latest:{$language}:{$date}";
$cache     = ApiCache::getInstance();

$result = $cache->remember($cache_key, 3600, function () use ($pdo, $language, $date): array {
    $stmt = $pdo->prepare(
        'SELECT id, digest_date, language_code, duration_seconds, file_url, article_ids, play_count
         FROM audio_digests
         WHERE language_code = ? AND digest_date <= ?
         ORDER BY digest_date DESC
         LIMIT 1'
    );
    $stmt->execute([$language, $date]);
    $digest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$digest) {
        return ['found' => false];
    }

    $article_ids = json_decode($digest['article_ids'], true) ?? [];

    // Fetch article metadata
    $articles = [];
    if (!empty($article_ids)) {
        $placeholders = implode(',', array_fill(0, count($article_ids), '?'));
        $stmt2 = $pdo->prepare(
            "SELECT id, title, slug, image_url, views FROM news
             WHERE id IN ({$placeholders}) AND status='approved'
             ORDER BY FIELD(id, " . implode(',', $article_ids) . ")"
        );
        $stmt2->execute($article_ids);
        $articles = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
        'found'    => true,
        'date'     => $digest['digest_date'],
        'title'    => 'Daily News Digest – ' . date('d M Y', strtotime($digest['digest_date'])),
        'language' => $digest['language_code'],
        'duration' => (int)$digest['duration_seconds'],
        'file_url' => $digest['file_url'],
        'play_count'=> (int)$digest['play_count'],
        'articles' => $articles,
    ];
});

if (empty($result['found'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No digest available']);
    exit;
}

// Increment play count asynchronously (best-effort, non-blocking)
$pdo->prepare('UPDATE audio_digests SET play_count = play_count + 1 WHERE digest_date = ? AND language_code = ?')
    ->execute([$result['date'], $language]);

echo json_encode(array_merge(['success' => true], $result));
