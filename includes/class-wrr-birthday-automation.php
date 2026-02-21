<?php
if (!defined('ABSPATH')) {
    exit;
}

class WRR_Birthday_Automation {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Schedule daily cron
        add_action('wp', array($this, 'schedule_birthday_check'));
        add_action('wrr_daily_birthday_check', array($this, 'check_birthdays_and_notify'));
        
        // Add birthday field to user profile
        add_action('show_user_profile', array($this, 'add_birthday_field'));
        add_action('edit_user_profile', array($this, 'add_birthday_field'));
        add_action('personal_options_update', array($this, 'save_birthday_field'));
        add_action('edit_user_profile_update', array($this, 'save_birthday_field'));
    }

    /**
     * Schedule daily check
     */
    public function schedule_birthday_check() {
        if (!wp_next_scheduled('wrr_daily_birthday_check')) {
            wp_schedule_event(time(), 'daily', 'wrr_daily_birthday_check');
        }
    }

    /**
     * The Cron Job Logic
     */
    public function check_birthdays_and_notify() {
        $settings = get_option('wrr_birthday_settings', array());
        if (empty($settings['enabled']) || $settings['enabled'] !== 'yes') {
            return;
        }

        $today = date('m-d'); // Month-Day format
        
        // Query users with birthday today
        // Note: This is an expensive query if you have 100k users. 
        // For standard sites, we query by meta.
        $args = array(
            'meta_query' => array(
                array(
                    'key'     => '_wrr_birthday_md', // Cached month-day for performance
                    'value'   => $today,
                    'compare' => '='
                )
            ),
            'fields' => 'ID'
        );
        
        $users = get_users($args);
        
        if (!empty($users)) {
            foreach ($users as $user_id) {
                $this->send_birthday_email($user_id);
                // Grant eligibility flag for today
                update_user_meta($user_id, '_wrr_birthday_eligible_date', date('Y-m-d'));
            }
        }
    }

    /**
     * Send the actual invitation
     */
    public function send_birthday_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $settings = get_option('wrr_birthday_settings', array());
        $subject = !empty($settings['email_subject']) ? $settings['email_subject'] : 'З Днем Народження! 🎂 Отримайте ваш подарунок!';
        $body = !empty($settings['email_content']) ? $settings['email_content'] : '<p>З Днем Народження!</p><p>Сьогодні ваш особливий день, і ми підготували для вас можливість виграти чудовий подарунок. Прокрутіть наше Колесо Фортуни прямо зараз!</p><p><a href="{site_url}" style="padding: 10px 20px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 5px;">Прокрутити Колесо</a></p>';

        // Replace placeholders
        $body = str_replace('{site_url}', home_url('/'), $body);
        $body = str_replace('{user_name}', $user->display_name, $body);

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($user->user_email, $subject, $body, $headers);
    }

    /**
     * Send test email
     */
    public function send_test_email($email) {
        $settings = get_option('wrr_birthday_settings', array());
        $subject = "[TEST] " . (!empty($settings['email_subject']) ? $settings['email_subject'] : 'З Днем Народження!');
        $body = !empty($settings['email_content']) ? $settings['email_content'] : '<p>Тестове повідомлення</p>';
        
        $body = str_replace('{site_url}', add_query_arg('wrr_test', '1', home_url('/')), $body);
        $body = str_replace('{user_name}', 'Test User', $body);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($email, $subject, $body, $headers);
    }

    /**
     * Birthday field in profile
     */
    public function add_birthday_field($user) {
        $birthday = get_user_meta($user->ID, '_wrr_birthday', true);
        ?>
        <h3>🎂 Reward Roulette: День Народження</h3>
        <table class="form-table">
            <tr>
                <th><label for="wrr_birthday">Дата народження</label></th>
                <td>
                    <input type="date" name="wrr_birthday" id="wrr_birthday" value="<?php echo esc_attr($birthday); ?>" class="regular-text">
                    <p class="description">Буде надіслано запрошення прокрутити рулетку в цей день.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_birthday_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) return false;
        
        if (isset($_POST['wrr_birthday'])) {
            $date = sanitize_text_field($_POST['wrr_birthday']);
            update_user_meta($user_id, '_wrr_birthday', $date);
            
            // Extract month-day for optimized queries
            if (!empty($date)) {
                $md = date('m-d', strtotime($date));
                update_user_meta($user_id, '_wrr_birthday_md', $md);
            } else {
                delete_user_meta($user_id, '_wrr_birthday_md');
            }
        }
    }
}
