<?php

declare(strict_types=1);

namespace Cinch\Concat;

use Cinch\Optimizer\Cache;
use Cinch\Optimizer\MinifierChain;
use Cinch\Plugin;

/**
 * Per-page CSS + JS concatenation.
 *
 * Strategy: hook `wp_print_styles` and `wp_print_footer_scripts` at
 * priority 1 — early enough to mutate WP_Styles::$queue + WP_Scripts::$queue
 * before WP emits any <link> / <script> tag. For each queue we:
 *
 *   1. Run WP's own dependency resolver via `WP_Dependencies::all_deps()`
 *      so we walk handles in the correct order (a dep must precede
 *      whatever requires it in the concat output).
 *   2. Filter to local, non-min, non-skip handles. External / minified
 *      / skipped handles stay registered and emit their own tags.
 *   3. Group by (media+conditional) for styles, (in_footer / async /
 *      defer / has-inline-data) for scripts. Each bucket compiles to a
 *      single bundle file.
 *   4. Hash = sha1(handle versions + source mtimes + source bytes
 *      length). Cheap, stable, busts whenever ANY input changes.
 *   5. Cache miss → read each source, minify each (chain), join with
 *      "\n;\n" (JS) or "\n" (CSS), append inline-script data for each
 *      JS handle that has it, write to disk via {@see Cache::write()}
 *      which also writes .br + .gz siblings.
 *   6. Deregister the bundled originals, register + enqueue a synthetic
 *      handle pointing at the bundle URL.
 *
 * Anything we can't safely bundle (external URL, .min.*, on the skip
 * list, registered with src=false placeholder, has `localized` data
 * that we couldn't migrate) gets left in the queue as-is — those
 * still flow through the per-handle {@see StyleFilter}/{@see ScriptFilter}.
 */
final class Bundler
{
    private Cache $cache;
    /** @var array<string,mixed> */
    private array $settings;
    private MinifierChain $chain;
    /** @var list<string> */
    private array $skip_handles;
    /** @var list<string> */
    private array $extra_skip;

    /** @param array<string,mixed> $settings */
    public function __construct(Cache $cache, array $settings, MinifierChain $chain)
    {
        $this->cache    = $cache;
        $this->settings = $settings;
        $this->chain    = $chain;

        $this->skip_handles = Plugin::parse_handles((string) ($settings['skip_handles'] ?? ''));
        $this->extra_skip   = Plugin::parse_handles((string) ($settings['concat_skip_handles'] ?? ''));
    }

    public function register(): void
    {
        // Styles: priority 1 on wp_print_styles, BEFORE wp_head fires
        // the default styles printer.
        add_action('wp_print_styles', [$this, 'bundle_styles'], 1);

        // Scripts: late enough that all add_inline_script() calls have
        // happened (those typically run during wp_enqueue_scripts), but
        // before WP starts emitting tags. Priority 1 on both head + footer
        // printers.
        add_action('wp_print_scripts', [$this, 'bundle_scripts'], 1);
        add_action('wp_print_footer_scripts', [$this, 'bundle_scripts'], 1);
    }

    /* =================================================================
     * STYLES
     * ===============================================================*/
    public function bundle_styles(): void
    {
        global $wp_styles;
        if (!$wp_styles instanceof \WP_Styles) {
            return;
        }
        if (empty($wp_styles->queue)) {
            return;
        }

        $ordered = $this->resolved_queue($wp_styles, $wp_styles->queue);

        /** @var array<string, list<string>> $buckets */
        $buckets = [];
        $bucket_meta = []; // bucket_key => ['media' => ..., 'conditional' => ...]
        /** @var array<string,string> $path_for_handle */
        $path_for_handle = [];

        foreach ($ordered as $handle) {
            $info = $this->classify_style($wp_styles, $handle);
            if ($info === null) {
                continue;
            }
            $path_for_handle[$handle] = $info['path'];
            $key = 'css|' . $info['media'] . '|' . ($info['conditional'] ?: '');
            $bucket_meta[$key] = ['media' => $info['media'], 'conditional' => $info['conditional']];
            $buckets[$key][] = $handle;
        }

        foreach ($buckets as $key => $handles) {
            if (count($handles) < 2) {
                continue; // No point bundling a single handle.
            }
            $meta = $bucket_meta[$key];
            $hash = $this->hash_bucket('css', $handles, $path_for_handle, $wp_styles);
            $entry = $this->cache->bundle_entry('css', $hash, 'css');

            if (!is_file($entry['path']) || @filesize($entry['path']) === 0) {
                $combined = '';
                $total_in = 0;
                foreach ($handles as $h) {
                    $source = (string) @file_get_contents($path_for_handle[$h]);
                    $total_in += strlen($source);
                    $min = $this->chain->minify($source, 'css');
                    if ($min === '' || strlen($min) > strlen($source)) {
                        $min = $source;
                    }
                    $combined .= "/*! " . $h . " */\n" . $min . "\n";
                    // Capture inline 'after' data (wp_add_inline_style).
                    $after = $wp_styles->get_data($h, 'after');
                    if (is_array($after) && $after !== []) {
                        $combined .= implode("\n", array_filter(array_map('strval', $after))) . "\n";
                    }
                }
                // Defensive: never write a bundle larger than the sum of inputs.
                if ($total_in > 0 && strlen($combined) > $total_in * 1.5) {
                    continue;
                }
                $this->cache->ensure_bundle_dir('css');
                if (!$this->cache->write($entry['path'], $combined)) {
                    continue;
                }
            }

            // Replace the bundled handles with a single synthetic one.
            // We keep the originals REGISTERED (so any other handle that
            // declared a dep on them still resolves) but blank out the src
            // and mark them done so WP_Styles::do_items() will not emit a
            // <link> tag for them.
            foreach ($handles as $h) {
                if (isset($wp_styles->registered[$h])) {
                    $wp_styles->registered[$h]->src = false;
                }
                wp_dequeue_style($h);
                $wp_styles->done[] = $h;
            }
            $wp_styles->done = array_values(array_unique($wp_styles->done));

            $synthetic = 'cinch-bundle-css-' . substr($hash, 0, 8);
            wp_register_style($synthetic, $entry['url'], [], null, $meta['media']);
            if ($meta['conditional']) {
                $wp_styles->add_data($synthetic, 'conditional', $meta['conditional']);
            }
            wp_enqueue_style($synthetic);
        }
    }

