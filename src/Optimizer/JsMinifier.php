<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * @deprecated since 0.2.0 — use {@see MinifierChain} instead. Kept as a
 * thin shim around {@see RegexMinifier::minify_js()} so the v0.1 test
 * harness continues to pass.
 */
final class JsMinifier
{
    public static function minify(string $js): string
    {
        return RegexMinifier::minify_js($js);
    }
}
