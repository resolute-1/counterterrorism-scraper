<?php
if (!defined('ABSPATH')) exit;

class CTS_Security {
    public function __construct() {
        add_filter('wp_kses_allowed_html', [$this, 'get_custom_allowed_html'], 10, 2);
    }

    public function get_custom_allowed_html($allowed_html, $context) {
        if ($context === 'post') {
            $allowed_html['iframe'] = [
                'src'             => true,
                'height'          => true,
                'width'           => true,
                'frameborder'     => true,
                'allowfullscreen' => true,
            ];
        }
        return $allowed_html;
    }

    public function validate_api_key($key) {
        return !empty($key) && is_string($key) && strlen($key) > 20;
    }

    public function sanitize_feed_url($url) {
        return esc_url_raw($url);
    }

    public function sanitize_keywords($keywords) {
        return sanitize_text_field($keywords);
    }

    public function verify_nonce($nonce, $action) {
        return wp_verify_nonce($nonce, $action);
    }

    public function check_capabilities() {
        return current_user_can('manage_options');
    }
}