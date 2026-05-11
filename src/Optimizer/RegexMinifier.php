<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * The original v0.1 hand-rolled scanner — now wrapped in the strategy
 * interface and used as the always-available fallback.
 *
 * Both CSS and JS pass through here when no better engine is on this
 * host. Code is intentionally vendored from v0.1 so the existing tests
 * keep passing without modification.
 */
final class RegexMinifier implements MinifierInterface
{
    public function id(): string
    {
        return 'regex';
    }

    public function available(): bool
    {
        return true;
    }

    public function supports(string $type): bool
    {
        return $type === 'css' || $type === 'js';
    }

    public function minify(string $code, string $type): string
    {
        if ($code === '') {
            return '';
        }
        if ($type === 'css') {
            return self::minify_css($code);
        }
        if ($type === 'js') {
            return self::minify_js($code);
        }
        return $code;
    }

    /* -----------------------------------------------------------------
     * CSS — verbatim from v0.1 CssMinifier::minify.
     * ---------------------------------------------------------------*/
    public static function minify_css(string $css): string
    {
        if ($css === '') {
            return '';
        }

        $css = (string) preg_replace('#/\*(?!!)[\s\S]*?\*/#', '', $css);
        $css = str_replace(["\r\n", "\r"], "\n", $css);

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

        $css = (string) preg_replace('/\s+/', ' ', $css);
        $css = (string) preg_replace('/\s*([{};:,>~+])\s*/', '$1', $css);
        $css = (string) preg_replace('/;}/', '}', $css);
        $css = trim($css);

        if ($placeholders !== []) {
            $css = strtr($css, $placeholders);
        }
        return $css;
    }

    /* -----------------------------------------------------------------
     * JS — verbatim from v0.1 JsMinifier::minify.
     * ---------------------------------------------------------------*/
    public static function minify_js(string $js): string
    {
        if ($js === '') {
            return '';
        }

        $js = str_replace(["\r\n", "\r"], "\n", $js);

        $out             = '';
        $i               = 0;
        $len             = strlen($js);
        $prev_meaningful = '';

        while ($i < $len) {
            $c  = $js[$i];
            $c2 = $i + 1 < $len ? $js[$i + 1] : '';

            if ($c === '/' && $c2 === '/') {
                $nl = strpos($js, "\n", $i);
                if ($nl === false) {
                    break;
                }
                $i = $nl;
                continue;
            }

            if ($c === '/' && $c2 === '*') {
                $end = strpos($js, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $is_banner = $i + 2 < $len && $js[$i + 2] === '!';
                if ($is_banner) {
                    $out .= substr($js, $i, $end - $i + 2);
                }
                $i = $end + 2;
                continue;
            }

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
                        while ($i < $len && ctype_alpha($js[$i])) {
                            $i++;
                        }
                        break;
                    } elseif ($ch === "\n") {
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

        $lines = explode("\n", $out);
        foreach ($lines as $k => $line) {
            $lines[$k] = rtrim($line);
        }
        $out = implode("\n", $lines);
        $out = (string) preg_replace("/\n{3,}/", "\n\n", $out);
        return trim($out) . "\n";
    }

    private static function could_start_regex(string $prev): bool
    {
        if ($prev === '') {
            return true;
        }
        if (ctype_alnum($prev) || $prev === '_' || $prev === '$' || $prev === ')' || $prev === ']') {
            return false;
        }
        return true;
    }
}
