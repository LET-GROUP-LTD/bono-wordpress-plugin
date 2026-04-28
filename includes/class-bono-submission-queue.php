<?php
/**
 * Durable submission queue with WP-Cron retries.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_Submission_Queue {
    /**
     * Cron hook name.
     */
    const CRON_HOOK = 'bono_process_submission_queue';

    /**
     * Retry schedule name.
     */
    const CRON_SCHEDULE = 'bono_every_five_minutes';

    /**
     * Queue table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * API client.
     *
     * @var Bono_API_Client
     */
    private $api_client;

    /**
     * Constructor.
     *
     * @param Bono_API_Client $api_client API client.
     */
    public function __construct(Bono_API_Client $api_client) {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bono_submission_queue';
        $this->api_client = $api_client;
    }

    /**
     * Register runtime hooks.
     *
     * @return void
     */
    public function register_hooks() {
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        add_action(self::CRON_HOOK, array($this, 'process_queue'));
    }

    /**
     * Add custom five-minute cron interval.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_schedule($schedules) {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Every 5 Minutes (Bono Queue)', 'bono-leads-connector'),
            );
        }

        return $schedules;
    }

    /**
     * Create queue table if needed.
     *
     * @return void
     */
    public function create_table() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            idempotency_key varchar(128) NOT NULL,
            provider varchar(50) NOT NULL,
            source_key varchar(255) NOT NULL,
            payload longtext NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            next_attempt_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY status_next_attempt (status, next_attempt_at),
            KEY source_key (source_key)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Schedule queue processing cron job.
     *
     * @return void
     */
    public function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + (5 * MINUTE_IN_SECONDS), self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    /**
     * Unschedule queue processing cron job.
     *
     * @return void
     */
    public function unschedule_cron() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Insert or update a failed submission in the queue.
     *
     * @param array  $payload Submission payload.
     * @param string $error   Failure reason.
     * @return bool
     */
    public function enqueue(array $payload, $error) {
        global $wpdb;

        $idempotency_key = isset($payload['idempotencyKey']) ? sanitize_text_field((string) $payload['idempotencyKey']) : '';
        $provider = isset($payload['provider']) ? sanitize_key((string) $payload['provider']) : '';
        $source_key = isset($payload['sourceKey']) ? sanitize_text_field((string) $payload['sourceKey']) : '';

        if ('' === $idempotency_key || '' === $provider || '' === $source_key) {
            return false;
        }

        $now = current_time('mysql');
        $next_attempt = gmdate('Y-m-d H:i:s', current_time('timestamp', true) + (5 * MINUTE_IN_SECONDS));
        $encoded_payload = wp_json_encode($payload);

        if (!is_string($encoded_payload) || '' === $encoded_payload) {
            return false;
        }

        $last_error = $this->sanitize_error($error);
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, attempts FROM {$this->table_name} WHERE idempotency_key = %s LIMIT 1",
                $idempotency_key
            ),
            ARRAY_A
        );

        if (is_array($existing) && !empty($existing['id'])) {
            $attempts = isset($existing['attempts']) ? (int) $existing['attempts'] : 0;

            return false !== $wpdb->update(
                $this->table_name,
                array(
                    'provider' => $provider,
                    'source_key' => $source_key,
                    'payload' => $encoded_payload,
                    'status' => $attempts >= 5 ? 'failed' : 'pending',
                    'last_error' => $last_error,
                    'next_attempt_at' => $next_attempt,
                    'updated_at' => $now,
                ),
                array('id' => (int) $existing['id']),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
        }

        $inserted = $wpdb->insert(
            $this->table_name,
            array(
                'idempotency_key' => $idempotency_key,
                'provider' => $provider,
                'source_key' => $source_key,
                'payload' => $encoded_payload,
                'status' => 'pending',
                'attempts' => 0,
                'last_error' => $last_error,
                'next_attempt_at' => $next_attempt,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );

        if (false !== $inserted) {
            return true;
        }

        // Gracefully handle duplicate-key races by updating the existing row.
        if (
            is_string($wpdb->last_error) &&
            false !== stripos($wpdb->last_error, 'Duplicate entry')
        ) {
            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE idempotency_key = %s LIMIT 1",
                    $idempotency_key
                )
            );

            if (!empty($existing_id)) {
                return false !== $wpdb->update(
                    $this->table_name,
                    array(
                        'provider' => $provider,
                        'source_key' => $source_key,
                        'payload' => $encoded_payload,
                        'last_error' => $last_error,
                        'updated_at' => $now,
                    ),
                    array('id' => (int) $existing_id),
                    array('%s', '%s', '%s', '%s', '%s'),
                    array('%d')
                );
            }
        }

        return false;
    }

    /**
     * Check if an idempotency key already exists in queue table.
     *
     * @param string $idempotency_key Idempotency key.
     * @return bool
     */
    public function has_idempotency_key($idempotency_key) {
        global $wpdb;

        $idempotency_key = sanitize_text_field((string) $idempotency_key);

        if ('' === $idempotency_key) {
            return false;
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE idempotency_key = %s LIMIT 1",
                $idempotency_key
            )
        );

        return !empty($existing);
    }

    /**
     * Process due queue items.
     *
     * @return int Number of rows processed.
     */
    public function process_queue() {
        global $wpdb;

        $now = current_time('mysql');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, idempotency_key, payload, attempts FROM {$this->table_name}
                WHERE status IN (%s, %s) AND next_attempt_at <= %s
                ORDER BY next_attempt_at ASC, id ASC
                LIMIT 10",
                'pending',
                'retrying',
                $now
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        $processed = 0;

        foreach ($rows as $row) {
            $processed++;
            $payload = json_decode(isset($row['payload']) ? (string) $row['payload'] : '', true);

            if (!is_array($payload)) {
                $this->mark_retry_failure((int) $row['id'], (int) $row['attempts'] + 1, __('Invalid queued payload.', 'bono-leads-connector'));
                continue;
            }

            $result = $this->api_client->send_submission($payload);

            if (!empty($result['success'])) {
                $this->mark_sent((int) $row['id']);
                continue;
            }

            $this->mark_retry_failure(
                (int) $row['id'],
                (int) $row['attempts'] + 1,
                isset($result['error']) ? $result['error'] : __('Queued submission failed.', 'bono-leads-connector')
            );
        }

        return $processed;
    }

    /**
     * Get queue status counters.
     *
     * @return array
     */
    public function get_counts() {
        global $wpdb;

        $counts = array(
            'pending' => 0,
            'retrying' => 0,
            'sent' => 0,
            'failed' => 0,
            'latest_failed_at' => '',
            'oldest_pending_age' => null,
            'health' => $this->get_default_health(),
        );

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS total FROM {$this->table_name} GROUP BY status",
            ARRAY_A
        );

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $status = isset($row['status']) ? (string) $row['status'] : '';

                if (isset($counts[$status])) {
                    $counts[$status] = isset($row['total']) ? (int) $row['total'] : 0;
                }
            }
        }

        $latest_failed_at = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(updated_at) FROM {$this->table_name} WHERE status = %s",
                'failed'
            )
        );

        if (is_string($latest_failed_at) && '' !== $latest_failed_at) {
            $counts['latest_failed_at'] = $latest_failed_at;
        }

        $counts['oldest_pending_age'] = $this->get_oldest_pending_age();
        $counts['health'] = $this->get_health($counts);

        return $counts;
    }

    /**
     * Get queue health state.
     *
     * @param array|null $counts Optional counts.
     * @return array
     */
    public function get_health($counts = null) {
        if (!is_array($counts)) {
            $counts = $this->get_counts();
        }

        $failed = isset($counts['failed']) ? (int) $counts['failed'] : 0;
        $pending = isset($counts['pending']) ? (int) $counts['pending'] : 0;
        $oldest_pending_age = isset($counts['oldest_pending_age']) && is_numeric($counts['oldest_pending_age'])
            ? (int) $counts['oldest_pending_age']
            : null;

        if ($failed >= 10 || (!is_null($oldest_pending_age) && $oldest_pending_age > HOUR_IN_SECONDS)) {
            return array(
                'state' => 'critical',
                'label' => __('Critical', 'bono-leads-connector'),
                'description' => __('Failed queue count is high or pending submissions are older than 1 hour.', 'bono-leads-connector'),
            );
        }

        if ($failed > 0 || $pending >= 10) {
            return array(
                'state' => 'warning',
                'label' => __('Warning', 'bono-leads-connector'),
                'description' => __('There are failed submissions or the pending queue is growing.', 'bono-leads-connector'),
            );
        }

        return $this->get_default_health();
    }

    /**
     * Get latest failed queue rows without payload data.
     *
     * @param int $limit Max rows.
     * @return array
     */
    public function get_latest_failed($limit = 5) {
        global $wpdb;

        $limit = max(1, min(20, (int) $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, updated_at, provider, source_key, attempts, last_error
                FROM {$this->table_name}
                WHERE status = %s
                ORDER BY updated_at DESC, id DESC
                LIMIT %d",
                'failed',
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Get oldest pending row age in seconds.
     *
     * @return int|null
     */
    public function get_oldest_pending_age() {
        global $wpdb;

        $oldest = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(created_at) FROM {$this->table_name} WHERE status = %s",
                'pending'
            )
        );

        if (!is_string($oldest) || '' === $oldest) {
            return null;
        }

        $oldest_timestamp = strtotime(get_gmt_from_date($oldest));

        if (false === $oldest_timestamp) {
            return null;
        }

        return max(0, current_time('timestamp', true) - $oldest_timestamp);
    }

    /**
     * Delete sent queue rows.
     *
     * @return int
     */
    public function delete_sent() {
        return $this->delete_by_status('sent');
    }

    /**
     * Delete failed queue rows.
     *
     * @return int
     */
    public function delete_failed() {
        return $this->delete_by_status('failed');
    }

    /**
     * Move failed rows back to retrying.
     *
     * @return int
     */
    public function retry_failed() {
        global $wpdb;

        $now = current_time('mysql');
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name}
                SET status = %s, next_attempt_at = %s, updated_at = %s
                WHERE status = %s",
                'retrying',
                $now,
                $now,
                'failed'
            )
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Delete rows by status.
     *
     * @param string $status Queue status.
     * @return int
     */
    private function delete_by_status($status) {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE status = %s",
                sanitize_key($status)
            )
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Default healthy state.
     *
     * @return array
     */
    private function get_default_health() {
        return array(
            'state' => 'healthy',
            'label' => __('Healthy', 'bono-leads-connector'),
            'description' => __('No failed submissions and pending queue is under 10.', 'bono-leads-connector'),
        );
    }

    /**
     * Mark queue row as sent.
     *
     * @param int $id Row ID.
     * @return void
     */
    private function mark_sent($id) {
        global $wpdb;

        $now = current_time('mysql');
        $wpdb->update(
            $this->table_name,
            array(
                'status' => 'sent',
                'last_error' => null,
                'updated_at' => $now,
            ),
            array('id' => (int) $id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Mark a queue row as retrying/failed with backoff.
     *
     * @param int    $id          Row ID.
     * @param int    $attempts    New attempt count.
     * @param string $error       Error message.
     * @return void
     */
    private function mark_retry_failure($id, $attempts, $error) {
        global $wpdb;

        $attempts = max(1, (int) $attempts);
        $now = current_time('mysql');
        $status = $attempts >= 5 ? 'failed' : 'retrying';
        $next_attempt = $this->calculate_next_attempt($attempts);

        $wpdb->update(
            $this->table_name,
            array(
                'attempts' => $attempts,
                'status' => $status,
                'last_error' => $this->sanitize_error($error),
                'next_attempt_at' => $next_attempt,
                'updated_at' => $now,
            ),
            array('id' => (int) $id),
            array('%d', '%s', '%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Calculate retry backoff time.
     *
     * @param int $attempts Attempts count after increment.
     * @return string
     */
    private function calculate_next_attempt($attempts) {
        $minutes_by_attempt = array(
            1 => 5,
            2 => 15,
            3 => 30,
            4 => 60,
        );

        $minutes = isset($minutes_by_attempt[$attempts]) ? $minutes_by_attempt[$attempts] : 60;

        return gmdate('Y-m-d H:i:s', current_time('timestamp', true) + ($minutes * MINUTE_IN_SECONDS));
    }

    /**
     * Sanitize error messages for safe storage/logging.
     *
     * @param string $error Raw error.
     * @return string
     */
    private function sanitize_error($error) {
        $sanitized = sanitize_text_field((string) $error);

        if (strlen($sanitized) > 500) {
            $sanitized = substr($sanitized, 0, 500);
        }

        return $sanitized;
    }
}
