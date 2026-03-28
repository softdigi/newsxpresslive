<?php
// ============================================================
// FIXED: geo/languages.php
// ISSUES:
//   1. No caching — languages almost never change
//   2. Blank line at top before <?php — can cause
//      "headers already sent" warning in some PHP configs
// ============================================================
header("Content-Type: application/json");
header("Cache-Control: public, max-age=3600");

require __DIR__ . "/config.php";
require __DIR__ . "/response.php";

$stmt = $pdo->query(
    "SELECT id, code, name, native_name, direction
     FROM languages
     ORDER BY name ASC"
);

jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), "");