    /**
     * @return array{path:string, media:string, conditional:string}|null
     */
    private function classify_style(\WP_Styles $deps, string $handle): ?array
    {
        if (in_array($handle, $this->skip_handles, true)) {
            return null;
        }
        if (in_array($handle, $this->extra_skip, true)) {
            return null;
        }
        if (str_starts_with($handle, 'cinch-bundle-')) {
            return null;
        }
        if (!isset($deps->registered[$handle])) {
            return null;
        }
        /** @var \_WP_Dependency $reg */
        $reg = $deps->registered[$handle];
        $src = (string) $reg->src;
        if ($src === '' || $src === 'false') {
            return null;
        }
        if (preg_match('/\.min\.css(?:\?|$)/i', $src)) {
            return null;
        }
        if (!preg_match('/\.css(?:\?|$)/i', $src)) {
            // Sometimes registered without an extension (printf'd) — be safe.
            return null;
        }
        $path = $this->cache->url_to_path($src);
        if ($path === null) {
            return null;
        }
        // Conditional comments (IE-only stylesheets) must not be bundled
        // into general output.
        $cond = (string) ($deps->get_data($handle, 'conditional') ?: '');
        $media = isset($reg->args) && is_string($reg->args) && $reg->args !== '' ? $reg->args : 'all';
        return ['path' => $path, 'media' => $media, 'conditional' => $cond];
    }

    /* =================================================================
     * SCRIPTS
     * ===============================================================*/
    public function bundle_scripts(): void
    {
        global $wp_scripts;
        if (!$wp_scripts instanceof \WP_Scripts) {
            return;
        }
        if (empty($wp_scripts->queue)) {
            return;
        }

        $current_action = current_action();
        $is_footer_pass = $current_action === 'wp_print_footer_scripts';

        $ordered = $this->resolved_queue($wp_scripts, $wp_scripts->queue);

        /** @var array<string, list<string>> $buckets */
        $buckets = [];
        $bucket_meta = [];
        /** @var array<string,string> $path_for_handle */
        $path_for_handle = [];

        foreach ($ordered as $handle) {
            $info = $this->classify_script($wp_scripts, $handle, $is_footer_pass);
            if ($info === null) {
                continue;
            }
            $path_for_handle[$handle] = $info['path'];
            $key = 'js|' . ($info['in_footer'] ? 'foot' : 'head')
                 . '|' . ($info['strategy'] ?: 'block');
            $bucket_meta[$key] = $info;
            $buckets[$key][] = $handle;
        }

        foreach ($buckets as $key => $handles) {
            if (count($handles) < 2) {
                continue;
            }
            $meta = $bucket_meta[$key];
            $hash = $this->hash_bucket('js', $handles, $path_for_handle, $wp_scripts);
            $entry = $this->cache->bundle_entry('js', $hash, 'js');

            if (!is_file($entry['path']) || @filesize($entry['path']) === 0) {
                $combined = '';
                $total_in = 0;
                foreach ($handles as $h) {
                    $source = (string) @file_get_contents($path_for_handle[$h]);
                    $total_in += strlen($source);

                    // Inline 'before' data — must precede the script body.
                    $before = $wp_scripts->get_data($h, 'before');
                    if (is_array($before) && $before !== []) {
                        $combined .= implode("\n", array_filter(array_map('strval', $before))) . "\n;\n";
                    }
                    // wp_localize_script() output sits in 'data' as a JS expression.
                    $data = $wp_scripts->get_data($h, 'data');
                    if (is_string($data) && $data !== '') {
                        $combined .= $data . "\n;\n";
                    }
                    $min = $this->chain->minify($source, 'js');
                    if ($min === '' || strlen($min) > strlen($source)) {
                        $min = $source;
                    }
                    $combined .= "/*! " . $h . " */\n" . $min . "\n;\n";

                    $after = $wp_scripts->get_data($h, 'after');
                    if (is_array($after) && $after !== []) {
                        $combined .= implode("\n", array_filter(array_map('strval', $after))) . "\n;\n";
                    }
                }
                if ($total_in > 0 && strlen($combined) > $total_in * 1.5) {
                    continue;
                }
                $this->cache->ensure_bundle_dir('js');
                if (!$this->cache->write($entry['path'], $combined)) {
                    continue;
                }
            }

            // Keep originals registered so other handles' deps still resolve;
            // blank src + mark done so WP won't emit their <script> tags.
            foreach ($handles as $h) {
                if (isset($wp_scripts->registered[$h])) {
                    $wp_scripts->registered[$h]->src = false;
                    // Drop the inline data we already folded in, so WP
                    // does not emit a stray <script> for it.
                    $wp_scripts->add_data($h, 'before', []);
                    $wp_scripts->add_data($h, 'after', []);
                    $wp_scripts->add_data($h, 'data', '');
                }
                wp_dequeue_script($h);
                $wp_scripts->done[] = $h;
            }
            $wp_scripts->done = array_values(array_unique($wp_scripts->done));

            $synthetic = 'cinch-bundle-js-' . substr($hash, 0, 8);
            wp_register_script($synthetic, $entry['url'], [], null, $meta['in_footer']);
            if ($meta['strategy']) {
                $wp_scripts->add_data($synthetic, 'strategy', $meta['strategy']);
            }
            wp_enqueue_script($synthetic);
        }
    }

