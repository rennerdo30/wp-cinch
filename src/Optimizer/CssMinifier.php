<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * Conservative regex-based CSS minifier.
 *
 * Goals:
 *  - Strip /* block comments *​/ (preserves /*! ... *​/ license blocks).
 *  - Collapse whitespace runs to a single space.
 *  - Drop whitespace around the structural tokens { } : ; , > ~ +.
 *  - Drop the last ; before a }.
 *  - Trim leading / trailing whitespace.
 *
 * Goals it does NOT pursue (rabbit holes that break valid CSS):
 *  - Removing the leading 0 from 0.5 → .5 (breaks calc() in some old browsers).
 *  - Merging adjacent rules with identical selectors.
 *  - Color shortening (#ffffff → #fff) — handled per-engine, can hurt cache hits.
 *  - Removing units from 0px → 0 (breaks flex-basis: 0% in old Safari).
 *
 * The big win is comment stripping + whitespace; the rest is noise next
 * to gzip/Brotli compression.
 */
final class CssMinifier
{
    public static function minify(string $css): string
    {
        if ($css === '') {
            return '';
        }

        // 1. Strip block comments. Preserve /*! ... */ license/banner blocks
        //    — that's a 20-year-old YUI convention every CSS minifier honours.
        $css = (string) preg_replace('#/\*(?!!)[\s\S]*?\*/#', '', $css);

        // 2. Normalise newlines.
        $css = str_replace(["\r\n", "\r"], "\n", $css);

        // 3. Protect strings from whitespace collapse. We don't have content
        //    that crosses line boundaries in well-formed CSS, but url() and
        //    content: "..." values can carry literal spaces.
        $placeholders = [];
        $css = (string) preg_replace_callback(
            '/(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')/',
            static function (array $m) use (&$placeholders): string {
                $key = "\x01CINCH_STR_" . count($placeholders) . "\x01";
                $placeholders[$key] = $m[0];
                return $key;
            },
            $css
        );

        // 4. Collapse internal whitespace runs.
        $css = (string) preg_replace('/\s+/', ' ', $css);

        // 5. Trim whitespace around structural tokens.
        $css = (string) preg_replace('/\s*([{};:,>~+])\s*/', '$1', $css);

        // 6. Drop the trailing ; before a closing brace — it's always optional.
        $css = (string) preg_replace('/;}/', '}', $css);

        // 7. Trim outer whitespace.
        $css = trim($css);

        // 8. Restore strings.
        if ($placeholders !== []) {
            $css = strtr($css, $placeholders);
        }

        return $css;
    }
}
