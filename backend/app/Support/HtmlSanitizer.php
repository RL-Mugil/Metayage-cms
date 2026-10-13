<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list sanitizer for admin-authored rich text (privacy/terms pages). The result is rendered
 * with innerHTML on the public marketing site, so anything not explicitly allowed is removed:
 * unknown tags are unwrapped, script/style-like tags are dropped with their content, and the only
 * attributes kept are a validated `style` (color / text-align), `href` on links, and `align`.
 */
class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'h1', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li',
        'a', 'span', 'div', 'blockquote', 'hr', 'font',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math',
        'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'title', 'head',
    ];

    private const ALIGNS = ['left', 'right', 'center', 'justify'];

    /** Extra tags allowed only when the caller opts in (articles): images hosted on this site, with captions. */
    private const IMAGE_TAGS = ['img', 'figure', 'figcaption'];

    /** Only images uploaded through the admin (stored under /insights/img/) may be embedded. */
    private const IMAGE_SRC = '#^/insights/img/[a-z0-9][a-z0-9._-]{0,100}\.(?:webp|jpe?g|png|gif)$#i';

    private static bool $images = false;

    public static function clean(string $html, bool $allowImages = false): string
    {
        self::$images = $allowImages;
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div id="__root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if (! $root) {
            return '';
        }

        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                    continue;
                }

                if ($tag === 'img' && self::$images && ! preg_match(self::IMAGE_SRC, trim((string) $child->getAttribute('src')))) {
                    $node->removeChild($child); // not one of our uploads: never embed remote or data: images

                    continue;
                }

                self::walk($child);

                $allowed = self::ALLOWED_TAGS;
                if (self::$images) {
                    $allowed = array_merge($allowed, self::IMAGE_TAGS);
                }
                if (! in_array($tag, $allowed, true)) {
                    // Unwrap: keep the (already sanitized) children, drop the element.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                self::cleanAttributes($child, $tag);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        $style = [];
        $href = null;
        $src = null;
        $alt = '';

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = trim($attr->nodeValue);

            if ($name === 'style') {
                $style = array_merge($style, self::parseStyle($value));
            } elseif ($name === 'align' && in_array(strtolower($value), self::ALIGNS, true)) {
                $style['text-align'] = strtolower($value);
            } elseif ($name === 'color' && $tag === 'font' && self::validColor($value)) {
                $style['color'] = $value;
            } elseif ($name === 'href' && $tag === 'a') {
                $href = $value;
            } elseif ($tag === 'img' && $name === 'src') {
                $src = $value;
            } elseif ($tag === 'img' && $name === 'alt') {
                $alt = mb_substr($value, 0, 200);
            }
        }

        while ($el->attributes->length > 0) {
            $el->removeAttributeNode($el->attributes->item(0));
        }

        if ($tag === 'a' && $href !== null && preg_match('#^(https?://|mailto:|/|\#)#i', $href)) {
            $el->setAttribute('href', $href);
            $el->setAttribute('rel', 'noopener noreferrer');
        }

        if ($tag === 'img' && $src !== null) {
            $el->setAttribute('src', $src);
            $el->setAttribute('alt', $alt);
            $el->setAttribute('loading', 'lazy');
        }

        if ($style) {
            $el->setAttribute('style', implode('; ', array_map(fn ($k, $v) => "$k: $v", array_keys($style), $style)));
        }
    }

    /** @return array<string,string> only `color` and `text-align`, with validated values */
    private static function parseStyle(string $css): array
    {
        $out = [];
        foreach (explode(';', $css) as $decl) {
            if (! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);

            if ($prop === 'color' && self::validColor($val)) {
                $out['color'] = $val;
            } elseif ($prop === 'text-align' && in_array(strtolower($val), self::ALIGNS, true)) {
                $out['text-align'] = strtolower($val);
            }
        }

        return $out;
    }

    private static function validColor(string $v): bool
    {
        return (bool) preg_match(
            '/^(#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}\s*(,\s*\d{1,3}\s*){2}(,\s*(0|1|0?\.\d+)\s*)?\)|[a-zA-Z]{3,20})$/',
            $v
        );
    }
}
