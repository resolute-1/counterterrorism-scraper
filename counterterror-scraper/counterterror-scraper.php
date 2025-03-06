<?php
// Temporary debug code for database tables
global $wpdb;
$cache_table = $wpdb->prefix . 'cts_cache';
$logs_table = $wpdb->prefix . 'cts_logs';

$cache_exists = $wpdb->get_var("SHOW TABLES LIKE '$cache_table'");
$logs_exists = $wpdb->get_var("SHOW TABLES LIKE '$logs_table'");

error_log("WordPress table prefix: " . $wpdb->prefix);
error_log("Cache table exists: " . ($cache_exists ? 'yes' : 'no'));
error_log("Logs table exists: " . ($logs_exists ? 'yes' : 'no'));

// Also check table structure if they exist
if ($cache_exists) {
    $cache_cols = $wpdb->get_results("DESCRIBE $cache_table");
    error_log("Cache table structure: " . print_r($cache_cols, true));
}
if ($logs_exists) {
    $logs_cols = $wpdb->get_results("DESCRIBE $logs_table");
    error_log("Logs table structure: " . print_r($logs_cols, true));
}

// Original debug code
$admin_file = __DIR__ . '/includes/class-cts-admin.php';
if (file_exists($admin_file)) {
    error_log("Admin file exists at: " . $admin_file);
    error_log("File contents: " . file_get_contents($admin_file));
} else {
    error_log("Admin file NOT found at: " . $admin_file);
}

