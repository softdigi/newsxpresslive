<?php
// ============================================================
// cron/national_digest.php
//
// PURPOSE:
//   Every 20 minutes, collect the latest national AND
//   international news, generate an AI digest, and send
//   FCM push notifications to the respective topics.
//
//   Topics used:
//     • national        → all users subscribed to national news
//     • international   → all users subscribed to international news
//
// CRON SCHEDULE (add to server crontab):
//   # Run every 20 minutes
//   */20 * * * * php /path/to/newsxpresslive/cron/national_digest.php >> /path/to/logs/national_digest.log 2>&1
//
// CLI ONLY — cannot be called via HTTP.
// ============================================================

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/notification.php';
require_once __DIR__ . '/../helpers/ai_summarizer.php';

$started  = date('Y-m-d H:i:s');
$interval = 20; // minutes — must match cron frequency
echo "[{$started}] national_digest.php started" . ($dryRun ? ' (DRY RUN)' : '') . "\n";

// ---- Fetch news published in the last $interval minutes ----------------

$cutoff = date('Y-m-d H:i:s', strtotime("-{$interval} minutes"));

$stmt = $pdo->prepare("
    SELECT
        n.id,
        n.title,
        n.description,
        c.slug AS category_slug,
        c.name AS category_name,
        c.scope
    FROM news n
    LEFT JOIN categories c ON n.category_id = c.id
    WHERE n.status    = 'approved'
      AND n.created_at >= :cutoff
    ORDER BY n.created_at DESC
    LIMIT 50
");
$stmt->execute([':cutoff' => $cutoff]);
$recentNews = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($recentNews)) {
    echo "[" . date('H:i:s') . "] No new articles in the last {$interval} minutes. Exiting.\n";
    exit(0);
}

// Separate national vs international by category scope
// Categories with scope='international' go to international topic;
// everything else (national, unset) goes to national topic.
$national      = [];
$international = [];
foreach ($recentNews as $n) {
    $scope = strtolower(trim($n['scope'] ?? 'national'));
    if ($scope === 'international') {
        $international[] = $n;
    } else {
        $national[] = $n;
    }
}

echo "[" . date('H:i:s') . "] National: " . count($national) .
     " | International: " . count($international) . " articles.\n";

// ---- Helper: generate + send one digest --------------------------------

function sendNationalDigest(
    PDO    $pdo,
    string $digestType,
    array  $articles,
    string $fcmTopic,
    bool   $dryRun
): void {
    if (empty($articles)) {
        echo "  → [{$digestType}] no articles, skipping.\n";
        return;
    }

    $newsIds = implode(',', array_column($articles, 'id'));

    $summary = aiSummarizeDigest($articles, $digestType, 'hi');

    $title = match ($digestType) {
        'national'      => '🇮🇳 ताज़ा राष्ट्रीय समाचार',
        'international' => '🌍 ताज़ा अंतर्राष्ट्रीय समाचार',
        default         => '📰 ताज़ा ख़बरें',
    };

    echo "  → [{$digestType}] " . count($articles) . " articles.\n";
    echo "    Summary: " . mb_substr($summary, 0, 100) . "...\n";

    if ($dryRun) {
        echo "    [DRY RUN] FCM not sent.\n";
        return;
    }

    $fcmResponse = sendFCMNotification(
        $title,
        $summary,
        [
            'type'        => 'rolling_digest',
            'digest_type' => $digestType,
            'interval'    => '20min',
            'sent_at'     => date('Y-m-d H:i:s'),
        ],
        $fcmTopic
    );

    // Store in DB
    $ins = $pdo->prepare("
        INSERT INTO news_digests
            (digest_type, location_id, digest_title, digest_text, news_ids, sent_at, fcm_response)
        VALUES
            (:dtype, NULL, :dtitle, :dtext, :nids, NOW(), :fcmr)
    ");
    $ins->execute([
        ':dtype'  => $digestType,
        ':dtitle' => $title,
        ':dtext'  => $summary,
        ':nids'   => $newsIds,
        ':fcmr'   => $fcmResponse ?: 'error',
    ]);

    echo "    FCM sent to topic [{$fcmTopic}]. Response: " . ($fcmResponse ? 'OK' : 'FAILED') . "\n";
}

// ---- Send national digest ----------------------------------------------
sendNationalDigest($pdo, 'national',      $national,      'national',      $dryRun);

// ---- Send international digest -----------------------------------------
sendNationalDigest($pdo, 'international', $international, 'international', $dryRun);

echo "[" . date('Y-m-d H:i:s') . "] national_digest.php completed.\n";
