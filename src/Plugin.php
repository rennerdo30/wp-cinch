<?php

declare(strict_types=1);

namespace Cinch;

use Cinch\Admin\SettingsPage;
use Cinch\Concat\Bundler;
use Cinch\Dequeue\Manager as DequeueManager;
use Cinch\Optimizer\Cache;
use Cinch\Optimizer\MinifierChain;
use Cinch\Optimizer\ScriptFilter;
use Cinch\Optimizer\StyleFilter;
use Cinch\Rest\StatsRoute;

/**
 * Plugin orchestrator. Wires the dequeue manager, bundler, CSS / JS
 * rewriters, the admin settings page, and the REST stats endpoint.
 */
final class Plugin
{
    public const OPTION_KEY  = 'cinch_settings';
    public const CACHE_SLUG  = 'cinch';
    public const HITS_OPTION = 'cinch_session_hits';

    public const DEFAULTS = [
        'enable_css'        => 1,
        'enable_js'         => 1,
        'bypass_admin_user' => 0,
        'skip_handles'      => "jquery-core\njquery-migrate",

        // 0.2 — dequeue layer
        'dequeue_dashicons_for_anonymous'        => 1,
        'dequeue_admin_bar_for_anonymous'        => 1,
        'dequeue_block_library_for_classic_theme' => 0,
        'dequeue_emoji'                           => 1,
        'dequeue_extra_handles'                   => '',

        // 0.2 — concatenation
        'concat_enabled'     => 1,
        'concat_skip_handles' => '',
    ];

    private static ?MinifierChain $chain = null;

    public function boot(): void
    {
        load_plugin_textdomain('cinch', false, dirname(plugin_basename(CINCH_FILE)) . '/languages');

        // Composer autoloader (matthiasmullie/minify, if installed).
        $vendor = CINCH_DIR . 'vendor/autoload.php';
        if (is_file($vendor)) {
            require_once $vendor;
        }

        $settings = self::settings();
        $cache    = new Cache(self::CACHE_SLUG);
        $chain    = self::minifier_chain();

        // Dequeue runs on EVERY front-end request (including logged-in
        // editors) so the demo banner / Lighthouse score reflects
        // production behavior. The toggles internally gate on
        // is_user_logged_in() for the "anonymous only" variants.
        if (!is_admin()) {
            (new DequeueManager($settings))->register();
        }

        if (self::should_optimize($settings)) {
            if ($settings['enable_css']) {
                (new StyleFilter($cache, $settings, $chain))->register();
            }
            if ($settings['enable_js']) {
                (new ScriptFilter($cache, $settings, $chain))->register();
            }
            if ($settings['concat_enabled']) {
                (new Bundler($cache, $settings, $chain))->register();
            }
        }

        if (is_admin()) {
            (new SettingsPage($cache, $chain))->register();
        }

        (new StatsRoute($cache))->register();
    }

    public static function on_activate(): void
    {
        if (false === get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, self::DEFAULTS);
        }
        $cache = new Cache(self::CACHE_SLUG);
        $cache->ensure_dir();
        $cache->ensure_htaccess();
    }

    public static function minifier_chain(): MinifierChain
    {
        if (self::$chain === null) {
            self::$chain = MinifierChain::build_default();
        }
        return self::$chain;
    }

    /**
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $merged = is_array($stored) ? array_merge(self::DEFAULTS, $stored) : self::DEFAULTS;

        // Cast everything to known types so callers don't need to guess.
        foreach ([
            'enable_css',
            'enable_js',
            'bypass_admin_user',
            'dequeue_dashicons_for_anonymous',
            'dequeue_admin_bar_for_anonymous',
            'dequeue_block_library_for_classic_theme',
            'dequeue_emoji',
            'concat_enabled',
        ] as $bool_key) {
            $merged[$bool_key] = (int) (bool) ($merged[$bool_key] ?? 0);
        }
        foreach (['skip_handles', 'dequeue_extra_handles', 'concat_skip_handles'] as $str_key) {
            $merged[$str_key] = (string) ($merged[$str_key] ?? '');
        }

        return $merged;
    }

    /**
     * Per-request gate. We optimize only on the public-facing front-end,
     * for non-admin requests, when WP_DEBUG is off (devs should see raw
     * source), and when the admin-bypass toggle isn't tripped by a
     * logged-in editor.
     *
     * @param array<string,mixed> $settings
     */
    public static function should_optimize(array $settings): bool
    {
        if (is_admin()) {
            return false;
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return false;
        }
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (!empty($settings['bypass_admin_user']) && is_user_logged_in() && current_user_can('manage_options')) {
            return false;
        }
        return true;
    }

    /**
     * Parse a textarea / comma-separated handle list into trimmed
     * entries. Comments (`# ...`) are stripped.
     *
     * @return list<string>
     */
    public static function parse_handles(string $raw): array
    {
        $raw   = str_replace(',', "\n", $raw);
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out   = [];
        foreach ($lines as $line) {
            $h = trim($line);
            if ($h !== '' && $h[0] !== '#') {
                $out[] = $h;
            }
        }
        return $out;
    }

    /**
     * @deprecated since 0.2.0 — use {@see parse_handles()}. Retained
     * because the old name is the more readable one for v0.1 settings.
     *
     * @return list<string>
     */
    public static function skip_handles(string $raw): array
    {
        return self::parse_handles($raw);
    }
}