    /**
     * @return array{path:string, in_footer:bool, strategy:string}|null
     */
    private function classify_script(\WP_Scripts $deps, string $handle, bool $footer_pass): ?array
    {
        if (in_array($handle, $this->skip_handles, true)) {
            return null;
        }
        if (in_array($handle, $this->extra_skip, true)) {
            return null;
        }
        if (str_starts_with($handle, 'cinch-bundle-')) {
            return null;
        }
        if (!isset($deps->registered[$handle])) {
            return null;
        }
        /** @var \_WP_Dependency $reg */
        $reg = $deps->registered[$handle];
        $src = (string) $reg->src;
        if ($src === '' || $src === 'false') {
            return null;
        }
        if (preg_match('/\.min\.js(?:\?|$)/i', $src)) {
            return null;
        }
        if (!preg_match('/\.js(?:\?|$)/i', $src)) {
            return null;
        }
        $path = $this->cache->url_to_path($src);
        if ($path === null) {
            return null;
        }

        $group     = (int) ($deps->get_data($handle, 'group') ?: 0);
        $in_footer = $group > 0;
        // Run the bundler twice: head pass + footer pass. Each pass
        // bundles only handles destined for its own location, otherwise
        // we'd move a footer script up and reorder DOM-mutating code.
        if ($footer_pass && !$in_footer) {
            return null;
        }
        if (!$footer_pass && $in_footer) {
            return null;
        }

        $strategy = (string) ($deps->get_data($handle, 'strategy') ?: '');
        // Don't bundle async — async scripts must remain independent
        // because the whole point is parallel network load.
        if ($strategy === 'async') {
            return null;
        }

        return ['path' => $path, 'in_footer' => $in_footer, 'strategy' => $strategy];
    }

    /* =================================================================
     * helpers
     * ===============================================================*/

    /**
     * Walk WP_Dependencies::all_deps() and return the resolved order of
     * just the handles we asked about. all_deps() populates ::to_do as
     * a side-effect — we copy it then clear it so WP itself can still
     * compute its own pass later.
     *
     * @param list<string> $handles
     * @return list<string>
     */
    private function resolved_queue(\WP_Dependencies $deps, array $handles): array
    {
        $saved_to_do = $deps->to_do;
        $deps->to_do = [];
        // all_deps() recurses through ::registered[$h]->deps.
        $deps->all_deps($handles, true);
        $resolved = $deps->to_do;
        $deps->to_do = $saved_to_do;
        return array_values(array_unique($resolved));
    }

    /**
     * @param list<string> $handles
     * @param array<string,string> $path_for_handle
     */
    private function hash_bucket(string $type, array $handles, array $path_for_handle, \WP_Dependencies $deps): string
    {
        $parts = [$type, CINCH_VERSION];
        foreach ($handles as $h) {
            $reg = $deps->registered[$h] ?? null;
            $ver = $reg ? (string) $reg->ver : '';
            $path = $path_for_handle[$h];
            $mtime = (int) @filemtime($path);
            $size  = (int) @filesize($path);
            $parts[] = $h . '@' . $ver . ':' . $mtime . ':' . $size;
            // Inline data also influences output → fold into hash.
            $after = $deps->get_data($h, 'after');
            if (is_array($after)) {
                $parts[] = 'after:' . md5(serialize($after));
            }
            $before = $deps->get_data($h, 'before');
            if (is_array($before)) {
                $parts[] = 'before:' . md5(serialize($before));
            }
            $data = $deps->get_data($h, 'data');
            if (is_string($data)) {
                $parts[] = 'data:' . md5($data);
            }
        }
        return sha1(implode('|', $parts));
    }
}
