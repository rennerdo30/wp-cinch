<?php

declare(strict_types=1);

namespace Cinch\Rest;

use Cinch\Optimizer\Cache;
use Cinch\Plugin;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/cinch/v1/stats → cache snapshot. Admin-only.
 */
final class StatsRoute
{
    private Cache $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('cinch/v1', '/stats', [
            'methods'             => 'GET',
            'permission_callback' => static function (): bool {
                return current_user_can('manage_options');
            },
            'callback' => [$this, 'handle'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $stats   = $this->cache->stats();
        $chain   = Plugin::minifier_chain();
        $precomp = $this->cache->precompression_support();
        return new WP_REST_Response([
            'cached_files'   => $stats['count'],
            'total_bytes'    => $stats['bytes'],
            'bundles'        => $stats['bundles'],
            'precompressed'  => $stats['precompressed'],
            'hits_session'   => (int) get_option(Plugin::HITS_OPTION, 0),
            'minifier_css'   => $chain->active_strategy('css'),
            'minifier_js'    => $chain->active_strategy('js'),
            'brotli_support' => $precomp['br'],
            'gzip_support'   => $precomp['gz'],
        ], 200);
    }
}
