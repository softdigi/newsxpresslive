<?php
// ============================================================
// api/v1/agency/csv_upload.php
//
// POST /api/v1/agency/csv_upload
//   Upload a .csv or .xlsx file (max 10 MB, max 500 rows).
//   Per-row validation runs synchronously.
//   Valid rows are queued for background processing.
//   Response: {success, data:{upload_id, total, valid_count, invalid_count, …}}
//
// GET /api/v1/agency/csv_upload?upload_id={N}
//   Returns status of a previous upload + downloadable error report.
//
// CSV columns (header row required):
//   external_id*, title*, content*, summary, category_id, language,
//   image_url, tags (pipe-separated), published_at, source_url
//   (* = required per-row)
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-Key, X-Agency-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../auth/agency_auth.php';

$agency = requireAgency($pdo);

// ── Route: GET — upload status / error report ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $uploadId = filter_input(INPUT_GET, 'upload_id', FILTER_VALIDATE_INT);
    if (!$uploadId) {
        http_response_code(400);
        echo json_encode([
            'success'   => false,
            'data'      => null,
            'error'     => 'upload_id (integer) is required',
            'timestamp' => gmdate('c'),
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, filename, total_rows, success_count, failed_count,
                    duplicate_count, status, error_log, created_at, completed_at
             FROM   agency_bulk_uploads
             WHERE  id = ? AND agency_id = ?'
        );
        $stmt->execute([$uploadId, $agency['id']]);
        $upload = $stmt->fetch();

        if (!$upload) {
            http_response_code(404);
            echo json_encode([
                'success'   => false,
                'data'      => null,
                'error'     => 'Upload not found',
                'timestamp' => gmdate('c'),
            ]);
            exit;
        }

        $errorRows = $upload['error_log'] ? json_decode($upload['error_log'], true) : [];

        // Build a downloadable CSV error report URL
        $errorReportUrl = null;
        if (!empty($errorRows)) {
            $reportDir  = __DIR__ . '/../../../web/uploads/agency_error_reports/';
            $reportFile = 'errors_' . $uploadId . '_' . $agency['id'] . '.csv';
            if (!is_dir($reportDir)) {
                mkdir($reportDir, 0755, true);
            }
            $reportPath = $reportDir . $reportFile;
            if (!file_exists($reportPath)) {
                _writeErrorCsv($reportPath, $errorRows);
            }
            $errorReportUrl = '/uploads/agency_error_reports/' . $reportFile;
        }

        echo json_encode([
            'success'   => true,
            'data'      => [
                'upload_id'       => (int)$upload['id'],
                'filename'        => $upload['filename'],
                'status'          => $upload['status'],
                'total'           => (int)$upload['total_rows'],
                'valid_count'     => (int)$upload['success_count'],
                'invalid_count'   => (int)$upload['failed_count'],
                'duplicate_count' => (int)$upload['duplicate_count'],
                'error_report'    => $errorReportUrl,
                'errors'          => $errorRows,
                'created_at'      => $upload['created_at'],
                'completed_at'    => $upload['completed_at'],
            ],
            'error'     => null,
            'timestamp' => gmdate('c'),
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success'   => false,
            'data'      => null,
            'error'     => 'Database error',
            'timestamp' => gmdate('c'),
        ]);
    }
    exit;
}

// ── Route: POST — file upload ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success'   => false,
        'data'      => null,
        'error'     => 'Method not allowed. Use POST to upload, GET to check status.',
        'timestamp' => gmdate('c'),
    ]);
    exit;
}

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

// ── Per-row validation ────────────────────────────────────────────────────────
$validRows    = [];
$invalidRows  = [];  // [{row, reason}]

foreach ($articles as $rowNum => $article) {
    $dataRow = $rowNum + 2;  // +1 for header, +1 for 1-based row number
    $errors  = _validateRow($article);
    if (empty($errors)) {
        $validRows[] = $article;
    } else {
        $invalidRows[] = [
            'row'    => $dataRow,
            'data'   => [
                'external_id' => $article['external_id'] ?? '',
                'title'       => isset($article['title']) ? mb_substr($article['title'], 0, 80) : '',
            ],
            'reason' => implode('; ', $errors),
        ];
    }
}

$validCount   = count($validRows);
$invalidCount = count($invalidRows);

