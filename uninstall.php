<?php
/**
 * Uninstall Plugin
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop Custom Tables
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wrr_sectors");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wrr_logs");

// Delete Options
delete_option('wrr_settings');
