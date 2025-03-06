<?php
class CTS_Logger {
    private $table_name;
    private $max_log_age = 30; // Days to keep logs
    private $log_levels = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5
    ];

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cts_logs';
        
        // Schedule cleanup
        if (!wp_next_scheduled('cts_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'cts_cleanup_logs');
        }
        add_action('cts_cleanup_logs', [$this, 'cleanup_old_logs']);
    }

    public function debug($message, $context = [], $source = null) {
        return $this->log($message, 'debug', $context, $source);
    }

    public function info($message, $context = [], $source = null) {
        return $this->log($message, 'info', $context, $source);
    }

    public function notice($message, $context = [], $source = null) {
        return $this->log($message, 'notice', $context, $source);
    }

    public function warning($message, $context = [], $source = null) {
        return $this->log($message, 'warning', $context, $source);
    }

    public function error($message, $context = [], $source = null) {
        return $this->log($message, 'error', $context, $source);
    }

    public function critical($message, $context = [], $source = null) {
        return $this->log($message, 'critical', $context, $source);
    }

    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context text NULL,
            source varchar(255) NULL,
            trace text NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function log($message, $level = 'info', $context = [], $source = null) {
        if (!isset($this->log_levels[$level])) {
            $level = 'info';
        }

        // Get debug backtrace for error levels
        $trace = null;
        if ($this->log_levels[$level] >= $this->log_levels['error']) {
            $trace = $this->get_backtrace();
        }

        // Prepare context data
        $context_data = array_merge([
            'user_id' => get_current_user_id(),
            'ip' => $this->get_client_ip(),
            'url' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
        ], $context);

        global $wpdb;
        $data = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'context' => json_encode($context_data),
            'source' => $source ?: $this->determine_source(),
            'trace' => $trace
        ];

        $wpdb->insert($this->table_name, $data);

        // Also log critical errors to WordPress error log
        if ($this->log_levels[$level] >= $this->log_levels['error']) {
            error_log("CounterTerror Scraper Error: {$message}");
        }

        return $wpdb->insert_id;
    }

    public function get_logs($args = []) {
        global $wpdb;

        $defaults = [
            'page' => 1,
            'per_page' => 50,
            'level' => null,
            'source' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null,
        ];

        $args = wp_parse_args($args, $defaults);
        $where = ['1=1'];
        $values = [];

        if ($args['level']) {
            $where[] = 'level = %s';
            $values[] = $args['level'];
        }

        if ($args['source']) {
            $where[] = 'source = %s';
            $values[] = $args['source'];
        }

        if ($args['date_from']) {
            $where[] = 'timestamp >= %s';
            $values[] = $args['date_from'];
        }

        if ($args['date_to']) {
            $where[] = 'timestamp <= %s';
            $values[] = $args['date_to'];
        }

        if ($args['search']) {
            $where[] = '(message LIKE %s OR context LIKE %s)';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_clause = implode(' AND ', $where);
        $offset = ($args['page'] - 1) * $args['per_page'];

        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE {$where_clause} 
             ORDER BY timestamp DESC 
             LIMIT %d OFFSET %d",
            array_merge($values, [$args['per_page'], $offset])
        );

        return $wpdb->get_results($query);
    }

    public function cleanup_old_logs() {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $this->max_log_age
        ));

        // Optimize table occasionally
        if (rand(1, 10) === 1) {
            $wpdb->query("OPTIMIZE TABLE {$this->table_name}");
        }
    }

    public function get_log_stats() {
        global $wpdb;

        return [
            'total_logs' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'error_count' => $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE level IN (%s, %s)",
                    'error',
                    'critical'
                )
            ),
            'latest_error' => $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_name} 
                     WHERE level IN (%s, %s) 
                     ORDER BY timestamp DESC 
                     LIMIT 1",
                    'error',
                    'critical'
                )
            ),
            'logs_today' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name} 
                 WHERE DATE(timestamp) = CURDATE()"
            ),
        ];
    }

    private function get_backtrace() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        // Remove this function and log() from trace
        array_shift($trace);
        array_shift($trace);
        return json_encode($trace);
    }

    private function determine_source() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        if (isset($trace[2])) {
            return basename($trace[2]['file']) . ':' . $trace[2]['line'];
        }
        return 'unknown';
    }

    private function get_client_ip() {
        $ip = '';
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ?: 'unknown';
    }

    public function export_logs($format = 'csv') {
        global $wpdb;
        
        $logs = $wpdb->get_results(
            "SELECT timestamp, level, message, context, source 
             FROM {$this->table_name} 
             ORDER BY timestamp DESC"
        );

        switch ($format) {
            case 'json':
                return json_encode($logs);
            
            case 'csv':
            default:
                $csv = "Timestamp,Level,Message,Context,Source\n";
                foreach ($logs as $log) {
                    $csv .= sprintf(
                        '"%s","%s","%s","%s","%s"' . "\n",
                        $log->timestamp,
                        $log->level,
                        str_replace('"', '""', $log->message),
                        str_replace('"', '""', $log->context),
                        $log->source
                    );
                }
                return $csv;
        }
    }
}