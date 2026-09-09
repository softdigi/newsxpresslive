<?php
/**
 * web/services/article_service.php
 *
 * TIER 3 — Code Quality: PHP Service layer
 *
 * Encapsulates all business logic and SQL for article operations.
 * API controllers (more_news.php, search.php, etc.) become thin
 * wrappers that parse input and call these methods.
 *
 * Benefits:
 *   - No duplicated SQL across API files
 *   - Single place to add caching, logging, or audit trails
 *   - Unit-testable without an HTTP layer
 */

require_once __DIR__ . '/../../helpers/cache.php';
require_once __DIR__ . '/../../helpers/image_cdn.php';

class ArticleService
{
    private PDO      $pdo;
    private ApiCache $cache;

    public function __construct(PDO $pdo)
    {
        $this->pdo   = $pdo;
        $this->cache = ApiCache::getInstance();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Feed
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fetch a paginated feed of published articles.
     *
     * @param  int    $page       1-based page number
     * @param  int    $perPage    Results per page (max 20)
     * @param  string $category   Category slug (empty = all categories)
     * @param  array  $languages  Allowed language codes (empty = all)
     * @return array{items: array, has_more: bool, total: int}
     */
    public function getFeed(
        int    $page     = 1,
        int    $perPage  = 10,
        string $category = '',
        array  $languages = []
    ): array {
        $page    = max(1, $page);
        $perPage = min(20, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $cacheKey = 'feed:' . md5("{$page}:{$perPage}:{$category}:" . implode(',', $languages));

        return $this->cache->remember($cacheKey, 60, function () use ($page, $perPage, $offset, $category, $languages) {
            [$where, $params] = $this->_buildFeedWhere($category, $languages);

            $sql = "SELECT n.id, n.title, n.slug, n.excerpt, n.featured_image,
                           n.created_at, n.is_breaking, n.views, n.viral_score,
                           c.name AS category_name, c.slug AS category_slug
                    FROM news n
                    LEFT JOIN categories c ON c.id = n.category_id
                    WHERE $where
                    ORDER BY n.created_at DESC
                    LIMIT :lim OFFSET :off";

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':lim',  $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off',  $offset,  PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll();

            $total   = $this->_countFeed($where, $params);
            $hasMore = ($offset + count($items)) < $total;

            return [
                'items'    => array_map([$this, '_formatArticle'], $items),
                'has_more' => $hasMore,
                'total'    => $total,
            ];
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Detail
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fetch a single article by slug. Returns null if not found.
     */
    public function getBySlug(string $slug): ?array
    {
        $cacheKey = 'article:slug:' . md5($slug);

        return $this->cache->remember($cacheKey, 300, function () use ($slug) {
            $stmt = $this->pdo->prepare(
                "SELECT n.*, c.name AS category_name, c.slug AS category_slug,
                        u.name AS author_name
                 FROM news n
                 LEFT JOIN categories c ON c.id = n.category_id
                 LEFT JOIN users u      ON u.id = n.reporter_id
                 WHERE n.slug = ? AND n.status = 'approved'
                 LIMIT 1"
            );
            $stmt->execute([$slug]);
            $row = $stmt->fetch();
            if (!$row) return null;
            return $this->_formatArticle($row, true);
        });
    }

    /**
     * Fetch a single article by ID. Returns null if not found.
     */
    public function getById(int $id): ?array
    {
        $cacheKey = 'article:id:' . $id;

        return $this->cache->remember($cacheKey, 300, function () use ($id) {
            $stmt = $this->pdo->prepare(
                "SELECT n.*, c.name AS category_name, c.slug AS category_slug
                 FROM news n
                 LEFT JOIN categories c ON c.id = n.category_id
                 WHERE n.id = ? AND n.status = 'approved'
                 LIMIT 1"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) return null;
            return $this->_formatArticle($row, true);
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Trending / Breaking
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fetch trending articles.
     */
    public function getTrending(int $limit = 10): array
    {
        return $this->cache->remember('trending:' . $limit, 120, function () use ($limit) {
            $stmt = $this->pdo->prepare(
                "SELECT n.id, n.title, n.slug, n.featured_image, n.views,
                        n.created_at, c.name AS category_name, c.slug AS category_slug
                 FROM news n
                 LEFT JOIN categories c ON c.id = n.category_id
                 WHERE n.status = 'approved'
                 ORDER BY n.viral_score DESC, n.views DESC, n.created_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
            return array_map([$this, '_formatArticle'], $stmt->fetchAll());
        });
    }

    /**
     * Fetch latest breaking news.
     */
    public function getBreaking(int $limit = 5): array
    {
        return $this->cache->remember('breaking:' . $limit, 60, function () use ($limit) {
            $stmt = $this->pdo->prepare(
                "SELECT n.id, n.title, n.slug, n.featured_image, n.created_at,
                        c.name AS category_name, c.slug AS category_slug
                 FROM news n
                 LEFT JOIN categories c ON c.id = n.category_id
                 WHERE n.status = 'approved' AND n.is_breaking = 1
                 ORDER BY n.created_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
            return array_map([$this, '_formatArticle'], $stmt->fetchAll());
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Increment views (fire-and-forget, non-blocking)
    // ──────────────────────────────────────────────────────────────────────

    public function incrementViews(int $articleId): void
    {
        try {
            $this->pdo->prepare('UPDATE news SET views = views + 1 WHERE id = ?')
                      ->execute([$articleId]);
            // Invalidate article caches
            $this->cache->delete('article:id:' . $articleId);
        } catch (PDOException $e) {
            error_log('ArticleService::incrementViews: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    private function _buildFeedWhere(string $category, array $languages): array
    {
        $conditions = ["n.status = :status"];
        $params     = [':status' => 'approved'];

        if ($category !== '') {
            $conditions[] = 'c.slug = :cat';
            $params[':cat'] = $category;
        }

        if (!empty($languages)) {
            $placeholders = implode(',', array_map(
                fn($i) => ":lang{$i}",
                range(0, count($languages) - 1)
            ));
            $conditions[] = "n.language IN ({$placeholders})";
            foreach ($languages as $i => $lang) {
                $params[":lang{$i}"] = $lang;
            }
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function _countFeed(string $where, array $params): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM news n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE $where"
        );
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    private function _formatArticle(array $row, bool $fullContent = false): array
    {
        $row['is_breaking']     = (bool)($row['is_breaking'] ?? false);
        $row['views']           = (int)($row['views'] ?? 0);
        $row['viral_score']     = (float)($row['viral_score'] ?? 0);
        $row['featured_image']  = !empty($row['featured_image'])
            ? imageUrl($row['featured_image'])
            : null;

        if (!$fullContent) {
            unset($row['content']);
        }

        // Remove internal/sensitive fields
        unset($row['reporter_id'], $row['category_id']);

        return $row;
    }
}