/*
Plugin Name: CounterTerror Scraper
Description: Scrapes terrorism news, summarizes it using AI, and posts to WordPress.
Version: 2.0
Author: Grok-Assisted User
License: GPL v2 or later
*/

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Plugin constants - only define if not already defined
if (!defined('CTS_VERSION')) {
    define('CTS_VERSION', '2.0');
}
if (!defined('CTS_PLUGIN_DIR')) {
    define('CTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('CTS_PLUGIN_URL')) {
    define('CTS_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('CTS_CACHE_EXPIRATION')) {
    define('CTS_CACHE_EXPIRATION', 3600); // 1 hour
}
if (!defined('CTS_MAX_RETRIES')) {
    define('CTS_MAX_RETRIES', 3);
}

// Debug function - renamed to avoid conflicts
if (!function_exists('cts_main_debug_log')) {
    function cts_main_debug_log($message) {
        if (WP_DEBUG === true) {
            if (is_array($message) || is_object($message)) {
                error_log(print_r($message, true));
            } else {
                error_log($message);
            }
        }
    }
}

// Check and load required files
$required_files = [
    'includes/class-cts-logger.php',
    'includes/class-cts-cache.php',
    'includes/class-cts-security.php',
    'includes/class-cts-ai-service.php',
    'includes/class-cts-scraper.php',
    'includes/class-cts-admin.php'
];

foreach ($required_files as $file) {
    $file_path = CTS_PLUGIN_DIR . $file;
    if (!file_exists($file_path)) {
        cts_main_debug_log("Missing required file: $file_path");
        return; // Exit if any required file is missing
    }
    require_once $file_path;
}

// Initialize plugin - only define if not already defined
if (!class_exists('CounterTerror_Scraper')) {
    class CounterTerror_Scraper {
        private static $instance = null;
        public $admin;
        public $scraper;
        public $ai_service;
        public $cache;
        public $security;
        public $logger;

        public static function get_instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            try {
                $this->init_components();
                $this->setup_hooks();
            } catch (Exception $e) {
                cts_main_debug_log('Error initializing plugin: ' . $e->getMessage());
            }
        }

        private function init_components() {
            // Initialize components in order with error checking
            try {
                if (!class_exists('CTS_Logger')) {
                    throw new Exception('CTS_Logger class not found');
                }
                $this->logger = new CTS_Logger();

                if (!class_exists('CTS_Cache')) {
                    throw new Exception('CTS_Cache class not found');
                }
                $this->cache = new CTS_Cache();

                if (!class_exists('CTS_Security')) {
                    throw new Exception('CTS_Security class not found');
                }
                $this->security = new CTS_Security();

                if (!class_exists('CTS_AI_Service')) {
                    throw new Exception('CTS_AI_Service class not found');
                }
                $this->ai_service = new CTS_AI_Service($this->cache, $this->logger);

                if (!class_exists('CTS_Scraper')) {
                    throw new Exception('CTS_Scraper class not found');
                }
                $this->scraper = new CTS_Scraper($this->ai_service, $this->cache, $this->logger);

                if (!class_exists('CTS_Admin')) {
                    throw new Exception('CTS_Admin class not found');
                }
                $this->admin = new CTS_Admin($this->scraper, $this->ai_service, $this->security, $this->logger);

            } catch (Exception $e) {
                cts_main_debug_log('Error in init_components: ' . $e->getMessage());
                throw $e;
            }
        }

        private function setup_hooks() {
            register_activation_hook(__FILE__, [$this, 'activate']);
            register_deactivation_hook(__FILE__, [$this, 'deactivate']);
            add_action('plugins_loaded', [$this, 'init']);
        }

        public function activate() {
            if (!current_user_can('activate_plugins')) return;

            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();

            // Create cache table with updated schema
            $cache_table = $wpdb->prefix . 'cts_cache';
            $sql_cache = "CREATE TABLE IF NOT EXISTS $cache_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                cache_key varchar(255) NOT NULL,
                cache_value longtext NOT NULL,
                expiration datetime NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY cache_key (cache_key),
                KEY expiration (expiration)
            ) $charset_collate;";

            // Create logs table
            $logs_table = $wpdb->prefix . 'cts_logs';
            $sql_logs = "CREATE TABLE IF NOT EXISTS $logs_table (
                id BIGINT(20) NOT NULL AUTO_INCREMENT,
                timestamp DATETIME NOT NULL,
                level VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                context TEXT,
                PRIMARY KEY (id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_cache);
            dbDelta($sql_logs);

            // Set default options silently
            $default_options = [
                'cts_sources' => '',
                'cts_keywords' => 'terrorism,terror,attack',
                'cts_openai_api_key' => '',
                'cts_claude_api_key' => '',
                'cts_post_status' => 'draft',
                'cts_summary_length' => '250',
                'cts_auto_fetch' => 0,
                'cts_fetch_time' => '00:00',
                'cts_fetch_days' => [1, 2, 3, 4, 5] // Monday through Friday by default
            ];

            foreach ($default_options as $key => $value) {
                if (false === get_option($key)) {
                    update_option($key, $value);
                }
            }

            // Create custom capabilities
            $role = get_role('administrator');
            if ($role) {
                $role->add_cap('manage_terror_scraper');
            }

            // Set up initial cron schedule if auto-fetch is enabled
            if (get_option('cts_auto_fetch') && isset($this->admin)) {
                $this->admin->update_cron_schedule();
            }

            // Log activation silently
            if (isset($this->logger)) {
                $this->logger->log('Plugin activated successfully');
            }
        }

        public function deactivate() {
            if (!current_user_can('activate_plugins')) return;

            // Clear all our scheduled hooks
            wp_clear_scheduled_hook('cts_scheduled_fetch');
            
            // Remove custom capabilities
            $role = get_role('administrator');
            if ($role) {
                $role->remove_cap('manage_terror_scraper');
            }

            if (isset($this->logger)) {
                $this->logger->log('Plugin deactivated');
            }
        }

        public function init() {
            load_plugin_textdomain('counterterror-scraper', false, dirname(plugin_basename(__FILE__)) . '/languages');
        }
    }
}

// Initialize the plugin - only define if not already defined
if (!function_exists('init_counterterror_scraper')) {
    function init_counterterror_scraper() {
        return CounterTerror_Scraper::get_instance();
    }
}

// Initialize the plugin with error handling
try {
    $GLOBALS['counterterror_scraper'] = init_counterterror_scraper();
} catch (Exception $e) {
    cts_main_debug_log('Error initializing plugin: ' . $e->getMessage());
}