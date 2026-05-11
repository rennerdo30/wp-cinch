<?php

declare(strict_types=1);

namespace Cinch\Dequeue;

use Cinch\Plugin;

/**
 * Removes WordPress assets that don't need to load for anonymous
 * front-end visitors — the single biggest Lighthouse win in the v0.2
 * release plan, ahead of better minification quality.
 *
 * Hooks:
 *   - `wp_enqueue_scripts` @ 999 — runs after themes + plugins have
 *      enqueued; removes by handle.
 *   - `init` @ 1 — toggles emoji + admin bar visibility before WP wires
 *      the default emoji detection script.
 *
 * The "for anonymous" variants gate on !is_user_logged_in() so editors
 * keep working features.
 */
final class Manager
{
    /** @var array<string,mixed> */
    private array $settings;

    /** @param array<string,mixed> $settings */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        // Run early enough to win the "show admin bar" race.
        add_action('init', [$this, 'apply_admin_bar'], 1);
        add_action('init', [$this, 'apply_emoji'], 1);

        // Late enough that we run after every other enqueue hook.
        add_action('wp_enqueue_scripts', [$this, 'apply_handle_dequeues'], 999);

        // Defensive secondary pass — some plugins re-register on
        // wp_print_styles. Run at priority 1 there to win.
        add_action('wp_print_styles', [$this, 'apply_handle_dequeues'], 1);
    }

    public function apply_admin_bar(): void
    {
        if (empty($this->settings['dequeue_admin_bar_for_anonymous'])) {
            return;
        }
        if (is_user_logged_in()) {
            return;
        }
        add_filter('show_admin_bar', '__return_false', PHP_INT_MAX);
    }

    public function apply_emoji(): void
    {
        if (empty($this->settings['dequeue_emoji'])) {
            return;
        }
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('emoji_svg_url', '__return_false');
        add_filter('tiny_mce_plugins', static function ($plugins) {
            return is_array($plugins) ? array_values(array_diff($plugins, ['wpemoji'])) : $plugins;
        });
    }

    public function apply_handle_dequeues(): void
    {
        $anonymous = !is_user_logged_in();

        // Dashicons — 35 KiB of unused CSS on every anonymous request.
        if ($anonymous && !empty($this->settings['dequeue_dashicons_for_anonymous'])) {
            wp_dequeue_style('dashicons');
            wp_deregister_style('dashicons');
        }

        // Admin bar styles — pair with the show_admin_bar filter above.
        if ($anonymous && !empty($this->settings['dequeue_admin_bar_for_anonymous'])) {
            wp_dequeue_style('admin-bar');
            wp_deregister_style('admin-bar');
            wp_dequeue_script('admin-bar');
            wp_deregister_script('admin-bar');
        }

        // Gutenberg block library — only safe when the active theme is
        // classic (no block patterns rendered on the public front-end).
        if (!empty($this->settings['dequeue_block_library_for_classic_theme'])) {
            wp_dequeue_style('wp-block-library');
            wp_dequeue_style('wp-block-library-theme');
            wp_dequeue_style('global-styles');
            wp_dequeue_style('classic-theme-styles');
        }

        // Operator-supplied extra handles.
        $extra = Plugin::parse_handles((string) ($this->settings['dequeue_extra_handles'] ?? ''));
        foreach ($extra as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
    }
}
