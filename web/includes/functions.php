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
