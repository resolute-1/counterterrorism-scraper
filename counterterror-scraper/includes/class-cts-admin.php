<?php
if (!defined('ABSPATH')) exit;

class CTS_Admin {
    private $scraper;
    private $ai_service;
    private $security;
    private $logger;
    private $cron_hook = 'cts_scheduled_fetch';
    private $plugin_slug = 'counterterror-scraper';

    public function __construct($scraper = null, $ai_service = null, $security = null, $logger = null) {
        $this->scraper = $scraper;
        $this->ai_service = $ai_service;
        $this->security = $security;
        $this->logger = $logger;

        // Remove the menu and settings registration since it's handled by Settings_Page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // Add AJAX handlers
        add_action('wp_ajax_cts_test_feed', array($this, 'ajax_test_feed'));
        add_action('wp_ajax_cts_test_ai', array($this, 'ajax_test_ai'));
        add_action('wp_ajax_cts_fetch_articles', array($this, 'ajax_fetch_articles'));

        // Add cron handler
        add_action($this->cron_hook, array($this, 'scheduled_fetch_articles'));
        
        // Add settings update handler
        add_action('update_option_cts_auto_fetch', array($this, 'update_cron_schedule'));
        add_action('update_option_cts_fetch_frequency', array($this, 'update_cron_schedule'));
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

    public function ajax_test_feed() {
        try {
            check_ajax_referer('cts-admin-nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
                return;
            }

            $feed_url = isset($_POST['feed_url']) ? trim($_POST['feed_url']) : '';
            
            if (empty($feed_url)) {
                wp_send_json_error('Feed URL is required');
                return;
            }

            require_once(ABSPATH . WPINC . '/feed.php');
            $rss = fetch_feed($feed_url);
            
            if (is_wp_error($rss)) {
                wp_send_json_error('Error fetching feed: ' . $rss->get_error_message());
                return;
            }

            $feed_title = $rss->get_title();
            $item_count = $rss->get_item_quantity();
            
            wp_send_json_success(sprintf(
                'Successfully connected to feed: %s (Found %d items)',
                esc_html($feed_title),
                $item_count
            ));

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

            $feeds = isset($_POST['feeds']) ? $_POST['feeds'] : '';
            $keywords = isset($_POST['keywords']) ? $_POST['keywords'] : '';
            $summary_length = get_option('cts_summary_length', 700);

            if (empty($feeds) || empty($keywords)) {
                wp_send_json_error('Missing feeds or keywords');
                return;
            }

            $feed_array = array_filter(array_map('trim', explode("\n", $feeds)));
            $keyword_array = array_filter(array_map('trim', explode(',', $keywords)));

            $created = 0;
            $skipped = 0;

            require_once(ABSPATH . WPINC . '/feed.php');
            
            foreach ($feed_array as $feed_url) {
                $rss = fetch_feed($feed_url);
                
                if (is_wp_error($rss)) {
                    continue;
                }

                $feed_title = $rss->get_title();
                $feed_link = $rss->get_permalink();
                $items = $rss->get_items(0, 10);
                
                foreach ($items as $item) {
                    $content = strtolower($item->get_title() . ' ' . $item->get_description());
                    $found_match = false;
                    
                    foreach ($keyword_array as $keyword) {
                        if (strpos($content, strtolower($keyword)) !== false) {
                            $found_match = true;
                            break;
                        }
                    }

                    if (!$found_match) {
                        $skipped++;
                        continue;
                    }

                    $title = $item->get_title();
                    $original_content = $item->get_content();
                    $author = $item->get_author();
                    $author_name = $author ? $author->get_name() : 'Unknown Author';
                    $pub_date = $item->get_date('F j, Y');
                    $original_url = $item->get_permalink();

                    // Generate summary
                    $summary = $this->ai_service->summarize_article($original_content, $summary_length);
                    if (empty($summary)) {
                        $summary = wp_trim_words(strip_tags($original_content), $summary_length);
                    }

                    // Prepare content with attribution
                    $final_content = sprintf(
                        '<div class="article-summary">%s</div>
                        <hr />
                        <div class="article-attribution">
                            <p>This article summary was created from content originally published by 
                            <a href="%s" target="_blank">%s</a> on %s.</p>
                            <p><strong>Author:</strong> %s</p>
                            <p><strong>Original Article:</strong> <a href="%s" target="_blank">Read the full article here</a></p>
                        </div>',
                        wpautop($summary),
                        esc_url($feed_link),
                        esc_html($feed_title),
                        esc_html($pub_date),
                        esc_html($author_name),
                        esc_url($original_url)
                    );

                    $post_data = array(
                        'post_title'    => wp_strip_all_tags($title),
                        'post_content'  => $final_content,
                        'post_status'   => 'draft',
                        'post_author'   => get_current_user_id(),
                        'post_type'     => 'post',
                        'meta_input'    => array(
                            'cts_source_url'   => $original_url,
                            'cts_source_feed'  => $feed_url,
                            'cts_source_title' => $feed_title,
                            'cts_author_name'  => $author_name,
                            'cts_pub_date'     => $pub_date
                        )
                    );

                    $post_id = wp_insert_post($post_data);
                    
                    if (!is_wp_error($post_id)) {
                        $category_id = $this->get_or_create_category($feed_title);
                        wp_set_post_categories($post_id, array($category_id));
                        $created++;
                    }
                }
            }

            wp_send_json_success(array(
                'created' => $created,
                'skipped' => $skipped
            ));

        } catch (Exception $e) {
            error_log('CTS Error: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    private function get_or_create_category($category_name) {
        $category = get_category_by_slug(sanitize_title($category_name));
        
        if ($category) {
            return $category->term_id;
        }
        
        $new_category = wp_insert_category(array(
            'cat_name' => $category_name,
            'category_description' => 'Articles automatically scraped from RSS feeds',
            'category_nicename' => sanitize_title($category_name)
        ));
        
        return $new_category;
    }

    public function update_cron_schedule() {
        $auto_fetch = get_option('cts_auto_fetch');
        $frequency = get_option('cts_fetch_frequency', 'daily');

        // Clear existing schedule
        wp_clear_scheduled_hook($this->cron_hook);

        // Set up new schedule if enabled
        if ($auto_fetch) {
            if (!wp_next_scheduled($this->cron_hook)) {
                wp_schedule_event(time(), $frequency, $this->cron_hook);
            }
        }
    }

    public function scheduled_fetch_articles() {
        $feeds = get_option('cts_sources', '');
        $keywords = get_option('cts_keywords', '');
        
        if (empty($feeds) || empty($keywords)) {
            if ($this->logger) {
                $this->logger->log('Scheduled fetch skipped - missing feeds or keywords');
            }
            return;
        }

        try {
            $feed_array = array_filter(array_map('trim', explode("\n", $feeds)));
            $keyword_array = array_filter(array_map('trim', explode(',', $keywords)));

            foreach ($feed_array as $feed_url) {
                if ($this->scraper) {
                    $this->scraper->process_feed($feed_url, $keyword_array);
                }
            }

            if ($this->logger) {
                $this->logger->log('Scheduled fetch completed successfully');
            }
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->log('Scheduled fetch error: ' . $e->getMessage(), 'error');
            }
        }
    }
} 
