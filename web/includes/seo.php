<?php
/**
 * SEO Helper
 * NewsXpressLive - Generates meta tags, Open Graph, Twitter Card,
 *                  JSON-LD structured data (NewsArticle + BreadcrumbList).
 */

/**
 * Output HTML meta tags for SEO and Open Graph.
 *
 * Supported $meta keys:
 *   title        (string)  – Page-specific title. SITE_NAME is appended automatically
 *                            UNLESS titleFull is also set.
 *   titleFull    (string)  – Use the value as-is (no SITE_NAME appended). Use for
 *                            the homepage where you've already composed the full title.
 *   description  (string)  – Meta description (max 160 chars after truncation).
 *   keywords     (string)  – Meta keywords.
 *   image        (string)  – Absolute URL to the featured image.
 *   url          (string)  – Canonical URL.
 *   type         (string)  – OG type: 'website' (default) or 'article'.
 *   author       (string)  – Author name (rendered as <meta name="author">).
 *   robots       (string)  – robots directive, e.g. 'noindex,nofollow'. Omit for default.
 *   published_at (string)  – Article publish date (ISO 8601) for OG article:published_time.
 */
function renderSeoMeta(array $meta = []): void
{
    $siteName    = SITE_NAME;
    $defaultDesc = 'Latest breaking news, top stories, and live updates from ' . SITE_NAME;
    $defaultImg  = SITE_URL . '/assets/img/og-default.jpg';

    // ── Title ──────────────────────────────────────────────────────────────
    // titleFull bypasses the "| SITE_NAME" suffix (used on the homepage to
    // avoid "NewsXpressLive – Tagline | NewsXpressLive" duplication).
    if (!empty($meta['titleFull'])) {
        $title = htmlspecialchars($meta['titleFull'], ENT_QUOTES, 'UTF-8');
    } elseif (!empty($meta['title'])) {
        $title = htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8') . ' | ' . $siteName;
    } else {
        $title = $siteName . ' | ' . SITE_TAGLINE;
    }

    // ── Description ────────────────────────────────────────────────────────
    $description = !empty($meta['description'])
        ? htmlspecialchars(strip_tags($meta['description']), ENT_QUOTES, 'UTF-8')
        : $defaultDesc;

    if (mb_strlen($description) > 160) {
        $description = mb_substr($description, 0, 157) . '...';
    }

    // ── Keywords ───────────────────────────────────────────────────────────
    $keywords = !empty($meta['keywords'])
        ? htmlspecialchars($meta['keywords'], ENT_QUOTES, 'UTF-8')
        : 'news, breaking news, latest news, headlines';

    // ── Other values ───────────────────────────────────────────────────────
    $image  = !empty($meta['image'])  ? $meta['image']  : $defaultImg;
    $url    = !empty($meta['url'])    ? $meta['url']    : SITE_URL . '/';
    $ogType = !empty($meta['type'])   ? $meta['type']   : 'website';

    // ── Standard tags ──────────────────────────────────────────────────────
    echo '<title>' . $title . '</title>' . "\n";
    echo '<meta name="description" content="' . $description . '">' . "\n";
    echo '<meta name="keywords" content="' . $keywords . '">' . "\n";

    if (!empty($meta['author'])) {
        echo '<meta name="author" content="' . htmlspecialchars($meta['author'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    if (!empty($meta['robots'])) {
        echo '<meta name="robots" content="' . htmlspecialchars($meta['robots'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    echo '<link rel="canonical" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    // ── Open Graph ─────────────────────────────────────────────────────────
    echo '<meta property="og:title" content="' . $title . '">' . "\n";
    echo '<meta property="og:description" content="' . $description . '">' . "\n";
    echo '<meta property="og:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:url" content="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:type" content="' . htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:site_name" content="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    if ($ogType === 'article' && !empty($meta['published_at'])) {
        echo '<meta property="article:published_time" content="'
            . htmlspecialchars($meta['published_at'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    // ── Twitter Card ───────────────────────────────────────────────────────
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . $title . '">' . "\n";
    echo '<meta name="twitter:description" content="' . $description . '">' . "\n";
    echo '<meta name="twitter:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
}

/**
 * Output a <script type="application/ld+json"> block.
 * Accepts any valid schema.org array (will be JSON-encoded).
 */
function renderJsonLd(array $schema): void
{
    echo '<script type="application/ld+json">'
        . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}

/**
 * Build a NewsArticle JSON-LD schema array for an article page.
 *
 * @param array $news  Row from the news + joins query (expects same fields as detail.php).
 */
function buildNewsArticleJsonLd(array $news): array
{
    $schema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'NewsArticle',
        'headline'         => $news['title'],
        'description'      => excerpt($news['content'], 160),
        'url'              => newsUrl($news['slug']),
        'datePublished'    => date('c', strtotime($news['created_at'])),
        'dateModified'     => date('c', strtotime($news['created_at'])),
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => SITE_NAME,
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => SITE_URL . '/assets/img/og-default.jpg',
            ],
        ],
    ];

    if (!empty($news['featured_image'])) {
        $schema['image'] = [
            '@type' => 'ImageObject',
            'url'   => newsImage($news['featured_image']),
        ];
    }

    if (!empty($news['reporter_name'])) {
        $schema['author'] = [
            '@type' => 'Person',
            'name'  => $news['reporter_name'],
            'url'   => reporterUrl((int)$news['reporter_id']),
        ];
    } else {
        $schema['author'] = [
            '@type' => 'Organization',
            'name'  => SITE_NAME,
        ];
    }

    return $schema;
}

/**
 * Build a BreadcrumbList JSON-LD schema array.
 *
 * @param array $items  [ ['name' => '...', 'url' => '...'], ... ]
 */
function buildBreadcrumbJsonLd(array $items): array
{
    $listItems = [];
    foreach ($items as $pos => $item) {
        $listItems[] = [
            '@type'    => 'ListItem',
            'position' => $pos + 1,
            'name'     => $item['name'],
            'item'     => $item['url'],
        ];
    }

    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $listItems,
    ];
}
