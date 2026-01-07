<?php

namespace App\Core\Libraries;

/**
 * Sanitizer Library
 */
class Sanitizer
{
    /**
     * Basic set of HTML tags
     */
    public const TAGS_BASIC = '<a><b><strong><em><i><ins><del><sup><sub><u><s><small><span><abbr><br><wbr>';

    /**
     * Standard set of HTML tags
     */
    public const TAGS_DEFAULT = '<a><b><strong><em><i><ins><del><sup><sub><u><s><small><span><abbr><br><wbr><div><section><article><aside><nav><main><menu><h1><h2><h3><h4><h5><h6><code><pre><p><ul><ol><li><blockquote><q><cite><author><table><thead><tbody><tfoot><tr><th><td><img><picture><video><source><audio><track><figure><figcaption><dl><dt><dialog><details><col><colgroup><summary><rp><rt><ruby><samp><time><style><button><label><legend><kbd><map><mark>';

    /**
     * All HTML tags
     */
    public const TAGS_FULL = 'full';

    /**
     * A list of self-closing tags
     */
    public const TAGS_SINGLE = [
        'area'    => true,
        'base'    => true,
        'br'      => true,
        'col'     => true,
        'command' => true,
        'embed'   => true,
        'hr'      => true,
        'img'     => true,
        'input'   => true,
        'link'    => true,
        'meta'    => true,
        'param'   => true,
        'source'  => true,
        'track'   => true,
        'wbr'     => true,
    ];

    /**
     * Attribute protocols pattern
     */
    protected string $protocolsCache = '';

    public function __construct()
    {
        // Build spaced-out patterns for all dangerous keywords
        $protocols = [];
        $keywords  = ['javascript', 'vbscript', 'data', 'livescript', 'behavior', 'expression', 'import'];

        foreach ($keywords as $keyword) {
            $protocols[] = implode('([\s\0\x09\x0A\x0D]|&#[xX]?[0-9a-fA-F]+;?|\/\*.*?\*\/)*', str_split($keyword));
        }

        $this->protocolsCache = '(' . implode('|', $protocols) . ')';
    }

    /**
     * Sanitize a key or a slug
     */
    public function sanitizeKey(string $text): string
    {
        $text = $this->normalizeString($text);

        $text = $this->transliterate($text);

        $text = mb_strtolower($text, 'UTF-8');

        return preg_replace(['/\s+/u', '/[^a-z0-9_\-]/'], ['_', ''], trim($text));
    }

    /**
     * Sanitize a filename (preserve extension, remove accents, filesystem safe)
     */
    public function sanitizeFilename(string $filename): string
    {
        $filename = $this->normalizeString($filename);

        $info = pathinfo($filename);

        $extension = isset($info['extension']) ? '.' . mb_strtolower($info['extension']) : '';

        $name = $info['filename'];

        // Remove accents and normalize Unicode
        $name = $this->transliterate($name);
        $name = mb_strtolower($name, 'UTF-8');

        // Allow a-z, 0-9, dash, underscore, space. Remove everything else.
        $name = preg_replace('/[^a-z0-9\- _.]/u', '', $name);

        // Collapse multiple consecutive spaces, dashes, or underscores
        $name = preg_replace('/([ \-_])\1+/u', '$1', $name);

        // Remove multiple consecutive dashes and trim
        $name = trim($name, '- _');

        if ($name === '') {
            $name = 'file';
        }

        // Limit length to 255 bytes (standard filesystem limit)
        $limit = 255 - strlen($extension);

        if (mb_strlen($name) > $limit) {
            $name = mb_strcut($name, 0, $limit, 'UTF-8');
        }

        return $name . $extension;
    }

    /**
     * Remove all tags and special characters from a string
     */
    public function sanitizeText(string $text, bool $newLines = false): string
    {
        $text = $this->normalizeString($text);

        if (str_contains($text, '<')) {
            $text = preg_replace('/<(?!\/?([a-z]|!))/i', '&lt;', $text);
            $text = strip_tags($text);
            $text = str_replace("<\n", "&lt;\n", $text);
        }

        $text = trim($text);

        if (str_contains($text, '%')) {
            $pattern = '/%[a-fA-F0-9]{2}/';
            if (preg_match($pattern, $text)) {
                do {
                    $text = preg_replace($pattern, '', $text, -1, $count);
                } while ($count > 0);
                $text = trim($text);
            }
        }

        if (!$newLines) {
            $text = strtr($text, "\r\n\t", '   ');
            $text = preg_replace('/  +/', ' ', $text);
        }

        return trim($text);
    }

