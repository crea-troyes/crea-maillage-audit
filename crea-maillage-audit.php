<?php
/**
 * Plugin Name: Créa – Internal Linking Audit
 * Description: Internal linking audit (orphans, link table, graph). 100% admin, manual scan.
 * Version: 1.0.0
 * Author: GUILLIER Alban
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: crea-maillage-audit
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('CMA_VERSION', '1.0.0');
define('CMA_PATH', plugin_dir_path(__FILE__));
define('CMA_URL', plugin_dir_url(__FILE__));
define('CMA_OPTION_KEY', 'cma_scan_data');

/**
 * Load plugin textdomain
 */
function cma_load_textdomain() {
    load_plugin_textdomain(
        'crea-maillage-audit',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('plugins_loaded', 'cma_load_textdomain');

require_once CMA_PATH . 'includes/class-cma-plugin.php';

add_action('plugins_loaded', static function () {

    if (!is_admin()) return;

    CMA_Plugin::instance();

});