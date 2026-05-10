<?php

declare(strict_types=1);

namespace Cinch\Admin;

use Cinch\Optimizer\Cache;
use Cinch\Plugin;

/**
 * Cinch → top-level admin menu.
 *
 * Exposes:
 *   - CSS / JS minify toggles
 *   - Skip-handles textarea (one wp_register_script handle per line)
 *   - Bypass-for-admins toggle (so editors see un-cached source)
 *   - Cache size + file count
 *   - "Purge cache" button
 */
final class SettingsPage
{
    private const PAGE_SLUG    = 'cinch';
    private const NONCE_PURGE  = 'cinch_purge';

    private Cache $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_cinch_purge', [$this, 'handle_purge']);
    }

    public function register_settings(): void
    {
        register_setting('cinch_group', Plugin::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => Plugin::DEFAULTS,
        ]);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Cinch', 'cinch'),
            __('Cinch', 'cinch'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-performance',
            82
        );
    }

    /** @param array<string,mixed> $input */
    public function sanitize(array $input): array
    {
        return [
            'enable_css'        => empty($input['enable_css']) ? 0 : 1,
            'enable_js'         => empty($input['enable_js']) ? 0 : 1,
            'bypass_admin_user' => empty($input['bypass_admin_user']) ? 0 : 1,
            'skip_handles'      => sanitize_textarea_field((string) ($input['skip_handles'] ?? '')),
        ];
    }

    public function handle_purge(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'cinch'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_PURGE);
        $deleted = $this->cache->purge();
        update_option(Plugin::HITS_OPTION, 0, false);
        wp_safe_redirect(add_query_arg([
            'page'    => self::PAGE_SLUG,
            'purged'  => $deleted,
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings    = Plugin::settings();
        $stats       = $this->cache->stats();
        $hits        = (int) get_option(Plugin::HITS_OPTION, 0);
        $purge_nonce = wp_create_nonce(self::NONCE_PURGE);
        $purged      = isset($_GET['purged']) ? (int) $_GET['purged'] : -1;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Cinch', 'cinch'); ?></h1>
            <p class="description">
                <?php esc_html_e('Minifies and caches enqueued CSS + JS assets on disk so the browser pulls smaller files and the edge cache hits more often.', 'cinch'); ?>
            </p>

            <?php if ($purged >= 0): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                        /* translators: %d: number of cached files deleted */
                        echo esc_html(sprintf(_n('Purged %d cached file.', 'Purged %d cached files.', $purged, 'cinch'), $purged));
                    ?></p>
                </div>
            <?php endif; ?>

            <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php echo wp_kses_post(__('<strong>WP_DEBUG is on.</strong> Cinch is bypassing all asset rewriting so you can debug raw source. Set <code>WP_DEBUG</code> to <code>false</code> in <code>wp-config.php</code> to enable the cache.', 'cinch')); ?>
                    </p>
                </div>
            <?php endif; ?>

            <h2 class="title"><?php esc_html_e('Overview', 'cinch'); ?></h2>
            <table class="widefat striped" style="max-width:780px;">
                <tbody>
                    <tr>
                        <th scope="row" style="width:220px;"><?php esc_html_e('Cached files', 'cinch'); ?></th>
                        <td><code><?php echo esc_html((string) $stats['count']); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Disk usage', 'cinch'); ?></th>
                        <td><code><?php echo esc_html(size_format($stats['bytes']) ?: ($stats['bytes'] . ' B')); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Cache hits this session', 'cinch'); ?></th>
                        <td><code><?php echo esc_html((string) $hits); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Cache directory', 'cinch'); ?></th>
                        <td><code><?php echo esc_html($this->cache->dir()); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Always-bypass conditions', 'cinch'); ?></th>
                        <td>
                            <?php foreach ([
                                __('External hosts',           'cinch'),
                                __('Already-minified files',   'cinch'),
                                __('WP_DEBUG = true',           'cinch'),
                                __('wp-admin requests',         'cinch'),
                                __('REST / AJAX requests',      'cinch'),
                            ] as $label): ?>
                                <span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;background:#f0f0f1;border-radius:10px;font-size:12px;"><?php echo esc_html($label); ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                <input type="hidden" name="action" value="cinch_purge" />
                <?php wp_nonce_field(self::NONCE_PURGE); ?>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Purge cache', 'cinch'); ?></button>
                <span class="description"><?php esc_html_e('Deletes every file under the cache directory. The next page load regenerates them.', 'cinch'); ?></span>
            </form>

            <hr>
            <h2 class="title"><?php esc_html_e('Settings', 'cinch'); ?></h2>
            <form action="options.php" method="post">
                <?php settings_fields('cinch_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('CSS minification', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[enable_css]" value="1" <?php checked($settings['enable_css'], 1); ?> />
                                <?php esc_html_e('Minify and cache local CSS files', 'cinch'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('JS minification', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[enable_js]" value="1" <?php checked($settings['enable_js'], 1); ?> />
                                <?php esc_html_e('Minify and cache local JS files', 'cinch'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('JS minification is conservative — comment stripping and whitespace only. No identifier mangling.', 'cinch'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Bypass for admins', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[bypass_admin_user]" value="1" <?php checked($settings['bypass_admin_user'], 1); ?> />
                                <?php esc_html_e('Skip minification for logged-in users with manage_options', 'cinch'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Useful while debugging — editors see the raw theme source, anonymous visitors still get the cached files.', 'cinch'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cinch-skip-handles"><?php esc_html_e('Skip handles', 'cinch'); ?></label></th>
                        <td>
                            <textarea id="cinch-skip-handles" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[skip_handles]" rows="6" cols="50"><?php echo esc_textarea((string) $settings['skip_handles']); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('One wp_register_script / wp_register_style handle per line. Handles on this list are served unmodified — good for assets that are already minified or that break under regex minification.', 'cinch'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
