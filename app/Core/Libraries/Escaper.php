<?php

namespace App\Core\Libraries;

/**
 * Escaper Library
 */
class Escaper
{
    /**
     * Escape the HTML tags and entities
     *
     * @param string $string
     *
     * @return string
     */
    public function escapeHtml(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}