<?php
// ============================================================
// api/v1/agency/submit.php
// POST /api/v1/agency/articles — Single article submission
//
// Accepts a JSON body describing one article, validates it,
// downloads the image, inserts into news + agency_articles.
// Requires X-Agency-Key + X-Agency-Secret headers.
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-Key, X-Agency-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../auth/agency_auth.php';

$agency = requireAgency($pdo);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// ── Validate required fields ──────────────────────────────────────────────────
$required = ['title', 'content', 'category_id'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "Field '{$field}' is required"]);
        exit;
    }
}

$externalId  = trim($input['external_id'] ?? '');
$title       = trim($input['title']);
$content     = trim($input['content']);
$summary     = trim($input['summary']     ?? substr(strip_tags($content), 0, 250));
$categoryId  = (int)$input['category_id'];
$language    = trim($input['language']    ?? 'hi');
$imageUrl    = trim($input['image_url']   ?? '');
$tags        = is_array($input['tags'] ?? null) ? $input['tags'] : [];
$publishedAt = trim($input['published_at'] ?? date('Y-m-d H:i:s'));
$sourceUrl   = trim($input['source_url']  ?? '');

// Sanitise
$title   = htmlspecialchars($title,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$summary = htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Validate category exists
$catCheck = $pdo->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
$catCheck->execute([$categoryId]);
if (!$catCheck->fetch()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid category_id']);
    exit;
}

// ── Duplicate check on (external_id, agency_id) ───────────────────────────────
if ($externalId !== '') {
    $dupCheck = $pdo->prepare(
        'SELECT id FROM agency_articles WHERE external_id = ? AND agency_id = ? LIMIT 1'
    );
    $dupCheck->execute([$externalId, $agency['id']]);
    if ($dupCheck->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error'   => 'Duplicate article: external_id already submitted by this agency',
        ]);
        exit;
    }
}

// ── Download and store image ──────────────────────────────────────────────────
$localImage = null;
if ($imageUrl !== '') {
    $localImage = _downloadImage($imageUrl);
    // Non-fatal: if download fails we log it but continue
    if (is_array($localImage) && isset($localImage['error'])) {
        error_log('agency submit image download failed: ' . $localImage['error'] . ' url=' . $imageUrl);
        $localImage = null;
    }
}

// ── Parse published_at ────────────────────────────────────────────────────────
$publishedTs = strtotime($publishedAt);
$publishedAt = $publishedTs ? date('Y-m-d H:i:s', $publishedTs) : date('Y-m-d H:i:s');

// ── Generate slug ─────────────────────────────────────────────────────────────
$slugBase = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title)));
$slug     = rtrim($slugBase, '-') . '-' . time();

// ── Insert into news ──────────────────────────────────────────────────────────
$pdo->beginTransaction();

try {
    $newsStmt = $pdo->prepare(
        'INSERT INTO news
            (agency_id, is_agency_content, title, slug, description, content,
             category_id, language, image, status, created_at, updated_at)
         VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, NOW())'
    );
    $newsStmt->execute([
        $agency['id'],
        $title,
        $slug,
        $summary,
        $content,
        $categoryId,
        $language,
        $localImage,
        $publishedAt,
    ]);
    $newsId = (int)$pdo->lastInsertId();

    // ── Insert into agency_articles ───────────────────────────────────────────
    $aaStmt = $pdo->prepare(
        'INSERT INTO agency_articles
            (agency_id, news_id, source_url, external_id, submitted_via, status, created_at)
         VALUES (?, ?, ?, ?, \'api\', \'pending\', NOW())'
    );
    $aaStmt->execute([
        $agency['id'],
        $newsId,
        $sourceUrl ?: null,
        $externalId !== '' ? $externalId : null,
    ]);

    // ── Handle tags ───────────────────────────────────────────────────────────
    if (!empty($tags)) {
        _insertTags($pdo, $newsId, $tags);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('agency submit error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to submit article']);
    exit;
}

echo json_encode([
    'success' => true,
    'news_id' => $newsId,
    'status'  => 'pending',
    'message' => 'Article submitted for review',
]);

// ── Private helpers ───────────────────────────────────────────────────────────

/**
 * Download a remote image and save it to web/uploads/agency/.
 * Returns the relative path on success, or ['error' => '...'] on failure.
 */
function _downloadImage(string $url): string|array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid image URL'];
    }

    $uploadDir = __DIR__ . '/../../../web/uploads/agency/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return ['error' => 'Cannot create upload directory'];
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 10,
            'follow_location' => true,
            'max_redirects'   => 3,
            'user_agent'      => 'NewsXpressLive/1.0',
        ],
        'ssl' => ['verify_peer' => true],
    ]);

    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 512) {
        return ['error' => 'Failed to download image'];
    }

    // Verify MIME type from content
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($data);

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowedMime[$mimeType])) {
        return ['error' => 'Remote URL did not return a supported image type'];
    }

    if (strlen($data) > 5 * 1024 * 1024) {
        return ['error' => 'Remote image exceeds 5 MB limit'];
    }

    $ext      = $allowedMime[$mimeType];
    $filename = 'agency_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $fullPath = $uploadDir . $filename;

    if (file_put_contents($fullPath, $data) === false) {
        return ['error' => 'Failed to write image to disk'];
    }

    return 'uploads/agency/' . $filename;
}

/**
 * Insert tags for a news article.
 * Silently skips invalid tags.
 */
function _insertTags(PDO $pdo, int $newsId, array $tags): void
{
    foreach ($tags as $tagName) {
        $tagName = trim((string)$tagName);
        if ($tagName === '' || mb_strlen($tagName) > 100) {
            continue;
        }
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $tagName));

        // Upsert tag
        $pdo->prepare(
            'INSERT INTO tags (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        )->execute([$tagName, $slug]);
        $tagId = (int)$pdo->lastInsertId();

        // Link to article (ignore duplicate)
        try {
            $pdo->prepare(
                'INSERT IGNORE INTO news_tags (news_id, tag_id) VALUES (?, ?)'
            )->execute([$newsId, $tagId]);
        } catch (Throwable) {
            // ignore
        }
    }
}
