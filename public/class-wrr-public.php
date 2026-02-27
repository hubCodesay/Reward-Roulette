<?php
if (!defined('ABSPATH')) {
    exit;
}

class WRR_Public {
    const ACCOUNT_ENDPOINT = 'roulette-gifts';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('init', array($this, 'register_account_endpoint'));
        add_action('init', array($this, 'maybe_flush_account_endpoint'), 20);
        add_filter('query_vars', array($this, 'register_account_query_var'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
        add_action('woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', array($this, 'render_account_rewards'));

        // Registration fields integration
        // Support WooCommerce registration form if WC active
        if ( class_exists('WooCommerce') ) {
            // Attach to the main WooCommerce register form hook only (avoid duplicate rendering)
            add_action('woocommerce_register_form', array($this, 'render_register_fields'));
            add_action('woocommerce_register_post', array($this, 'validate_register_fields'), 10, 3);
            add_action('woocommerce_created_customer', array($this, 'save_register_fields'), 10, 1);
        }

        // Support WP core registration form (when site uses default register.php)
        add_action('register_form', array($this, 'render_register_fields'));
        add_filter('registration_errors', array($this, 'validate_register_fields_wp'), 10, 3);
        add_action('user_register', array($this, 'save_register_fields'));
    }

    public function register_account_endpoint() {
        if (!function_exists('add_rewrite_endpoint')) {
            return;
        }
        add_rewrite_endpoint(self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function maybe_flush_account_endpoint() {
        $flag = (string) get_option('wrr_account_endpoint_flushed', '');
        if ($flag === WRR_VERSION) {
            return;
        }
        flush_rewrite_rules(false);
        update_option('wrr_account_endpoint_flushed', WRR_VERSION);
    }

    public function register_account_query_var($vars) {
        $vars[] = self::ACCOUNT_ENDPOINT;
        return $vars;
    }

    public function add_account_menu_item($items) {
        if (!is_array($items)) {
            return $items;
        }

        $new_items = array();
        $inserted = false;
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ('dashboard' === $key) {
                $new_items[self::ACCOUNT_ENDPOINT] = __('Подарунки від рулетки', 'reward-roulette');
                $inserted = true;
            }
        }
        if (!$inserted) {
            $new_items[self::ACCOUNT_ENDPOINT] = __('Подарунки від рулетки', 'reward-roulette');
        }

        return $new_items;
    }

    public function render_account_rewards() {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('Увійдіть, щоб переглянути подарунки.', 'reward-roulette') . '</p>';
            return;
        }

        $user_id = get_current_user_id();
        $rewards = WRR_Database::get_user_rewards($user_id, 200);

        echo '<h3>' . esc_html__('Подарунки від плагіну', 'reward-roulette') . '</h3>';
        if (empty($rewards)) {
            echo '<p>' . esc_html__('Подарунків поки немає.', 'reward-roulette') . '</p>';
            return;
        }

        echo '<table class="shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Дата', 'reward-roulette') . '</th>';
        echo '<th>' . esc_html__('Подарунок', 'reward-roulette') . '</th>';
        echo '<th>' . esc_html__('Код', 'reward-roulette') . '</th>';
        echo '<th>' . esc_html__('Діє до', 'reward-roulette') . '</th>';
        echo '<th>' . esc_html__('Статус', 'reward-roulette') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rewards as $reward) {
            $status = (string) $reward->status;
            $expires_at = (string) $reward->expires_at;

            // Derive current status for Woo coupons.
            if (!empty($reward->coupon_id)) {
                $coupon = class_exists('WC_Coupon') ? new WC_Coupon($reward->coupon_id) : null;
                if ($coupon) {
                    $usage_limit = (int) $coupon->get_usage_limit();
                    $usage_count = (int) $coupon->get_usage_count();
                    if ($usage_limit > 0 && $usage_count >= $usage_limit) {
                        $status = 'used';
                    }
                }
            }
            if (!empty($expires_at) && strtotime($expires_at) < time() && 'used' !== $status) {
                $status = 'expired';
            }

            echo '<tr>';
            echo '<td>' . esc_html($reward->created_at) . '</td>';
            echo '<td>' . esc_html(trim($reward->reward_name . ' ' . $reward->reward_value)) . '</td>';
            echo '<td>' . esc_html($reward->coupon_code ? $reward->coupon_code : '—') . '</td>';
            echo '<td>' . esc_html($expires_at ? $expires_at : '—') . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function enqueue_scripts() {
        if (!is_user_logged_in()) {
            // Only logged in users can spin usually
             // return; 
             // Commented out to allow JS to load, maybe show "Login to Spin"
        }

        wp_enqueue_style('wrr-style', WRR_PLUGIN_URL . 'public/css/wrr-style.css', array(), WRR_VERSION);
        wp_enqueue_script('wrr-script', WRR_PLUGIN_URL . 'public/js/wrr-script.js', array('jquery'), WRR_VERSION, true);
        // Fallback script to inject registration fields if theme overrides server-side hooks
        wp_enqueue_script('wrr-register-fallback', WRR_PLUGIN_URL . 'public/js/wrr-register-fallback.js', array('jquery'), WRR_VERSION, true);

        $sectors = WRR_Database::get_sectors();
        
        wp_localize_script('wrr-script', 'wrr_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wrr_spin_nonce'),
            'sectors' => $sectors,
            'settings' => array(
                'confetti' => true // Feature flag
            )
        ));
        // Pass registration field visibility and labels to fallback script
        $reg_fields = get_option('wrr_registration_fields', array('first_name'=>1,'last_name'=>1,'date_of_birth'=>0));
        $reg_labels = get_option('wrr_registration_labels', array('first_name' => 'First name', 'last_name' => 'Last name', 'date_of_birth' => 'Date of birth'));
        wp_localize_script('wrr-register-fallback', 'wrr_register_settings', array('fields' => $reg_fields, 'labels' => $reg_labels));
    }

    /**
     * Render additional registration fields on WooCommerce register form
     */
    public function render_register_fields() {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('WRR: render_register_fields fired on ' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown'));
        }

        // Prevent duplicate rendering within same request
        static $wrr_rendered = false;
        if ( $wrr_rendered ) {
            return;
        }
        $wrr_rendered = true;
        // Check settings which fields to show and labels
        $reg_fields = get_option('wrr_registration_fields', array('first_name'=>1,'last_name'=>1,'date_of_birth'=>0));
        $reg_labels = get_option('wrr_registration_labels', array('first_name' => 'First name', 'last_name' => 'Last name', 'date_of_birth' => 'Date of birth'));

        // Preserve posted values
        $first = isset($_POST['wrr_first_name']) ? esc_attr($_POST['wrr_first_name']) : '';
        $last  = isset($_POST['wrr_last_name']) ? esc_attr($_POST['wrr_last_name']) : '';
        $dob   = isset($_POST['wrr_dob']) ? esc_attr($_POST['wrr_dob']) : '';

        ?>
        <?php if (!empty($reg_fields['first_name'])): ?>
        <p class="form-row form-row-first">
            <label for="reg_wrr_first_name"><?php echo esc_html($reg_labels['first_name']); ?> <span class="required">*</span></label>
            <input type="text" class="input-text" name="wrr_first_name" id="reg_wrr_first_name" value="<?php echo $first; ?>" />
        </p>
        <?php endif; ?>

        <?php if (!empty($reg_fields['last_name'])): ?>
        <p class="form-row form-row-last">
            <label for="reg_wrr_last_name"><?php echo esc_html($reg_labels['last_name']); ?> <span class="required">*</span></label>
            <input type="text" class="input-text" name="wrr_last_name" id="reg_wrr_last_name" value="<?php echo $last; ?>" />
        </p>
        <?php endif; ?>

        <?php if (!empty($reg_fields['date_of_birth'])): ?>
        <p class="form-row form-row-wide">
            <label for="reg_wrr_dob"><?php echo esc_html($reg_labels['date_of_birth']); ?></label>
            <input type="date" class="input-text" name="wrr_dob" id="reg_wrr_dob" value="<?php echo $dob; ?>" />
        </p>
        <?php endif; ?>
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
     * Validate for WP core registration_errors filter
     */
    public function validate_register_fields_wp($errors, $sanitized_user_login, $user_email) {
        if ( isset($_POST['wrr_first_name']) && empty(trim($_POST['wrr_first_name'])) ) {
            $errors->add('wrr_first_name_error', __('First name is required.', 'reward-roulette'));
        }

        if ( isset($_POST['wrr_last_name']) && empty(trim($_POST['wrr_last_name'])) ) {
            $errors->add('wrr_last_name_error', __('Last name is required.', 'reward-roulette'));
        }

        if ( isset($_POST['wrr_dob']) && !empty($_POST['wrr_dob']) ) {
            $d = sanitize_text_field($_POST['wrr_dob']);
            if ( ! strtotime($d) ) {
                $errors->add('wrr_dob_error', __('Invalid date of birth.', 'reward-roulette'));
            }
        }

        return $errors;
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
