<?php
if (!defined('ABSPATH')) {
    exit;
}

class WRR_Database {

    const DB_VERSION = '1.1.0';

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_sectors = $wpdb->prefix . 'wrr_sectors';
        $table_logs = $wpdb->prefix . 'wrr_logs';
        $table_rewards = $wpdb->prefix . 'wrr_user_rewards';

        // Table for Roulette Sectors
        $sql_sectors = "CREATE TABLE $table_sectors (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            type varchar(50) NOT NULL, 
            value varchar(100) DEFAULT '' NOT NULL,
            probability int(3) DEFAULT 0 NOT NULL,
            color varchar(20) DEFAULT '#333333',
            text_color varchar(20) DEFAULT '#ffffff',
            is_active tinyint(1) DEFAULT 1 NOT NULL,
            sort_order int(3) DEFAULT 0,
            max_wins_per_user int(5) DEFAULT 0,
            coupon_discount_type varchar(20) DEFAULT 'percent' NOT NULL,
            coupon_expiry_days int(5) DEFAULT 20 NOT NULL,
            coupon_usage_limit int(5) DEFAULT 1 NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Table for Spin Logs (Anti-fraud)
        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            sector_id mediumint(9) NOT NULL,
            reward_type varchar(50) NOT NULL,
            reward_value varchar(100) NOT NULL,
            ip_address varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";

        // Table for user-visible rewards.
        $sql_rewards = "CREATE TABLE $table_rewards (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            spin_log_id bigint(20) DEFAULT 0 NOT NULL,
            sector_id mediumint(9) DEFAULT 0 NOT NULL,
            reward_type varchar(50) NOT NULL,
            reward_name varchar(190) DEFAULT '' NOT NULL,
            reward_value varchar(100) DEFAULT '' NOT NULL,
            coupon_id bigint(20) DEFAULT 0 NOT NULL,
            coupon_code varchar(100) DEFAULT '' NOT NULL,
            expires_at datetime NULL,
            status varchar(30) DEFAULT 'active' NOT NULL,
            meta longtext NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_sectors);
        dbDelta($sql_logs);
        dbDelta($sql_rewards);
        
        // Seed default sectors if empty
        self::seed_defaults($table_sectors);
        update_option('wrr_db_version', self::DB_VERSION);
    }

    /**
     * Run schema updates when plugin code is newer than DB.
     */
    public static function maybe_upgrade() {
        $current = (string) get_option('wrr_db_version', '');
        if (self::DB_VERSION !== $current) {
            self::create_tables();
        }
    }
    
    private static function seed_defaults($table_name) {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        if ($count == 0) {
            $defaults = array(
                array('name' => '10% Знижка', 'type' => 'coupon', 'value' => '10', 'probability' => 20, 'color' => '#e74c3c'),
                array('name' => 'Спробуй ще', 'type' => 'no_win', 'value' => '', 'probability' => 40, 'color' => '#34495e'),
                array('name' => 'Безкоштовна Доставка', 'type' => 'shipping', 'value' => '', 'probability' => 15, 'color' => '#f1c40f'),
                array('name' => '5% Кешбек', 'type' => 'cashback', 'value' => '5', 'probability' => 20, 'color' => '#2ecc71'),
                array('name' => 'Секретний Подарунок', 'type' => 'product', 'value' => '0', 'probability' => 5, 'color' => '#9b59b6')
            );
            
            foreach ($defaults as $i => $item) {
                $wpdb->insert($table_name, array(
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'value' => $item['value'],
                    'probability' => $item['probability'],
                    'color' => $item['color'],
                    'sort_order' => $i,
                    'coupon_discount_type' => 'percent',
                    'coupon_expiry_days' => 20,
                    'coupon_usage_limit' => 1,
                ));
            }
        }
    }

    public static function get_sectors($active_only = true) {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}wrr_sectors";
        if ($active_only) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC";
        return $wpdb->get_results($sql);
    }
    
    public static function log_spin($data) {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}wrr_logs", $data);
        return $wpdb->insert_id;
    }
    
    public static function count_user_wins($user_id, $sector_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wrr_logs WHERE user_id = %d AND sector_id = %d",
            $user_id, $sector_id
        ));
    }
    
    public static function add_sector($data) {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}wrr_sectors", $data);
        return $wpdb->insert_id;
    }
    
    public static function delete_sector($id) {
        global $wpdb;
        return $wpdb->delete("{$wpdb->prefix}wrr_sectors", array('id' => $id));
    }

    public static function get_logs($limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.user_login, u.user_email 
             FROM {$wpdb->prefix}wrr_logs l
             LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
             ORDER BY l.created_at DESC LIMIT %d",
            $limit
        ));
    }

    public static function add_user_reward($data) {
        global $wpdb;
        $defaults = array(
            'user_id' => 0,
            'spin_log_id' => 0,
            'sector_id' => 0,
            'reward_type' => '',
            'reward_name' => '',
            'reward_value' => '',
            'coupon_id' => 0,
            'coupon_code' => '',
            'expires_at' => null,
            'status' => 'active',
            'meta' => '',
        );
        $data = wp_parse_args($data, $defaults);

        $wpdb->insert(
            "{$wpdb->prefix}wrr_user_rewards",
            array(
                'user_id' => absint($data['user_id']),
                'spin_log_id' => absint($data['spin_log_id']),
                'sector_id' => absint($data['sector_id']),
                'reward_type' => sanitize_text_field($data['reward_type']),
                'reward_name' => sanitize_text_field($data['reward_name']),
                'reward_value' => sanitize_text_field($data['reward_value']),
                'coupon_id' => absint($data['coupon_id']),
                'coupon_code' => sanitize_text_field($data['coupon_code']),
                'expires_at' => !empty($data['expires_at']) ? gmdate('Y-m-d H:i:s', strtotime($data['expires_at'])) : null,
                'status' => sanitize_key($data['status']),
                'meta' => is_array($data['meta']) ? wp_json_encode($data['meta']) : (string) $data['meta'],
            ),
            array('%d','%d','%d','%s','%s','%s','%d','%s','%s','%s','%s')
        );

        return (int) $wpdb->insert_id;
    }

    public static function get_user_rewards($user_id, $limit = 200) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wrr_user_rewards
                 WHERE user_id = %d
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d",
                absint($user_id),
                max(1, absint($limit))
            )
        );
    }

    public static function get_recent_user_rewards($limit = 100) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, u.user_login, u.user_email
                 FROM {$wpdb->prefix}wrr_user_rewards r
                 LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                 ORDER BY r.created_at DESC, r.id DESC
                 LIMIT %d",
                max(1, absint($limit))
            )
        );
    }
}