    /**
     * Remove any potentially malicious code and tags
     */
    public function sanitizeHtml(string $html, ?string $allowedTags = null): string
    {
        // Swap placeholders for existing entities to restore them later
        $html = str_ireplace(['&lt;', '&gt;', '%3c', '%3e'], ['##lt##', '##gt##', '<', '>'], $html);

        $html = $this->normalizeString($html);

        if (!str_contains($html, '<')) {
            return trim($html);
        }

        if ($allowedTags === null) {
            $html = strip_tags($html, $this::TAGS_DEFAULT);
        } elseif ($allowedTags !== $this::TAGS_FULL) {
            $allowedTags = match ($allowedTags) {
                'basic' => $this::TAGS_BASIC,
                'default' => $this::TAGS_DEFAULT,
                default => $allowedTags,
            };

            $html = strip_tags($html, $allowedTags);
        }

        // Sanitize tag attributes
        $html = $this->processAttributes($html);

        $html = str_ireplace(['##lt##', '##gt##'], ['&lt;', '&gt;'], $html);

        // Process <style> sections
        if (str_contains($html, '<style')) {
            $html = $this->processStyle($html);
        }

        // Remove tags with empty or too short src
        $html = preg_replace('/<(img|video|audio|source|iframe)\b[^>]*?\s+(src|poster)\s*=\s*["\']\s*.{0,3}\s*["\'][^>]*>/siu', '', $html);

        // Remove orphaned ">" at the start of a line
        $html = preg_replace('/^[\s"“\'`]*>/um', '', $html);

        if (str_starts_with($html, '>') or str_starts_with($html, '">')) {
            $html = ltrim($html, '"> ');
        }

        if ($this->hasUnbalancedTags($html)) {
            $html = $this->balanceTags($html);
        }

        return trim($html);
    }

    /**
     * Balance HTML tags and remove stray tags
     */
    public function balanceTags(string $html): string
    {
        // Find all tags: <(slash?)(tagname)(attributes)>
        // This pattern matches: <div...>, </div>, <img ... />
        preg_match_all('/<(\/?)([a-z0-9:-]+)([^>]*)>/i', $html, $matches, PREG_OFFSET_CAPTURE);

        $output       = '';
        $stack        = [];
        $lastPosition = 0;

        foreach ($matches[0] as $key => $full_match) {

            // Append text content that exists BEFORE this tag
            $position     = $full_match[1];
            $output       .= substr($html, $lastPosition, $position - $lastPosition);
            $lastPosition = $position + strlen($full_match[0]);

            // Analyze the tag components
            $tag = strtolower($matches[2][$key][0]);

            $attrs    = $matches[3][$key][0];
            $isSingle = isset($this::TAGS_SINGLE[$tag]);
            $isClose  = ($matches[1][$key][0] === '/');

            if ($isClose) {
                // Handle Closing Tag
                if ($stack and end($stack) === $tag) {
                    // Perfect match: pop stack and output
                    array_pop($stack);
                    $output .= "</$tag>";
                } elseif (in_array($tag, $stack, true)) {
                    // Implicit match: close nested tags until we find the match
                    // Example: <b><i>text</b> -> <b><i>text</i></b>
                    while (($popped = array_pop($stack)) !== $tag) {
                        $output .= "</$popped>";
                    }
                    $output .= "</$tag>";
                }
                // If not in stack, it's a stray </tag>. Do nothing (remove it).
            } elseif (substr(trim($attrs), -1) === '/' && !$isSingle) {
                // Handle Opening Tag
                // Check for XHTML self-closing style <div /> on non-single tags
                $cleanAttrs = rtrim(trim($attrs), '/');
                $output     .= "<$tag$cleanAttrs></$tag>";
            } else {
                // Output the open tag
                $output .= "<$tag$attrs>";
                // Push to stack if it needs a closing tag later
                if (!$isSingle) {
                    $stack[] = $tag;
                }
            }
        }

        // Append any remaining text after the last tag
        $output .= substr($html, $lastPosition);

        // Close any tags left open in the stack
        while ($tag = array_pop($stack)) {
            $output .= "</$tag>";
        }

        return $output;
    }

    /**
     * Check if a string contains unbalanced or improperly nested tags.
     */
    public function hasUnbalancedTags(string $html): bool
    {
        // Find all tags: <(slash?)(tagname)>
        if (!preg_match_all('/<(\/?)([a-z0-9:-]+)[^>]*>/i', $html, $matches)) {
            return false;
        }

        $stack = [];

        foreach ($matches[2] as $index => $tagName) {

            $tag = strtolower($tagName);

            $is_close = ($matches[1][$index] === '/');

            // Skip single tags (e.g. <br>, <img>) as they don't affect balance
            if (isset($this::TAGS_SINGLE[$tag])) {
                continue;
            }

            if ($is_close) {
                // Found closing tag </tag>
                if (empty($stack)) {
                    return true; // Error: Closing tag with no opener
                }

                $top = array_pop($stack);

                if ($top !== $tag) {
                    return true; // Error: Nesting mismatch (e.g., <b><i></b></i>)
                }
            } elseif (substr(trim($matches[0][$index]), -2, 1) !== '/') {
                // Found opening tag <tag>
                // Check if it's a self-closing XHTML tag like <div />
                $stack[] = $tag;
            }
        }

        // If stack is not empty, we have unclosed tags (e.g., <div> with no </div>)
        return !empty($stack);
    }

