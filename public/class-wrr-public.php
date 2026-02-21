<?php
if (!defined('ABSPATH')) {
    exit;
}

class WRR_Public {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts() {
        if (!is_user_logged_in()) {
            // Only logged in users can spin usually
             // return; 
             // Commented out to allow JS to load, maybe show "Login to Spin"
        }

        wp_enqueue_style('wrr-style', WRR_PLUGIN_URL . 'public/css/wrr-style.css', array(), WRR_VERSION);
        wp_enqueue_script('wrr-script', WRR_PLUGIN_URL . 'public/js/wrr-script.js', array('jquery'), WRR_VERSION, true);

        $sectors = WRR_Database::get_sectors();
        
        wp_localize_script('wrr-script', 'wrr_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wrr_spin_nonce'),
            'sectors' => $sectors,
            'settings' => array(
                'confetti' => true // Feature flag
            )
        ));
    }
}
