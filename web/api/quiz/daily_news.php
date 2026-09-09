<?php
/**
 * web/api/quiz/daily_news.php
 * Authenticated API — today's news-based trivia quiz.
 *
 * GET  — returns today's questions (randomized, correct_option hidden)
 * POST — submit answers, returns score + coins earned + daily rank
 *
 * POST body (JSON):
 * {
 *   "answers": { "1": "a", "2": "c", "5": "b" }  // question_id => chosen option
 * }
 *
 * Header: X-User-Uid: <firebase_uid>
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../helpers/cache.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'POST', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
$uid = trim($_SERVER['HTTP_X_USER_UID'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$uid = preg_replace('/^Bearer\s+/i', '', $uid);
if ($uid === '' || !preg_match('/^[a-zA-Z0-9_\-]{10,128}$/', $uid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$today = date('Y-m-d');

// ─────────────────────────────────────────────────────────────────────────────
// GET — return today's questions (hide correct_option)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $cache     = ApiCache::getInstance();
    $cache_key = "quiz:daily_news:{$today}";

    $questions = $cache->remember($cache_key, 3600, function () use ($pdo, $today): array {
        $stmt = $pdo->prepare(
            'SELECT q.id, q.question, q.option_a, q.option_b, q.option_c, q.option_d,
                    q.difficulty, q.article_id,
                    n.thumbnail_url
             FROM news_quiz_questions q
             LEFT JOIN news n ON n.id = q.article_id
             WHERE q.quiz_date = ?
             ORDER BY q.sort_order ASC, RAND()
             LIMIT 10'
        );
        $stmt->execute([$today]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });

    // Check if user already completed today's quiz
    $session = $pdo->prepare(
        'SELECT completed, correct_answers, score, coins_earned
         FROM news_quiz_sessions
         WHERE user_uid = ? AND quiz_date = ?'
    );
    $session->execute([$uid, $today]);
    $existing = $session->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'quiz_date'  => $today,
        'total'      => count($questions),
        'completed'  => !empty($existing['completed']),
        'questions'  => array_map(function (array $q): array {
            return [
                'id'            => (int)$q['id'],
                'question'      => $q['question'],
                'options'       => [
                    'a' => $q['option_a'],
                    'b' => $q['option_b'],
                    'c' => $q['option_c'],
                    'd' => $q['option_d'],
                ],
                'difficulty'    => $q['difficulty'],
                'thumbnail_url' => $q['thumbnail_url'],
            ];
        }, $questions),
        'your_result' => $existing ? [
            'correct' => (int)$existing['correct_answers'],
            'score'   => (int)$existing['score'],
            'coins'   => (int)$existing['coins_earned'],
        ] : null,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — submit answers
// ─────────────────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['answers']) || !is_array($body['answers'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'answers field required']);
    exit;
}

// Check not already submitted
$check = $pdo->prepare('SELECT id, completed FROM news_quiz_sessions WHERE user_uid = ? AND quiz_date = ?');
$check->execute([$uid, $today]);
$existing_session = $check->fetch(PDO::FETCH_ASSOC);

if ($existing_session && (int)$existing_session['completed'] === 1) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Quiz already completed for today']);
    exit;
}

// Fetch today's questions with correct answers
$stmt = $pdo->prepare(
    'SELECT id, correct_option FROM news_quiz_questions WHERE quiz_date = ?'
);
$stmt->execute([$today]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($questions)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No quiz available for today']);
    exit;
}

// Score the answers
$correct        = 0;
$total          = count($questions);
$user_answers   = $body['answers'];
$time_taken     = max(0, (int)($body['time_taken_seconds'] ?? 0));

foreach ($questions as $q) {
    $qid    = (string)$q['id'];
    $chosen = strtolower(trim($user_answers[$qid] ?? ''));
    if (in_array($chosen, ['a','b','c','d'], true) && $chosen === $q['correct_option']) {
        $correct++;
    }
}

// ── Coins calculation ─────────────────────────────────────────────────────────
$coins = 0;
if ($total >= 10 && $correct >= 10)      $coins = 20;
elseif ($correct >= 7)                   $coins = 10;
elseif ($correct >= 5)                   $coins = 5;

// Score: correct * 10 - time penalty (1 point per 5 seconds)
$score = $correct * 10 - (int)floor($time_taken / 5);
$score = max(0, $score);

// ── Check daily streak for 50-coin bonus ─────────────────────────────────────
$streak_bonus = 0;
$streak_stmt  = $pdo->prepare(
    'SELECT COUNT(*) FROM news_quiz_sessions
     WHERE user_uid = ? AND quiz_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
       AND completed = 1'
);
$streak_stmt->execute([$uid]);
$streak_days = (int)$streak_stmt->fetchColumn();
if ($streak_days >= 6) { // 6 previous + today = 7 streak
    $coins       += 50;
    $streak_bonus = 50;
}

// ── Upsert session ────────────────────────────────────────────────────────────
$pdo->prepare(
    'INSERT INTO news_quiz_sessions
       (user_uid, quiz_date, questions_attempted, correct_answers,
        time_taken_seconds, score, coins_earned, completed)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
       questions_attempted = VALUES(questions_attempted),
       correct_answers     = VALUES(correct_answers),
       time_taken_seconds  = VALUES(time_taken_seconds),
       score               = VALUES(score),
       coins_earned        = VALUES(coins_earned),
       completed           = 1'
)->execute([$uid, $today, $total, $correct, $time_taken, $score, $coins]);

// ── Compute daily rank ────────────────────────────────────────────────────────
$rank_stmt = $pdo->prepare(
    'SELECT COUNT(*) + 1 FROM news_quiz_sessions
     WHERE quiz_date = ? AND score > ? AND completed = 1'
);
$rank_stmt->execute([$today, $score]);
$rank = (int)$rank_stmt->fetchColumn();

// Update rank in session
$pdo->prepare('UPDATE news_quiz_sessions SET rank_daily = ? WHERE user_uid = ? AND quiz_date = ?')
    ->execute([$rank, $uid, $today]);

// ── Return correct answers for review ────────────────────────────────────────
$q_with_answers = $pdo->prepare(
    'SELECT id, correct_option, explanation FROM news_quiz_questions WHERE quiz_date = ?'
);
$q_with_answers->execute([$today]);
$answers_map = [];
foreach ($q_with_answers->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $answers_map[(string)$row['id']] = [
        'correct'     => $row['correct_option'],
        'explanation' => $row['explanation'],
    ];
}

echo json_encode([
    'success'          => true,
    'quiz_date'        => $today,
    'total_questions'  => $total,
    'correct_answers'  => $correct,
    'score'            => $score,
    'rank'             => $rank,
    'coins_earned'     => $coins,
    'streak_bonus'     => $streak_bonus,
    'answers'          => $answers_map,
]);
