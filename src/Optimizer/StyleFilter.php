<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

use Cinch\Plugin;

/**
 * Hooks `style_loader_src` and rewrites local CSS URLs to point at a
 * cached, minified copy under wp-content/uploads/<slug>/.
 *
 * The actual minification is delegated to a {@see MinifierChain} so the
 * best available engine (esbuild → matthiasmullie → regex) runs on
 * each source file. The filter still owns the URL → path resolution,
 * the cache lookup, the size-regression guard, and the hit counter.
 *
 * When the bundler runs (priority 1 on wp_print_styles), it dequeues
 * the original handles BEFORE WP starts emitting tags, so this filter
 * never sees them — bundle output bypasses the rewriter entirely.
 */
final class StyleFilter
{
    private Cache $cache;
    /** @var array<string,mixed> */
    private array $settings;
    /** @var list<string> */
    private array $skip_handles;
    private MinifierChain $chain;

    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(Cache $cache, array $settings, MinifierChain $chain)
    {
        $this->cache        = $cache;
        $this->settings     = $settings;
        $this->skip_handles = Plugin::parse_handles((string) ($settings['skip_handles'] ?? ''));
        $this->chain        = $chain;
    }

    public function register(): void
    {
        add_filter('style_loader_src', [$this, 'rewrite'], 99, 2);
    }

    public function rewrite(string $src, string $handle = ''): string
    {
        if ($src === '') {
            return $src;
        }
        if (in_array($handle, $this->skip_handles, true)) {
            return $src;
        }
        if (preg_match('/\.min\.css(?:\?|$)/i', $src)) {
            return $src;
        }
        if (!preg_match('/\.css(?:\?|$)/i', $src)) {
            return $src;
        }

        $source_path = $this->cache->url_to_path($src);
        if ($source_path === null) {
            return $src;
        }

        $source = (string) @file_get_contents($source_path);
        if ($source === '') {
            return $src;
        }
        $entry = $this->cache->entry_for($source_path, 'css', $source);
        if ($entry === null) {
            return $src;
        }

        if (!$this->cache->is_fresh($entry['path'])) {
            $min = $this->chain->minify($source, 'css');
            // Defensive: never write a larger file than the source.
            if ($min === '' || strlen($min) > strlen($source)) {
                return $src;
            }
            if (!$this->cache->write($entry['path'], $min)) {
                return $src;
            }
        }

        $hits = (int) get_option(Plugin::HITS_OPTION, 0);
        update_option(Plugin::HITS_OPTION, $hits + 1, false);

        return $entry['url'];
    }
}
