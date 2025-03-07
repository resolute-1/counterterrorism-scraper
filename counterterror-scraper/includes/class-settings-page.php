<?php
if (!defined('ABSPATH')) exit;

class Settings_Page {
    private $options;
    private $plugin_slug = 'counterterror-scraper';
    private $scraper;
    private $ai_service;
    private $security;
    private $logger;

    public function __construct($scraper = null, $ai_service = null, $security = null, $logger = null) {
        $this->scraper = $scraper;
        $this->ai_service = $ai_service;
        $this->security = $security;
        $this->logger = $logger;

        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Add AJAX handlers
        add_action('wp_ajax_cts_test_feed', array($this, 'ajax_test_feed'));
        add_action('wp_ajax_cts_test_ai', array($this, 'ajax_test_ai'));
        add_action('wp_ajax_cts_fetch_articles', array($this, 'ajax_fetch_articles'));
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, $this->plugin_slug) === false) {
            return;
        }

        wp_enqueue_style(
            'cts-admin-styles',
            plugins_url('assets/css/cts-admin.css', dirname(__FILE__)),
            array(),
            CTS_VERSION
        );

        wp_enqueue_script(
            'cts-admin-script',
            plugins_url('assets/js/cts-admin.js', dirname(__FILE__)),
            array('jquery'),
            CTS_VERSION,
            true
        );

        wp_localize_script('cts-admin-script', 'ctsAdmin', array(
            'nonce' => wp_create_nonce('cts-admin-nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }

    public function add_plugin_page() {
        // Only add the main menu page, not an options page
        add_menu_page(
            'CounterTerror Scraper',           // Page title
            'CT Scraper',                      // Menu title
            'manage_options',                  // Capability
            $this->plugin_slug,               // Menu slug
            array($this, 'create_admin_page'), // Callback function
            'dashicons-rss',                   // Icon
            30                                 // Position
        );
    }

    public function create_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sorry, you do not have sufficient permissions to access this page.'));
        }
        ?>
        <div class="wrap">
            <h1>CounterTerror Scraper Settings</h1>
            <div class="actions" style="margin-bottom: 20px;">
                <button id="test-feeds" class="button">Test Feeds</button>
                <button id="fetch-articles" class="button-primary">Fetch Articles Now</button>
            </div>
            <form method="post" action="options.php">
                <?php
                settings_fields('counterterror_scraper_settings');
                do_settings_sections('counterterror_scraper_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        // Register settings
        register_setting('counterterror_scraper_settings', 'cts_sources');
        register_setting('counterterror_scraper_settings', 'cts_keywords');
        register_setting('counterterror_scraper_settings', 'cts_openai_api_key');
        register_setting('counterterror_scraper_settings', 'cts_claude_api_key');
        register_setting('counterterror_scraper_settings', 'cts_summary_length', array($this, 'sanitize_summary_length'));
        register_setting('counterterror_scraper_settings', 'cts_schedule_days');
        register_setting('counterterror_scraper_settings', 'cts_schedule_time');
        
        // General Settings Section
        add_settings_section(
            'cts_general_section',
            'General Settings',
            array($this, 'general_section_callback'),
            'counterterror_scraper_settings'
        );

        // Schedule Settings Section
        add_settings_section(
            'cts_schedule_section',
            'Schedule Settings',
            array($this, 'schedule_section_callback'),
            'counterterror_scraper_settings'
        );

        // Add fields
        add_settings_field(
            'cts_sources',
            'RSS Feed URLs',
            array($this, 'rss_feeds_callback'),
            'counterterror_scraper_settings',
            'cts_general_section'
        );

        add_settings_field(
            'cts_keywords',
            'Keywords',
            array($this, 'keywords_callback'),
            'counterterror_scraper_settings',
            'cts_general_section'
        );

        add_settings_field(
            'cts_openai_api_key',
            'OpenAI API Key',
            array($this, 'openai_key_callback'),
            'counterterror_scraper_settings',
            'cts_general_section'
        );

        add_settings_field(
            'cts_claude_api_key',
            'Claude API Key',
            array($this, 'claude_key_callback'),
            'counterterror_scraper_settings',
            'cts_general_section'
        );

        add_settings_field(
            'cts_summary_length',
            'Summary Length (words)',
            array($this, 'summary_length_callback'),
            'counterterror_scraper_settings',
            'cts_general_section'
        );

        add_settings_field(
            'cts_schedule_days',
            'Run on Days',
            array($this, 'schedule_days_callback'),
            'counterterror_scraper_settings',
            'cts_schedule_section'
        );

        add_settings_field(
            'cts_schedule_time',
            'Run at Time',
            array($this, 'schedule_time_callback'),
            'counterterror_scraper_settings',
            'cts_schedule_section'
        );
    }

    public function sanitize_summary_length($length) {
        $length = intval($length);
        if ($length < 100) return 100;  // Minimum 100 words
        if ($length > 700) return 700;  // Maximum 700 words
        return $length;
    }

    public function general_section_callback() {
        echo '<p>Configure your scraper settings</p>';
    }

    public function schedule_section_callback() {
        echo '<p>Configure when the scraper should automatically run</p>';
    }

    public function rss_feeds_callback() {
        $feeds = get_option('cts_sources', '');
        echo "<textarea name='cts_sources' rows='5' cols='50'>" . esc_textarea($feeds) . "</textarea>";
        echo "<p class='description'>Enter one RSS feed URL per line</p>";
    }

    public function keywords_callback() {
        $keywords = get_option('cts_keywords', '');
        echo "<textarea name='cts_keywords' rows='5' cols='50'>" . esc_textarea($keywords) . "</textarea>";
        echo "<p class='description'>Enter keywords separated by commas</p>";
    }

    public function openai_key_callback() {
        $key = get_option('cts_openai_api_key', '');
        echo "<input type='password' name='cts_openai_api_key' value='" . esc_attr($key) . "' size='50'>";
        echo "<button type='button' class='button test-ai' data-service='openai'>Test OpenAI Connection</button>";
    }

    public function claude_key_callback() {
        $key = get_option('cts_claude_api_key', '');
        echo "<input type='password' name='cts_claude_api_key' value='" . esc_attr($key) . "' size='50'>";
        echo "<button type='button' class='button test-ai' data-service='claude'>Test Claude Connection</button>";
    }

    public function summary_length_callback() {
        $length = get_option('cts_summary_length', '300');
        echo "<input type='number' name='cts_summary_length' value='" . esc_attr($length) . "' min='100' max='700'>";
        echo "<p class='description'>Enter a value between 100 and 700 words</p>";
    }

    public function schedule_days_callback() {
        $days = get_option('cts_schedule_days', array());
        $weekdays = array(
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday'
        );
        
        foreach ($weekdays as $value => $label) {
            $checked = in_array($value, (array)$days) ? 'checked' : '';
            echo "<label style='margin-right: 15px;'>";
            echo "<input type='checkbox' name='cts_schedule_days[]' value='$value' $checked>";
            echo "$label</label>";
        }
    }

    public function schedule_time_callback() {
        $time = get_option('cts_schedule_time', '00:00');
        echo "<input type='time' name='cts_schedule_time' value='$time'>";
    }

    // Add AJAX handlers from CTS_Admin
    public function ajax_test_feed() {
        try {
            check_ajax_referer('cts-admin-nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
                return;
            }

            // Get all feeds from settings instead of from POST
            $feeds = get_option('cts_sources', '');
            if (empty($feeds)) {
                wp_send_json_error('No feeds configured');
                return;
            }

            $feed_array = array_filter(array_map('trim', explode("\n", $feeds)));
            $results = array();
            $has_error = false;

            require_once(ABSPATH . WPINC . '/feed.php');
            
            foreach ($feed_array as $feed_url) {
                $rss = fetch_feed($feed_url);
                
                if (is_wp_error($rss)) {
                    $results[] = sprintf(
                        '❌ Error fetching %s: %s',
                        $feed_url,
                        $rss->get_error_message()
                    );
                    $has_error = true;
                    continue;
                }

                $feed_title = $rss->get_title();
                $item_count = $rss->get_item_quantity();
                
                $results[] = sprintf(
                    '✅ Successfully connected to %s: %s (Found %d items)',
                    $feed_url,
                    esc_html($feed_title),
                    $item_count
                );
            }

            if ($has_error) {
                wp_send_json_error(implode("\n", $results));
            } else {
                wp_send_json_success(implode("\n", $results));
            }

        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function ajax_test_ai() {
        try {
            check_ajax_referer('cts-admin-nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
                return;
            }

            $service = isset($_POST['service']) ? $_POST['service'] : '';
            
            if (!in_array($service, array('openai', 'claude'))) {
                wp_send_json_error('Invalid AI service specified');
                return;
            }

            $test_content = "This is a test article. Please summarize it to verify the API connection is working.";
            
            if ($service === 'openai') {
                $api_key = get_option('cts_openai_api_key');
                if (empty($api_key)) {
                    wp_send_json_error('OpenAI API key is not configured');
                    return;
                }
                $summary = $this->ai_service->try_openai_summary($test_content, 100);
            } else {
                $api_key = get_option('cts_claude_api_key');
                if (empty($api_key)) {
                    wp_send_json_error('Claude API key is not configured');
                    return;
                }
                $summary = $this->ai_service->try_claude_summary($test_content, 100);
            }

            if ($summary) {
                wp_send_json_success(sprintf(
                    '%s API connection successful. Test summary generated.',
                    ucfirst($service)
                ));
            } else {
                wp_send_json_error(sprintf(
                    'Failed to generate test summary using %s API',
                    ucfirst($service)
                ));
            }

        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function ajax_fetch_articles() {
        try {
            check_ajax_referer('cts-admin-nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
                return;
            }

            if (!isset($this->scraper)) {
                wp_send_json_error('Scraper not initialized');
                return;
            }

            // Get settings
            $feeds = get_option('cts_sources', '');
            $keywords = get_option('cts_keywords', '');
            $summary_length = get_option('cts_summary_length', 700);

            if (empty($feeds) || empty($keywords)) {
                wp_send_json_error('Missing feeds or keywords in settings');
                return;
            }

            $feed_array = array_filter(array_map('trim', explode("\n", $feeds)));
            $keyword_array = array_filter(array_map('trim', explode(',', $keywords)));

            $created = 0;
            $skipped = 0;

            foreach ($feed_array as $feed_url) {
                if ($this->scraper) {
                    $result = $this->scraper->process_feed($feed_url, $keyword_array);
                    if (is_array($result)) {
                        $created += $result['created'];
                        $skipped += $result['skipped'];
                    }
                }
            }

            wp_send_json_success(array(
                'message' => sprintf(
                    'Processing complete. Created %d articles, skipped %d items.',
                    $created,
                    $skipped
                ),
                'created' => $created,
                'skipped' => $skipped
            ));

        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
}

// Do NOT initialize here anymore since we're passing dependencies
// The main plugin file will handle initialization 
