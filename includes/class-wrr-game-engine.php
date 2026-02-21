<?php
if (!defined('ABSPATH')) {
    exit;
}

class WRR_Game_Engine {

    public function __construct() {
        add_action('wp_ajax_wrr_spin', array($this, 'handle_spin'));
        add_action('wp_ajax_nopriv_wrr_spin', array($this, 'handle_spin'));
        
        // Auto-show logic (hook into footer)
        add_action('wp_footer', array($this, 'render_popup_markup'));
        add_shortcode('wrr_button', array($this, 'render_shortcode_button'));
    }

    /**
     * Render Trigger Button Shortcode
     */
    public function render_shortcode_button($atts) {
        if (!is_user_logged_in()) {
            return '<p><a href="' . wp_login_url(get_permalink()) . '">Login to Play</a></p>';
        }
        return '<button class="wrr-btn-primary wrr-trigger-btn">🎰 Play Roulette</button>';
    }

    // ... handle_spin ... (no change needed there yet)

    /**
     * Render the frontend popup
     */
    public function render_popup_markup() {
        $is_test = isset($_GET['wrr_test']);
        $is_birthday = false;
        
        if (is_user_logged_in()) {
            $eligible_date = get_user_meta(get_current_user_id(), '_wrr_birthday_eligible_date', true);
            if ($eligible_date === date('Y-m-d')) {
                $is_birthday = true;
            }
        }
        
        // ONLY show markup if testing OR if it's the user's specific birthday reward day
        if (!$is_test && !$is_birthday) {
            return;
        }

        include WRR_PLUGIN_DIR . 'templates/popup.php';
        
        // Auto-open logic for test mode OR active birthday spin
        if ($is_test || $is_birthday) {
            echo '<script>
            jQuery(document).ready(function($){ 
                setTimeout(function(){ $("#wrr-overlay").addClass("wrr-open"); }, 1000); 
            });
            </script>';
        }
    }
    public function handle_spin() {
        check_ajax_referer('wrr_spin_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please login to spin.'));
        }
        
        $user_id = get_current_user_id();

        // Check Targeting Rules
        if (!$this->check_user_eligibility($user_id)) {
            wp_send_json_error(array('message' => 'You are not eligible to play yet.'));
        }
        
        // 1. Check if user already reached global limit (optional setting)
        // For MVP, we skip global limit, but check daily limit if needed.
        
        // 2. Get available sectors
        $sectors = WRR_Database::get_sectors();
        if (empty($sectors)) {
            wp_send_json_error(array('message' => 'No rewards available.'));
        }
        
        // 3. Filter sectors based on User Limits (max wins per reward)
        $valid_sectors = array();
        foreach ($sectors as $sector) {
            if ($sector->max_wins_per_user > 0) {
                $wins = WRR_Database::count_user_wins($user_id, $sector->id);
                if ($wins >= $sector->max_wins_per_user) {
                    continue; // Skip this sector, limit reached
                }
            }
            $valid_sectors[] = $sector;
        }
        
        if (empty($valid_sectors)) {
            // All sectors exhausted? Fallback to 'no_win' if exists, or error
            wp_send_json_error(array('message' => 'You have used all your luck!'));
        }

        // 4. Calculate Winner (Weighted Random)
        $winner = $this->get_weighted_winner($valid_sectors);
        
        // 5. Apply Reward
        $reward_data = $this->apply_reward($user_id, $winner);
        
        // 6. Log Result
        WRR_Database::log_spin(array(
            'user_id' => $user_id,
            'sector_id' => $winner->id,
            'reward_type' => $winner->type,
            'reward_value' => $winner->value,
            'ip_address' => $_SERVER['REMOTE_ADDR']
        ));

        // 6.1 Clear birthday eligibility if this was the spin
        $eligible_date = get_user_meta($user_id, '_wrr_birthday_eligible_date', true);
        if ($eligible_date === date('Y-m-d')) {
            delete_user_meta($user_id, '_wrr_birthday_eligible_date');
        }
        
        // 7. Return Result
        wp_send_json_success(array(
            'sector_id' => $winner->id, // Frontend will stop here
            'message' => $reward_data['message'],
            'reward' => $winner
        ));
    }

    /**
     * weighted random selection
     */
    private function get_weighted_winner($sectors) {
        $total_weight = 0;
        foreach ($sectors as $sector) {
            $total_weight += intval($sector->probability);
        }
        
        $rand = mt_rand(1, $total_weight);
        $current = 0;
        
        foreach ($sectors as $sector) {
            $current += intval($sector->probability);
            if ($rand <= $current) {
                return $sector;
            }
        }
        
        return $sectors[0]; // Fallback
    }

    /**
     * Apply the reward logic
     */
    private function apply_reward($user_id, $sector) {
        $message = "You won " . $sector->name;
        
        switch ($sector->type) {
            case 'coupon':
                // Create dynamic coupon
                if (class_exists('WooCommerce')) {
                    $coupon_code = 'SPIN-' . strtoupper(wp_generate_password(6, false));
                    $amount = floatval($sector->value);
                    
                    $coupon = new WC_Coupon();
                    $coupon->set_code($coupon_code);
                    $coupon->set_amount($amount);
                    $coupon->set_discount_type('percent'); // Default to percent
                    $coupon->set_description('Won via Reward Roulette');
                    $coupon->set_usage_limit(1);
                    $coupon->set_email_restrictions(array(get_userdata($user_id)->user_email));
                    $coupon->save();
                    
                    $message = "You won a {$amount}% coupon: {$coupon_code}";
                    
                    // Email Notification
                    $email_subject = "Вітаємо! Ваш купон на знижку {$amount}%";
                    $email_body = "<p>Вітаємо!</p>";
                    $email_body .= "<p>Ви виграли купон на знижку <strong>{$amount}%</strong> в нашому колесі фортуни.</p>";
                    $email_body .= "<p>Ваш промокод: <code style='font-size: 1.2em; border: 1px dashed #ccc; padding: 5px;'>{$coupon_code}</code></p>";
                    $email_body .= "<p>Використайте його при оформленні замовлення.</p>";
                    
                    $this->send_win_email($user_id, $email_subject, $email_body);
                }
                break;
                
            case 'shipping':
                // Create Free Shipping Coupon
                if (class_exists('WooCommerce')) {
                    $coupon_code = 'SHIP-' . strtoupper(wp_generate_password(6, false));
                    
                    $coupon = new WC_Coupon();
                    $coupon->set_code($coupon_code);
                    $coupon->set_discount_type('fixed_cart'); // Type required, but zero amount
                    $coupon->set_amount(0);
                    $coupon->set_free_shipping(true); // <--- Enable Free Shipping
                    $coupon->set_description('Free Shipping won via Reward Roulette');
                    $coupon->set_usage_limit(1);
                    $coupon->set_individual_use(true); // Prevent stacking with other coupons? Optional.
                    $coupon->set_email_restrictions(array(get_userdata($user_id)->user_email));
                    $coupon->save();
                    
                    $message = "You won Free Shipping! Code: {$coupon_code}";
                    
                    // Email Notification
                    $email_subject = "Вітаємо! Ви виграли Безкоштовну Доставку";
                    $email_body = "<p>Вітаємо!</p>";
                    $email_body .= "<p>Ви виграли <strong>Безкоштовну Доставку</strong> в нашому колесі фортуни.</p>";
                    $email_body .= "<p>Ваш промокод: <code style='font-size: 1.2em; border: 1px dashed #ccc; padding: 5px;'>{$coupon_code}</code></p>";
                    $email_body .= "<p>Використайте його при оформленні наступного замовлення.</p>";
                    
                    $this->send_win_email($user_id, $email_subject, $email_body);
                }
                break;
                
            case 'cashback':
                // Integration with existing Cashback System (white-label compat)
                if (class_exists('WCS_Cashback_Database')) {
                    $amount = floatval($sector->value);
                    WCS_Cashback_Database::update_balance($user_id, $amount, 'earned');
                    
                    // Log adjustment as transaction
                    WCS_Cashback_Database::add_transaction(array(
                        'user_id' => $user_id,
                        'order_id' => 0,
                        'transaction_type' => 'earned',
                        'amount' => $amount,
                        'balance_after' => 0, // Simplified, DB class handles recalc usually
                        'description' => 'Won in Reward Roulette'
                    ));
                    
                    $message = "You won {$amount} Cashback!";
                }
                break;
                
            case 'no_win':
                $message = "Better luck next time!";
                break;
        }
        
        return array('message' => $message);
    }

    /**
     * Check if user meets targeting criteria
     */
    private function check_user_eligibility($user_id) {
        // --- BIRTHDAY OVERRIDE ---
        $eligible_date = get_user_meta($user_id, '_wrr_birthday_eligible_date', true);
        if ($eligible_date === date('Y-m-d')) {
            return true; // Birthday priority!
        }

        $settings = get_option('wrr_targeting_settings', array());
        
        // 1. Roles
        if (!empty($settings['allowed_roles'])) {
            $user = get_userdata($user_id);
            $user_roles = $user->roles;
            $intersect = array_intersect($settings['allowed_roles'], $user_roles);
            if (empty($intersect)) return false;
        }
        
        // 2. Spent & Orders (WooCommerce required)
        if (class_exists('WooCommerce')) {
             if (!empty($settings['min_spent'])) {
                  $spent = wc_get_customer_total_spent($user_id);
                  if ($spent < floatval($settings['min_spent'])) return false;
             }
             
             if (!empty($settings['min_orders'])) {
                  $order_count = wc_get_customer_order_count($user_id);
                  if ($order_count < intval($settings['min_orders'])) return false;
             }
        }
        
        return true;
    }
    
    /**
     * Send email notification to winner
     */
    private function send_win_email($user_id, $subject, $message) {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        $to = $user->user_email;
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // Add site footer
        $message .= "<br><hr><p><small>Sent from " . get_bloginfo('name') . "</small></p>";
        
        wp_mail($to, $subject, $message, $headers);
    } // End send_win_email

}
