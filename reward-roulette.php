<?php
/**
 * Plugin Name: Reward Roulette for WooCommerce
 * Plugin URI: https://example.com/reward-roulette
 * Description: A gamified loyalty system allowing users to spin a roulette wheel to win discounts, cashback, and prizes.
 * Version: 1.0.0
 * Author: Antigravity
 * Text Domain: reward-roulette
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants (guarded to avoid redeclare when plugin copied twice)
if ( ! defined('WRR_VERSION') ) {
    define('WRR_VERSION', '1.0.0');
}
if ( ! defined('WRR_PLUGIN_DIR') ) {
    define('WRR_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if ( ! defined('WRR_PLUGIN_URL') ) {
    define('WRR_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if ( ! defined('WRR_PLUGIN_BASENAME') ) {
    define('WRR_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

/**
 * Main Plugin Class
 */
if ( ! class_exists('Reward_Roulette') ) {
    class Reward_Roulette {

        private static $instance = null;

        public static function get_instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            $this->load_dependencies();
            $this->init_hooks();
        }

        private function load_dependencies() {
            require_once WRR_PLUGIN_DIR . 'includes/class-wrr-database.php';
            require_once WRR_PLUGIN_DIR . 'includes/class-wrr-game-engine.php';
            require_once WRR_PLUGIN_DIR . 'includes/class-wrr-birthday-automation.php';
            
            if (is_admin()) {
                require_once WRR_PLUGIN_DIR . 'admin/class-wrr-admin.php';
            }
            
            require_once WRR_PLUGIN_DIR . 'public/class-wrr-public.php';
        }

        private function init_hooks() {
            register_activation_hook(__FILE__, array('WRR_Database', 'create_tables'));
            add_action('plugins_loaded', array($this, 'on_plugins_loaded'));
        }

        public function on_plugins_loaded() {
            // Init classes
            if (is_admin()) {
                WRR_Admin::get_instance();
            }
            WRR_Public::get_instance();
            WRR_Birthday_Automation::get_instance();
            new WRR_Game_Engine();
            
            // Load text domain
            load_plugin_textdomain('reward-roulette', false, dirname(WRR_PLUGIN_BASENAME) . '/languages');
        }
    }
}

// Initialize the plugin
if ( class_exists('Reward_Roulette') ) {
    Reward_Roulette::get_instance();
}
