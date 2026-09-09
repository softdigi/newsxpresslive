<?php
// ============================================================
// FIXED: helpers/upload.php
// CRITICAL BUGS:
//   1. $file['type'] is CLIENT-SUPPLIED MIME type — attacker
//      can set Content-Type: image/jpeg on a .php file and
//      bypass the check completely → Remote Code Execution!
//      Fixed: use mime_content_type() on the actual tmp file.
//
//   2. Extension taken from original filename directly:
//      $ext = pathinfo($file['name'], PATHINFO_EXTENSION)
//      Attacker sends: filename="shell.php.jpg"
//      pathinfo gives ext="jpg" but name has .php in it.
//      Fixed: derive extension from validated MIME type only.
//
//   3. Upload directory not checked/created — if dir missing,
//      move_uploaded_file() silently fails with generic error.
//
//   4. $folder parameter accepted but NEVER USED — path is
//      hardcoded to news/images regardless. Fixed.
//
//   5. No UPLOAD_ERR check — if PHP upload error occurred
//      (file too large, partial upload), the error is ignored
//      and tmp_name is processed anyway.
// ============================================================

function uploadImage(array $file, string $folder): array
{
    // FIXED: Check PHP upload errors FIRST
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension',
        ];
        $err = $upload_errors[$file['error']] ?? 'Unknown upload error';
        return ['error' => $err];
    }

    // FIXED: Check actual MIME type from file content — not client header
    $real_mime = mime_content_type($file['tmp_name']);

    $allowed_mime_to_ext = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!array_key_exists($real_mime, $allowed_mime_to_ext)) {
        return ['error' => 'Invalid image type — only JPG, PNG, WebP, GIF allowed'];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['error' => 'Image too large (max 5MB)'];
    }

    // FIXED: derive extension from real MIME — never from filename
    $ext = $allowed_mime_to_ext[$real_mime];

    // FIXED: safe filename — no original filename used at all
    $name = uniqid('img_', true) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    // FIXED: $folder actually used, sanitized to prevent path traversal
    $folder      = trim(trim($folder), '/');
    // Strip anything that isn't alphanumeric, slash, underscore, hyphen
    $safe_folder = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $folder);
    $upload_dir  = __DIR__ . '/../uploads/' . $safe_folder . '/';

    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            return ['error' => 'Failed to create upload directory'];
        }
    }

    $full_path = $upload_dir . $name;

    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        return ['error' => 'Upload failed — check directory permissions'];
    }

    return [
        'success'  => true,
        'filename' => $name,
        'url'      => '/uploads/' . $safe_folder . '/' . $name,
    ];
}
