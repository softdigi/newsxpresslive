<?php
// ============================================================
// FIXED: geo/countries.php
// ISSUES:
//   1. No caching — countries table almost never changes.
//      Every app launch hits DB for same 200+ rows.
//      Fixed: Cache-Control header so client/CDN caches it.
//   2. No rate limiting (noted — implement at nginx/server level)
//   3. Returns ALL countries — add optional search if needed
// ============================================================
header("Content-Type: application/json");
header("Cache-Control: public, max-age=3600"); // cache 1 hour — countries rarely change

require __DIR__ . "/config.php";
require __DIR__ . "/response.php";

$stmt = $pdo->query(
    "SELECT id, name, iso2 FROM countries ORDER BY name ASC"
);
$countries = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true, $countries, "");
