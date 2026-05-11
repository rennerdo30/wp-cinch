<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

use Cinch\Plugin;

/**
 * Hooks `script_loader_src` and rewrites local JS URLs to a cached,
 * minified copy. Mirrors {@see StyleFilter} but defaults to a more
 * conservative skip list because mangling third-party JS is the
 * fastest way to break a site.
 */
final class ScriptFilter
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
        add_filter('script_loader_src', [$this, 'rewrite'], 99, 2);
    }

    public function rewrite(string $src, string $handle = ''): string
    {
        if ($src === '') {
            return $src;
        }
        if (in_array($handle, $this->skip_handles, true)) {
            return $src;
        }
        if (preg_match('/\.min\.js(?:\?|$)/i', $src)) {
            return $src;
        }
        if (!preg_match('/\.js(?:\?|$)/i', $src)) {
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
        $entry = $this->cache->entry_for($source_path, 'js', $source);
        if ($entry === null) {
            return $src;
        }

        if (!$this->cache->is_fresh($entry['path'])) {
            $min = $this->chain->minify($source, 'js');
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
