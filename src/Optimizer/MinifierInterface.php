<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * Strategy interface for a CSS or JS minifier.
 *
 * Each implementation announces what it can do via {@see id()} +
 * {@see available()} and is wired through {@see MinifierChain} so the
 * runtime can pick the best engine available on this host.
 */
interface MinifierInterface
{
    /**
     * Stable, lowercase identifier — used in logs + the settings page so
     * an operator can see which engine actually ran.
     */
    public function id(): string;

    /**
     * Cheap boot-time probe. MUST NOT do any heavy work — settings page
     * calls it on every render.
     */
    public function available(): bool;

    /**
     * @param 'css'|'js' $type
     */
    public function supports(string $type): bool;

    /**
     * Returns the minified output. Throws on any non-recoverable
     * failure so {@see MinifierChain} can fall through to the next
     * implementation. Returning an empty string is also treated as
     * failure by the chain.
     *
     * @param 'css'|'js' $type
     */
    public function minify(string $code, string $type): string;
}
