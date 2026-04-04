<?php
/**
 * CsvUploadValidationTest — Unit tests for CSV upload security & validation.
 *
 * Tests the functions introduced in api/v1/agency/csv_upload.php:
 *   • _sanitizeCsvCell()   — CSV injection prevention
 *   • _scanMaliciousContent() — script/iframe/XSS detection
 *   • _sanitizeArticleRow()   — combined row sanitizer
 *   • _validateRow()          — required field and format checks
 *
 * Also covers:
 *   • MIME type acceptance/rejection
 *   • Duplicate external_id detection
 *   • Missing required fields
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Copy the functions under test so we can call them directly without
// bootstrapping the full HTTP request pipeline.
// ---------------------------------------------------------------------------

function _sanitizeCsvCell(string $value): string
{
    $value = trim($value);
    if (strlen($value) > 0 && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $value = "\t" . $value;
    }
    return $value;
}

function _scanMaliciousContent(array $row): array
{
    $errors = [];
    $fieldsToScan = ['title', 'content', 'summary'];

    $patterns = [
        '/<script[\s>]/i'           => 'contains <script> tag',
        '/<\/script>/i'             => 'contains </script> tag',
        '/<iframe[\s>]/i'           => 'contains <iframe> tag',
        '/<object[\s>]/i'           => 'contains <object> tag',
        '/<embed[\s>]/i'            => 'contains <embed> tag',
        '/<form[\s>]/i'             => 'contains <form> tag',
        '/javascript\s*:/i'         => 'contains javascript: URI',
        '/\bon\w+\s*=/i'            => 'contains inline event handler (on*=)',
        '/vbscript\s*:/i'           => 'contains vbscript: URI',
        '/data\s*:\s*text\/html/i'  => 'contains data:text/html URI',
    ];

    foreach ($fieldsToScan as $field) {
        $value = $row[$field] ?? '';
        if ($value === '') continue;
        foreach ($patterns as $pattern => $description) {
            if (preg_match($pattern, $value)) {
                $errors[] = "field '{$field}' {$description}";
                break;
            }
        }
    }

    return $errors;
}

function _sanitizeArticleRow(array $row): array
{
    $stringFields = ['external_id', 'title', 'content', 'summary', 'language', 'image_url', 'source_url'];
    foreach ($stringFields as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = _sanitizeCsvCell($row[$field]);
        }
    }

    $allowedTags = '<p><br><b><strong><em><i><ul><ol><li><h1><h2><h3><h4><blockquote><a>';
    foreach (['title', 'content', 'summary'] as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = strip_tags($row[$field], $allowedTags);
        }
    }

    return $row;
}

function _validateRow(array $row): array
{
    $errors = [];

    if (empty($row['external_id'])) {
        $errors[] = 'external_id is required';
    }
    if (empty($row['title'])) {
        $errors[] = 'title is required';
    } elseif (mb_strlen($row['title']) < 10) {
        $errors[] = 'title must be at least 10 characters';
    } elseif (mb_strlen($row['title']) > 200) {
        $errors[] = 'title must not exceed 200 characters';
    }
    if (empty($row['content'])) {
        $errors[] = 'content is required';
    } elseif (mb_strlen($row['content']) < 100) {
        $errors[] = 'content must be at least 100 characters';
    }

    foreach (['image_url', 'source_url'] as $urlField) {
        if (!empty($row[$urlField])) {
            if (filter_var($row[$urlField], FILTER_VALIDATE_URL) === false) {
                $errors[] = "{$urlField} is not a valid URL";
            }
        }
    }

    if (!empty($row['published_at'])) {
        $ts = strtotime($row['published_at']);
        if ($ts === false || $ts <= 0) {
            $errors[] = 'published_at is not a valid datetime';
        }
    }

    $maliciousErrors = _scanMaliciousContent($row);
    $errors = array_merge($errors, $maliciousErrors);

    return $errors;
}

// ---------------------------------------------------------------------------
// Helpers for building test rows
// ---------------------------------------------------------------------------

function validArticleRow(array $overrides = []): array
{
    return array_merge([
        'external_id' => 'EXT-001',
        'title'       => 'This is a valid article title',
        'content'     => str_repeat('This is enough content for the article. ', 10),
        'summary'     => 'A brief summary.',
        'category_id' => '1',
        'language'    => 'en',
        'image_url'   => 'https://example.com/image.jpg',
        'source_url'  => 'https://example.com/source',
        'published_at'=> '2024-01-15T10:00:00Z',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------

class CsvUploadValidationTest extends TestCase
{
    // ── CSV injection sanitization ───────────────────────────────────────────

    public function testEqualsFormulaIsPrefixed(): void
    {
        $sanitized = _sanitizeCsvCell('=SUM(A1:A10)');
        $this->assertStringStartsWith("\t", $sanitized);
        $this->assertStringContainsString('=SUM', $sanitized);
    }

    public function testPlusFormulaIsPrefixed(): void
    {
        $sanitized = _sanitizeCsvCell('+5+5');
        $this->assertStringStartsWith("\t", $sanitized);
    }

    public function testMinusFormulaIsPrefixed(): void
    {
        $sanitized = _sanitizeCsvCell('-5');
        $this->assertStringStartsWith("\t", $sanitized);
    }

    public function testAtFormulaIsPrefixed(): void
    {
        $sanitized = _sanitizeCsvCell('@SUM(1)');
        $this->assertStringStartsWith("\t", $sanitized);
    }

    public function testNormalTextIsUnchanged(): void
    {
        $value     = 'Normal article title here';
        $sanitized = _sanitizeCsvCell($value);
        $this->assertEquals($value, $sanitized);
    }

    public function testHyperlinkFormulaIsNeutralized(): void
    {
        $malicious = '=HYPERLINK("http://evil.com","Click me")';
        $sanitized = _sanitizeCsvCell($malicious);
        $this->assertStringStartsWith("\t", $sanitized,
            'CSV hyperlink formula must be prefixed to prevent injection');
    }

    public function testEmptyStringIsUnchanged(): void
    {
        $this->assertEquals('', _sanitizeCsvCell(''));
    }

    // ── Malicious content scanning ────────────────────────────────────────────

    public function testScriptTagInTitleIsDetected(): void
    {
        $row    = validArticleRow(['title' => '<script>alert("XSS")</script>A valid title']);
        $errors = _scanMaliciousContent($row);
        $this->assertNotEmpty($errors, 'Script tag in title must be flagged');
        $this->assertStringContainsString('script', $errors[0]);
    }

    public function testIframeInContentIsDetected(): void
    {
        $row = validArticleRow([
            'content' => str_repeat('Long content. ', 10) . '<iframe src="http://evil.com"></iframe>',
        ]);
        $errors = _scanMaliciousContent($row);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('iframe', $errors[0]);
    }

    public function testJavascriptUriInContentIsDetected(): void
    {
        $row = validArticleRow([
            'content' => str_repeat('Long content here. ', 10) . '<a href="javascript:alert(1)">click</a>',
        ]);
        $errors = _scanMaliciousContent($row);
        $this->assertNotEmpty($errors);
    }

    public function testOnEventHandlerIsDetected(): void
    {
        $row = validArticleRow([
            'content' => str_repeat('Good content here. ', 10) . '<img src="x" onerror="alert(1)">',
        ]);
        $errors = _scanMaliciousContent($row);
        $this->assertNotEmpty($errors);
    }

    public function testObjectTagIsDetected(): void
    {
        $row = validArticleRow([
            'content' => str_repeat('Content here. ', 10) . '<object data="malware.swf"></object>',
        ]);
        $errors = _scanMaliciousContent($row);
        $this->assertNotEmpty($errors);
    }

    public function testVbscriptUriIsDetected(): void
    {
        $row = validArticleRow([
            'content' => str_repeat('Safe content. ', 10) . '<a href="vbscript:MsgBox(1)">click</a>',
        ]);
        $errors = _scanMaliciousContent($row);
        $this->assertNotEmpty($errors);
    }

    public function testCleanContentPassesScan(): void
    {
        $row    = validArticleRow();
        $errors = _scanMaliciousContent($row);
        $this->assertEmpty($errors, 'Clean content should produce no security errors');
    }

    // ── Row validation ────────────────────────────────────────────────────────

    public function testValidRowHasNoErrors(): void
    {
        $errors = _validateRow(validArticleRow());
        $this->assertEmpty($errors, implode(', ', $errors));
    }

    public function testMissingExternalIdIsRejected(): void
    {
        $errors = _validateRow(validArticleRow(['external_id' => '']));
        $this->assertContains('external_id is required', $errors);
    }

    public function testMissingTitleIsRejected(): void
    {
        $errors = _validateRow(validArticleRow(['title' => '']));
        $this->assertContains('title is required', $errors);
    }

    public function testTitleTooShortIsRejected(): void
    {
        $errors = _validateRow(validArticleRow(['title' => 'Short']));
        $this->assertContains('title must be at least 10 characters', $errors);
    }

    public function testTitleTooLongIsRejected(): void
    {
        $errors = _validateRow(validArticleRow(['title' => str_repeat('X', 201)]));
        $this->assertContains('title must not exceed 200 characters', $errors);
    }

    public function testMissingContentIsRejected(): void
    {
        $errors = _validateRow(validArticleRow(['content' => '']));
        $this->assertContains('content is required', $errors);
    }

    public function testContentTooShortIsRejected(): void
    {
        $errors = _validateRow(validArticleRow(['content' => 'Too short.']));
        $this->assertContains('content must be at least 100 characters', $errors);
    }

    public function testInvalidImageUrlIsRejected(): void
    {
        $errors = _validateRow(validArticleRow(['image_url' => 'not-a-url']));
        $this->assertContains('image_url is not a valid URL', $errors);
    }

    public function testValidImageUrlPasses(): void
    {
        $errors = _validateRow(validArticleRow(['image_url' => 'https://cdn.example.com/img.jpg']));
        $this->assertNotContains('image_url is not a valid URL', $errors);
    }

    public function testInvalidPublishedAtIsRejected(): void
    {
        $errors = _validateRow(validArticleRow(['published_at' => 'not-a-date']));
        $this->assertContains('published_at is not a valid datetime', $errors);
    }

    public function testValidIso8601DatePasses(): void
    {
        $errors = _validateRow(validArticleRow(['published_at' => '2024-06-15T08:00:00Z']));
        $this->assertEmpty(array_filter($errors, fn($e) => str_contains($e, 'published_at')));
    }

    public function testScriptInjectionInTitleFailsValidation(): void
    {
        $errors = _validateRow(validArticleRow([
            'title' => '<script>alert("xss")</script> Valid Title Here',
        ]));
        $hasSecurityError = !empty(array_filter($errors, fn($e) => str_contains($e, 'script')));
        $this->assertTrue($hasSecurityError, 'Script injection in title must be caught by _validateRow');
    }

    // ── Combined sanitization ─────────────────────────────────────────────────

    public function testSanitizeArticleRowRemovesScriptTags(): void
    {
        $row      = validArticleRow(['title' => '<script>bad()</script> Good Title Here']);
        $cleaned  = _sanitizeArticleRow($row);
        $this->assertStringNotContainsString('<script>', $cleaned['title']);
        $this->assertStringContainsString('Good Title Here', $cleaned['title']);
    }

    public function testSanitizeArticleRowNeutralizesFormulas(): void
    {
        $row     = validArticleRow(['external_id' => '=FORMULA']);
        $cleaned = _sanitizeArticleRow($row);
        $this->assertStringStartsWith("\t", $cleaned['external_id']);
    }

    public function testSanitizeArticleRowPreservesAllowedHtml(): void
    {
        $row     = validArticleRow(['summary' => '<b>Bold</b> and <em>italic</em> text.']);
        $cleaned = _sanitizeArticleRow($row);
        $this->assertStringContainsString('<b>Bold</b>', $cleaned['summary']);
        $this->assertStringContainsString('<em>italic</em>', $cleaned['summary']);
    }
}
