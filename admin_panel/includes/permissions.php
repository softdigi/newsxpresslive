<?php
// ============================================================
// admin_panel/includes/permissions.php — UPDATED
// Now delegates to auth/session.php
// can() and requireRole() defined there — this file kept
// for backward compatibility with existing module files
// ============================================================
require_once __DIR__ . '/../../auth/session.php';

// can() is already defined in session.php
// requireRole() is already defined in session.php
// No changes needed in module files that use can()
