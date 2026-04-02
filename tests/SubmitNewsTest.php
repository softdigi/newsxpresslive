<?php
/**
 * Tests for api/submit_news.php  — valid and invalid payload scenarios.
 *
 * Rather than booting the full HTTP stack we test the validation logic
 * extracted into a testable function so the test suite can run without
 * Apache/nginx.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// ── Inline validation logic (mirrors submit_news.php) ─────────────────────────

/**
 * Validates a news-submission payload.
 *
 * @param  array $input  Decoded JSON body.
 * @return array{ok: bool, missing: list<string>}
 */
function validateSubmitNews(array $input): array
{
    $required = ['title', 'description', 'category_id', 'language_id'];
    $missing  = [];

    foreach ($required as $field) {
        if (empty($input[$field])) {
            $missing[] = $field;
        }
    }

    return ['ok' => empty($missing), 'missing' => $missing];
}

/**
 * Sanitise and build a news row ready for INSERT.
 *
 * @param  array $input
 * @param  int   $userId
 * @return array  Column → value map.
 */
function buildNewsRow(array $input, int $userId): array
{
    $slugBase = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $input['title'])));
    $slug     = $slugBase . '-' . time();

    return [
        'title'            => $input['title'],
        'slug'             => $slug,
        'description'      => $input['description'],
        'category_id'      => (int) $input['category_id'],
        'language_id'      => (int) $input['language_id'],
        'user_id'          => $userId,
        'status'           => 'pending',
        'meta_title'       => $input['meta_title']       ?? $input['title'],
        'meta_description' => $input['meta_description'] ?? substr($input['description'], 0, 150),
        'moderation_flag'  => empty($input['moderation_flag']) ? 0 : 1,
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

class SubmitNewsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        // Seed a reporter user
        $this->pdo->exec(
            "INSERT INTO users (firebase_uid, name, role, status)
             VALUES ('uid_reporter', 'Reporter User', 'reporter', 'active')"
        );
    }

    // ── Validation: required fields ───────────────────────────────────────────

    public function testValidPayloadPassesValidation(): void
    {
        $input = [
            'title'       => 'Big news today',
            'description' => 'Something important happened.',
            'category_id' => 3,
            'language_id' => 1,
        ];

        $result = validateSubmitNews($input);
        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['missing']);
    }

    public function testMissingTitleFailsValidation(): void
    {
        $input = [
            'description' => 'No title here.',
            'category_id' => 1,
            'language_id' => 1,
        ];

        $result = validateSubmitNews($input);
        $this->assertFalse($result['ok']);
        $this->assertContains('title', $result['missing']);
    }

    public function testMissingDescriptionFailsValidation(): void
    {
        $input = [
            'title'       => 'A title',
            'category_id' => 1,
            'language_id' => 1,
        ];

        $result = validateSubmitNews($input);
        $this->assertFalse($result['ok']);
        $this->assertContains('description', $result['missing']);
    }

    public function testMissingCategoryAndLanguageFailsValidation(): void
    {
        $input = [
            'title'       => 'A title',
            'description' => 'A body',
        ];

        $result = validateSubmitNews($input);
        $this->assertFalse($result['ok']);
        $this->assertContains('category_id',  $result['missing']);
        $this->assertContains('language_id', $result['missing']);
    }

    public function testEmptyPayloadReportsAllFields(): void
    {
        $result = validateSubmitNews([]);
        $this->assertFalse($result['ok']);
        $this->assertCount(4, $result['missing']);
    }

    // ── Slug generation ───────────────────────────────────────────────────────

    public function testSlugIsGeneratedFromTitle(): void
    {
        $row = buildNewsRow(
            ['title' => 'Hello World!', 'description' => 'Body', 'category_id' => 1, 'language_id' => 1],
            userId: 1,
        );

        $this->assertStringStartsWith('hello-world-', $row['slug']);
    }

    public function testSpecialCharsAreRemovedFromSlug(): void
    {
        $row = buildNewsRow(
            ['title' => 'Test & Verify: ₹100', 'description' => 'Body', 'category_id' => 1, 'language_id' => 1],
            userId: 1,
        );

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+-\d+$/', $row['slug']);
    }

    // ── Moderation flag ───────────────────────────────────────────────────────

    public function testModerationFlagDefaultsToZero(): void
    {
        $row = buildNewsRow(
            ['title' => 'T', 'description' => 'D', 'category_id' => 1, 'language_id' => 1],
            userId: 1,
        );
        $this->assertSame(0, $row['moderation_flag']);
    }

    public function testModerationFlagIsSetToOneWhenProvided(): void
    {
        $row = buildNewsRow(
            ['title' => 'T', 'description' => 'D', 'category_id' => 1, 'language_id' => 1, 'moderation_flag' => 1],
            userId: 1,
        );
        $this->assertSame(1, $row['moderation_flag']);
    }

    // ── Database round-trip ───────────────────────────────────────────────────

    public function testValidSubmissionCanBeInsertedIntoDb(): void
    {
        $input = [
            'title'       => 'DB Test Article',
            'description' => 'Body text for testing database insertion.',
            'category_id' => 2,
            'language_id' => 1,
        ];

        $validation = validateSubmitNews($input);
        $this->assertTrue($validation['ok']);

        $row = buildNewsRow($input, userId: 1);
        $row['created_at'] = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            "INSERT INTO news (title, slug, description, category_id, language_id,
                               user_id, status, meta_title, meta_description,
                               moderation_flag, created_at)
             VALUES (:title, :slug, :description, :category_id, :language_id,
                     :user_id, :status, :meta_title, :meta_description,
                     :moderation_flag, :created_at)"
        );
        $stmt->execute($row);

        $id = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $id);

        $fetched = $this->pdo->query("SELECT * FROM news WHERE id = $id")->fetch();
        $this->assertEquals('DB Test Article', $fetched['title']);
        $this->assertEquals('pending', $fetched['status']);
    }
}
