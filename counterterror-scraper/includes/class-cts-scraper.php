<?php
if (!defined('ABSPATH')) exit;

class CTS_Scraper {
    private $ai_service;
    private $cache;
    private $logger;
    private $max_articles_per_source = 10;
    private $fetch_timeout = 60;

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
        try {
            $this->logger->log('Starting scraping process');
            
            $sources = $this->get_sources();
            if (empty($sources)) {
                throw new Exception('No sources configured');
            }
            
            $keywords = $this->get_keywords();
            if (empty($keywords)) {
                throw new Exception('No keywords configured');
            }
            
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
        } catch (Exception $e) {
            $this->logger->log('Error in scrape_and_process: ' . $e->getMessage(), 'error');
            throw $e;
        }
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
        
        require_once(ABSPATH . WPINC . '/feed.php');
        $rss = fetch_feed($source);
        
        if (is_wp_error($rss)) {
            $this->logger->log('Error fetching feed: ' . $rss->get_error_message(), 'error');
            return array();
        }

        $maxitems = $rss->get_item_quantity($this->max_articles_per_source);
        $this->logger->log('Found ' . $maxitems . ' items in feed');
        
        $rss_items = $rss->get_items(0, $maxitems);
        $articles = array();

        foreach ($rss_items as $item) {
            $article = array(
                'title' => $item->get_title(),
                'content' => strip_tags($item->get_content() ?: $item->get_description()),
                'link' => $item->get_permalink(),
                'pubDate' => $item->get_date('Y-m-d H:i:s'),
                'source_name' => $rss->get_title(),
                'guid' => $item->get_id() ?: $item->get_permalink()
            );

            $this->logger->log('Found article: ' . $article['title']);
            $articles[] = $article;
        }

        if (!empty($articles)) {
            $this->cache->set($cache_key, $articles, 1800); // Cache for 30 minutes
            $this->logger->log('Successfully loaded and cached ' . count($articles) . ' articles from feed');
        }

        $filtered = $this->filter_articles($articles, $keywords);
        $this->logger->log('After filtering, found ' . count($filtered) . ' matching articles');
        
        return $filtered;
    }

    private function filter_articles($articles, $keywords) {
        $this->logger->log('Filtering ' . count($articles) . ' articles with keywords: ' . implode(', ', $keywords));
        $filtered = array_filter($articles, function($article) use ($keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($article['title'], $keyword) !== false || 
                    stripos($article['content'], $keyword) !== false) {
                    $this->logger->log('Article matched keyword "' . $keyword . '": ' . $article['title']);
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
            if ($this->is_article_processed($article['guid'])) {
                continue;
            }

            $summary = $this->ai_service->generate_summary($article['content'], $article['title']);
            
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
