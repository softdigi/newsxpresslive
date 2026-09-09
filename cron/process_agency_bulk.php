<?php
// ============================================================
// cron/process_agency_bulk.php
// Background processor for queued agency_bulk_uploads jobs.
//
// Run via system cron every 2–5 minutes:
//   */2 * * * * php /path/to/cron/process_agency_bulk.php >> /var/log/agency_bulk.log 2>&1
//
// Processing logic mirrors api/v1/agency/bulk_submit.php:
//   1. Select queued jobs (max 5 at a time)
//   2. Load payload JSON from file_path
//   3. For each article: validate, deduplicate, insert news + agency_articles
//   4. Update job row with counts and status
// ============================================================

declare(strict_types=1);

// Ensure only CLI execution
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';

define('BATCH_LIMIT', 5);   // max jobs to process per run
define('MAX_ROWS_PER_JOB', 500);

$jobs = fetchQueuedJobs($pdo);
if (empty($jobs)) {
    echo '[' . date('Y-m-d H:i:s') . '] No queued bulk jobs.' . PHP_EOL;
    exit(0);
}

foreach ($jobs as $job) {
    echo '[' . date('Y-m-d H:i:s') . "] Processing job #{$job['id']} ({$job['total_rows']} rows)" . PHP_EOL;
    processJob($pdo, $job);
}

echo '[' . date('Y-m-d H:i:s') . "] Done. Processed " . count($jobs) . " job(s)." . PHP_EOL;
exit(0);

// ── Functions ─────────────────────────────────────────────────────────────────

