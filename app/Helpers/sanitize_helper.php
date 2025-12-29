<?php

use \App\Core\Container\Container;
use \App\Core\Libraries\Sanitizer;

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
        return Container::getInstance()->make(Sanitizer::class)->sanitizeKey($text);
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
        return Container::getInstance()->make(Sanitizer::class)->sanitizeFilename($filename);
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
        return Container::getInstance()->make(Sanitizer::class)->sanitizeText($string, $newLines);
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
        return Container::getInstance()->make(Sanitizer::class)->sanitizeHtml($html, $allowedTags);
    }
}