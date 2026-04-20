<?php
/**
 * Message Queue - manages queuing and processing of Zalo messages
 */

if (!defined('ABSPATH')) exit;

class TGS_Zalo_Queue {

    /**
     * Add a message to the queue
     *
     * @param array $args {
     *     @type int    $blog_id         Site ID
     *     @type string $phone           Customer phone (raw format)
     *     @type int    $template_id     Internal template ID (from tgs_zalo_templates)
     *     @type string $zalo_template_id Zalo's template ID
     *     @type array  $template_data   Key-value data for template
     *     @type string $tracking_id     Unique tracking ID
     * }
     * @return int|false Queue ID or false on failure
     */
    public static function enqueue(array $args) {
        global $wpdb;
        $table = TGS_TABLE_ZALO_MESSAGE_QUEUE;

        $phone = TGS_Zalo_API::format_phone($args['phone'] ?? '');
        if (empty($phone)) {
            error_log('[TGS Zalo Queue] Invalid phone number: ' . ($args['phone'] ?? 'empty'));
            return false;
        }

        $max_retries = intval(get_site_option('tgs_zalo_retry_max', 3));

        $result = $wpdb->insert($table, [
            'blog_id'          => intval($args['blog_id'] ?? get_current_blog_id()),
            'phone'            => $phone,
            'template_id'      => intval($args['template_id'] ?? 0),
            'zalo_template_id' => sanitize_text_field($args['zalo_template_id'] ?? ''),
            'template_data'    => wp_json_encode($args['template_data'] ?? [], JSON_UNESCAPED_UNICODE),
            'tracking_id'      => sanitize_text_field($args['tracking_id'] ?? ''),
            'status'           => 'pending',
            'retry_count'      => 0,
            'max_retries'      => $max_retries,
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
        ]);

        if ($result === false) {
            error_log('[TGS Zalo Queue] Insert failed: ' . $wpdb->last_error);
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Process the queue - send pending messages in batches
     *
     * @return array Stats about processed messages
     */
    public static function process_queue() {
        if (!get_site_option('tgs_zalo_enabled', 0)) {
            return ['skipped' => true, 'reason' => 'Plugin disabled'];
        }

        global $wpdb;
        $table = TGS_TABLE_ZALO_MESSAGE_QUEUE;
        $batch_size = intval(get_site_option('tgs_zalo_batch_size', 50));

        $now = current_time('mysql');

        // Get pending messages (including retry-ready ones)
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status IN ('pending', 'failed') 
               AND (next_retry_at IS NULL OR next_retry_at <= %s)
               AND retry_count < max_retries
             ORDER BY created_at ASC 
             LIMIT %d",
            $now,
            $batch_size
        ));

        if (empty($messages)) {
            return ['processed' => 0, 'sent' => 0, 'failed' => 0];
        }

        // Mark as processing to prevent duplicate processing
        $ids = array_map(fn($m) => $m->id, $messages);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id IN ({$placeholders})",
            $now, ...$ids
        ));

        $stats = ['processed' => count($messages), 'sent' => 0, 'failed' => 0];

        foreach ($messages as $msg) {
            $template_data = json_decode($msg->template_data, true) ?: [];

            $result = TGS_Zalo_API::send_zns_message(
                $msg->phone,
                $msg->zalo_template_id,
                $template_data,
                $msg->tracking_id
            );

            if (is_wp_error($result)) {
                self::mark_failed($msg, $result->get_error_message());
                $stats['failed']++;
            } else {
                self::mark_sent($msg, $result);
                $stats['sent']++;
            }
        }

        error_log("[TGS Zalo Queue] Processed: {$stats['processed']}, Sent: {$stats['sent']}, Failed: {$stats['failed']}");

        return $stats;
    }

    /**
     * Mark a message as sent and log it
     */
    private static function mark_sent($msg, $zalo_response) {
        global $wpdb;
        $queue_table = TGS_TABLE_ZALO_MESSAGE_QUEUE;
        $log_table = TGS_TABLE_ZALO_MESSAGE_LOG;
        $now = current_time('mysql');

        $zalo_msg_id = $zalo_response['data']['msg_id'] ?? '';

        // Update queue
        $wpdb->update($queue_table, [
            'status'      => 'sent',
            'zalo_msg_id' => $zalo_msg_id,
            'sent_at'     => $now,
            'updated_at'  => $now,
        ], ['id' => $msg->id]);

        // Insert into log
        $wpdb->insert($log_table, [
            'queue_id'          => $msg->id,
            'blog_id'           => $msg->blog_id,
            'phone'             => $msg->phone,
            'zalo_template_id'  => $msg->zalo_template_id,
            'template_data'     => $msg->template_data,
            'tracking_id'       => $msg->tracking_id,
            'status'            => 'sent',
            'zalo_msg_id'       => $zalo_msg_id,
            'zalo_response'     => wp_json_encode($zalo_response, JSON_UNESCAPED_UNICODE),
            'retry_count'       => $msg->retry_count,
            'created_at'        => $msg->created_at,
            'sent_at'           => $now,
        ]);
    }

    /**
     * Mark a message as failed with retry scheduling
     */
    private static function mark_failed($msg, $error_message) {
        global $wpdb;
        $queue_table = TGS_TABLE_ZALO_MESSAGE_QUEUE;
        $log_table = TGS_TABLE_ZALO_MESSAGE_LOG;
        $now = current_time('mysql');

        $new_retry_count = intval($msg->retry_count) + 1;
        $max_retries = intval($msg->max_retries);

        // Calculate next retry time with exponential backoff: 5min, 15min, 30min
        $delays = [300, 900, 1800]; // seconds
        $delay_index = min($new_retry_count - 1, count($delays) - 1);
        $next_retry = wp_date('Y-m-d H:i:s', time() + $delays[$delay_index]);

        $final_failed = $new_retry_count >= $max_retries;

        // Update queue
        $wpdb->update($queue_table, [
            'status'        => 'failed',
            'retry_count'   => $new_retry_count,
            'next_retry_at' => $final_failed ? null : $next_retry,
            'last_error'    => $error_message,
            'updated_at'    => $now,
        ], ['id' => $msg->id]);

        // Log only final failures
        if ($final_failed) {
            $wpdb->insert($log_table, [
                'queue_id'          => $msg->id,
                'blog_id'           => $msg->blog_id,
                'phone'             => $msg->phone,
                'zalo_template_id'  => $msg->zalo_template_id,
                'template_data'     => $msg->template_data,
                'tracking_id'       => $msg->tracking_id,
                'status'            => 'failed',
                'error_message'     => $error_message,
                'retry_count'       => $new_retry_count,
                'created_at'        => $msg->created_at,
                'sent_at'           => $now,
            ]);
        }
    }

    /**
     * Reset a failed message for retry (manual)
     */
    public static function reset_for_retry($queue_id) {
        global $wpdb;
        $table = TGS_TABLE_ZALO_MESSAGE_QUEUE;

        $wpdb->update($table, [
            'status'        => 'pending',
            'retry_count'   => 0,
            'next_retry_at' => null,
            'last_error'    => null,
            'updated_at'    => current_time('mysql'),
        ], ['id' => $queue_id, 'status' => 'failed']);
    }

    /**
     * Get queue statistics
     */
    public static function get_stats() {
        global $wpdb;
        $table = TGS_TABLE_ZALO_MESSAGE_QUEUE;

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as total FROM {$table} GROUP BY status",
            OBJECT_K
        );

        return [
            'pending'    => intval($results['pending']->total ?? 0),
            'processing' => intval($results['processing']->total ?? 0),
            'sent'       => intval($results['sent']->total ?? 0),
            'failed'     => intval($results['failed']->total ?? 0),
            'cancelled'  => intval($results['cancelled']->total ?? 0),
        ];
    }

    /**
     * Recover messages stuck in 'processing' state (e.g. after PHP crash)
     * Resets to 'pending' if stuck for more than $minutes minutes
     */
    public static function recover_stuck_messages($minutes = 10) {
        global $wpdb;
        $table = TGS_TABLE_ZALO_MESSAGE_QUEUE;
        $now = current_time('mysql');

        $count = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'pending', updated_at = %s 
             WHERE status = 'processing' AND updated_at < DATE_SUB(%s, INTERVAL %d MINUTE)",
            $now, $now, $minutes
        ));

        if ($count > 0) {
            error_log("[TGS Zalo Queue] Recovered {$count} stuck processing messages.");
        }

        return $count;
    }

    /**
     * Clean old sent messages from queue (keep log table)
     * Run daily to prevent queue table from growing too large
     */
    public static function cleanup_old_messages($days = 7) {
        global $wpdb;
        $table = TGS_TABLE_ZALO_MESSAGE_QUEUE;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'sent' AND sent_at < DATE_SUB(%s, INTERVAL %d DAY)",
            current_time('mysql'),
            $days
        ));
    }
}
