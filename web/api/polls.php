<?php
/**
 * web/api/polls.php
 *
 * GET   polls.php?news_id=123          → poll(s) for a specific article
 * GET   polls.php?poll_id=5            → single poll with live vote counts
 * GET   polls.php?list=1&page=1        → paginated list of active polls
 * POST  (JSON) { poll_id, option_index, firebase_uid }  → cast / change vote
 *
 * Response shapes
 * ───────────────
 * Single poll:
 *   { poll: { id, question, options:[{index,text,votes,pct}], total_votes,
 *             user_vote, is_active, ends_at } }
 *
 * Vote:
 *   { success, poll_id, option_index, total_votes, counts:[int,…] }
 *
 * List:
 *   { polls:[…], total, page, has_more }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── Helper: build poll response ───────────────────────────────────────────────

function buildPoll(PDO $pdo, int $pollId, ?string $uid): ?array {
    $row = $pdo->prepare(
        'SELECT id, news_id, question, options, is_active, ends_at, created_at
         FROM news_polls WHERE id = :id LIMIT 1'
    );
    $row->execute([':id' => $pollId]);
    $poll = $row->fetch();
    if (!$poll) return null;

    $optTexts = json_decode($poll['options'], true);
    if (!is_array($optTexts)) $optTexts = [];
    $numOpts  = count($optTexts);

    // Vote counts per option
    $counts = array_fill(0, $numOpts, 0);
    $stmt   = $pdo->prepare(
        'SELECT option_index, COUNT(*) AS cnt
         FROM news_poll_votes
         WHERE poll_id = :pid
         GROUP BY option_index'
    );
    $stmt->execute([':pid' => $pollId]);
    foreach ($stmt->fetchAll() as $c) {
        $idx = (int)$c['option_index'];
        if (isset($counts[$idx])) $counts[$idx] = (int)$c['cnt'];
    }

    $total = array_sum($counts);

    // User's vote
    $userVote = null;
    if ($uid !== null) {
        $v = $pdo->prepare(
            'SELECT option_index FROM news_poll_votes
             WHERE poll_id = :pid AND firebase_uid = :uid LIMIT 1'
        );
        $v->execute([':pid' => $pollId, ':uid' => $uid]);
        $vr = $v->fetch();
        if ($vr) $userVote = (int)$vr['option_index'];
    }

    $options = [];
    foreach ($optTexts as $i => $text) {
        $votes = $counts[$i] ?? 0;
        $options[] = [
            'index' => $i,
            'text'  => $text,
            'votes' => $votes,
            'pct'   => $total > 0 ? round($votes / $total * 100, 1) : 0.0,
        ];
    }

    return [
        'id'          => (int)$poll['id'],
        'news_id'     => $poll['news_id'] !== null ? (int)$poll['news_id'] : null,
        'question'    => $poll['question'],
        'options'     => $options,
        'total_votes' => $total,
        'user_vote'   => $userVote,
        'is_active'   => (bool)$poll['is_active'],
        'ends_at'     => $poll['ends_at'],
        'created_at'  => $poll['created_at'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// GET
// ─────────────────────────────────────────────────────────────────────────────

if ($method === 'GET') {

    $uid = isset($_GET['firebase_uid'])
        ? mb_substr(trim($_GET['firebase_uid']), 0, 128)
        : null;

    // Single poll by ID
    if (isset($_GET['poll_id'])) {
        $pollId = (int)$_GET['poll_id'];
        $data   = buildPoll($pdo, $pollId, $uid);
        if (!$data) {
            http_response_code(404);
            echo json_encode(['error' => 'Poll not found']);
            exit;
        }
        echo json_encode(['poll' => $data]);
        exit;
    }

    // Polls for a specific article
    if (isset($_GET['news_id'])) {
        $newsId = (int)$_GET['news_id'];
        $ids    = $pdo->prepare(
            'SELECT id FROM news_polls
             WHERE news_id = :nid AND is_active = 1
               AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY id ASC'
        );
        $ids->execute([':nid' => $newsId]);
        $polls = [];
        foreach ($ids->fetchAll() as $r) {
            $p = buildPoll($pdo, (int)$r['id'], $uid);
            if ($p) $polls[] = $p;
        }
        echo json_encode(['polls' => $polls]);
        exit;
    }

    // Paginated list
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(20, max(1, (int)($_GET['per'] ?? 10)));
    $offset  = ($page - 1) * $perPage;

    $total = (int)$pdo->query(
        'SELECT COUNT(*) FROM news_polls WHERE is_active = 1
         AND (ends_at IS NULL OR ends_at > NOW())'
    )->fetchColumn();

    $rows = $pdo->prepare(
        'SELECT id FROM news_polls
         WHERE is_active = 1 AND (ends_at IS NULL OR ends_at > NOW())
         ORDER BY id DESC LIMIT :lim OFFSET :off'
    );
    $rows->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $rows->bindValue(':off', $offset,  PDO::PARAM_INT);
    $rows->execute();

    $polls = [];
    foreach ($rows->fetchAll() as $r) {
        $p = buildPoll($pdo, (int)$r['id'], $uid);
        if ($p) $polls[] = $p;
    }

    echo json_encode([
        'polls'    => $polls,
        'total'    => $total,
        'page'     => $page,
        'has_more' => ($offset + $perPage) < $total,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — cast vote
// ─────────────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $input       = json_decode(file_get_contents('php://input'), true) ?? [];
    $pollId      = isset($input['poll_id'])      ? (int)$input['poll_id']          : 0;
    $optionIndex = isset($input['option_index']) ? (int)$input['option_index']     : -1;
    $uid         = isset($input['firebase_uid'])
        ? mb_substr(trim($input['firebase_uid']), 0, 128)
        : null;

    if ($pollId <= 0 || $optionIndex < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'poll_id and option_index required']);
        exit;
    }
    if (!$uid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }

    // Validate poll
    $pollRow = $pdo->prepare(
        'SELECT id, options, is_active, ends_at FROM news_polls WHERE id = :id LIMIT 1'
    );
    $pollRow->execute([':id' => $pollId]);
    $poll = $pollRow->fetch();

    if (!$poll) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Poll not found']);
        exit;
    }
    if (!$poll['is_active'] || ($poll['ends_at'] && strtotime($poll['ends_at']) < time())) {
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => 'Poll has ended']);
        exit;
    }
    $optTexts = json_decode($poll['options'], true);
    if (!is_array($optTexts) || $optionIndex >= count($optTexts)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid option_index']);
        exit;
    }

    try {
        // Upsert: if user already voted, update their choice
        $pdo->prepare(
            'INSERT INTO news_poll_votes (poll_id, firebase_uid, option_index)
             VALUES (:pid, :uid, :opt)
             ON DUPLICATE KEY UPDATE option_index = :opt2'
        )->execute([
            ':pid'  => $pollId,
            ':uid'  => $uid,
            ':opt'  => $optionIndex,
            ':opt2' => $optionIndex,
        ]);

        // Return updated counts
        $stmt = $pdo->prepare(
            'SELECT option_index, COUNT(*) AS cnt
             FROM news_poll_votes WHERE poll_id = :pid GROUP BY option_index'
        );
        $stmt->execute([':pid' => $pollId]);
        $counts = array_fill(0, count($optTexts), 0);
        foreach ($stmt->fetchAll() as $c) {
            $idx = (int)$c['option_index'];
            if (isset($counts[$idx])) $counts[$idx] = (int)$c['cnt'];
        }
        $total = array_sum($counts);

        echo json_encode([
            'success'      => true,
            'poll_id'      => $pollId,
            'option_index' => $optionIndex,
            'total_votes'  => $total,
            'counts'       => $counts,
        ]);
    } catch (PDOException $e) {
        error_log('polls.php vote error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