function fetchQueuedJobs(PDO $pdo): array
{
    // Claim jobs atomically: update status first to prevent double-processing
    $pdo->exec(
        "UPDATE agency_bulk_uploads
         SET    status = 'processing'
         WHERE  status = 'queued'
         ORDER BY created_at ASC
         LIMIT " . BATCH_LIMIT
    );

    $stmt = $pdo->prepare(
        "SELECT abu.id, abu.agency_id, abu.file_path, abu.total_rows,
                ag.revenue_share_percent, ag.name AS agency_name, ag.email AS agency_email
         FROM   agency_bulk_uploads abu
         JOIN   agencies ag ON ag.id = abu.agency_id
         WHERE  abu.status = 'processing'
         ORDER BY abu.created_at ASC
         LIMIT " . BATCH_LIMIT
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function processJob(PDO $pdo, array $job): void
{
    $jobId    = (int)$job['id'];
    $agencyId = (int)$job['agency_id'];

    // ── Resolve payload file ──────────────────────────────────────────────────
    // file_path is stored relative to project root
    $basePath = __DIR__ . '/../web/';

    // Try the stored path first; fall back to agency_bulk directory
    $relPath  = $job['file_path'];
    $fullPath = __DIR__ . '/../' . $relPath;

    // CSV uploads stored a CSV file; bulk_submit stored a .json payload alongside
    if (!file_exists($fullPath)) {
        // Try the json payload written by bulk_submit or csv_upload
        $jsonPath = __DIR__ . '/../web/uploads/agency_bulk/' . pathinfo($relPath, PATHINFO_FILENAME) . '.json';
        if (file_exists($jsonPath)) {
            $fullPath = $jsonPath;
        } else {
            _failJob($pdo, $jobId, 'Payload file not found: ' . $relPath);
            return;
        }
    }

    $content = file_get_contents($fullPath);
    if ($content === false) {
        _failJob($pdo, $jobId, 'Cannot read payload file');
        return;
    }

    $articles = json_decode($content, true);
    if (!is_array($articles)) {
        _failJob($pdo, $jobId, 'Invalid JSON payload');
        return;
    }

    if (count($articles) > MAX_ROWS_PER_JOB) {
        _failJob($pdo, $jobId, 'Payload exceeds ' . MAX_ROWS_PER_JOB . ' row limit');
        return;
    }

    // ── Process each article ──────────────────────────────────────────────────
    $successCount   = 0;
    $failedCount    = 0;
    $duplicateCount = 0;
    $errorLog       = [];

    foreach ($articles as $i => $article) {
        $row = $i + 1;

        // Required field check
        if (empty($article['title']) || empty($article['content']) || empty($article['category_id'])) {
            $failedCount++;
            $errorLog[] = ['row' => $row, 'reason' => 'Missing required fields: title, content, category_id'];
            continue;
        }

        $externalId = trim($article['external_id'] ?? '');

        // Duplicate check
        if ($externalId !== '') {
            $dupCheck = $pdo->prepare(
                'SELECT id FROM agency_articles WHERE external_id = ? AND agency_id = ? LIMIT 1'
            );
            $dupCheck->execute([$externalId, $agencyId]);
            if ($dupCheck->fetch()) {
                $duplicateCount++;
                $errorLog[] = ['row' => $row, 'reason' => 'Duplicate external_id: ' . $externalId];
                continue;
            }
        }

        // Category check
        $catCheck = $pdo->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
        $catCheck->execute([(int)$article['category_id']]);
        if (!$catCheck->fetch()) {
            $failedCount++;
            $errorLog[] = ['row' => $row, 'reason' => 'Invalid category_id: ' . $article['category_id']];
            continue;
        }

        try {
            $title       = htmlspecialchars(trim($article['title']),   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $content     = trim($article['content']);
            $summary     = htmlspecialchars(trim($article['summary'] ?? substr(strip_tags($content), 0, 250)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $categoryId  = (int)$article['category_id'];
            $language    = trim($article['language'] ?? 'hi');
            $sourceUrl   = trim($article['source_url'] ?? '');
            $publishedTs = strtotime($article['published_at'] ?? 'now');
            $publishedAt = $publishedTs ? date('Y-m-d H:i:s', $publishedTs) : date('Y-m-d H:i:s');
            $slug        = rtrim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title)), '-')
                           . '-' . time() . $i;

            // Download image (non-fatal)
            $localImage = null;
            $imageUrl   = trim($article['image_url'] ?? '');
            if ($imageUrl !== '') {
                $dl = _downloadImage($imageUrl);
                if (is_string($dl)) {
                    $localImage = $dl;
                }
            }

            $pdo->beginTransaction();

            $newsStmt = $pdo->prepare(
                'INSERT INTO news
                    (agency_id, is_agency_content, title, slug, description, content,
                     category_id, language, image, status, created_at, updated_at)
                 VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, NOW())'
            );
            $newsStmt->execute([
                $agencyId, $title, $slug, $summary, $content,
                $categoryId, $language, $localImage, $publishedAt,
            ]);
            $newsId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO agency_articles
                    (agency_id, news_id, source_url, external_id, submitted_via, status, created_at)
                 VALUES (?, ?, ?, ?, \'csv_upload\', \'pending\', NOW())'
            )->execute([
                $agencyId, $newsId,
                $sourceUrl ?: null,
                $externalId !== '' ? $externalId : null,
            ]);

            // Handle tags
            if (!empty($article['tags']) && is_array($article['tags'])) {
                foreach ($article['tags'] as $tagName) {
                    $tagName = trim((string)$tagName);
                    if ($tagName === '' || mb_strlen($tagName) > 100) {
                        continue;
                    }
                    $slug2 = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $tagName));
                    $pdo->prepare(
                        'INSERT INTO tags (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
                    )->execute([$tagName, $slug2]);
                    $tagId = (int)$pdo->lastInsertId();
                    try {
                        $pdo->prepare('INSERT IGNORE INTO news_tags (news_id, tag_id) VALUES (?, ?)')->execute([$newsId, $tagId]);
                    } catch (Throwable) {}
                }
            }

            $pdo->commit();
            $successCount++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failedCount++;
            $errorLog[] = ['row' => $row, 'reason' => 'DB error: ' . $e->getMessage()];
            error_log("cron/process_agency_bulk job#{$jobId} row#{$row}: " . $e->getMessage());
        }
    }

    // ── Mark job completed ────────────────────────────────────────────────────
    $pdo->prepare(
        "UPDATE agency_bulk_uploads
         SET    status          = 'completed',
                success_count   = ?,
                failed_count    = ?,
                duplicate_count = ?,
                error_log       = ?,
                completed_at    = NOW()
         WHERE  id = ?"
    )->execute([
        $successCount,
        $failedCount,
        $duplicateCount,
        json_encode($errorLog),
        $jobId,
    ]);

    echo "[{$job['agency_name']}] Job #{$jobId}: success={$successCount} failed={$failedCount} duplicate={$duplicateCount}" . PHP_EOL;
}

function _failJob(PDO $pdo, int $jobId, string $reason): void
{
    $pdo->prepare(
        "UPDATE agency_bulk_uploads
         SET    status    = 'failed',
                error_log = ?,
                completed_at = NOW()
         WHERE  id = ?"
    )->execute([json_encode([['row' => 0, 'reason' => $reason]]), $jobId]);
    error_log("process_agency_bulk job#{$jobId} FAILED: {$reason}");
}

function _downloadImage(string $url): string|null
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $uploadDir = __DIR__ . '/../web/uploads/agency/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return null;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => true, 'max_redirects' => 3]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 512 || strlen($data) > 5 * 1024 * 1024) {
        return null;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    $map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) {
        return null;
    }
    $fn = 'agency_' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    file_put_contents($uploadDir . $fn, $data);
    return 'uploads/agency/' . $fn;
}
