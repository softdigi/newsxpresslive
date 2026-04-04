<?php
// ============================================================
// api/v1/agency/csv_upload.php
// POST /api/v1/agency/csv_upload
//
// Accepts a multipart/form-data file upload (.csv or .xlsx).
// Parses up to 500 rows and queues them as an agency_bulk_uploads job.
//
// Supported CSV columns (header row required):
//   external_id, title, content, summary, category_id, language,
//   image_url, tags, published_at, source_url
//
// Required: title, content, category_id
// Optional: all others
//
// Response:
//   {success: true, job_id: N, total_rows: N, queued: true}
//
// Cron process_agency_bulk.php processes the queued job.
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-Key, X-Agency-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../auth/agency_auth.php';

$agency = requireAgency($pdo);

// ── File checks ───────────────────────────────────────────────────────────────
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file uploaded — use multipart/form-data with field name "file"',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension',
    ];
    $errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errMsg  = $uploadErrors[$errCode] ?? 'Unknown upload error';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $errMsg]);
    exit;
}

$file     = $_FILES['file'];
$maxBytes = 10 * 1024 * 1024;  // 10 MB

if ($file['size'] > $maxBytes) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'File exceeds 10 MB limit']);
    exit;
}

// Validate extension
$origName = basename($file['name']);
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if (!in_array($ext, ['csv', 'xlsx'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Only .csv and .xlsx files are accepted']);
    exit;
}

// Validate MIME from actual file content (not client-supplied type)
$realMime     = mime_content_type($file['tmp_name']);
$allowedMimes = [
    'text/csv', 'text/plain', 'application/csv', 'application/octet-stream',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-excel',
];
if (!in_array($realMime, $allowedMimes, true) && $ext !== 'csv') {
    // CSV files often report as text/plain — only hard-block xlsx with wrong mime
    if ($ext === 'xlsx') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid file type for .xlsx upload']);
        exit;
    }
}

// ── Move to permanent upload dir ──────────────────────────────────────────────
$uploadDir = __DIR__ . '/../../../web/uploads/agency_csv/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Cannot create upload directory']);
    exit;
}

$storedFilename = 'csv_' . $agency['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$storedPath     = $uploadDir . $storedFilename;

if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
    exit;
}

// ── Parse file ────────────────────────────────────────────────────────────────
if ($ext === 'xlsx') {
    $articles = _parseXlsx($storedPath);
} else {
    $articles = _parseCsv($storedPath);
}

if (!is_array($articles)) {
    // $articles is an error string
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $articles]);
    exit;
}

if (empty($articles)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'File contains no data rows']);
    exit;
}

$maxRows = 500;
if (count($articles) > $maxRows) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => "File exceeds {$maxRows} row limit"]);
    exit;
}

$totalRows = count($articles);

// ── Persist payload for background processing ─────────────────────────────────
$payloadDir = __DIR__ . '/../../../web/uploads/agency_bulk/';
if (!is_dir($payloadDir) && !mkdir($payloadDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Cannot create bulk payload directory']);
    exit;
}
$payloadFile = 'bulk_csv_' . bin2hex(random_bytes(8)) . '.json';
file_put_contents($payloadDir . $payloadFile, json_encode($articles));

// ── Create agency_bulk_uploads row ────────────────────────────────────────────
$stmt = $pdo->prepare(
    'INSERT INTO agency_bulk_uploads
        (agency_id, filename, file_path, total_rows, status, created_at)
     VALUES (?, ?, ?, ?, \'queued\', NOW())'
);
$stmt->execute([
    $agency['id'],
    $storedFilename,
    'uploads/agency_csv/' . $storedFilename,
    $totalRows,
]);
$jobId = (int)$pdo->lastInsertId();

echo json_encode([
    'success'    => true,
    'job_id'     => $jobId,
    'filename'   => $origName,
    'total_rows' => $totalRows,
    'queued'     => true,
    'message'    => "File parsed successfully. {$totalRows} rows queued for processing. "
        . "Check status at GET /api/v1/agency/articles/bulk/{$jobId}",
]);

// ── Parsers ───────────────────────────────────────────────────────────────────

$knownColumns = [
    'external_id', 'title', 'content', 'summary',
    'category_id', 'language', 'image_url',
    'tags', 'published_at', 'source_url',
];

/**
 * Parse a CSV file into an array of article arrays.
 * Returns string error message on failure.
 */
function _parseCsv(string $path): array|string
{
    global $knownColumns;

    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return 'Cannot open CSV file';
    }

    // Read header row
    $header = fgetcsv($handle);
    if (!is_array($header) || empty($header)) {
        fclose($handle);
        return 'CSV file is empty or missing header row';
    }

    // Normalise header names
    $header = array_map(fn($h) => strtolower(trim($h)), $header);

    // Validate required columns exist in header
    $required = ['title', 'content', 'category_id'];
    foreach ($required as $req) {
        if (!in_array($req, $header, true)) {
            fclose($handle);
            return "CSV header missing required column: {$req}";
        }
    }

    $articles = [];
    $row      = 1;

    while (($cols = fgetcsv($handle)) !== false) {
        $row++;
        if (count($cols) !== count($header)) {
            // Skip malformed rows silently (will fail validation in processor)
        }
        $article = [];
        foreach ($header as $idx => $colName) {
            if (in_array($colName, $GLOBALS['knownColumns'], true)) {
                $article[$colName] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
            }
        }
        // Parse tags CSV sub-list (e.g. "politics|crime|sports")
        if (!empty($article['tags'])) {
            $article['tags'] = array_map('trim', explode('|', $article['tags']));
        }
        $articles[] = $article;
    }
    fclose($handle);

    return $articles;
}

/**
 * Parse an XLSX file using PHP's ZipArchive + SimpleXML (no external libs).
 * Returns string error message on failure.
 */
function _parseXlsx(string $path): array|string
{
    global $knownColumns;

    if (!class_exists('ZipArchive')) {
        return 'XLSX parsing requires ZipArchive extension (not available on this server)';
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return 'Cannot open XLSX file';
    }

    // Read shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } else {
                    foreach ($si->r as $r) {
                        if (isset($r->t)) {
                            $text .= (string)$r->t;
                        }
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // Read first worksheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false) {
        return 'XLSX file does not contain a worksheet';
    }

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        return 'Cannot parse XLSX worksheet XML';
    }

    $rows   = [];
    $header = null;

    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $cellType  = (string)($cell['t'] ?? '');
            $cellValue = (string)$cell->v;

            if ($cellType === 's') {
                // Shared string reference
                $cellValue = $sharedStrings[(int)$cellValue] ?? '';
            } elseif ($cellType === 'str') {
                $cellValue = (string)$cell->v;
            }
            $rowData[] = trim($cellValue);
        }

        if ($header === null) {
            $header = array_map('strtolower', $rowData);
            continue;
        }

        if (empty(array_filter($rowData))) {
            continue;   // skip blank rows
        }

        $article = [];
        foreach ($header as $idx => $colName) {
            if (in_array($colName, $GLOBALS['knownColumns'], true)) {
                $article[$colName] = $rowData[$idx] ?? '';
            }
        }
        if (!empty($article['tags'])) {
            $article['tags'] = array_map('trim', explode('|', $article['tags']));
        }
        $rows[] = $article;
    }

    if ($header === null) {
        return 'XLSX file is empty';
    }

    $required = ['title', 'content', 'category_id'];
    foreach ($required as $req) {
        if (!in_array($req, $header, true)) {
            return "XLSX header missing required column: {$req}";
        }
    }

    return $rows;
}
