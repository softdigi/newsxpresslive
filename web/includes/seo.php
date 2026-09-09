<?php
/**
 * SEO Helper
 * NewsXpressLive - Generates meta tags, Open Graph, Twitter Card,
 *                  JSON-LD structured data (NewsArticle + BreadcrumbList + WebSite).
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
 *   modified_at  (string)  – Article modified date (ISO 8601) for OG article:modified_time.
 *   section      (string)  – Article section / category for OG article:section.
 *   tags         (array)   – Array of tag name strings for OG article:tag.
 *   news_keywords(string)  – Comma-separated keywords for Google News (news_keywords meta).
 *   amphtml      (string)  – AMP page URL; emits <link rel="amphtml">.
 *   prefetch_url (string)  – Next article URL; emits <link rel="prefetch">.
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

    // Default robots allows crawling and permits large image previews for Google Discover.
    // Pass $meta['robots'] to override (e.g. 'noindex,nofollow' for 404 pages).
    $robots = !empty($meta['robots'])
        ? htmlspecialchars($meta['robots'], ENT_QUOTES, 'UTF-8')
        : 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
    echo '<meta name="robots" content="' . $robots . '">' . "\n";

    if (!empty($meta['news_keywords'])) {
        echo '<meta name="news_keywords" content="' . htmlspecialchars($meta['news_keywords'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    echo '<link rel="canonical" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    // AMP counterpart link (tells Google this page has an AMP version)
    if (!empty($meta['amphtml'])) {
        echo '<link rel="amphtml" href="' . htmlspecialchars($meta['amphtml'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    // Optional prefetch of the next article (set via $meta['prefetch_url'])
    if (!empty($meta['prefetch_url'])) {
        echo '<link rel="prefetch" href="' . htmlspecialchars($meta['prefetch_url'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    // ── Open Graph ─────────────────────────────────────────────────────────
    echo '<meta property="og:title" content="' . $title . '">' . "\n";
    echo '<meta property="og:description" content="' . $description . '">' . "\n";
    echo '<meta property="og:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:image:width" content="' . (int)($meta['image_width'] ?? 1200) . '">' . "\n";
    echo '<meta property="og:image:height" content="' . (int)($meta['image_height'] ?? 630) . '">' . "\n";
    echo '<meta property="og:image:type" content="image/jpeg">' . "\n";
    echo '<meta property="og:url" content="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:type" content="' . htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:site_name" content="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:locale" content="en_US">' . "\n";

    if ($ogType === 'article') {
        if (!empty($meta['published_at'])) {
            echo '<meta property="article:published_time" content="'
                . htmlspecialchars($meta['published_at'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        if (!empty($meta['modified_at'])) {
            echo '<meta property="article:modified_time" content="'
                . htmlspecialchars($meta['modified_at'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        if (!empty($meta['author'])) {
            echo '<meta property="article:author" content="'
                . htmlspecialchars($meta['author'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        if (!empty($meta['section'])) {
            echo '<meta property="article:section" content="'
                . htmlspecialchars($meta['section'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        if (!empty($meta['tags']) && is_array($meta['tags'])) {
            foreach ($meta['tags'] as $tag) {
                echo '<meta property="article:tag" content="'
                    . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }
        }
    }

    // ── Twitter Card ───────────────────────────────────────────────────────
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . $title . '">' . "\n";
    echo '<meta name="twitter:description" content="' . $description . '">' . "\n";
    echo '<meta name="twitter:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta name="twitter:image:alt" content="' . $title . '">' . "\n";
    if (defined('TWITTER_SITE') && TWITTER_SITE !== '') {
        echo '<meta name="twitter:site" content="' . htmlspecialchars(TWITTER_SITE, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
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
 * @param array $tags  Optional array of tag name strings for the keywords property.
 */
function buildNewsArticleJsonLd(array $news, array $tags = []): array
{
    $datePublished = date('c', strtotime($news['created_at']));
    // Use updated_at when available and it differs meaningfully from created_at
    $dateModified  = !empty($news['updated_at'])
        ? date('c', strtotime($news['updated_at']))
        : $datePublished;

    $schema = [
        '@context'      => 'https://schema.org',
        '@type'         => 'NewsArticle',
        'headline'      => $news['title'],
        'description'   => excerpt($news['content'], 160),
        'url'           => newsUrl($news['slug']),
        'datePublished' => $datePublished,
        'dateModified'  => $dateModified,
        'publisher'     => [
            '@type' => 'Organization',
            'name'  => SITE_NAME,
            'logo'  => [
                '@type'  => 'ImageObject',
                'url'    => SITE_URL . '/assets/img/og-default.jpg',
                'width'  => 600,
                'height' => 60,
            ],
        ],
    ];

    if (!empty($news['featured_image'])) {
        $schema['image'] = [
            '@type'  => 'ImageObject',
            'url'    => newsImage($news['featured_image']),
            'width'  => 1200,
            'height' => 630,
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

    if (!empty($news['category_name'])) {
        $schema['articleSection'] = $news['category_name'];
    }

    if (!empty($tags)) {
        $schema['keywords'] = implode(', ', array_column($tags, 'name'));
    }

    $wordCount = str_word_count(strip_tags($news['content']));
    if ($wordCount > 0) {
        $schema['wordCount'] = $wordCount;
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

/**
 * Build a WebSite JSON-LD schema with SiteLinksSearchBox for the homepage.
 */
function buildWebSiteJsonLd(): array
{
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        'name'            => SITE_NAME,
        'url'             => SITE_URL . '/',
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => SITE_URL . '/news/search.php?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}
