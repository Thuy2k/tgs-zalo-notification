<?php
/**
 * Cron handler - registers WP Cron events for queue processing and cleanup
 */

if (!defined('ABSPATH')) exit;

class TGS_Zalo_Cron {

    const PROCESS_HOOK = 'tgs_zalo_process_queue';
    const CLEANUP_HOOK = 'tgs_zalo_cleanup_queue';

    /**
     * Register cron hooks and schedules
     */
    public static function register() {
        // Add custom cron interval (every 1 minute)
        add_filter('cron_schedules', [__CLASS__, 'add_cron_interval']);

        // Queue processor
        add_action(self::PROCESS_HOOK, [__CLASS__, 'run_process_queue']);

        // Daily cleanup
        add_action(self::CLEANUP_HOOK, [__CLASS__, 'run_cleanup']);

        // Schedule on init if not scheduled yet
        add_action('init', [__CLASS__, 'schedule_events']);
    }

    /**
     * Add 1-minute cron interval
     */
    public static function add_cron_interval($schedules) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => 'Mỗi phút',
        ];
        return $schedules;
    }

    /**
     * Schedule cron events
     */
    public static function schedule_events() {
        if (!wp_next_scheduled(self::PROCESS_HOOK)) {
            wp_schedule_event(time(), 'every_minute', self::PROCESS_HOOK);
        }

        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CLEANUP_HOOK);
        }
    }

    /**
     * Process queue (called by WP Cron)
     */
    public static function run_process_queue() {
        // Prevent concurrent runs with a lock
        $lock_key = 'tgs_zalo_queue_lock';
        $lock = get_site_transient($lock_key);

        if ($lock) {
            error_log('[TGS Zalo Cron] Queue processor already running, skipping.');
            return;
        }

        // Set lock for 5 minutes max
        set_site_transient($lock_key, time(), 300);

        try {
            // Recover stuck messages before processing
            TGS_Zalo_Queue::recover_stuck_messages(10);

            TGS_Zalo_Queue::process_queue();
        } catch (Exception $e) {
            error_log('[TGS Zalo Cron] Queue processing error: ' . $e->getMessage());
        } finally {
            delete_site_transient($lock_key);
        }
    }

    /**
     * Cleanup old messages (called daily)
     */
    public static function run_cleanup() {
        TGS_Zalo_Queue::cleanup_old_messages(7);
    }

    /**
     * Unschedule all events (on deactivation)
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled(self::PROCESS_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::PROCESS_HOOK);
        }

        $timestamp = wp_next_scheduled(self::CLEANUP_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CLEANUP_HOOK);
        }
    }
}
