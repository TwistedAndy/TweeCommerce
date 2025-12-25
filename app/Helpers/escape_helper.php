<?php

use \App\Libraries\Escaper;

if (!function_exists('esc_html')) {
    /**
     * Escape the HTML tags and entities
     *
     * @param string $string
     *
     * @return string
     */
    function esc_html($string): string
    {
        return Escaper::escapeHtml($string);
    }
}