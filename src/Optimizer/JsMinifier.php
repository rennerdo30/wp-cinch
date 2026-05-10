<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * Ultra-conservative regex-based JS minifier.
 *
 * Real JS minification (mangling identifiers, dead-code elimination,
 * folding constants) requires a parser — Terser, esbuild, etc. Those
 * pull in Node, which we refuse to require. Aggressively minifying JS
 * with regex is a footgun (regex literals look like division, ASI rules
 * are subtle, template strings span lines). So we do the cheap passes:
 *
 *  - Strip // line comments that aren't inside a string or regex literal.
 *  - Strip /* block comments *​/ (preserve /*! license *​/ banners).
 *  - Collapse runs of blank lines.
 *  - Trim trailing whitespace per line.
 *
 * The actual on-the-wire win comes from removing comments (especially
 * the JSDoc walls in jQuery-flavored plugins) and from Brotli/gzip
 * eating the resulting denser file better.
 */
final class JsMinifier
{
    public static function minify(string $js): string
    {
        if ($js === '') {
            return '';
        }

        $js = str_replace(["\r\n", "\r"], "\n", $js);

        // Tokenize so we can leave strings / regex / template literals alone.
        // This isn't a real JS parser — it's a single-pass scanner that
        // recognizes "stuff we must not touch" and replaces it with
        // placeholders. Comments outside strings get dropped.
        $out         = '';
        $i           = 0;
        $len         = strlen($js);
        $prev_meaningful = '';

        while ($i < $len) {
            $c  = $js[$i];
            $c2 = $i + 1 < $len ? $js[$i + 1] : '';

            // Line comment // — only if not inside a string (we're already
            // outside strings here).
            if ($c === '/' && $c2 === '/') {
                $nl = strpos($js, "\n", $i);
                if ($nl === false) {
                    break;
                }
                $i = $nl; // keep the \n
                continue;
            }

            // Block comment /* ... */
            if ($c === '/' && $c2 === '*') {
                $end = strpos($js, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $is_banner = $i + 2 < $len && $js[$i + 2] === '!';
                if ($is_banner) {
                    // Preserve /*! ... */ license blocks verbatim.
                    $out .= substr($js, $i, $end - $i + 2);
                }
                $i = $end + 2;
                continue;
            }

            // String literals: ' " `
            if ($c === '"' || $c === "'" || $c === '`') {
                $quote = $c;
                $start = $i;
                $i++;
                while ($i < $len) {
                    $ch = $js[$i];
                    if ($ch === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($ch === $quote) {
                        $i++;
                        break;
                    }
                    $i++;
                }
                $out .= substr($js, $start, $i - $start);
                $prev_meaningful = $quote;
                continue;
            }

            // Regex literal — only when the / could syntactically start one.
            // Heuristic: previous meaningful char is empty, or one of
            // (,=:[!&|?{};+~*%<>^ or a keyword terminator. Otherwise it's division.
            if ($c === '/' && self::could_start_regex($prev_meaningful)) {
                $start = $i;
                $i++;
                $in_class = false;
                while ($i < $len) {
                    $ch = $js[$i];
                    if ($ch === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($ch === '[') {
                        $in_class = true;
                    } elseif ($ch === ']') {
                        $in_class = false;
                    } elseif ($ch === '/' && !$in_class) {
                        $i++;
                        // Consume flags.
                        while ($i < $len && ctype_alpha($js[$i])) {
                            $i++;
                        }
                        break;
                    } elseif ($ch === "\n") {
                        // Regex can't span lines — treat as division after all.
                        $i = $start + 1;
                        $out .= '/';
                        $prev_meaningful = '/';
                        continue 2;
                    }
                    $i++;
                }
                $out .= substr($js, $start, $i - $start);
                $prev_meaningful = '/';
                continue;
            }

            $out .= $c;
            if (!ctype_space($c)) {
                $prev_meaningful = $c;
            }
            $i++;
        }

        // Collapse runs of blank lines + trim trailing whitespace per line.
        $lines = explode("\n", $out);
        foreach ($lines as $k => $line) {
            $lines[$k] = rtrim($line);
        }
        $out = implode("\n", $lines);
        $out = (string) preg_replace("/\n{3,}/", "\n\n", $out);
        return trim($out) . "\n";
    }

    /**
     * Could a `/` at this position start a regex literal? True when the
     * previous meaningful (non-whitespace) char allows a prefix-position
     * expression. False when it follows an identifier / closing bracket
     * (which would make `/` division).
     */
    private static function could_start_regex(string $prev): bool
    {
        if ($prev === '') {
            return true;
        }
        // Identifier-character or closing bracket → division.
        if (ctype_alnum($prev) || $prev === '_' || $prev === '$' || $prev === ')' || $prev === ']') {
            return false;
        }
        return true;
    }
}
