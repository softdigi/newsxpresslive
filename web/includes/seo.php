<?php
/**
 * SEO Helper
 * NewsXpressLive - Generates meta tags for pages
 */

/**
 * Output HTML meta tags for SEO and Open Graph
 *
 * @param array $meta {
 *   @type string title       Page title
 *   @type string description Meta description
 *   @type string keywords    Meta keywords
 *   @type string image       Absolute URL to the featured image
 *   @type string url         Canonical URL
 *   @type string type        OG type (website, article)
 * }
 */
function renderSeoMeta(array $meta = []): void
{
    $siteName    = SITE_NAME;
    $defaultDesc = 'Latest breaking news, top stories, and live updates from ' . SITE_NAME;
    $defaultImg  = SITE_URL . '/assets/img/og-default.jpg';

    $title       = !empty($meta['title'])
        ? htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8') . ' | ' . $siteName
        : $siteName . ' | ' . SITE_TAGLINE;

    $description = !empty($meta['description'])
        ? htmlspecialchars(strip_tags($meta['description']), ENT_QUOTES, 'UTF-8')
        : $defaultDesc;

    // Truncate description to 160 chars
    if (mb_strlen($description) > 160) {
        $description = mb_substr($description, 0, 157) . '...';
    }

    $keywords = !empty($meta['keywords'])
        ? htmlspecialchars($meta['keywords'], ENT_QUOTES, 'UTF-8')
        : 'news, breaking news, latest news, headlines';

    $image    = !empty($meta['image']) ? $meta['image'] : $defaultImg;
    $url      = !empty($meta['url'])   ? $meta['url']   : SITE_URL . '/';
    $ogType   = !empty($meta['type'])  ? $meta['type']  : 'website';

    echo '<title>' . $title . '</title>' . "\n";
    echo '<meta name="description" content="' . $description . '">' . "\n";
    echo '<meta name="keywords" content="' . $keywords . '">' . "\n";
    echo '<link rel="canonical" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    // Open Graph
    echo '<meta property="og:title" content="' . $title . '">' . "\n";
    echo '<meta property="og:description" content="' . $description . '">' . "\n";
    echo '<meta property="og:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:url" content="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:type" content="' . htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:site_name" content="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    // Twitter Card
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . $title . '">' . "\n";
    echo '<meta name="twitter:description" content="' . $description . '">' . "\n";
    echo '<meta name="twitter:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
}
