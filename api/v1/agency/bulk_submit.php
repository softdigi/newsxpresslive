<?php
// ============================================================
// api/v1/agency/bulk_submit.php
//
// POST /api/v1/agency/articles/bulk
//   — Queue a batch of up to 100 articles for background processing.
//   — Returns {job_id, total, queued: true}
//
// GET /api/v1/agency/articles/bulk/{job_id}
//   — Check the processing status of a previously queued batch.
//   — Returns {job_id, status, total_rows, success_count, failed_count,
//              duplicate_count, error_log, created_at, completed_at}
//
// Background processing: the queued batch is written to agency_bulk_uploads
// with status='queued'.  A cron job (cron/process_agency_bulk.php) picks it
// up and calls _processBulkJob().  Alternatively, if the server can handle
// it within the request timeout, _processBulkJob() is called inline for
// small batches (≤ 10 articles).
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-Key, X-Agency-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../auth/agency_auth.php';

$agency = requireAgency($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: status check ─────────────────────────────────────────────────────────
if ($method === 'GET') {
    // Expect job_id as path segment or query param
    $jobId = (int)(
        $_GET['job_id']
        ?? array_reverse(explode('/', rtrim($_SERVER['PATH_INFO'] ?? '', '/')))[0]
        ?? 0
    );

    if ($jobId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'job_id required']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id, agency_id, filename, total_rows, success_count,
                failed_count, duplicate_count, status, error_log,
                created_at, completed_at
         FROM   agency_bulk_uploads
         WHERE  id = ? AND agency_id = ?
         LIMIT  1'
    );
    $stmt->execute([$jobId, $agency['id']]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Job not found']);
        exit;
    }

    $job['error_log'] = $job['error_log'] ? json_decode($job['error_log'], true) : [];
    echo json_encode(['success' => true, 'job' => $job]);
    exit;
}

// ── POST: submit batch ────────────────────────────────────────────────────────
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST or GET required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Expected a JSON array of articles']);
    exit;
}

// Support both a bare array and {articles: [...]}
$articles = isset($input['articles']) ? $input['articles'] : $input;

if (!is_array($articles) || empty($articles)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'articles array is empty']);
    exit;
}

if (count($articles) > 100) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Maximum 100 articles per bulk request']);
    exit;
}

$total = count($articles);

// Serialise payload to a temporary file so the cron job can read it
$uploadDir = __DIR__ . '/../../../web/uploads/agency_bulk/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Cannot create bulk upload directory']);
    exit;
}

$filename = 'bulk_' . bin2hex(random_bytes(8)) . '.json';
$filePath = $uploadDir . $filename;

if (file_put_contents($filePath, json_encode($articles)) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to write bulk payload']);
    exit;
}

// ── Create agency_bulk_uploads row ────────────────────────────────────────────
$stmt = $pdo->prepare(
    'INSERT INTO agency_bulk_uploads
        (agency_id, filename, file_path, total_rows, status, created_at)
     VALUES (?, ?, ?, ?, \'queued\', NOW())'
);
$stmt->execute([$agency['id'], $filename, 'uploads/agency_bulk/' . $filename, $total]);
$jobId = (int)$pdo->lastInsertId();

// ── Process inline for small batches (≤ 10) to give immediate results ─────────
if ($total <= 10) {
    _processBulkJob($pdo, $jobId, $agency, $articles);
}

// ── Response ──────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT id, status, total_rows, success_count, failed_count, duplicate_count
     FROM   agency_bulk_uploads
     WHERE  id = ?'
);
$stmt->execute([$jobId]);
$job = $stmt->fetch();

echo json_encode([
    'success'  => true,
    'job_id'   => $jobId,
    'total'    => $total,
    'queued'   => ($job['status'] === 'queued'),
    'status'   => $job['status'],
    'message'  => $total <= 10
        ? 'Batch processed immediately'
        : 'Batch queued for background processing. Poll GET /api/v1/agency/articles/bulk/' . $jobId,
    'job'      => $job,
]);

// ── Background processing logic ───────────────────────────────────────────────

/**
 * Process all articles in a bulk job.
 * Called either inline (small batches) or by cron/process_agency_bulk.php.
 */
function _processBulkJob(PDO $pdo, int $jobId, array $agency, array $articles): void
{
    $pdo->prepare(
        "UPDATE agency_bulk_uploads SET status='processing' WHERE id=?"
    )->execute([$jobId]);

    $successCount   = 0;
    $failedCount    = 0;
    $duplicateCount = 0;
    $errorLog       = [];

    foreach ($articles as $i => $article) {
        $row = $i + 1;

        // Basic validation
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
            $dupCheck->execute([$externalId, $agency['id']]);
            if ($dupCheck->fetch()) {
                $duplicateCount++;
                $errorLog[] = ['row' => $row, 'reason' => 'Duplicate external_id: ' . $externalId];
                continue;
            }
        }

        // Validate category
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
            $slug        = rtrim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title)), '-') . '-' . time() . $i;

            // Download image (non-fatal)
            $localImage = null;
            $imageUrl   = trim($article['image_url'] ?? '');
            if ($imageUrl !== '') {
                $dl = _bulkDownloadImage($imageUrl);
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
                $agency['id'], $title, $slug, $summary, $content,
                $categoryId, $language, $localImage, $publishedAt,
            ]);
            $newsId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO agency_articles
                    (agency_id, news_id, source_url, external_id, submitted_via, status, created_at)
                 VALUES (?, ?, ?, ?, \'api\', \'pending\', NOW())'
            )->execute([
                $agency['id'], $newsId,
                $sourceUrl ?: null,
                $externalId !== '' ? $externalId : null,
            ]);

            $pdo->commit();
            $successCount++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failedCount++;
            $errorLog[] = ['row' => $row, 'reason' => 'DB error: ' . $e->getMessage()];
            error_log("bulk_submit job#{$jobId} row#{$row} error: " . $e->getMessage());
        }
    }

    $pdo->prepare(
        "UPDATE agency_bulk_uploads
         SET    status         = 'completed',
                success_count  = ?,
                failed_count   = ?,
                duplicate_count= ?,
                error_log      = ?,
                completed_at   = NOW()
         WHERE  id = ?"
    )->execute([
        $successCount,
        $failedCount,
        $duplicateCount,
        json_encode($errorLog),
        $jobId,
    ]);
}

function _bulkDownloadImage(string $url): string|null
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $uploadDir = __DIR__ . '/../../../web/uploads/agency/';
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
