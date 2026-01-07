<?php

namespace Tests\Unit\Core\Libraries;

use App\Core\Libraries\Sanitizer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Functional Test Suite for Sanitizer Library.
 * This suite verifies the non-security features of the Sanitizer, such as:
 * - Tag balancing and repair
 * - Allowed tag filtering
 * - Filename and key sanitization
 * - Text normalization
 */
class SanitizerFeatureTest extends CIUnitTestCase
{
    protected Sanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new Sanitizer();
    }

    /**
     * Test sanitization of keys/slugs.
     * Should lowercase, remove accents, and replace spaces with underscores.
     */
    public function testSanitizeKey(): void
    {
        $cases = [
            'Hello World'                  => 'hello_world',
            '  Trim Me  '                  => 'trim_me',
            'Café & Restaurant'            => 'cafe__restaurant',
            // & removed, spaces become _
            'User-Name_123'                => 'user-name_123',
            "Invalid!@#Chars\x80"          => 'invalidchars',
            '___Multiple___Underscores___' => '___multiple___underscores___',
            // Leading/trailing underscores preserved usually unless specific logic exists, but let's check basic normalization
            '123 Key'                      => '123_key',
            'UPPER_CASE'                   => 'upper_case',
            "New\nLine"                    => 'new_line',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->sanitizer->sanitizeKey($input));
        }
    }

    /**
     * Test filename sanitization.
     * Should preserve extensions, remove paths, and ensure filesystem safety.
     */
    public function testSanitizeFilename(): void
    {
        $cases = [
            'My Image.jpg'                => 'my image.jpg',
            '../../etc/passwd'            => 'passwd', // Directory traversal removal via pathinfo
            'héllo_wörld.png'             => 'hello_world.png', // Transliteration
            'image  with  spaces.gif'     => 'image with spaces.gif', // Collapsing spaces
            '-start-dash.txt'             => 'start-dash.txt', // Trim leading dash
            'end-underscore_'             => 'end-underscore', // Trim trailing underscore
            str_repeat('a', 300) . '.txt' => str_repeat('a', 251) . '.txt', // Length limit
            ''                            => 'file', // Empty fallback
            '.hidden'                     => 'file.hidden', // Hidden file becoming filename
            'multiple.dots.tar.gz'        => 'multiple.dots.tar.gz',
            'Invalid!@#Chars'             => 'invalidchars',
            '  spaced  .txt'              => 'spaced.txt',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->sanitizer->sanitizeFilename($input));
        }
    }

    /**
     * Test plain text sanitization.
     * Should remove tags and optionally handle newlines.
     */
    public function testSanitizeText(): void
    {
        // 1. Tag Removal
        $this->assertSame('Hello World', $this->sanitizer->sanitizeText('<b>Hello</b> World'));
        $this->assertSame('alert(1)', $this->sanitizer->sanitizeText('<script>alert(1)</script>'));

        // 2. Newline Handling (False = remove newlines)
        $this->assertSame('Line 1 Line 2', $this->sanitizer->sanitizeText("Line 1\nLine 2", false));

        // 3. Newline Handling (True = preserve newlines)
        $this->assertSame("Line 1\nLine 2", $this->sanitizer->sanitizeText("Line 1\nLine 2", true));

        // 4. Encoded entities
        $this->assertSame('Foo', $this->sanitizer->sanitizeText('&#70;oo'));

        // 5. Recursive decoding check (e.g. % encoding)
        // sanitizeText removes %xx sequences, it does not decode them
        $this->assertSame('bc', $this->sanitizer->sanitizeText('%61bc'));

        // 6. Whitespace normalization
        $this->assertSame('Too many spaces', $this->sanitizer->sanitizeText("Too    many \t spaces"));
    }

    /**
     * Test HTML sanitization with different filtered tag sets.
     */
    public function testSanitizeHtmlAllowedTags(): void
    {
        $html = '<h1>Title</h1><p>Paragraph</p><div>Div</div><script>bad</script>';

        // 1. Default tags (allows h1, p, div)
        $cleanDefault = $this->sanitizer->sanitizeHtml($html);
        $this->assertStringContainsString('<h1>Title</h1>', $cleanDefault);
        $this->assertStringContainsString('<p>Paragraph</p>', $cleanDefault);
        $this->assertStringContainsString('<div>Div</div>', $cleanDefault);
        $this->assertStringNotContainsString('<script>', $cleanDefault);

        // 2. Basic tags (allows simple formatting, disallows h1, div)
        $cleanBasic = $this->sanitizer->sanitizeHtml($html, 'basic');
        $this->assertStringNotContainsString('<h1>', $cleanBasic);
        $this->assertStringContainsString('Title', $cleanBasic); // Content preserved
        $this->assertStringNotContainsString('<div>', $cleanBasic);

        // 3. Custom tag list
        $cleanCustom = $this->sanitizer->sanitizeHtml($html, '<h1>');
        $this->assertStringContainsString('<h1>Title</h1>', $cleanCustom);
        $this->assertStringNotContainsString('<p>', $cleanCustom);
        $this->assertStringNotContainsString('<div>', $cleanCustom);

        // 4. Full (allows everything except dangerous scripts)
        // Note: sanitizeHtml strips <script> even in 'full' mode via blacklist logic usually,
        // but let's check non-blacklisted structural tags.
        $htmlFull  = '<article>Content</article>';
        $cleanFull = $this->sanitizer->sanitizeHtml($htmlFull, 'full');
        $this->assertSame('<article>Content</article>', $cleanFull);
    }

    /**
     * Test the tag balancing feature specifically.
     */
    public function testBalanceTags(): void
    {
        $cases = [
            // Simple closing
            '<div>Content'                 => '<div>Content</div>',

            // Nested closing
            '<b><i>Bold Italic</b>'        => '<b><i>Bold Italic</i></b>',

            // List closing - simple balancer closes explicitly without implied closure knowledge
            '<ul><li>One<li>Two</ul>'      => '<ul><li>One<li>Two</li></li></ul>',

            // Removing stray closing tags
            'Content</div>'                => 'Content',
            'Content</b></i>'              => 'Content',

            // Complex nesting
            '<div><p><span>Text</p></div>' => '<div><p><span>Text</span></p></div>',

            // Attributes preservation during balance
            '<div class="test">Content'    => '<div class="test">Content</div>',

            // Single tags handling (should not try to close <br> or <img>)
            'Line 1<br>Line 2'             => 'Line 1<br>Line 2',
            '<img src="img.jpg">'          => '<img src="img.jpg">',

            // Case insensitivity (Balancer lowercases tags)
            '<DIV>Content'                 => '<div>Content</div>',

            // Interleaved tags
            '<b><i>Text</b></i>'           => '<b><i>Text</i></b>',
            '<div><span>Text</div></span>' => '<div><span>Text</span></div>',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->sanitizer->sanitizeHtml($input));
        }
    }

    /**
     * Test handling of attributes (quoting and filtering).
     */
    public function testAttributeHandling(): void
    {
        // 1. Attribute Quoting
        $input    = '<div class=myClass id=myId>Content</div>';
        $expected = '<div class="myClass" id="myId">Content</div>';
        $this->assertSame($expected, $this->sanitizer->sanitizeHtml($input));

        // 2. Removal of empty or too short src/poster
        $inputSrc = '<img src="">';
        $this->assertEmpty($this->sanitizer->sanitizeHtml($inputSrc));

        $inputShort = '<img src="a">';
        $this->assertEmpty($this->sanitizer->sanitizeHtml($inputShort));

        // 3. Valid src preservation
        $inputValid = '<img src="valid.jpg">';
        $this->assertSame($inputValid, $this->sanitizer->sanitizeHtml($inputValid));

        // 4. Duplicate attributes (Sanitizer usually keeps the first or cleans structure, just checking it doesn't break)
        $inputDup = '<div class="a" class="b"></div>';
        $cleanDup = $this->sanitizer->sanitizeHtml($inputDup);
        $this->assertStringContainsString('class="a"', $cleanDup);
    }

    /**
     * Test removal of orphaned closing brackets or stray syntax.
     */
    public function testStrayArtifacts(): void
    {
        // Orphaned > at start
        // Sanitizer skips processing if no < is found
        $this->assertSame('>Text', $this->sanitizer->sanitizeHtml('>Text'));
        // If < is present, cleaning kicks in and trims leading ">"
        $this->assertSame('<div>Text</div>', $this->sanitizer->sanitizeHtml('"><div>Text</div>'));

        // Null bytes
        $this->assertSame('Hello', $this->sanitizer->sanitizeHtml("Hel\0lo"));
    }

    /**
     * Test UTF-8 and encoding normalization.
     */
    public function testEncodingNormalization(): void
    {
        // Invalid UTF-8 sequence should be cleaned or handled gracefully
        $invalidUtf8 = "Invalid \x80 sequence";
        $clean       = $this->sanitizer->sanitizeHtml($invalidUtf8);
        $this->assertNotFalse(mb_check_encoding($clean, 'UTF-8'));
    }
}