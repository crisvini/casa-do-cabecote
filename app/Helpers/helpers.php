<?php

use Illuminate\Support\Str;

if (!function_exists('contrast_color')) {
    /**
     * Retorna uma cor de texto (#000 ou #FFF) com base na cor de fundo (hex).
     */
    function contrast_color(string $hexColor, string $default = '#111827'): string
    {
        if (empty($hexColor)) {
            return $default;
        }

        $hexColor = ltrim($hexColor, '#');

        // Trata cores curtas (ex: #abc → #aabbcc)
        if (strlen($hexColor) === 3) {
            $hexColor = $hexColor[0] . $hexColor[0] . $hexColor[1] . $hexColor[1] . $hexColor[2] . $hexColor[2];
        }

        $r = hexdec(substr($hexColor, 0, 2));
        $g = hexdec(substr($hexColor, 2, 2));
        $b = hexdec(substr($hexColor, 4, 2));

        // Fórmula de luminância perceptiva
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b);

        return $luminance > 160 ? '#111827' : '#FFFFFF';
    }
}
