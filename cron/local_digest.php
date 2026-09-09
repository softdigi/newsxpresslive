<?php
// ============================================================
// cron/local_digest.php
//
// PURPOSE:
//   Send evening "Aaj ki Khabren" digest to users grouped by
//   their district and state location.
//
//   For each district/state that has news today:
//     • Generate an AI digest summary (Hindi by default)
//     • Send FCM push to topic  district_{id}  /  state_{id}
//     • Store digest in news_digests table
//
// CRON SCHEDULE (add to server crontab):
//   # Run once at 8 PM every day
//   0 20 * * * php /path/to/newsxpresslive/cron/local_digest.php >> /path/to/logs/local_digest.log 2>&1
//
// CLI ONLY — cannot be called via HTTP.
// ============================================================

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Optionally accept --dry-run flag to test without sending
$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/notification.php';
require_once __DIR__ . '/../helpers/ai_summarizer.php';

$started = date('Y-m-d H:i:s');
echo "[{$started}] local_digest.php started" . ($dryRun ? ' (DRY RUN)' : '') . "\n";

// ---- 1. Fetch today's approved news grouped by district & state --------

$today      = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';

// Fetch all of today's news with location info
$stmt = $pdo->prepare("
    SELECT
        n.id,
        n.title,
        n.description,
        n.district_id,
        n.state_id,
        d.name AS district_name,
        s.name AS state_name
    FROM news n
    LEFT JOIN districts d ON n.district_id = d.id
    LEFT JOIN states    s ON n.state_id    = s.id
    WHERE n.status = 'approved'
      AND n.created_at BETWEEN :start AND :end
    ORDER BY n.created_at DESC
");
$stmt->execute([':start' => $todayStart, ':end' => $todayEnd]);
$allNews = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($allNews)) {
    echo "[" . date('H:i:s') . "] No news found for today. Exiting.\n";
    exit(0);
}

// Group by district_id
$byDistrict = [];
$byState    = [];
foreach ($allNews as $n) {
    if ($n['district_id']) {
        $byDistrict[$n['district_id']]['articles'][]      = $n;
        $byDistrict[$n['district_id']]['district_name']   = $n['district_name'] ?? 'District';
    }
    if ($n['state_id']) {
        $byState[$n['state_id']]['articles'][]    = $n;
        $byState[$n['state_id']]['state_name']    = $n['state_name'] ?? 'State';
    }
}

echo "[" . date('H:i:s') . "] Found " . count($allNews) . " articles across " .
     count($byDistrict) . " districts, " . count($byState) . " states.\n";

// ---- Helper: insert into news_digests & send FCM -------------------

function sendDigest(
    PDO    $pdo,
    string $digestType,
    int    $locationId,
    string $locationName,
    array  $articles,
    string $fcmTopic,
    bool   $dryRun
): void {
    $newsIds = implode(',', array_column($articles, 'id'));

    // Generate AI digest
    $summary = aiSummarizeDigest($articles, $digestType, 'hi');
    $title   = match ($digestType) {
        'local_district' => "📍 {$locationName} की आज की ख़बरें",
        'local_state'    => "🗺️ {$locationName} की आज की ख़बरें",
        default          => "📰 आज की ख़बरें",
    };

    echo "  → [{$digestType}] {$locationName} (id={$locationId}): " . count($articles) . " articles\n";
    echo "    Summary: " . mb_substr($summary, 0, 100) . "...\n";

    if ($dryRun) {
        echo "    [DRY RUN] FCM not sent.\n";
        return;
    }

    $fcmResponse = sendFCMNotification(
        $title,
        $summary,
        [
            'type'          => 'daily_digest',
            'digest_type'   => $digestType,
            'location_id'   => (string)$locationId,
            'location_name' => $locationName,
            'date'          => date('Y-m-d'),
        ],
        $fcmTopic
    );

    // Store in DB
    $ins = $pdo->prepare("
        INSERT INTO news_digests
            (digest_type, location_id, digest_title, digest_text, news_ids, sent_at, fcm_response)
        VALUES
            (:dtype, :lid, :dtitle, :dtext, :nids, NOW(), :fcmr)
    ");
    $ins->execute([
        ':dtype'  => $digestType,
        ':lid'    => $locationId,
        ':dtitle' => $title,
        ':dtext'  => $summary,
        ':nids'   => $newsIds,
        ':fcmr'   => $fcmResponse ?: 'error',
    ]);

    echo "    FCM sent to topic [{$fcmTopic}]. Response: " . ($fcmResponse ? 'OK' : 'FAILED') . "\n";
}

// ---- 2. Send district-level digests ------------------------------------

echo "[" . date('H:i:s') . "] Sending district digests...\n";
foreach ($byDistrict as $districtId => $data) {
    // Skip if already sent a digest for this district today
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM news_digests
        WHERE digest_type = 'local_district'
          AND location_id  = ?
          AND DATE(sent_at) = CURDATE()
    ");
    $check->execute([$districtId]);
    if ((int)$check->fetchColumn() > 0) {
        echo "  → District {$districtId} already sent today, skipping.\n";
        continue;
    }

    sendDigest(
        $pdo,
        'local_district',
        (int)$districtId,
        $data['district_name'],
        $data['articles'],
        'district_' . $districtId,
        $dryRun
    );
}

// ---- 3. Send state-level digests ---------------------------------------

echo "[" . date('H:i:s') . "] Sending state digests...\n";
foreach ($byState as $stateId => $data) {
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM news_digests
        WHERE digest_type = 'local_state'
          AND location_id  = ?
          AND DATE(sent_at) = CURDATE()
    ");
    $check->execute([$stateId]);
    if ((int)$check->fetchColumn() > 0) {
        echo "  → State {$stateId} already sent today, skipping.\n";
        continue;
    }

    sendDigest(
        $pdo,
        'local_state',
        (int)$stateId,
        $data['state_name'],
        $data['articles'],
        'state_' . $stateId,
        $dryRun
    );
}

echo "[" . date('Y-m-d H:i:s') . "] local_digest.php completed.\n";
