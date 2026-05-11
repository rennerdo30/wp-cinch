<?php
/**
 * Plugin Name: Cinch
 * Plugin URI: https://github.com/rennerdo30/wp-cinch
 * Description: Dequeues unused assets, concatenates per-page CSS + JS, minifies through the best engine on the host (esbuild then matthiasmullie then regex), and writes brotli + gzip siblings for the edge. MIT.
 * Version: 0.2.0
 * Author: renner.dev
 * Author URI: https://renner.dev
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: cinch
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('CINCH_VERSION', '0.2.0');
define('CINCH_FILE', __FILE__);
define('CINCH_DIR', plugin_dir_path(__FILE__));
define('CINCH_URL', plugin_dir_url(__FILE__));

// Lightweight PSR-4 autoloader for the Cinch namespace.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Cinch\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = CINCH_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Composer autoload — included up front so matthiasmullie is reachable
// before plugins_loaded fires. No-op when composer install has not run.
$cinch_vendor = __DIR__ . '/vendor/autoload.php';
if (is_file($cinch_vendor)) {
    require_once $cinch_vendor;
}
unset($cinch_vendor);

add_action('plugins_loaded', static function (): void {
    (new \Cinch\Plugin())->boot();
});

register_activation_hook(__FILE__, static function (): void {
    \Cinch\Plugin::on_activate();
});
