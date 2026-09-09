<?php
/**
 * cron/generate_offline_packs.php
 * CLI cron — generate daily offline news packs (JSON + gzip).
 *
 * Run daily at 5am: 0 5 * * * php /path/to/cron/generate_offline_packs.php
 *
 * For each language (+ optionally each state) generates a gzipped JSON file
 * of the top 50 articles from yesterday and stores metadata in offline_packs.
 *
 * Required env vars:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 *   SITE_URL
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';

$yesterday = date('Y-m-d', strtotime('-1 day'));
$pack_dir  = __DIR__ . '/../uploads/offline_packs';

if (!is_dir($pack_dir)) {
    mkdir($pack_dir, 0755, true);
}

echo "[" . date('Y-m-d H:i:s') . "] Generating offline packs for {$yesterday}...\n";

// Languages to generate
$languages = ['hi', 'en'];

// States to generate (NULL = national pack, IDs from geo/states table)
$stmt     = $pdo->query("SELECT DISTINCT state_id FROM news WHERE state_id IS NOT NULL AND DATE(created_at) = '{$yesterday}' LIMIT 10");
$state_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'state_id');
array_unshift($state_ids, null); // null = national pack

foreach ($languages as $lang) {
    foreach ($state_ids as $state_id) {
        $state_label = $state_id ? "state_{$state_id}" : 'national';
        echo "  Generating [{$lang}][{$state_label}]...\n";

        // Check if already generated
        $check = $pdo->prepare(
            'SELECT id FROM offline_packs WHERE pack_date = ? AND language_code = ? AND state_id <=> ?'
        );
        $check->execute([$yesterday, $lang, $state_id]);
        if ($check->fetch()) {
            echo "  Already exists. Skipping.\n";
            continue;
        }

        // Fetch top 50 articles
        $where  = "n.status = 'approved' AND DATE(n.created_at) = ?";
        $params = [$yesterday];
        if ($state_id !== null) {
            $where  .= ' AND n.state_id = ?';
            $params[] = $state_id;
        }

        $articles_stmt = $pdo->prepare(
            "SELECT n.id, n.title, n.description, n.content, n.thumbnail_url,
                    n.views, n.created_at, c.name AS category
             FROM news n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE {$where}
             ORDER BY n.views DESC, n.created_at DESC
             LIMIT 50"
        );
        $articles_stmt->execute($params);
        $articles = $articles_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($articles)) {
            echo "  No articles found for [{$lang}][{$state_label}]. Skipping.\n";
            continue;
        }

        $pack_data = [
            'pack_date'      => $yesterday,
            'language_code'  => $lang,
            'state_id'       => $state_id,
            'generated_at'   => date('c'),
            'articles_count' => count($articles),
            'articles'       => $articles,
        ];

        $json      = json_encode($pack_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $filename  = "pack_{$yesterday}_{$lang}" . ($state_id ? "_{$state_id}" : '') . '.json.gz';
        $filepath  = "{$pack_dir}/{$filename}";

        // Write gzipped file
        $gz = gzopen($filepath, 'wb9');
        gzwrite($gz, $json);
        gzclose($gz);

        $size_kb  = (int)ceil(filesize($filepath) / 1024);
        $pack_url = rtrim(SITE_URL, '/') . "/uploads/offline_packs/{$filename}";

        $pdo->prepare(
            'INSERT INTO offline_packs
               (pack_date, language_code, state_id, articles_count, pack_size_kb, pack_url, is_ready)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               articles_count=VALUES(articles_count), pack_size_kb=VALUES(pack_size_kb),
               pack_url=VALUES(pack_url), is_ready=1'
        )->execute([$yesterday, $lang, $state_id, count($articles), $size_kb, $pack_url]);

        echo "  Saved: {$filename} ({$size_kb} KB, " . count($articles) . " articles).\n";
    }
}

// Clean up packs older than 7 days
$old_date  = date('Y-m-d', strtotime('-7 days'));
$old_packs = $pdo->prepare('SELECT pack_url FROM offline_packs WHERE pack_date < ?');
$old_packs->execute([$old_date]);
foreach ($old_packs->fetchAll(PDO::FETCH_ASSOC) as $old) {
    $file = __DIR__ . '/../' . parse_url($old['pack_url'], PHP_URL_PATH);
    if (is_file($file)) {
        unlink($file);
        echo "  Deleted old pack: {$file}\n";
    }
}
$pdo->prepare('DELETE FROM offline_packs WHERE pack_date < ?')->execute([$old_date]);

echo "Offline pack generation complete.\n";
