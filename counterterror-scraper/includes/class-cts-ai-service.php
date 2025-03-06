<?php
class CTS_AI_Service {
    private $cache;
    private $logger;
    private $rate_limiter = [];

    public function __construct($cache, $logger) {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function summarize_article($content, $word_limit = 700) {
        // Sanitize and validate word limit
        $word_limit = min(max(intval($word_limit), 100), 700);
        
        // Clean up content
        $content = $this->clean_content($content);
        
        // Check cache first
        $cache_key = md5($content . $word_limit);
        $cached_summary = $this->cache->get('summary_' . $cache_key);
        if ($cached_summary) {
            return $cached_summary;
        }

        // Try each AI service in order
        $summary = $this->try_openai_summary($content, $word_limit);
        if (!$summary) {
            $summary = $this->try_claude_summary($content, $word_limit);
        }
        if (!$summary) {
            $summary = $this->fallback_summary($content, $word_limit);
        }

        // Cache the result
        if ($summary) {
            $this->cache->set('summary_' . $cache_key, $summary, 86400); // 24 hours
        }

        return $summary;
    }

    private function clean_content($content) {
        // Remove HTML tags
        $content = wp_strip_all_tags($content);
        // Remove extra whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        // Remove special characters
        $content = preg_replace('/[^\p{L}\p{N}\s\.\,\!\?\-\'\"]/u', '', $content);
        return trim($content);
    }

    private function try_openai_summary($content, $word_limit) {
        if (!$this->check_rate_limit('openai')) {
            return false;
        }

        $api_key = get_option('cts_openai_api_key');
        if (empty($api_key)) {
            return false;
        }

        try {
            $prompt = sprintf(
                "Summarize the following article in approximately %d words. Focus on key facts and maintain journalistic objectivity:\n\n%s",
                $word_limit,
                $content
            );

            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a professional news summarizer. Create clear, accurate, and objective summaries.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.3,
                    // Convert words to tokens (approximate)
                    'max_tokens' => min($word_limit * 1.5, 1000),
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['choices'][0]['message']['content'])) {
                $this->update_rate_limit('openai');
                return $this->format_summary($body['choices'][0]['message']['content'], $word_limit);
            }

            throw new Exception('Invalid response format from OpenAI');
        } catch (Exception $e) {
            $this->logger->log('OpenAI Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    private function try_claude_summary($content, $word_limit) {
        if (!$this->check_rate_limit('claude')) {
            return false;
        }

        $api_key = get_option('cts_claude_api_key');
        if (empty($api_key)) {
            return false;
        }

        try {
            $prompt = sprintf(
                "Summarize the following article in approximately %d words. Focus on key facts and maintain journalistic objectivity:\n\n%s",
                $word_limit,
                $content
            );

            $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'body' => json_encode([
                    'model' => 'claude-3-sonnet-20240229',
                    'max_tokens' => min($word_limit * 2, 1500),
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ]
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['content'][0]['text'])) {
                $this->update_rate_limit('claude');
                return $this->format_summary($body['content'][0]['text'], $word_limit);
            }

            throw new Exception('Invalid response format from Claude');
        } catch (Exception $e) {
            $this->logger->log('Claude Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    private function fallback_summary($content, $word_limit) {
        $this->logger->log('Using fallback summarization method', 'notice');
        
        // Split into sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);
        
        // Initialize summary
        $summary = '';
        $word_count = 0;
        
        // Add sentences until we reach the word limit
        foreach ($sentences as $sentence) {
            $sentence_words = str_word_count($sentence);
            if ($word_count + $sentence_words > $word_limit) {
                break;
            }
            $summary .= $sentence . ' ';
            $word_count += $sentence_words;
        }
        
        return trim($summary);
    }

    private function format_summary($summary, $word_limit) {
        // Clean the summary
        $summary = $this->clean_content($summary);
        
        // Ensure it's not too long
        $words = explode(' ', $summary);
        if (count($words) > $word_limit) {
            $words = array_slice($words, 0, $word_limit);
            $summary = implode(' ', $words);
            
            // Ensure it ends with proper punctuation
            $summary = rtrim($summary, ',;:-');
            if (!preg_match('/[.!?]$/', $summary)) {
                $summary .= '.';
            }
        }
        
        return $summary;
    }

    private function check_rate_limit($service) {
        $limits = [
            'openai' => ['requests' => 3, 'window' => 60], // 3 requests per minute
            'claude' => ['requests' => 2, 'window' => 60], // 2 requests per minute
        ];

        if (!isset($this->rate_limiter[$service])) {
            $this->rate_limiter[$service] = [
                'count' => 0,
                'window_start' => time()
            ];
            return true;
        }

        $limiter = &$this->rate_limiter[$service];
        $current_time = time();

        if ($current_time - $limiter['window_start'] > $limits[$service]['window']) {
            $limiter['count'] = 0;
            $limiter['window_start'] = $current_time;
            return true;
        }

        if ($limiter['count'] >= $limits[$service]['requests']) {
            $this->logger->log("Rate limit reached for $service", 'notice');
            return false;
        }

        return true;
    }

    private function update_rate_limit($service) {
        if (!isset($this->rate_limiter[$service])) {
            $this->rate_limiter[$service] = [
                'count' => 0,
                'window_start' => time()
            ];
        }
        $this->rate_limiter[$service]['count']++;
    }
}