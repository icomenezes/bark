<?php

namespace App\Support;

class ColorShade
{
    /** @return array{0:int,1:int,2:int} */
    public static function toRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Mistura o hex com branco na proporção $amount (0 = original, 1 = branco). */
    public static function lighten(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::toRgb($hex);
        $amount = max(0.0, min(1.0, $amount));

        $r = (int) round($r + (255 - $r) * $amount);
        $g = (int) round($g + (255 - $g) * $amount);
        $b = (int) round($b + (255 - $b) * $amount);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
