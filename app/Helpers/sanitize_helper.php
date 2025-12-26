<?php

use App\Libraries\Sanitizer;

if (!function_exists('sanitize_key')) {
    /**
     * Sanitize a key or a slug
     *
     * @param string $text
     *
     * @return string
     */
    function sanitize_key(string $text): string
    {
        return Sanitizer::sanitizeKey($text);
    }
}

if (!function_exists('sanitize_filename')) {
    /**
     * Sanitize a filename (preserve extension, remove accents, filesystem safe)
     *
     * @param string $filename
     *
     * @return string
     */
    function sanitize_filename(string $filename): string
    {
        return Sanitizer::sanitizeFilename($filename);
    }
}

if (!function_exists('sanitize_text')) {
    /**
     * Remove all tags and special characters from a string
     *
     * @param string $string
     * @param bool $newLines
     *
     * @return string
     */
    function sanitize_text(string $string, bool $newLines = false): string
    {
        return Sanitizer::sanitizeText($string, $newLines);
    }
}

if (!function_exists('sanitize_html')) {
    /**
     * Remove any potentially malicious code and tags
     *
     * @param string $html
     * @param null|string $allowedTags
     *
     * @return string
     */
    function sanitize_html(string $html, ?string $allowedTags = null): string
    {
        return Sanitizer::sanitizeHtml($html, $allowedTags);
    }
}