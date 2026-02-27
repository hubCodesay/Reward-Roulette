<?php
if (!defined('ABSPATH')) {
    exit;
}

class WRR_Birthday_Automation {
    const TODAY_FOUND_OPTION = 'wrr_birthday_today_found';
    const YEAR_AHEAD_OPTION = 'wrr_birthday_year_ahead_list';
    const YEAR_AHEAD_BUILT_DATE_OPTION = 'wrr_birthday_year_ahead_built_date';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Schedule periodic cron
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        add_action('wp', array($this, 'schedule_birthday_check'));
        add_action('wrr_daily_birthday_check', array($this, 'check_birthdays_and_notify'));
        
        // Add birthday field to user profile
        add_action('show_user_profile', array($this, 'add_birthday_field'));
        add_action('edit_user_profile', array($this, 'add_birthday_field'));
        add_action('personal_options_update', array($this, 'save_birthday_field'));
        add_action('edit_user_profile_update', array($this, 'save_birthday_field'));
    }

    /**
     * Add custom cron intervals.
     */
    public function add_cron_intervals($schedules) {
        if (!isset($schedules['wrr_every_fifteen_minutes'])) {
            $schedules['wrr_every_fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every 15 minutes', 'reward-roulette'),
            );
        }

        return $schedules;
    }

    /**
     * Schedule periodic check.
     */
    public function schedule_birthday_check() {
        $next = wp_next_scheduled('wrr_daily_birthday_check');

        // Recreate legacy daily schedule to a frequent one for configurable windows.
        if ($next) {
            $event = wp_get_scheduled_event('wrr_daily_birthday_check');
            if ($event && 'wrr_every_fifteen_minutes' !== $event->schedule) {
                wp_clear_scheduled_hook('wrr_daily_birthday_check');
                $next = false;
            }
        }

        if (!$next) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'wrr_every_fifteen_minutes', 'wrr_daily_birthday_check');
        }
    }

    /**
     * Check if current site time is inside configured sending window.
     */
    private function is_inside_sending_window($settings) {
        if (empty($settings['send_window_enabled']) || 'yes' !== $settings['send_window_enabled']) {
            return true;
        }

        $start = isset($settings['send_window_start']) ? sanitize_text_field($settings['send_window_start']) : '';
        $end = isset($settings['send_window_end']) ? sanitize_text_field($settings['send_window_end']) : '';

        if ('' === $start || '' === $end) {
            return true;
        }

        $now_minutes = intval(wp_date('H')) * 60 + intval(wp_date('i'));
        $start_parts = array_map('intval', explode(':', $start));
        $end_parts = array_map('intval', explode(':', $end));

        if (count($start_parts) !== 2 || count($end_parts) !== 2) {
            return true;
        }

        $start_minutes = ($start_parts[0] * 60) + $start_parts[1];
        $end_minutes = ($end_parts[0] * 60) + $end_parts[1];

        // Same start/end means full day.
        if ($start_minutes === $end_minutes) {
            return true;
        }

        // Same-day window: 09:00-18:00.
        if ($start_minutes < $end_minutes) {
            return ($now_minutes >= $start_minutes && $now_minutes <= $end_minutes);
        }

        // Overnight window: 22:00-02:00.
        return ($now_minutes >= $start_minutes || $now_minutes <= $end_minutes);
    }

    /**
     * The Cron Job Logic
     */
    public function check_birthdays_and_notify() {
        // Keep yearly preview list fresh for admins (daily auto refresh).
        $this->maybe_refresh_year_ahead_list();

        $settings = get_option('wrr_birthday_settings', array());
        if (empty($settings['enabled']) || $settings['enabled'] !== 'yes') {
            return;
        }

        // Stage 1: prepare queue for users whose birthday is tomorrow.
        $this->prepare_tomorrow_queue();

        if (!$this->is_inside_sending_window($settings)) {
            return;
        }

        $today_ymd = wp_date('Y-m-d');
        $users = $this->get_today_recipients($today_ymd);
        $this->save_today_found_snapshot($today_ymd, $users);

        if (!empty($users)) {
            foreach ($users as $user_id) {
                $already_sent = get_user_meta($user_id, '_wrr_birthday_email_sent_date', true);
                if ($already_sent === $today_ymd) {
                    continue;
                }

                $sent = $this->send_birthday_invitation($user_id);
                if ($sent) {
                    // Grant eligibility flag for today
                    update_user_meta($user_id, '_wrr_birthday_eligible_date', $today_ymd);
                    update_user_meta($user_id, '_wrr_birthday_email_sent_date', $today_ymd);
                }
            }
        }
    }

    /**
     * Save today's detected users for admin monitoring.
     */
    private function save_today_found_snapshot($today_ymd, $user_ids) {
        $items = array();
        foreach ((array) $user_ids as $user_id) {
            $user = get_userdata($user_id);
            if (!$user) {
                continue;
            }
            $items[] = array(
                'user_id' => absint($user_id),
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
            );
        }

        update_option(
            self::TODAY_FOUND_OPTION,
            array(
                'date' => $today_ymd,
                'count' => count($items),
                'items' => $items,
                'updated_at' => current_time('mysql'),
            ),
            false
        );
    }

    /**
     * Build/refresh list for next 365 days.
     */
    private function maybe_refresh_year_ahead_list($force = false) {
        $today_ymd = wp_date('Y-m-d');
        $built_for = (string) get_option(self::YEAR_AHEAD_BUILT_DATE_OPTION, '');
        if (!$force && $built_for === $today_ymd) {
            return;
        }

        $rows = array();
        for ($i = 0; $i < 365; $i++) {
            $target_ts = strtotime('+' . $i . ' day');
            $target_ymd = wp_date('Y-m-d', $target_ts);
            $target_md = wp_date('m-d', $target_ts);
            $user_ids = $this->get_user_ids_by_birthday_md($target_md);
            if (empty($user_ids)) {
                continue;
            }

            $day_items = array();
            foreach ($user_ids as $user_id) {
                $user = get_userdata($user_id);
                if (!$user) {
                    continue;
                }
                $day_items[] = array(
                    'user_id' => absint($user_id),
                    'name' => (string) $user->display_name,
                    'email' => (string) $user->user_email,
                );
            }

            if (!empty($day_items)) {
                $rows[] = array(
                    'date' => $target_ymd,
                    'count' => count($day_items),
                    'items' => $day_items,
                );
            }
        }

        update_option(
            self::YEAR_AHEAD_OPTION,
            array(
                'built_for' => $today_ymd,
                'updated_at' => current_time('mysql'),
                'days' => $rows,
            ),
            false
        );
        update_option(self::YEAR_AHEAD_BUILT_DATE_OPTION, $today_ymd, false);
    }

    /**
     * Admin helper: force list rebuild.
     */
    public static function force_refresh_year_ahead_list() {
        $instance = self::get_instance();
        $instance->maybe_refresh_year_ahead_list(true);
    }

    /**
     * Admin helper: get latest "today found" snapshot.
     */
    public static function get_today_found_snapshot() {
        $snapshot = get_option(self::TODAY_FOUND_OPTION, array());
        return is_array($snapshot) ? $snapshot : array();
    }

    /**
     * Admin helper: get list for next year.
     */
    public static function get_year_ahead_list() {
        $instance = self::get_instance();
        $instance->maybe_refresh_year_ahead_list();
        $data = get_option(self::YEAR_AHEAD_OPTION, array());
        return is_array($data) ? $data : array();
    }

    /**
     * Get user IDs by cached birthday month-day value.
     */
    private function get_user_ids_by_birthday_md($month_day) {
        $month_day = sanitize_text_field($month_day);
        if ('' === $month_day) {
            return array();
        }

        $users = get_users(
            array(
                'meta_query' => array(
                    array(
                        'key'     => '_wrr_birthday_md',
                        'value'   => $month_day,
                        'compare' => '=',
                    ),
                ),
                'fields' => 'ID',
            )
        );

        return array_map('absint', (array) $users);
    }

    /**
     * Save tomorrow birthday users into queue.
     */
    private function prepare_tomorrow_queue() {
        $tomorrow_md = wp_date('m-d', strtotime('+1 day'));
        $tomorrow_ymd = wp_date('Y-m-d', strtotime('+1 day'));
        $prepared_marker = '_wrr_birthday_prepared_for_' . $tomorrow_ymd;

        // Avoid rewriting queue on every 15-min cron run.
        if ('yes' === get_option($prepared_marker, 'no')) {
            return;
        }

        $users = $this->get_user_ids_by_birthday_md($tomorrow_md);
        foreach ($users as $user_id) {
            update_user_meta($user_id, '_wrr_birthday_queue_date', $tomorrow_ymd);
        }

        update_option($prepared_marker, 'yes', false);
    }

    /**
     * Resolve recipients for today.
     */
    private function get_today_recipients($today_ymd) {
        $queued = get_users(
            array(
                'meta_query' => array(
                    array(
                        'key'     => '_wrr_birthday_queue_date',
                        'value'   => $today_ymd,
                        'compare' => '=',
                    ),
                ),
                'fields' => 'ID',
            )
        );

        // Safety fallback: today birthdays are included even if queue missed.
        $today_md = wp_date('m-d');
        $direct = $this->get_user_ids_by_birthday_md($today_md);

        $all = array_unique(array_merge(array_map('absint', (array) $queued), $direct));
        return array_values(array_filter($all));
    }

    /**
     * Send invite using configured channel.
     */
    private function send_birthday_invitation($user_id) {
        $settings = get_option('wrr_birthday_settings', array());
        $channel = isset($settings['delivery_channel']) ? sanitize_key($settings['delivery_channel']) : 'email';
        if (!in_array($channel, array('email', 'sms', 'both'), true)) {
            $channel = 'email';
        }

        $sent = false;
        if ('email' === $channel || 'both' === $channel) {
            $sent = $this->send_birthday_email($user_id) || $sent;
        }
        if ('sms' === $channel || 'both' === $channel) {
            $sent = $this->send_birthday_sms($user_id) || $sent;
        }

        return (bool) $sent;
    }

    /**
     * Send the actual invitation
     */
    public function send_birthday_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        $settings = get_option('wrr_birthday_settings', array());
        $subject = !empty($settings['email_subject']) ? $settings['email_subject'] : 'З Днем Народження! 🎂 Отримайте ваш подарунок!';
        $body = !empty($settings['email_content']) ? $settings['email_content'] : '<p>З Днем Народження!</p><p>Сьогодні ваш особливий день, і ми підготували для вас можливість виграти чудовий подарунок. Прокрутіть наше Колесо Фортуни прямо зараз!</p><p><a href="{site_url}" style="padding: 10px 20px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 5px;">Прокрутити Колесо</a></p>';

        // Replace placeholders
        $body = str_replace('{site_url}', home_url('/'), $body);
        $body = str_replace('{user_name}', $user->display_name, $body);

        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($user->user_email, $subject, $body, $headers);
    }

    /**
     * Send SMS invitation through external integration hook.
     *
     * Integration point:
     * add_filter('wrr_send_sms', function($sent, $phone, $message, $user_id, $settings){ ... return true/false; }, 10, 5);
     */
    public function send_birthday_sms($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        $settings = get_option('wrr_birthday_settings', array());
        $phone_meta_key = !empty($settings['sms_phone_meta_key']) ? sanitize_key($settings['sms_phone_meta_key']) : 'billing_phone';
        $phone = trim((string) get_user_meta($user_id, $phone_meta_key, true));
        if ('' === $phone) {
            return false;
        }

        $message = !empty($settings['sms_content']) ? (string) $settings['sms_content'] : 'З Днем Народження, {user_name}! 🎉 Вам доступна святкова рулетка: {site_url}';
        $message = str_replace('{site_url}', home_url('/'), $message);
        $message = str_replace('{user_name}', $user->display_name, $message);

        $sent = apply_filters('wrr_send_sms', null, $phone, $message, $user_id, $settings);
        return is_bool($sent) ? $sent : false;
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

            // Rebuild yearly list after profile change.
            self::force_refresh_year_ahead_list();
        }
    }
}
