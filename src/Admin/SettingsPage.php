<?php

declare(strict_types=1);

namespace Cinch\Admin;

use Cinch\Optimizer\Cache;
use Cinch\Optimizer\EsbuildMinifier;
use Cinch\Optimizer\MinifierChain;
use Cinch\Plugin;

/**
 * Cinch → top-level admin menu.
 *
 * v0.2 surfaces:
 *   - active minifier strategy per type
 *   - esbuild binary path + version when detected
 *   - dequeue toggles (dashicons, admin bar, emoji, block library)
 *   - concat toggle + per-bundle skip list
 *   - .br / .gz support status
 *   - bundle file count
 */
final class SettingsPage
{
    private const PAGE_SLUG    = 'cinch';
    private const NONCE_PURGE  = 'cinch_purge';

    private Cache $cache;
    private MinifierChain $chain;

    public function __construct(Cache $cache, MinifierChain $chain)
    {
        $this->cache = $cache;
        $this->chain = $chain;
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
        $bool = static fn ($v): int => empty($v) ? 0 : 1;
        $str  = static fn ($v): string => sanitize_textarea_field((string) ($v ?? ''));
        return [
            'enable_css'        => $bool($input['enable_css'] ?? 0),
            'enable_js'         => $bool($input['enable_js'] ?? 0),
            'bypass_admin_user' => $bool($input['bypass_admin_user'] ?? 0),
            'skip_handles'      => $str($input['skip_handles'] ?? ''),

            'dequeue_dashicons_for_anonymous'        => $bool($input['dequeue_dashicons_for_anonymous'] ?? 0),
            'dequeue_admin_bar_for_anonymous'        => $bool($input['dequeue_admin_bar_for_anonymous'] ?? 0),
            'dequeue_block_library_for_classic_theme' => $bool($input['dequeue_block_library_for_classic_theme'] ?? 0),
            'dequeue_emoji'                           => $bool($input['dequeue_emoji'] ?? 0),
            'dequeue_extra_handles'                   => $str($input['dequeue_extra_handles'] ?? ''),

            'concat_enabled'      => $bool($input['concat_enabled'] ?? 0),
            'concat_skip_handles' => $str($input['concat_skip_handles'] ?? ''),
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

        $css_strategy = $this->chain->active_strategy('css');
        $js_strategy  = $this->chain->active_strategy('js');
        $esbuild      = null;
        foreach ($this->chain->strategies() as $s) {
            if ($s instanceof EsbuildMinifier) {
                $esbuild = $s;
                break;
            }
        }
        $esbuild_bin = $esbuild ? $esbuild->bin_path() : null;
        $esbuild_ver = $esbuild ? $esbuild->version() : '';
        $precomp     = $this->cache->precompression_support();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Cinch', 'cinch'); ?></h1>
            <p class="description">
                <?php esc_html_e('Dequeues unused assets, concatenates per-page CSS + JS, minifies through the best engine on this host, and writes brotli + gzip siblings for the edge.', 'cinch'); ?>
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
                        <?php echo wp_kses_post(__('<strong>WP_DEBUG is on.</strong> Cinch is bypassing minification + concat so you can debug raw source. Dequeue still runs.', 'cinch')); ?>
                    </p>
                </div>
            <?php endif; ?>

            <h2 class="title"><?php esc_html_e('Overview', 'cinch'); ?></h2>
            <table class="widefat striped" style="max-width:920px;">
                <tbody>
                    <tr>
                        <th scope="row" style="width:240px;"><?php esc_html_e('Active CSS minifier', 'cinch'); ?></th>
                        <td><code><?php echo esc_html($css_strategy); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Active JS minifier', 'cinch'); ?></th>
                        <td><code><?php echo esc_html($js_strategy); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('esbuild binary', 'cinch'); ?></th>
                        <td>
                            <?php if ($esbuild_bin): ?>
                                <code><?php echo esc_html($esbuild_bin); ?></code>
                                <?php if ($esbuild_ver !== ''): ?>
                                    <span class="description">(<?php echo esc_html($esbuild_ver); ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <em><?php esc_html_e('not detected — set CINCH_ESBUILD_BIN or drop a binary in wp-content/cinch/bin/esbuild', 'cinch'); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('matthiasmullie/minify', 'cinch'); ?></th>
                        <td><code><?php echo class_exists(\MatthiasMullie\Minify\CSS::class) ? 'installed' : 'not installed'; ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Pre-compression', 'cinch'); ?></th>
                        <td>
                            <code>brotli: <?php echo $precomp['br'] ? 'on' : 'off'; ?></code>
                            <code style="margin-left:8px;">gzip: <?php echo $precomp['gz'] ? 'on' : 'off'; ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Cached files', 'cinch'); ?></th>
                        <td><code><?php echo esc_html((string) $stats['count']); ?></code>
                            <span class="description">
                                (<?php echo esc_html(sprintf(__('%d bundle, %d precompressed', 'cinch'), $stats['bundles'], $stats['precompressed'])); ?>)
                            </span>
                        </td>
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
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                <input type="hidden" name="action" value="cinch_purge" />
                <?php wp_nonce_field(self::NONCE_PURGE); ?>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Purge cache', 'cinch'); ?></button>
                <span class="description"><?php esc_html_e('Deletes every file under the cache directory (preserves .htaccess). The next page load regenerates them.', 'cinch'); ?></span>
            </form>

            <hr>
            <h2 class="title"><?php esc_html_e('Minification', 'cinch'); ?></h2>
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
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Bypass for admins', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[bypass_admin_user]" value="1" <?php checked($settings['bypass_admin_user'], 1); ?> />
                                <?php esc_html_e('Skip minification + concat for logged-in users with manage_options', 'cinch'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cinch-skip-handles"><?php esc_html_e('Skip handles (minify)', 'cinch'); ?></label></th>
                        <td>
                            <textarea id="cinch-skip-handles" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[skip_handles]" rows="4" cols="50"><?php echo esc_textarea((string) $settings['skip_handles']); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('One handle per line. Handles on this list are served unmodified — escape hatch for assets that break under minification.', 'cinch'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2 class="title"><?php esc_html_e('Concatenation', 'cinch'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Per-page concat', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[concat_enabled]" value="1" <?php checked($settings['concat_enabled'], 1); ?> />
                                <?php esc_html_e('Bundle local CSS + JS into one file per bucket (per-media for CSS, per-location for JS)', 'cinch'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Async + minified-source + external + skipped handles bypass the bundler.', 'cinch'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cinch-concat-skip"><?php esc_html_e('Skip handles (concat)', 'cinch'); ?></label></th>
                        <td>
                            <textarea id="cinch-concat-skip" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[concat_skip_handles]" rows="4" cols="50"><?php echo esc_textarea((string) $settings['concat_skip_handles']); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Handles to keep as separate tags (e.g. plugins that depend on a specific source URL).', 'cinch'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2 class="title"><?php esc_html_e('Dequeue', 'cinch'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Dashicons', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[dequeue_dashicons_for_anonymous]" value="1" <?php checked($settings['dequeue_dashicons_for_anonymous'], 1); ?> />
                                <?php esc_html_e('Dequeue dashicons for anonymous visitors', 'cinch'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('WordPress front-end rarely needs the admin icon font. ~35 KiB savings on a fresh install.', 'cinch'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Admin bar', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[dequeue_admin_bar_for_anonymous]" value="1" <?php checked($settings['dequeue_admin_bar_for_anonymous'], 1); ?> />
                                <?php esc_html_e('Hide admin bar for anonymous visitors', 'cinch'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Emoji', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[dequeue_emoji]" value="1" <?php checked($settings['dequeue_emoji'], 1); ?> />
                                <?php esc_html_e('Remove the WP emoji detection script + stylesheet', 'cinch'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Block library', 'cinch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[dequeue_block_library_for_classic_theme]" value="1" <?php checked($settings['dequeue_block_library_for_classic_theme'], 1); ?> />
                                <?php esc_html_e('Dequeue Gutenberg block library CSS (classic themes only)', 'cinch'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Off by default — breaks block patterns rendered on the public front-end.', 'cinch'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cinch-dequeue-extra"><?php esc_html_e('Extra handles to dequeue', 'cinch'); ?></label></th>
                        <td>
                            <textarea id="cinch-dequeue-extra" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[dequeue_extra_handles]" rows="4" cols="50"><?php echo esc_textarea((string) $settings['dequeue_extra_handles']); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Comma- or newline-separated handle names. Useful when a specific plugin enqueues something you do not actually use.', 'cinch'); ?>
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
