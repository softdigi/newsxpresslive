#!/usr/bin/env php
<?php
/**
 * scripts/path_audit.php — Pre-deployment Path Audit
 *
 * Checks for hardcoded paths/credentials that would break
 * deployment on any domain.
 *
 * Usage:
 *   php scripts/path_audit.php
 *
 * Exit codes:
 *   0 = all checks passed
 *   1 = one or more issues found
 */

$root = dirname(__DIR__);
chdir($root);

function colorize(string $color, string $text): string
{
    $colors = ['green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'reset' => "\033[0m"];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

/**
 * Run a grep and return matching lines as an array.
 * Returns [] if nothing found.
 */
function grepFiles(string $pattern, string $dir = '.', array $exts = ['php'], array $excludeDirs = []): array
{
    // Build --include flags
    $includeFlags = implode(' ', array_map(fn($e) => '--include=' . escapeshellarg('*.' . $e), $exts));
    $excludeFlags = implode(' ', array_map(fn($d) => '--exclude-dir=' . escapeshellarg($d), $excludeDirs));

    // Single quotes inside the pattern break shell quoting; replace with a character class.
    $safePat = str_replace("'", "[']", $pattern);

    $cmd = sprintf(
        "grep -rn --color=never -E %s %s %s %s 2>/dev/null",
        $excludeFlags,
        $includeFlags,
        escapeshellarg($safePat),
        escapeshellarg($dir)
    );
    $output = [];
    exec($cmd, $output);
    return $output;
}

// ── Checks ───────────────────────────────────────────────────────────────────

$checks = [
    [
        'label'   => 'Hardcoded /newsxpresslive_api path',
        'pattern' => '/newsxpresslive_api',
        'exts'    => ['php', 'js', 'html'],
        'dirs'    => ['.'],
        'exclude' => ['tests', '.git', 'vendor'],
        // Lines to exclude from results (test fixtures, comments, this script itself)
        'filter_out' => null,
    ],
    [
        'label'   => 'Hardcoded newsxpresslive.com domain (non-test PHP files)',
        'pattern' => 'newsxpresslive\.com',
        'exts'    => ['php'],
        'dirs'    => ['.'],
        'exclude' => ['tests', '.git', 'vendor'],
        'filter_out' => null,
    ],
    [
        'label'   => 'define(DB_USER) hardcoded without getenv fallback',
        'pattern' => "define.*DB_USER.*'root'",
        'exts'    => ['php'],
        'dirs'    => ['.'],
        'exclude' => ['tests', '.git', 'vendor'],
        // Lines using getenv() fallback are acceptable defaults
        'filter_out' => 'getenv',
    ],
    [
        'label'   => 'define(SITE_URL) pointing to localhost',
        'pattern' => "define.*SITE_URL.*localhost",
        'exts'    => ['php'],
        'dirs'    => ['.'],
        'exclude' => ['tests', '.git', 'vendor'],
        'filter_out' => null,
    ],
    [
        'label'   => 'Bare require_once without __DIR__ in admin/',
        'pattern' => "require[^(]*'[.][.]/",
        'exts'    => ['php'],
        'dirs'    => ['admin'],
        'exclude' => ['.git'],
        'filter_out' => null,
    ],
    [
        'label'   => 'Wrong require depth /../../../ in 2-level-deep dirs (admin_panel/actions, admin_panel/media)',
        'pattern' => '__DIR__.*/../../../',
        'exts'    => ['php'],
        // Only check dirs that are exactly 2 levels deep — api/v1/* (3 levels) is correct
        'dirs'    => ['admin_panel/actions', 'admin_panel/media'],
        'exclude' => ['tests', '.git', 'vendor'],
        'filter_out' => null,
    ],
    [
        'label'   => 'Hardcoded noreply@newsxpresslive.com email',
        'pattern' => 'noreply@newsxpresslive\.com',
        'exts'    => ['php'],
        'dirs'    => ['.'],
        'exclude' => ['tests', '.git', 'vendor'],
        'filter_out' => null,
    ],
];

// ── Run ──────────────────────────────────────────────────────────────────────

$totalIssues = 0;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║          NewsXpressLive — Pre-deployment Path Audit          ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

foreach ($checks as $check) {
    $results = [];

    foreach ($check['dirs'] as $dir) {
        $hits = grepFiles($check['pattern'], $dir, $check['exts'], $check['exclude']);
        // Filter out this audit script itself
        $hits = array_filter($hits, fn($l) => !str_contains($l, 'scripts/path_audit.php'));
        // Filter out .env.example (allowed to contain placeholder names)
        $hits = array_filter($hits, fn($l) => !str_contains($l, '.env.example'));
        // Apply optional exclusion filter: lines containing this string are considered acceptable
        if (!empty($check['filter_out'])) {
            $fo   = $check['filter_out'];
            $hits = array_filter($hits, fn($l) => !str_contains($l, $fo));
        }
        $results = array_merge($results, array_values($hits));
    }

    if (empty($results)) {
        echo colorize('green', '[PASS]') . " {$check['label']}\n";
    } else {
        echo colorize('red', '[FAIL]') . " {$check['label']}\n";
        foreach ($results as $line) {
            echo colorize('yellow', '  → ' . $line) . "\n";
        }
        $totalIssues += count($results);
    }
}

echo "\n──────────────────────────────────────────────────────────────\n";

if ($totalIssues === 0) {
    echo colorize('green', '✔  All checks passed — codebase is deployment-ready.') . "\n\n";
    exit(0);
} else {
    echo colorize('red', "✘  {$totalIssues} issue(s) found — fix before deploying.") . "\n\n";
    exit(1);
}
