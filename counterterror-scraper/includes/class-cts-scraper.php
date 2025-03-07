<?php
if (!defined('ABSPATH')) exit;

class CTS_Scraper {
    private $ai_service;
    private $cache;
    private $logger;
    private $max_articles_per_source = 10;
    private $fetch_timeout = 30;

    public function __construct($ai_service, $cache, $logger) {
        $this->ai_service = $ai_service;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->logger->log('Initializing scraper');
        
        if ($this->cache) {
            $this->logger->log('Using provided cache instance');
        }
    }

    public function scrape_and_process() {
        $this->logger->log('Starting scraping process');
        
        $sources = $this->get_sources();
        $keywords = $this->get_keywords();
        
        $this->logger->log('Found sources: ' . print_r($sources, true));
        $this->logger->log('Found keywords: ' . print_r($keywords, true));
        
        $articles = [];

        foreach ($sources as $source) {
            try {
                $this->logger->log('Processing source: ' . $source);
                $feed_articles = $this->process_source($source, $keywords);
                $this->logger->log('Found ' . count($feed_articles) . ' matching articles from source');
                $articles = array_merge($articles, $feed_articles);
            } catch (Exception $e) {
                $this->logger->log("Error processing source {$source}: " . $e->getMessage(), 'error');
                continue;
            }
        }

        $this->logger->log('Total matching articles found: ' . count($articles));

        if (!empty($articles)) {
            $this->create_posts($articles);
        } else {
            $this->logger->log('No relevant articles found');
        }

        return count($articles);
    }

    private function get_sources() {
        $sources_option = get_option('cts_sources', '');
        if (empty($sources_option)) {
            $this->logger->log('No sources found in options');
            return array();
        }

        $sources = array_filter(array_map('trim', explode("\n", $sources_option)));
        if (empty($sources)) {
            $this->logger->log('No valid sources after filtering');
            return array();
        }

        $this->logger->log('Found ' . count($sources) . ' sources to process');
        return $sources;
    }

    private function get_keywords() {
        $keywords = get_option('cts_keywords', '');
        return array_filter(array_map('trim', explode(',', $keywords)));
    }

    private function process_source($source, $keywords) {
        $this->logger->log('Processing source: ' . $source);
        $cache_key = 'feed_' . md5($source);
        $cached_feed = $this->cache->get($cache_key);

        if ($cached_feed) {
            $this->logger->log('Using cached feed data');
            return $this->filter_articles($cached_feed, $keywords);
        }

        $this->logger->log('Fetching fresh feed data');
        
        // Add user agent and increase timeout
        $args = array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        );
        
        // Use wp_remote_get instead of simplexml_load_file
        $response = wp_remote_get($source, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log('Error fetching feed: ' . $response->get_error_message(), 'error');
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            $this->logger->log('Empty response from feed', 'error');
            return array();
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        
        if (!$xml) {
            $this->logger->log('Failed to parse XML feed', 'error');
            return array();
        }

        $articles = array();
        $count = 0;

        foreach ($xml->channel->item as $item) {
            if ($count >= $this->max_articles_per_source) {
                break;
            }

            $article = array(
                'title' => (string)$item->title,
                'content' => strip_tags((string)$item->description),
                'link' => (string)$item->link,
                'pubDate' => (string)$item->pubDate,
                'source_name' => (string)$xml->channel->title,
                'guid' => (string)$item->guid ?: (string)$item->link
            );

            $articles[] = $article;
            $count++;
        }

        if (!empty($articles)) {
            $this->cache->set($cache_key, $articles, 1800); // Cache for 30 minutes
        }

        return $this->filter_articles($articles, $keywords);
    }

    private function filter_articles($articles, $keywords) {
        $this->logger->log('Filtering ' . count($articles) . ' articles with keywords: ' . implode(', ', $keywords));
        $filtered = array_filter($articles, function($article) use ($keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($article['title'], $keyword) !== false || 
                    stripos($article['content'], $keyword) !== false) {
                    return true;
                }
            }
            return false;
        });
        $this->logger->log('Found ' . count($filtered) . ' matching articles after filtering');
        return $filtered;
    }

    private function create_posts($articles) {
        foreach ($articles as $article) {
            // Skip if already processed
            if ($this->is_article_processed($article['guid'])) {
                continue;
            }

            // Generate AI summary
            $summary = $this->ai_service->generate_summary($article['content'], $article['title']);
            
            // Create post
            $post_id = wp_insert_post(array(
                'post_title' => sanitize_text_field($article['title']),
                'post_content' => wp_kses_post($this->format_post_content($article, $summary)),
                'post_status' => 'draft',
                'post_type' => 'post',
                'post_date' => date('Y-m-d H:i:s', strtotime($article['pubDate']))
            ));

            if ($post_id) {
                add_post_meta($post_id, '_cts_source_url', esc_url($article['link']));
                add_post_meta($post_id, '_cts_source_name', sanitize_text_field($article['source_name']));
                add_post_meta($post_id, '_cts_original_guid', sanitize_text_field($article['guid']));
                $this->mark_article_processed($article['guid']);
            }
        }
    }

    private function format_post_content($article, $summary) {
        return sprintf(
            '<div class="article-summary">
                <p>%s</p>
                <div class="article-metadata">
                    <p class="source-link">Originally published by <a href="%s" target="_blank" rel="nofollow">%s</a></p>
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
            esc_url($article['link'])
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
} 
