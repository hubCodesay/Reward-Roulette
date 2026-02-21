<?php
if (!defined('ABSPATH')) {
    exit;
}

class WRR_Database {

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_sectors = $wpdb->prefix . 'wrr_sectors';
        $table_logs = $wpdb->prefix . 'wrr_logs';

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

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_sectors);
        dbDelta($sql_logs);
        
        // Seed default sectors if empty
        self::seed_defaults($table_sectors);
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
                    'sort_order' => $i
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
}
