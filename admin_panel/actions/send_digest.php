<?php
// ============================================================
// admin_panel/actions/send_digest.php
//
// Admin action: manually trigger an AI digest send.
// POST only, CSRF protected.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

requireRole(['super_admin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ADMIN_URL . '/notifications/digest.php');
    exit;
}

verify_csrf();

require_once __DIR__ . '/../../helpers/notification.php';
require_once __DIR__ . '/../../helpers/ai_summarizer.php';

$digestType = $_POST['digest_type'] ?? '';
$allowed    = ['local', 'national', 'international'];

if (!in_array($digestType, $allowed, true)) {
    header('Location: ' . ADMIN_URL . '/notifications/digest.php?error=fail');
    exit;
}

$success = false;

try {
    if ($digestType === 'local') {
        // Today's news grouped by district + state
        $today      = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT n.id, n.title, n.description, n.district_id, n.state_id,
                   d.name AS district_name, s.name AS state_name
            FROM news n
            LEFT JOIN districts d ON n.district_id = d.id
            LEFT JOIN states    s ON n.state_id    = s.id
            WHERE n.status = 'approved'
              AND DATE(n.created_at) = :today
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([':today' => $today]);
        $allNews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byDistrict = [];
        $byState    = [];
        foreach ($allNews as $n) {
            if ($n['district_id']) {
                $byDistrict[$n['district_id']]['articles'][]    = $n;
                $byDistrict[$n['district_id']]['name']          = $n['district_name'] ?? 'District';
            }
            if ($n['state_id']) {
                $byState[$n['state_id']]['articles'][] = $n;
                $byState[$n['state_id']]['name']       = $n['state_name'] ?? 'State';
            }
        }

        foreach ($byDistrict as $id => $data) {
            _insertAndSend($pdo, 'local_district', (int)$id, $data['name'], $data['articles'], 'district_' . $id);
        }
        foreach ($byState as $id => $data) {
            _insertAndSend($pdo, 'local_state', (int)$id, $data['name'], $data['articles'], 'state_' . $id);
        }
        $success = true;

    } elseif ($digestType === 'national') {
        $stmt = $pdo->prepare("
            SELECT n.id, n.title, n.description, c.scope
            FROM news n
            LEFT JOIN categories c ON n.category_id = c.id
            WHERE n.status = 'approved'
              AND (c.scope IS NULL OR c.scope != 'international')
              AND n.created_at >= NOW() - INTERVAL 20 MINUTE
            ORDER BY n.created_at DESC LIMIT 30
        ");
        $stmt->execute();
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        _insertAndSend($pdo, 'national', null, 'National', $articles, 'national');
        $success = true;

    } elseif ($digestType === 'international') {
        $stmt = $pdo->prepare("
            SELECT n.id, n.title, n.description
            FROM news n
            LEFT JOIN categories c ON n.category_id = c.id
            WHERE n.status = 'approved'
              AND c.scope = 'international'
              AND n.created_at >= NOW() - INTERVAL 20 MINUTE
            ORDER BY n.created_at DESC LIMIT 30
        ");
        $stmt->execute();
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        _insertAndSend($pdo, 'international', null, 'International', $articles, 'international');
        $success = true;
    }

} catch (Throwable $e) {
    error_log('send_digest admin error: ' . $e->getMessage());
    $success = false;
}

header('Location: ' . ADMIN_URL . '/notifications/digest.php?' . ($success ? 'sent=ok' : 'error=fail'));
exit;

// ---- Helper ----
function _insertAndSend(PDO $pdo, string $dtype, ?int $locId, string $locName, array $articles, string $topic): void
{
    if (empty($articles)) {
        return;
    }
    $newsIds = implode(',', array_column($articles, 'id'));
    $summary = aiSummarizeDigest($articles, $dtype, 'hi');
    $title   = match ($dtype) {
        'local_district' => "📍 {$locName} की आज की ख़बरें",
        'local_state'    => "🗺️ {$locName} की आज की ख़बरें",
        'national'       => '🇮🇳 ताज़ा राष्ट्रीय समाचार',
        'international'  => '🌍 ताज़ा अंतर्राष्ट्रीय समाचार',
        default          => '📰 ताज़ा ख़बरें',
    };

    $fcmResponse = sendFCMNotification(
        $title,
        $summary,
        ['type' => 'manual_digest', 'digest_type' => $dtype],
        $topic
    );

    $ins = $pdo->prepare("
        INSERT INTO news_digests
            (digest_type, location_id, digest_title, digest_text, news_ids, sent_at, fcm_response)
        VALUES
            (:dtype, :lid, :dtitle, :dtext, :nids, NOW(), :fcmr)
    ");
    $ins->execute([
        ':dtype'  => $dtype,
        ':lid'    => $locId,
        ':dtitle' => $title,
        ':dtext'  => $summary,
        ':nids'   => $newsIds,
        ':fcmr'   => $fcmResponse ?: 'error',
    ]);
}
