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

        // WooCommerce registration fields integration
        if ( class_exists('WooCommerce') ) {
            add_action('woocommerce_register_form', array($this, 'render_register_fields'));
            add_action('woocommerce_register_post', array($this, 'validate_register_fields'), 10, 3);
            add_action('woocommerce_created_customer', array($this, 'save_register_fields'), 10, 1);
        }
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

    /**
     * Render additional registration fields on WooCommerce register form
     */
    public function render_register_fields() {
        // Preserve posted values
        $first = isset($_POST['wrr_first_name']) ? esc_attr($_POST['wrr_first_name']) : '';
        $last  = isset($_POST['wrr_last_name']) ? esc_attr($_POST['wrr_last_name']) : '';
        $dob   = isset($_POST['wrr_dob']) ? esc_attr($_POST['wrr_dob']) : '';

        ?>
        <p class="form-row form-row-first">
            <label for="reg_wrr_first_name"><?php esc_html_e('First name', 'reward-roulette'); ?> <span class="required">*</span></label>
            <input type="text" class="input-text" name="wrr_first_name" id="reg_wrr_first_name" value="<?php echo $first; ?>" />
        </p>

        <p class="form-row form-row-last">
            <label for="reg_wrr_last_name"><?php esc_html_e('Last name', 'reward-roulette'); ?> <span class="required">*</span></label>
            <input type="text" class="input-text" name="wrr_last_name" id="reg_wrr_last_name" value="<?php echo $last; ?>" />
        </p>

        <p class="form-row form-row-wide">
            <label for="reg_wrr_dob"><?php esc_html_e('Date of birth', 'reward-roulette'); ?></label>
            <input type="date" class="input-text" name="wrr_dob" id="reg_wrr_dob" value="<?php echo $dob; ?>" />
        </p>
        <div class="clear"></div>
        <?php
    }

    /**
     * Validate registration fields
     * @param string $username
     * @param string $email
     * @param WP_Error $validation_errors
     */
    public function validate_register_fields($username, $email, $validation_errors) {
        if ( isset($_POST['wrr_first_name']) && empty(trim($_POST['wrr_first_name'])) ) {
            $validation_errors->add('wrr_first_name_error', __('First name is required.', 'reward-roulette'));
        }

        if ( isset($_POST['wrr_last_name']) && empty(trim($_POST['wrr_last_name'])) ) {
            $validation_errors->add('wrr_last_name_error', __('Last name is required.', 'reward-roulette'));
        }

        // DOB is optional; if provided, basic sanitize/format check
        if ( isset($_POST['wrr_dob']) && !empty($_POST['wrr_dob']) ) {
            $d = sanitize_text_field($_POST['wrr_dob']);
            if ( ! strtotime($d) ) {
                $validation_errors->add('wrr_dob_error', __('Invalid date of birth.', 'reward-roulette'));
            }
        }
    }

    /**
     * Save registration fields into user meta and WooCommerce customer data
     * @param int $customer_id
     */
    public function save_register_fields($customer_id) {
        if ( isset($_POST['wrr_first_name']) ) {
            $first = sanitize_text_field($_POST['wrr_first_name']);
            wp_update_user(array('ID' => $customer_id, 'first_name' => $first));
            update_user_meta($customer_id, 'first_name', $first);
            update_user_meta($customer_id, 'billing_first_name', $first);
        }

        if ( isset($_POST['wrr_last_name']) ) {
            $last = sanitize_text_field($_POST['wrr_last_name']);
            wp_update_user(array('ID' => $customer_id, 'last_name' => $last));
            update_user_meta($customer_id, 'last_name', $last);
            update_user_meta($customer_id, 'billing_last_name', $last);
        }

        if ( isset($_POST['wrr_dob']) && ! empty($_POST['wrr_dob']) ) {
            $dob = sanitize_text_field($_POST['wrr_dob']);
            // Store plugin-specific birthday meta
            update_user_meta($customer_id, '_wrr_birthday', $dob);
            // Also store month-day cache used elsewhere
            $md = date('m-d', strtotime($dob));
            update_user_meta($customer_id, '_wrr_birthday_md', $md);
            // Optionally store in billing meta
            update_user_meta($customer_id, 'billing_birth_date', $dob);
        }
    }
}
