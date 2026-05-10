<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

use Cinch\Plugin;

/**
 * Hooks `style_loader_src` and rewrites local CSS URLs to point at a
 * cached, minified copy under wp-content/uploads/<slug>/.
 *
 * Logic:
 *  - Skip when the URL is on an external host.
 *  - Skip when the file is already minified (`.min.css`).
 *  - Skip when the handle is on the user's skip list.
 *  - Skip when the source can't be resolved to a local filesystem path.
 *  - Otherwise: hash the source, look up `<hash>.css`, return its URL.
 *    Generate it on the spot if missing.
 */
final class StyleFilter
{
    private Cache $cache;
    /** @var array{enable_css:int, enable_js:int, bypass_admin_user:int, skip_handles:string} */
    private array $settings;
    /** @var list<string> */
    private array $skip_handles;

    /**
     * @param array{enable_css:int, enable_js:int, bypass_admin_user:int, skip_handles:string} $settings
     */
    public function __construct(Cache $cache, array $settings)
    {
        $this->cache        = $cache;
        $this->settings     = $settings;
        $this->skip_handles = Plugin::skip_handles($settings['skip_handles']);
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
        // Quick extension test before we hit the disk.
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
            $min = CssMinifier::minify($source);
            // Defensive: never write a larger file than the source. If the
            // minifier ever regresses, fall back to the source URL.
            if ($min === '' || strlen($min) > strlen($source)) {
                return $src;
            }
            if (!$this->cache->write($entry['path'], $min)) {
                return $src;
            }
        }

        // Bump session hit counter for the admin stats panel.
        $hits = (int) get_option(Plugin::HITS_OPTION, 0);
        update_option(Plugin::HITS_OPTION, $hits + 1, false);

        return $entry['url'];
    }
}
