<?php
/**
 * Helper / Utility Functions
 * NewsXpressLive
 */

/**
 * Truncate a string to a given length, appending an ellipsis.
 */
function excerpt(string $text, int $length = 100): string
{
    $text = strip_tags($text);
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '...';
}

/**
 * Build an absolute URL to a news detail page using its slug.
 */
function newsUrl(string $slug): string
{
    return SITE_URL . '/news/detail.php?slug=' . urlencode($slug);
}

/**
 * Build an absolute URL to the AMP version of an article.
 */
function ampUrl(string $slug): string
{
    return SITE_URL . '/news/amp.php?slug=' . urlencode($slug);
}

/**
 * Build an absolute URL to a category page using its slug.
 */
function categoryUrl(string $slug): string
{
    return SITE_URL . '/category/view.php?slug=' . urlencode($slug);
}

/**
 * Build an absolute URL to a reporter profile using their id.
 */
function reporterUrl(int $id): string
{
    return SITE_URL . '/reporter/profile.php?id=' . $id;
}

/**
 * Build an absolute URL to an agency profile using their id.
 */
function agencyUrl(int $id): string
{
    return SITE_URL . '/agency/profile.php?id=' . $id;
}

/**
 * Build an absolute URL to a city news page using the city id.
 */
function cityUrl(int $id): string
{
    return SITE_URL . '/location/city.php?id=' . $id;
}

/**
 * Return the full URL to a news image, or a placeholder if not set.
 * Validates the filename to prevent directory traversal.
 */
function newsImage(string $filename = ''): string
{
    // Reject empty, paths containing directory separators, or dot-prefixed names
    if (
        $filename === '' ||
        strpos($filename, '/') !== false ||
        strpos($filename, '\\') !== false ||
        strpos($filename, '..') !== false ||
        $filename[0] === '.'
    ) {
        return SITE_URL . '/assets/img/placeholder.jpg';
    }

    $path = __DIR__ . '/../uploads/news/' . $filename;
    if (file_exists($path)) {
        return SITE_URL . '/uploads/news/' . rawurlencode($filename);
    }
    return SITE_URL . '/assets/img/placeholder.jpg';
}

/**
 * Return a safe absolute URL to a media file (reporter photo, agency logo, etc.).
 * Validates the filename to prevent directory traversal, same rules as newsImage().
 *
 * @param string $filename  Bare filename stored in the DB (e.g. "photo.jpg").
 * @param string $subdir    Sub-directory under /uploads/ (e.g. "reporters").
 * @param string $fallback  URL to return when no valid file is found.
 */
function mediaUrl(string $filename, string $subdir, string $fallback = ''): string
{
    if (
        $filename === '' ||
        strpos($filename, '/') !== false ||
        strpos($filename, '\\') !== false ||
        strpos($filename, '..') !== false ||
        $filename[0] === '.'
    ) {
        return $fallback;
    }

    return SITE_URL . '/uploads/' . $subdir . '/' . rawurlencode($filename);
}


/**
 * Estimate reading time in minutes.
 * Uses an average adult reading speed of 200 words per minute.
 * Returns at least 1 minute.
 */
function readingTime(string $content, int $wpm = 200): int
{
    $wordCount = str_word_count(strip_tags($content));
    return max(1, (int)ceil($wordCount / $wpm));
}

/**
 * Format a MySQL datetime string for display.
 */
