<?php
/**
 * web/api/quiz.php
 *
 * GET   quiz.php?firebase_uid=xxx      → today's quiz question + user's attempt
 * GET   quiz.php?history=1&firebase_uid=xxx  → last 7 days of attempts
 * POST  (JSON) { quiz_id, selected_index, firebase_uid }  → submit answer
 *
 * Response shapes
 * ───────────────
 * GET (today):
 *   { quiz: { id, question, options:[str], quiz_date,
 *              user_attempt: null | { selected_index, is_correct, score },
 *              correct_index (only when user_attempt != null),
 *              explanation  (only when user_attempt != null) } }
 *
 * POST success:
 *   { success, is_correct, score, correct_index, explanation }
 *
 * POST duplicate:
 *   { success: false, message: 'already_answered', is_correct, score }
 *
 * History:
 *   { history: [{ quiz_id, quiz_date, question, selected_index,
 *                 correct_index, is_correct, score, attempted_at }] }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ─────────────────────────────────────────────────────────────────────────────
// GET
// ─────────────────────────────────────────────────────────────────────────────

if ($method === 'GET') {

    $uid = isset($_GET['firebase_uid'])
        ? mb_substr(trim($_GET['firebase_uid']), 0, 128)
        : null;

    // ── History feed ───────────────────────────────────────────────────────
    if (isset($_GET['history'])) {
        if (!$uid) {
            http_response_code(401);
            echo json_encode(['error' => 'firebase_uid required']);
            exit;
        }
        $rows = $pdo->prepare(
            'SELECT dq.id AS quiz_id, dq.quiz_date, dq.question,
                    dq.correct_index,
                    dqa.selected_index, dqa.is_correct, dqa.score, dqa.attempted_at
             FROM daily_quiz_attempts dqa
             JOIN daily_quiz dq ON dq.id = dqa.quiz_id
             WHERE dqa.firebase_uid = :uid
             ORDER BY dq.quiz_date DESC
             LIMIT 30'
        );
        $rows->execute([':uid' => $uid]);
        $history = array_map(function ($r) {
            return [
                'quiz_id'        => (int)$r['quiz_id'],
                'quiz_date'      => $r['quiz_date'],
                'question'       => $r['question'],
                'selected_index' => (int)$r['selected_index'],
                'correct_index'  => (int)$r['correct_index'],
                'is_correct'     => (bool)$r['is_correct'],
                'score'          => (int)$r['score'],
                'attempted_at'   => $r['attempted_at'],
            ];
        }, $rows->fetchAll());

        echo json_encode(['history' => $history]);
        exit;
    }

    // ── Today's quiz ───────────────────────────────────────────────────────
    try {
        $quizRow = $pdo->prepare(
            'SELECT id, question, options, correct_index, explanation, quiz_date
             FROM daily_quiz
             WHERE quiz_date = CURDATE() AND is_active = 1
             LIMIT 1'
        );
        $quizRow->execute();
        $quiz = $quizRow->fetch();

        if (!$quiz) {
            echo json_encode(['quiz' => null, 'message' => 'No quiz today']);
            exit;
        }

        $opts    = json_decode($quiz['options'], true) ?? [];
        $attempt = null;
        $showAnswer = false;

        if ($uid) {
            $aRow = $pdo->prepare(
                'SELECT selected_index, is_correct, score
                 FROM daily_quiz_attempts
                 WHERE quiz_id = :qid AND firebase_uid = :uid LIMIT 1'
            );
            $aRow->execute([':qid' => $quiz['id'], ':uid' => $uid]);
            $a = $aRow->fetch();
            if ($a) {
                $attempt    = [
                    'selected_index' => (int)$a['selected_index'],
                    'is_correct'     => (bool)$a['is_correct'],
                    'score'          => (int)$a['score'],
                ];
                $showAnswer = true;
            }
        }

        $response = [
            'id'           => (int)$quiz['id'],
            'question'     => $quiz['question'],
            'options'      => $opts,
            'quiz_date'    => $quiz['quiz_date'],
            'user_attempt' => $attempt,
        ];
        if ($showAnswer) {
            $response['correct_index'] = (int)$quiz['correct_index'];
            $response['explanation']   = $quiz['explanation'];
        }

        echo json_encode(['quiz' => $response]);
    } catch (PDOException $e) {
        error_log('quiz.php GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — submit answer
// ─────────────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $input         = json_decode(file_get_contents('php://input'), true) ?? [];
    $quizId        = isset($input['quiz_id'])        ? (int)$input['quiz_id']          : 0;
    $selectedIndex = isset($input['selected_index']) ? (int)$input['selected_index']   : -1;
    $uid           = isset($input['firebase_uid'])
        ? mb_substr(trim($input['firebase_uid']), 0, 128)
        : null;

    if ($quizId <= 0 || $selectedIndex < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'quiz_id and selected_index required']);
        exit;
    }
    if (!$uid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }

    try {
        $quizRow = $pdo->prepare(
            'SELECT id, options, correct_index, explanation, quiz_date, is_active
             FROM daily_quiz WHERE id = :id LIMIT 1'
        );
        $quizRow->execute([':id' => $quizId]);
        $quiz = $quizRow->fetch();

        if (!$quiz || !$quiz['is_active']) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Quiz not found']);
            exit;
        }
        $opts = json_decode($quiz['options'], true) ?? [];
        if ($selectedIndex >= count($opts)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid selected_index']);
            exit;
        }

        // Check duplicate
        $dup = $pdo->prepare(
            'SELECT selected_index, is_correct, score
             FROM daily_quiz_attempts
             WHERE quiz_id = :qid AND firebase_uid = :uid LIMIT 1'
        );
        $dup->execute([':qid' => $quizId, ':uid' => $uid]);
        $existing = $dup->fetch();

        if ($existing) {
            echo json_encode([
                'success'       => false,
                'message'       => 'already_answered',
                'is_correct'    => (bool)$existing['is_correct'],
                'score'         => (int)$existing['score'],
                'correct_index' => (int)$quiz['correct_index'],
                'explanation'   => $quiz['explanation'],
            ]);
            exit;
        }

        $isCorrect = ($selectedIndex === (int)$quiz['correct_index']);
        $score     = $isCorrect ? 10 : 0;

        $pdo->prepare(
            'INSERT INTO daily_quiz_attempts
               (quiz_id, firebase_uid, selected_index, is_correct, score)
             VALUES (:qid, :uid, :sel, :corr, :sc)'
        )->execute([
            ':qid'  => $quizId,
            ':uid'  => $uid,
            ':sel'  => $selectedIndex,
            ':corr' => $isCorrect ? 1 : 0,
            ':sc'   => $score,
        ]);

        echo json_encode([
            'success'       => true,
            'is_correct'    => $isCorrect,
            'score'         => $score,
            'correct_index' => (int)$quiz['correct_index'],
            'explanation'   => $quiz['explanation'],
        ]);
    } catch (PDOException $e) {
        error_log('quiz.php POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
