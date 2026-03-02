<?php
if (!defined('ABSPATH')) exit;

require_once CMA_PATH . 'includes/class-cma-admin.php';
require_once CMA_PATH . 'includes/class-cma-scanner.php';
require_once CMA_PATH . 'includes/class-cma-analyzer.php';

final class CMA_Plugin {
    private static ?CMA_Plugin $instance = null;

    public static function instance(): CMA_Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        new CMA_Admin();
    }
}