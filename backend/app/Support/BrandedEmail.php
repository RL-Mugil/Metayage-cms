<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Building blocks for CanPatentIt's HTML emails: a branded frame, a calendar tile, rows, buttons.
 * Table layout with inline styles only, because that is what mail apps render reliably.
 */
class BrandedEmail
{
    public const BLUE = '#1481c4';
    public const DEEP = '#0f6ca8';
    public const INK = '#212529';
    public const MUTED = '#5c646c';
    public const GOLD = '#ecb439';
    public const SITE = 'https://canpatentit.com';

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** Full email: logo bar, coloured hero, white content card, quiet footer. */
    public static function frame(string $preheader, string $eyebrow, string $title, string $subtitle, string $content, string $footer = ''): string
    {
        $e = [self::class, 'e'];
        $subtitleHtml = $subtitle !== '' ? '<p style="margin:10px 0 0;color:#dff2fb;font-size:15px;line-height:1.55">'.$e($subtitle).'</p>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>'
            .'<body style="margin:0;padding:0;background:#eef2f5;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:'.self::INK.'">'
            .'<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">'.$e($preheader).'</div>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f5"><tr><td align="center" style="padding:24px 12px">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px">'
            // logo bar
            .'<tr><td style="padding:0 4px 14px"><a href="'.self::SITE.'" style="text-decoration:none"><img src="'.self::SITE.'/assets/logo.png" width="150" height="50" alt="CanPatentIt" style="display:block;border:0;height:auto"></a></td></tr>'
            // hero
            .'<tr><td bgcolor="'.self::DEEP.'" style="background:'.self::DEEP.' linear-gradient(135deg,'.self::DEEP.','.self::BLUE.');border-radius:14px 14px 0 0;padding:30px 32px 28px">'
            .'<p style="margin:0;color:#9fdcf5;font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase">'.$e($eyebrow).'</p>'
            .'<h1 style="margin:8px 0 0;color:#ffffff;font-size:26px;line-height:1.2;font-weight:650">'.$e($title).'</h1>'.$subtitleHtml.'</td></tr>'
            // content
            .'<tr><td style="background:#ffffff;border-radius:0 0 14px 14px;padding:26px 32px 30px;box-shadow:0 8px 30px rgba(20,60,90,.08)">'.$content.'</td></tr>'
            // footer
            .'<tr><td style="padding:18px 8px 0;color:#7b848c;font-size:12px;line-height:1.6;text-align:center">'.$footer
            .'<p style="margin:8px 0 0">CanPatentIt &middot; US patent strategy for deep technology &middot; <a href="'.self::SITE.'" style="color:'.self::DEEP.';text-decoration:none">canpatentit.com</a></p></td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    /** Calendar-style tile: month, big day, weekday, time range, time zone. */
    public static function dateTile(Carbon $start, Carbon $end, string $tz): string
    {
        $s = $start->copy()->setTimezone($tz);
        $en = $end->copy()->setTimezone($tz);

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f9fd;border:1px solid #d5e9f5;border-radius:12px"><tr>'
            .'<td width="96" align="center" style="padding:16px 8px 16px 18px"><table role="presentation" cellpadding="0" cellspacing="0" width="76" style="background:#ffffff;border:1px solid #cfe3f0;border-radius:10px;overflow:hidden">'
            .'<tr><td align="center" style="background:'.self::BLUE.';color:#ffffff;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:5px 0">'.self::e($s->format('M')).'</td></tr>'
            .'<tr><td align="center" style="padding:6px 0 8px;font-size:32px;font-weight:700;line-height:1;color:'.self::INK.'">'.self::e($s->format('j')).'</td></tr></table></td>'
            .'<td style="padding:16px 18px 16px 8px"><div style="font-size:18px;font-weight:650;color:'.self::INK.'">'.self::e($s->format('l, j F Y')).'</div>'
            .'<div style="margin-top:4px;font-size:15px;color:'.self::DEEP.';font-weight:600">'.self::e($s->format('g:i A')).' &ndash; '.self::e($en->format('g:i A')).' <span style="color:'.self::MUTED.';font-weight:400">'.self::e($s->format('T')).'</span></div>'
            .'<div style="margin-top:2px;font-size:12px;color:'.self::MUTED.'">'.self::e($tz).'</div></td></tr></table>';
    }

    /** Label/value rows in a quiet table. $rows: [label => html-safe value]. */
    public static function rows(array $rows): string
    {
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px">';
        foreach ($rows as $label => $valueHtml) {
            $out .= '<tr><td width="110" valign="top" style="padding:10px 0;border-bottom:1px solid #edf1f4;color:'.self::MUTED.';font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">'.self::e($label).'</td>'
                .'<td valign="top" style="padding:10px 0;border-bottom:1px solid #edf1f4;color:'.self::INK.';font-size:15px;line-height:1.5">'.$valueHtml.'</td></tr>';
        }

        return $out.'</table>';
    }

    public static function button(string $label, string $url, bool $primary = true): string
    {
        $bg = $primary ? self::GOLD : '#ffffff';
        $color = $primary ? self::INK : self::DEEP;
        $border = $primary ? self::GOLD : '#b8d6e8';

        return '<a href="'.self::e($url).'" style="display:inline-block;background:'.$bg.';color:'.$color.';border:1px solid '.$border.';border-radius:8px;padding:12px 22px;font-size:14px;font-weight:700;text-decoration:none;margin:0 10px 10px 0">'.self::e($label).'</a>';
    }

    /** A short bulleted list with round blue markers. */
    public static function steps(array $items): string
    {
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px">';
        foreach (array_values($items) as $i => $text) {
            $out .= '<tr><td width="34" valign="top" style="padding:7px 0"><div style="width:24px;height:24px;line-height:24px;border-radius:12px;background:#e6f3fb;color:'.self::DEEP.';font-size:12px;font-weight:700;text-align:center">'.($i + 1).'</div></td>'
                .'<td valign="top" style="padding:8px 0;font-size:14px;line-height:1.55;color:'.self::INK.'">'.self::e($text).'</td></tr>';
        }

        return $out.'</table>';
    }
}