// ── Persist valid payload for background processing ───────────────────────────
$payloadDir = __DIR__ . '/../../../web/uploads/agency_bulk/';
if (!is_dir($payloadDir) && !mkdir($payloadDir, 0755, true)) {
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'data'      => null,
        'error'     => 'Cannot create bulk payload directory',
        'timestamp' => gmdate('c'),
    ]);
    exit;
}
$payloadFile = 'bulk_csv_' . bin2hex(random_bytes(8)) . '.json';
file_put_contents($payloadDir . $payloadFile, json_encode($validRows));

// ── Create agency_bulk_uploads row ────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'INSERT INTO agency_bulk_uploads
            (agency_id, filename, file_path, total_rows, success_count, failed_count, status, error_log, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $agency['id'],
        $storedFilename,
        'uploads/agency_bulk/' . $payloadFile,
        $totalRows,
        $validCount,
        $invalidCount,
        $validCount > 0 ? 'queued' : 'completed',
        $invalidCount > 0 ? json_encode($invalidRows) : null,
    ]);
    $uploadId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'data'      => null,
        'error'     => 'Failed to create upload record',
        'timestamp' => gmdate('c'),
    ]);
    exit;
}

echo json_encode([
    'success'   => true,
    'data'      => [
        'upload_id'     => $uploadId,
        'filename'      => $origName,
        'total'         => $totalRows,
        'valid_count'   => $validCount,
        'invalid_count' => $invalidCount,
        'queued'        => $validCount > 0,
        'status_url'    => '/api/v1/agency/csv_upload?upload_id=' . $uploadId,
        'preview_errors' => array_slice($invalidRows, 0, 5),
    ],
    'error'     => null,
    'timestamp' => gmdate('c'),
]);

// ── Parsers & Validators ──────────────────────────────────────────────────────

$knownColumns = [
    'external_id', 'title', 'content', 'summary',
    'category_id', 'language', 'image_url',
    'tags', 'published_at', 'source_url',
];

/**
 * Validate a single parsed article row.
 * Returns array of error strings (empty = valid).
 */
function _validateRow(array $row): array
{
    $errors = [];

    // Required fields
    if (empty($row['external_id'])) {
        $errors[] = 'external_id is required';
    }
    if (empty($row['title'])) {
        $errors[] = 'title is required';
    } elseif (mb_strlen($row['title']) < 10) {
        $errors[] = 'title must be at least 10 characters';
    } elseif (mb_strlen($row['title']) > 200) {
        $errors[] = 'title must not exceed 200 characters';
    }
    if (empty($row['content'])) {
        $errors[] = 'content is required';
    } elseif (mb_strlen($row['content']) < 100) {
        $errors[] = 'content must be at least 100 characters';
    }

    // Optional URL fields
    foreach (['image_url', 'source_url'] as $urlField) {
        if (!empty($row[$urlField])) {
            if (filter_var($row[$urlField], FILTER_VALIDATE_URL) === false) {
                $errors[] = "{$urlField} is not a valid URL";
            }
        }
    }

    // Optional datetime field
    if (!empty($row['published_at'])) {
        $ts = strtotime($row['published_at']);
        if ($ts === false || $ts <= 0) {
            $errors[] = 'published_at is not a valid datetime (use ISO-8601, e.g. 2024-01-15T10:30:00Z)';
        }
    }

    return $errors;
}

/**
 * Write an error report CSV to $path from the $errorRows array.
 */
function _writeErrorCsv(string $path, array $errorRows): void
{
    $fh = @fopen($path, 'w');
    if ($fh === false) {
        return;
    }
    fputcsv($fh, ['row_number', 'external_id', 'title_preview', 'reason']);
    foreach ($errorRows as $err) {
        fputcsv($fh, [
            $err['row']              ?? '',
            $err['data']['external_id'] ?? '',
            $err['data']['title']    ?? '',
            $err['reason']           ?? '',
        ]);
    }
    fclose($fh);
}

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
    $required = ['external_id', 'title', 'content'];
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

    $required = ['external_id', 'title', 'content'];
    foreach ($required as $req) {
        if (!in_array($req, $header, true)) {
            return "XLSX header missing required column: {$req}";
        }
    }

    return $rows;
}
