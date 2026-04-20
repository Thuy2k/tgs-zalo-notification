<?php
/**
 * Database activator - creates tables on activation
 */

if (!defined('ABSPATH')) exit;

class TGS_Zalo_Activator {

    /**
     * Run on plugin activation
     */
    public static function activate($network_wide = false) {
        if ($network_wide) {
            self::create_tables();
        }
    }

    /**
     * Create tables if not exist (safe to call multiple times)
     */
    public static function maybe_create_tables() {
        $version = get_site_option('tgs_zalo_db_version', '0');
        if (version_compare($version, TGS_ZALO_VERSION, '<')) {
            self::create_tables();
            update_site_option('tgs_zalo_db_version', TGS_ZALO_VERSION);
        }
    }

    /**
     * Create all required tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->base_prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Message queue table
        $queue_table = TGS_TABLE_ZALO_MESSAGE_QUEUE;
        $sql_queue = "CREATE TABLE {$queue_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
            phone varchar(20) NOT NULL,
            template_id bigint(20) unsigned NOT NULL DEFAULT 0,
            zalo_template_id varchar(100) NOT NULL DEFAULT '',
            template_data longtext NOT NULL,
            tracking_id varchar(100) NOT NULL DEFAULT '',
            status enum('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
            retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
            max_retries tinyint(3) unsigned NOT NULL DEFAULT 3,
            next_retry_at datetime DEFAULT NULL,
            last_error text DEFAULT NULL,
            zalo_msg_id varchar(100) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime DEFAULT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_retry (status, next_retry_at),
            KEY idx_blog_id (blog_id),
            KEY idx_phone (phone),
            KEY idx_created_at (created_at),
            KEY idx_tracking_id (tracking_id)
        ) {$charset};";

        dbDelta($sql_queue);

        // Message log table (for sent/completed messages - archival)
        $log_table = TGS_TABLE_ZALO_MESSAGE_LOG;
        $sql_log = "CREATE TABLE {$log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            queue_id bigint(20) unsigned DEFAULT NULL,
            blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
            phone varchar(20) NOT NULL,
            zalo_template_id varchar(100) NOT NULL DEFAULT '',
            template_data longtext NOT NULL,
            tracking_id varchar(100) NOT NULL DEFAULT '',
            status enum('sent','failed','cancelled') NOT NULL DEFAULT 'sent',
            zalo_msg_id varchar(100) DEFAULT NULL,
            zalo_response longtext DEFAULT NULL,
            error_message text DEFAULT NULL,
            retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_blog_id (blog_id),
            KEY idx_status (status),
            KEY idx_phone (phone),
            KEY idx_created_at (created_at)
        ) {$charset};";

        dbDelta($sql_log);

        // Template mapping table
        $templates_table = TGS_TABLE_ZALO_TEMPLATES;
        $sql_templates = "CREATE TABLE {$templates_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            zalo_template_id varchar(100) NOT NULL,
            event_type varchar(50) NOT NULL,
            label varchar(255) NOT NULL DEFAULT '',
            field_mapping longtext NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_active (is_active)
        ) {$charset};";

        dbDelta($sql_templates);
    }
}