    /**
     * Prepare the string
     */
    public function normalizeString(string $text): string
    {
        // Check the encoding first
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Remove null bytes
        $text = str_replace(chr(0), '', $text);

        // Decode entities to reveal hidden keywords
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove non-layout control characters
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    /**
     * Transliterate a string (remove accents) with fallback
     */
    public function transliterate(string $text): string
    {
        if (function_exists('normalizer_is_normalized') and !normalizer_is_normalized($text)) {
            $text = normalizer_normalize($text);
        }

        if (function_exists('transliterator_transliterate')) {
            $result = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
            if ($result !== false) {
                return $result;
            }
        }

        if (function_exists('iconv')) {
            return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        }

        return $text;
    }

    /**
     * Sanitize the <style> tags
     */
    protected function processStyle(string $html): string
    {
        return preg_replace_callback('#(<style[^>]*>)(.*?)(</style>)#si', static function ($matches) {

            $styles = $matches[2] ?? '';

            // Strip all the comments
            $styles = preg_replace('#/\*.*?\*/#s', '', $styles);

            // Check for dangerous chars, keywords, or imports
            if (strpbrk($styles, '(\\@') === false and stripos($styles, 'behavior') === false) {
                return $styles ? '<style>' . $styles . '</style>' : '';
            }

            // Helper regex part: match until semicolon OR closing brace OR end of string
            // This prevents the regex from "eating" the closing bracket of the class
            $terminator = '(?=;|}|$)';

            // Remove @import rules with \s+ to ensure it catches spaces/newlines after @import
            $styles = preg_replace('#@import\s+.*?' . $terminator . '[\s;]*#si', '/* removed import */', $styles);

            // Remove "expression(...)" rules
            $styles = preg_replace('#\b[\w-]+\s*:\s*expression\s*\(.*?' . $terminator . '[\s;]*#si', '/* removed expression */', $styles);

            // Remove "url(javascript:...)" or "url(vbscript:...)" or "url(data:...)"
            $styles = preg_replace(
                '#\b[\w-]+\s*:\s*url\s*\(\s*[\'"]?\s*(javascript:|vbscript:|data:)[^)]+\).*?' . $terminator . '[\s;]*#si',
                '/* removed unsafe url */',
                $styles
            );

            // Remove "behavior" property (IE .htc Attack)
            $styles = preg_replace('#\bbehavior\s*:.*?' . $terminator . '[\s;]*#si', '/* removed behavior */', $styles);

            // Remove rules with obfuscated backslash escapes in property names
            $styles = preg_replace('#\b[\w-]*\\[\w-]*\s*:.*?' . $terminator . '[\s;]*#si', '/* removed obfuscated property */', $styles);

            return $styles ? '<style>' . $styles . '</style>' : '';
        }, $html);
    }

    /**
     * Sanitize tag attributes
     */
    protected function processAttributes(string $html): string
    {
        // Force quotes on attributes
        $count   = 0;
        $pattern = '/(<[a-z0-9]+\b[^>]*?)\s+([a-z0-9_-]+)\s*=\s*(?!["\'])([^\s>]+)/iu';

        do {
            $html = preg_replace($pattern, '$1 $2="$3"', $html, -1, $count);
        } while ($count > 0);

        $patterns = [
            // Clean the dangerous attributes like on
            '/(<[a-z][a-z0-9]*\b[^>]*?)\K([\s\/]+(on[a-z]+|formaction|classid|dynsrc|lowsrc)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))/iu',

            // Check attributes, which may have a link
            '/(<[a-z][a-z0-9]*\b[^>]*?)\K([\s\/]+(href|src|style|action|data|formaction|background|cite|poster|xlink:href)\s*=\s*("[^"]*?(' . $this->protocolsCache . ')[^"]*?"|\'[^\']*?(' . $this->protocolsCache . ')[^\']*?\'|[^\s>]*?(' . $this->protocolsCache . ')[^\s>]*))/siu',

            // Check malformed attributes
            '/(<[a-z][a-z0-9]*\b[^>]*?)\K([\s\/]+[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\')[^\s>]+)/iu',
        ];

        // Loop to handle nested attacks like <a href="java&#09;script:..."> or <img src="javajavascript:script:">
        do {
            $html = preg_replace($patterns, '', $html, -1, $count);
        } while ($count > 0);

        return $html;
    }

}