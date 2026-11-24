<?php

namespace App\Services\Reports;

/**
 * A small round "digitally signed" seal drawn with GD: a gold (signed) or grey (unsigned)
 * disc with a radial gradient, a darker rim and a highlight for depth, a soft drop shadow, a
 * check mark (or a dash) in the middle and the caption around the top. Returned as PNG bytes.
 */
class SignatureSeal
{
    private const SIZE = 240;   // drawn large, shown at ~64 px, so edges stay smooth

    public function png(bool $signed = true): string
    {
        $s = self::SIZE;
        $img = imagecreatetruecolor($s, $s);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);

        [$light, $dark] = $signed ? [[255, 214, 102], [176, 122, 14]] : [[226, 232, 240], [100, 116, 139]];
        $cx = $s / 2;
        $cy = $s / 2 - 6;
        $r = (int) ($s * 0.40);

        // Drop shadow (offset, soft).
        for ($i = 14; $i > 0; $i--) {
            imagefilledellipse($img, (int) $cx + 4, (int) $cy + 10, 2 * $r + $i * 2, 2 * $r + $i * 2, imagecolorallocatealpha($img, 0, 0, 0, 120 - $i * 2));
        }
        // Rim.
        imagefilledellipse($img, (int) $cx, (int) $cy, 2 * $r + 10, 2 * $r + 10, imagecolorallocate($img, (int) ($dark[0] * 0.8), (int) ($dark[1] * 0.8), (int) ($dark[2] * 0.8)));
        // Disc: radial gradient, lighter towards the upper left.
        for ($k = $r; $k > 0; $k--) {
            $t = $k / $r;
            $col = array_map(fn ($a, $b) => (int) round($b + ($a - $b) * (1 - $t) * 0.9), $light, $dark);
            $off = (1 - $t) * $r * 0.25;
            imagefilledellipse($img, (int) ($cx - $off), (int) ($cy - $off), 2 * $k, 2 * $k, imagecolorallocate($img, ...$col));
        }
        // Inner ring and highlight arc.
        imagesetthickness($img, 3);
        imagearc($img, (int) $cx, (int) $cy, (int) (2 * $r * 0.78), (int) (2 * $r * 0.78), 0, 360, imagecolorallocatealpha($img, 255, 255, 255, 70));
        imagesetthickness($img, 6);
        imagearc($img, (int) $cx, (int) $cy, 2 * $r - 8, 2 * $r - 8, 200, 290, imagecolorallocatealpha($img, 255, 255, 255, 55));

        // Centre mark: a check (signed) or a dash (unsigned).
        $white = imagecolorallocate($img, 255, 255, 255);
        imagesetthickness($img, 14);
        if ($signed) {
            imageline($img, (int) ($cx - 30), (int) ($cy + 2), (int) ($cx - 8), (int) ($cy + 24), $white);
            imageline($img, (int) ($cx - 8), (int) ($cy + 24), (int) ($cx + 34), (int) ($cy - 22), $white);
        } else {
            imageline($img, (int) ($cx - 28), (int) $cy, (int) ($cx + 28), (int) $cy, $white);
        }

        // Caption around the top arc.
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $text = $signed ? 'DIGITALLY SIGNED' : 'UNSIGNED DRAFT';
        if (is_file($font)) {
            $size = 13;
            $radius = $r * 0.62;
            $gap = 1.5;                             // px between letters along the arc
            // Each letter takes its own width along the arc, so narrow letters (I) sit close.
            $widths = array_map(function ($ch) use ($size, $font) {
                $box = imagettfbbox($size, 0, $font, $ch === ' ' ? 'n' : $ch);

                return abs($box[2] - $box[0]) * ($ch === ' ' ? 0.6 : 1.0);
            }, mb_str_split($text));
            $total = array_sum($widths) + $gap * (count($widths) - 1);
            $deg = 270 - rad2deg($total / $radius) / 2;   // centred on the top
            foreach (mb_str_split($text) as $i => $ch) {
                $half = rad2deg(($widths[$i] / 2) / $radius);
                $mid = $deg + $half;
                if ($ch !== ' ') {
                    $rad = deg2rad($mid);
                    // Start the glyph half its width before the arc point, along the tangent.
                    $tx = cos($rad + M_PI / 2);
                    $ty = sin($rad + M_PI / 2);
                    $x = $cx + $radius * cos($rad) - $tx * $widths[$i] / 2;
                    $y = $cy + $radius * sin($rad) - $ty * $widths[$i] / 2;
                    imagettftext($img, $size, -($mid + 90), (int) round($x), (int) round($y), $white, $font, $ch);
                }
                $deg = $mid + $half + rad2deg($gap / $radius);
            }
        }

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    public function dataUri(bool $signed = true): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($signed));
    }
}
