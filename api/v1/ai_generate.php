<?php
// ============================================================
// api/v1/ai_generate.php
//
// AJAX endpoint for the AI news generator.
//
// POST  action=generate   → generate article from topic
// POST  action=save       → save generated article to DB as pending
//
// Requires: admin session (admin_panel login)
// Returns: JSON
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../../admin_panel/includes/config.php';
require_once __DIR__ . '/../../admin_panel/includes/auth.php';
require_once __DIR__ . '/../../admin_panel/includes/csrf.php';
require_once __DIR__ . '/../../helpers/ai_news_generator.php';

header('Content-Type: application/json');

// Must be a logged-in admin/reporter/editor
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Rate-limit: max 10 generate calls per minute per session
$rateKey = 'ai_gen_count_' . session_id();
$_SESSION[$rateKey] = ($_SESSION[$rateKey] ?? 0) + 1;
$rateTs  = 'ai_gen_ts_' . session_id();
if (empty($_SESSION[$rateTs]) || (time() - $_SESSION[$rateTs]) > 60) {
    $_SESSION[$rateTs]  = time();
    $_SESSION[$rateKey] = 1;
}
if ($_SESSION[$rateKey] > 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait a minute.']);
    exit;
}

$action = trim($_POST['action'] ?? '');

// ---- ACTION: generate ------------------------------------------------
if ($action === 'generate') {
    verify_csrf();

    $topic = trim($_POST['topic'] ?? '');
    $lang  = in_array($_POST['lang'] ?? 'en', ['en', 'hi'], true) ? $_POST['lang'] : 'en';

    if ($topic === '' || mb_strlen($topic) > 300) {
        echo json_encode(['error' => 'Topic is required (max 300 characters).']);
        exit;
    }

    $result = aiGenerateNewsArticle($topic, $lang);
    echo json_encode($result);
    exit;
}

// ---- ACTION: save ----------------------------------------------------
if ($action === 'save') {
    verify_csrf();

    $user        = $_SESSION['admin'];
    $reporter_id = (int)$user['id'];
    $agency_id   = isset($user['agency_id']) ? (int)$user['agency_id'] : null;

    $title            = mb_substr(trim($_POST['title']            ?? ''), 0, 255, 'UTF-8');
    $content          = trim($_POST['content']          ?? '');
    $meta_title       = mb_substr(trim($_POST['meta_title']       ?? ''), 0, 255, 'UTF-8');
    $meta_description = mb_substr(trim($_POST['meta_description'] ?? ''), 0, 500, 'UTF-8');
    $topic            = mb_substr(trim($_POST['topic']            ?? ''), 0, 255, 'UTF-8');

    if ($title === '' || $content === '') {
        echo json_encode(['error' => 'Title and content are required.']);
        exit;
    }

    // Sanitise content — only safe HTML tags
    $content = strip_tags($content, '<p><br><strong><em><ul><ol><li><a>');

    // Generate slug
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $title), '-'));
    // Ensure uniqueness
    $slugCheck = $pdo->prepare("SELECT id FROM news WHERE slug = ? LIMIT 1");
    $slugCheck->execute([$slug]);
    if ($slugCheck->fetch()) {
        $slug .= '-' . time();
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO news
                (title, slug, content, reporter_id, agency_id, status,
                 meta_title, meta_description, ai_generated, ai_topic, created_at)
            VALUES
                (?, ?, ?, ?, ?, 'pending', ?, ?, 1, ?, NOW())
        ");
        $stmt->execute([
            $title,
            $slug,
            $content,
            $reporter_id,
            $agency_id,
            $meta_title,
            $meta_description,
            $topic,
        ]);
        $newsId = (int)$pdo->lastInsertId();
        echo json_encode(['success' => true, 'news_id' => $newsId, 'slug' => $slug]);
    } catch (Throwable $e) {
        error_log('AINews save error: ' . $e->getMessage());
        // If ai_generated/ai_topic columns don't exist yet, retry without them
        try {
            $stmt2 = $pdo->prepare("
                INSERT INTO news
                    (title, slug, content, reporter_id, agency_id, status,
                     meta_title, meta_description, created_at)
                VALUES
                    (?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            ");
            $stmt2->execute([
                $title, $slug, $content, $reporter_id, $agency_id,
                $meta_title, $meta_description,
            ]);
            $newsId = (int)$pdo->lastInsertId();
            echo json_encode(['success' => true, 'news_id' => $newsId, 'slug' => $slug]);
        } catch (Throwable $e2) {
            error_log('AINews save fallback error: ' . $e2->getMessage());
            echo json_encode(['error' => 'Failed to save article. Please try again.']);
        }
    }
    exit;
}

echo json_encode(['error' => 'Invalid action.']);
