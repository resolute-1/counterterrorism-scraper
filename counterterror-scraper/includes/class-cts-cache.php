<?php
// Prevent direct access
if (!defined('ABSPATH')) exit;

class CTS_Cache {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cts_cache';
    }

    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            cache_value longtext NOT NULL,
            expiration datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expiration (expiration)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function get($key) {
        global $wpdb;
        
        // Clean expired cache entries
        $this->cleanup();

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cache_value FROM {$this->table_name} 
                 WHERE cache_key = %s AND expiration > NOW()",
                $key
            )
        );

        if ($result) {
            return maybe_unserialize($result->cache_value);
        }

        return false;
    }

    public function set($key, $value, $expiration = 3600) {
        global $wpdb;

        $data = [
            'cache_key' => $key,
            'cache_value' => maybe_serialize($value),
            'expiration' => date('Y-m-d H:i:s', time() + $expiration)
        ];

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE cache_key = %s",
                $key
            )
        );

        if ($existing) {
            $wpdb->update(
                $this->table_name,
                $data,
                ['cache_key' => $key]
            );
        } else {
            $wpdb->insert($this->table_name, $data);
        }

        return true;
    }

    public function delete($key) {
        global $wpdb;
        
        return $wpdb->delete(
            $this->table_name,
            ['cache_key' => $key]
        );
    }

    public function cleanup() {
        global $wpdb;
        
        // Delete expired entries
        $wpdb->query(
            "DELETE FROM {$this->table_name} WHERE expiration < NOW()"
        );

        // Optimize table periodically (1% chance on each cleanup)
        if (rand(1, 100) === 1) {
            $wpdb->query("OPTIMIZE TABLE {$this->table_name}");
        }
    }

    public function flush() {
        global $wpdb;
        
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }

    public function get_stats() {
        global $wpdb;
        
        $stats = [
            'total_entries' => 0,
            'expired_entries' => 0,
            'cache_size' => 0,
            'oldest_entry' => null,
            'newest_entry' => null
        ];

        // Get total entries
        $stats['total_entries'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}"
        );

        // Get expired entries
        $stats['expired_entries'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE expiration < NOW()"
        );

        // Get approximate cache size
        $stats['cache_size'] = $wpdb->get_var(
            "SELECT SUM(LENGTH(cache_value)) FROM {$this->table_name}"
        );

        // Get oldest and newest entries
        $stats['oldest_entry'] = $wpdb->get_var(
            "SELECT created_at FROM {$this->table_name} ORDER BY created_at ASC LIMIT 1"
        );

        $stats['newest_entry'] = $wpdb->get_var(
            "SELECT created_at FROM {$this->table_name} ORDER BY created_at DESC LIMIT 1"
        );

        return $stats;
    }

    public function optimize_table() {
        global $wpdb;
        
        // Remove expired entries
        $this->cleanup();
        
        // Optimize table structure
        $wpdb->query("OPTIMIZE TABLE {$this->table_name}");
        
        return true;
    }

    public function is_available() {
        global $wpdb;
        
        // Check if cache table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->table_name
            )
        );

        return !empty($table_exists);
    }

    public function get_cache_size() {
        global $wpdb;
        
        return $wpdb->get_var(
            "SELECT SUM(LENGTH(cache_value)) FROM {$this->table_name}"
        );
    }

    public function prune_old_entries($max_age = 86400) {
        global $wpdb;
        
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d SECOND)",
                $max_age
            )
        );
    }
}