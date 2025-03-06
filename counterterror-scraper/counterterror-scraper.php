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
    'includes/class-settings-page.php'  // Keep only this one for settings
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
        public $scraper;
        public $ai_service;
        public $cache;
        public $security;
        public $logger;
        public $settings;

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

                if (!class_exists('Settings_Page')) {
                    throw new Exception('Settings_Page class not found');
                }
                $this->settings = new Settings_Page($this->scraper, $this->ai_service, $this->security, $this->logger);

            } catch (Exception $e) {
                cts_main_debug_log('Error in init_components: ' . $e->getMessage());
                throw $e;
            }
        }

        private function setup_hooks() {
            register_activation_hook(__FILE__, [$this, 'activate']);
            register_deactivation_hook(__FILE__, [$this, 'deactivate']);
            add_action('plugins_loaded', [$this, 'init']);
            
            // Add cron action
            add_action('counterterror_scraper_cron', [$this, 'run_scheduled_scrape']);
        }

        // ... rest of the class methods stay the same ...
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
