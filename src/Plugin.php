<?php

declare(strict_types=1);

namespace Cinch;

use Cinch\Admin\SettingsPage;
use Cinch\Optimizer\Cache;
use Cinch\Optimizer\ScriptFilter;
use Cinch\Optimizer\StyleFilter;
use Cinch\Rest\StatsRoute;

/**
 * Plugin orchestrator. Wires the CSS / JS rewriters, the admin
 * settings page, and the REST stats endpoint.
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
    ];

    public function boot(): void
    {
        load_plugin_textdomain('cinch', false, dirname(plugin_basename(CINCH_FILE)) . '/languages');

        $settings = self::settings();
        $cache    = new Cache(self::CACHE_SLUG);

        if (self::should_optimize($settings)) {
            if ($settings['enable_css']) {
                (new StyleFilter($cache, $settings))->register();
            }
            if ($settings['enable_js']) {
                (new ScriptFilter($cache, $settings))->register();
            }
        }

        if (is_admin()) {
            (new SettingsPage($cache))->register();
        }

        (new StatsRoute($cache))->register();
    }

    public static function on_activate(): void
    {
        if (false === get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, self::DEFAULTS);
        }
        // Pre-create the cache dir so first request doesn't pay the cost.
        (new Cache(self::CACHE_SLUG))->ensure_dir();
    }

    /**
     * @return array{enable_css:int, enable_js:int, bypass_admin_user:int, skip_handles:string}
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $merged = is_array($stored) ? array_merge(self::DEFAULTS, $stored) : self::DEFAULTS;

        $merged['enable_css']        = (int) (bool) $merged['enable_css'];
        $merged['enable_js']         = (int) (bool) $merged['enable_js'];
        $merged['bypass_admin_user'] = (int) (bool) $merged['bypass_admin_user'];
        $merged['skip_handles']      = (string) $merged['skip_handles'];

        return $merged;
    }

    /**
     * Per-request gate. We optimize only on the public-facing front-end,
     * for non-admin requests, when WP_DEBUG is off (devs should see raw
     * source), and when the admin-bypass toggle isn't tripped by a
     * logged-in editor.
     *
     * @param array{enable_css:int, enable_js:int, bypass_admin_user:int, skip_handles:string} $settings
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
        if ($settings['bypass_admin_user'] && is_user_logged_in() && current_user_can('manage_options')) {
            return false;
        }
        return true;
    }

    /**
     * Parse the skip-handles textarea into a list of trimmed handles.
     *
     * @return list<string>
     */
    public static function skip_handles(string $raw): array
    {
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
}
