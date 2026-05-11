<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * @deprecated since 0.2.0 — use {@see MinifierChain} instead. Kept as a
 * thin shim around {@see RegexMinifier::minify_css()} so the v0.1 test
 * harness continues to pass.
 */
final class CssMinifier
{
    public static function minify(string $css): string
    {
        return RegexMinifier::minify_css($css);
    }
}
