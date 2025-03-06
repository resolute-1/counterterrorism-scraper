<?php
class CTS_Scraper {
    private $ai_service;
    private $cache;
    private $logger;
    private $max_articles_per_source = 10;
    private $fetch_timeout = 15;

    public function __construct($ai_service, $cache, $logger) {
        $this->ai_service = $ai_service;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function scrape_and_process() {
        $this->logger->log('Starting scraping process');
        
        $sources = $this->get_sources();
        $keywords = $this->get_keywords();
        $articles = [];

        foreach ($sources as $source) {
            try {
                $feed_articles = $this->process_source($source, $keywords);
                $articles = array_merge($articles, $feed_articles);
            } catch (Exception $e) {
                $this->logger->log("Error processing source {$source}: " . $e->getMessage(), 'error');
                continue;
            }
        }

        if (!empty($articles)) {
            $this->create_posts($articles);
        } else {
            $this->logger->log('No relevant articles found');
        }

        return count($articles);
    }

    private function get_sources() {
        $sources = explode("\n", get_option('cts_sources', ''));
        return array_filter(array_map('trim', $sources));
    }

    private function get_keywords() {
        $keywords = explode(',', get_option('cts_keywords', ''));
        return array_filter(array_map('trim', $keywords));
    }

    private function process_source($source, $keywords) {
        $cache_key = 'feed_' . md5($source);
        $cached_feed = $this->cache->get($cache_key);

        if ($cached_feed) {
            return $this->filter_articles($cached_feed, $keywords);
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->fetch_timeout,
                'user_agent' => 'CounterTerror Scraper/2.0 (WordPress Plugin)'
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);

        $xml = @simplexml_load_file($source, null, LIBXML_NOWARNING | LIBXML_NOERROR, null, true, $context);

        if (!$xml) {
            throw new Exception('Failed to load RSS feed');
        }

        $articles = [];
        $count = 0;

        foreach ($xml->channel->item as $item) {
            if ($count >= $this->max_articles_per_source) {
                break;
            }

            $article = [
                'title' => (string)$item->title,
                'content' => strip_tags((string)$item->description),
                'link' => (string)$item->link,
                'pubDate' => (string)$item->pubDate,
                'source_name' => (string)$xml->channel->title,
                'guid' => (string)$item->guid
            ];

            // Check if article was already processed
            if ($this->is_article_processed($article['guid'])) {
                continue;
            }

            $articles[] = $article;
            $count++;
        }

        $this->cache->set($cache_key, $articles, 1800); // Cache for 30 minutes
        return $this->filter_articles($articles, $keywords);
    }

    private function filter_articles($articles, $keywords) {
        return array_filter($articles, function($article) use ($keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($article['title'], $keyword) !== false || 
                    stripos($article['content'], $keyword) !== false) {
                    return true;
                }
            }
            return false;
        });
    }

    private function create_posts($articles) {
        $summary_content = '<h2>Daily Terrorism News Summary - ' . date('F j, Y') . '</h2>';
        
        foreach ($articles as $article) {
            // Generate AI summary
            $summary = $this->ai_service->generate_summary($article['content'], $article['title']);
            
            // Create individual post
            $post_content = $this->format_post_content($article, $summary);
            
            $post_id = wp_insert_post([
                'post_title' => sanitize_text_field($article['title']),
                'post_content' => wp_kses_post($post_content),
                'post_status' => get_option('cts_post_status', 'draft'),
                'post_type' => 'post',
                'post_date' => date('Y-m-d H:i:s', strtotime($article['pubDate']))
            ]);

            if ($post_id) {
                // Add source metadata
                add_post_meta($post_id, '_cts_source_url', esc_url($article['link']));
                add_post_meta($post_id, '_cts_source_name', sanitize_text_field($article['source_name']));
                add_post_meta($post_id, '_cts_original_guid', sanitize_text_field($article['guid']));
                
                // Add to summary
                $summary_content .= $this->format_summary_entry($article, $summary);
                
                $this->mark_article_processed($article['guid']);
            } else {
                $this->logger->log("Failed to create post for article: {$article['title']}", 'error');
            }
        }

        // Create summary post
        wp_insert_post([
            'post_title' => 'Terrorism News Summary - ' . date('F j, Y'),
            'post_content' => wp_kses_post($summary_content),
            'post_status' => get_option('cts_post_status', 'draft'),
            'post_type' => 'post'
        ]);
    }

    private function format_post_content($article, $summary) {
        return sprintf(
            '<div class="article-summary">
                <p>%s</p>
                <div class="article-metadata">
                    <p class="source-link">Originally published by <a href="%s" target="_blank" rel="nofollow">%s</a> on %s</p>
                    <p class="read-more"><a href="%s" target="_blank" rel="nofollow">Read the full article →</a></p>
                </div>
                <div class="disclaimer">
                    <p><em>This is an AI-generated summary for informational purposes. 
                    Visit the source website for the complete article.</em></p>
                </div>
            </div>',
            esc_html($summary),
            esc_url($article['link']),
            esc_html($article['source_name']),
            esc_html(date('F j, Y', strtotime($article['pubDate']))),
            esc_url($article['link'])
        );
    }

    private function format_summary_entry($article, $summary) {
        return sprintf(
            '<div class="summary-entry">
                <h3><a href="%s" target="_blank" rel="nofollow">%s</a></h3>
                <p>%s</p>
                <p class="source-info">Source: %s | %s</p>
            </div>',
            esc_url($article['link']),
            esc_html($article['title']),
            esc_html(wp_trim_words($summary, 30)),
            esc_html($article['source_name']),
            esc_html(date('F j, Y', strtotime($article['pubDate'])))
        );
    }

    private function is_article_processed($guid) {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cts_original_guid' AND meta_value = %s",
                $guid
            )
        );
    }

    private function mark_article_processed($guid) {
        $this->cache->set('processed_' . md5($guid), true, 86400 * 30); // Store for 30 days
    }

    public function test_source($source) {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'user_agent' => 'CounterTerror Scraper/2.0 (WordPress Plugin)'
                ]
            ]);

            $xml = @simplexml_load_file($source, null, LIBXML_NOWARNING | LIBXML_NOERROR, null, true, $context);

            if (!$xml) {
                return [
                    'success' => false,
                    'message' => 'Failed to load RSS feed'
                ];
            }

            $item_count = count($xml->channel->item);
            return [
                'success' => true,
                'message' => "Successfully loaded feed ({$item_count} items found)",
                'feed_title' => (string)$xml->channel->title
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}