function formatDate(string $datetime, string $format = 'M j, Y'): string
{
    try {
        $dt = new DateTime($datetime);
        return $dt->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Highlight search keywords inside a text string.
 */
function highlightKeywords(string $text, string $query): string
{
    if (trim($query) === '') {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    $safe  = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $words = array_filter(array_map('trim', explode(' ', $query)));
    foreach ($words as $word) {
        $escaped = preg_quote(htmlspecialchars($word, ENT_QUOTES, 'UTF-8'), '/');
        $safe = preg_replace(
            '/(' . $escaped . ')/iu',
            '<mark>$1</mark>',
            $safe
        );
    }
    return $safe;
}

/**
 * Sanitize a GET string parameter: strip tags, trim, and limit length.
 * NOTE: Does NOT apply htmlspecialchars – escape output at the point of display.
 */
function getParam(string $key, string $default = ''): string
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    return mb_substr(strip_tags(trim((string)$_GET[$key])), 0, 500);
}

/**
 * Sanitize a POST string parameter: strip tags, trim, and limit length.
 * NOTE: Does NOT apply htmlspecialchars – escape output at the point of display.
 */
function postParam(string $key, string $default = ''): string
{
    if (!isset($_POST[$key])) {
        return $default;
    }
    return mb_substr(strip_tags(trim((string)$_POST[$key])), 0, 500);
}

/**
 * Fetch all categories (cached in a static var to avoid repeated queries).
 */
function getAllCategories(PDO $pdo): array
{
    static $categories = null;
    if ($categories === null) {
        $stmt       = $pdo->query('SELECT id, name, slug FROM categories ORDER BY name ASC');
        $categories = $stmt->fetchAll();
    }
    return $categories;
}

/**
 * Paginate helper – returns offset and total pages.
 *
 * @return array{page: int, offset: int, perPage: int}
 */
function getPagination(int $perPage = 10): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    return [
        'page'    => $page,
        'offset'  => ($page - 1) * $perPage,
        'perPage' => $perPage,
    ];
}

/**
 * Render pagination links.
 * @param string $baseUrl URL up to (and including) the '?' character, e.g. "/news/?".
 *                         The function detects whether '?' is present and uses '&' or '?'
 *                         as appropriate.
 */
function renderPagination(int $totalItems, int $perPage, int $currentPage, string $baseUrl): void
{
    $totalPages = (int)ceil($totalItems / $perPage);
    if ($totalPages <= 1) {
        return;
    }

    // Determine the separator between base URL and page param
    $sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';

    echo '<nav class="pagination" aria-label="Pagination">';
    echo '<ul class="pagination__list">';

    // Previous
    if ($currentPage > 1) {
        $prev = $currentPage - 1;
        $url  = htmlspecialchars($baseUrl . $sep . 'page=' . $prev, ENT_QUOTES, 'UTF-8');
        echo '<li><a href="' . $url . '" class="pagination__link">&laquo; Prev</a></li>';
    }

    // Pages
    for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
        $active = ($i === $currentPage) ? ' pagination__link--active' : '';
        $url    = htmlspecialchars($baseUrl . $sep . 'page=' . $i, ENT_QUOTES, 'UTF-8');
        echo '<li><a href="' . $url . '" class="pagination__link' . $active . '">' . $i . '</a></li>';
    }

    // Next
    if ($currentPage < $totalPages) {
        $next = $currentPage + 1;
        $url  = htmlspecialchars($baseUrl . $sep . 'page=' . $next, ENT_QUOTES, 'UTF-8');
        echo '<li><a href="' . $url . '" class="pagination__link">Next &raquo;</a></li>';
    }

    echo '</ul></nav>';
}

/**
 * Generate URL-friendly slug from a string.
 * Used by trending/search pages for news links.
 */
function generateSlug(string $text): string
{
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    $text = trim($text, '-');
    return mb_strtolower($text, 'UTF-8');
}

/**
 * Format view counts for display (e.g., 1.2K, 3.4M).
 */
function formatViews(int $views): string
{
    if ($views >= 1000000) {
        return round($views / 1000000, 1) . 'M';
    }
    if ($views >= 1000) {
        return round($views / 1000, 1) . 'K';
    }
    return (string)$views;
}

/**
 * Display relative time (e.g., "2 hours ago").
 */
function timeAgo(string $datetime): string
{
    try {
        $now  = new DateTime();
        $past = new DateTime($datetime);
        $diff = $now->diff($past);

        if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
        return 'just now';
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Alias for highlightKeywords for backward compatibility.
 */
function highlightKeyword(string $text, string $query): string
{
    return highlightKeywords($text, $query);
}

/**
 * Extract first N sentences from content as key points.
 * Used for "Key Points" summary box on article pages.
 *
 * @param string $content  Raw article content (may contain HTML).
 * @param int    $count    Number of sentences to extract.
 * @return array           Array of sentences (min 30 chars each).
 */
function extractKeyPoints(string $content, int $count = 3): array
{
    // Strip HTML and decode entities
    $text = html_entity_decode(strip_tags($content), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', trim($text));
    
    // Split by sentence-ending punctuation
    $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    
    $points = [];
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        // Only include sentences with at least 30 characters
        if (mb_strlen($sentence) >= 30) {
            $points[] = $sentence;
            if (count($points) >= $count) {
                break;
            }
        }
    }
    
    return $points;
}

/**
 * Build URL for tag page.
 */
function tagUrl(string $slug): string
{
    return SITE_URL . '/news/tag.php?slug=' . urlencode($slug);
}

/**
 * Build URL for bookmarks page.
 */
function bookmarksUrl(): string
{
    return SITE_URL . '/bookmarks/';
}

/**
 * Fetch a single value from the settings table.
 * Uses a static cache so multiple calls for the same key hit the DB only once.
 */
function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([$key]);
            $cache[$key] = (string)($stmt->fetchColumn() ?: '');
        } catch (PDOException $e) {
            $cache[$key] = '';
        }
    }
    return $cache[$key] !== '' ? $cache[$key] : $default;
}
