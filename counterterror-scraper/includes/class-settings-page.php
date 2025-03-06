<?php

class Settings_Page {
    private $options;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_plugin_page() {
        add_options_page(
            'Counterterror Scraper Settings',
            'Counterterror Scraper',
            'manage_options',
            'counterterror-scraper',
            array($this, 'create_admin_page')
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h2>Counterterror Scraper Settings</h2>
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
        register_setting('counterterror_scraper_settings', 'cts_rss_feeds');
        register_setting('counterterror_scraper_settings', 'cts_keywords');
        register_setting('counterterror_scraper_settings', 'cts_openai_key');
        register_setting('counterterror_scraper_settings', 'cts_summary_length');
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
            'cts_rss_feeds',
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
            'cts_openai_key',
            'OpenAI API Key',
            array($this, 'openai_key_callback'),
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

    public function general_section_callback() {
        echo '<p>Configure your scraper settings</p>';
    }

    public function schedule_section_callback() {
        echo '<p>Configure when the scraper should automatically run</p>';
    }

    public function rss_feeds_callback() {
        $feeds = get_option('cts_rss_feeds', '');
        echo "<textarea name='cts_rss_feeds' rows='5' cols='50'>" . esc_textarea($feeds) . "</textarea>";
        echo "<p class='description'>Enter one RSS feed URL per line</p>";
    }

    public function keywords_callback() {
        $keywords = get_option('cts_keywords', '');
        echo "<textarea name='cts_keywords' rows='5' cols='50'>" . esc_textarea($keywords) . "</textarea>";
        echo "<p class='description'>Enter one keyword per line</p>";
    }

    public function openai_key_callback() {
        $key = get_option('cts_openai_key', '');
        echo "<input type='text' name='cts_openai_key' value='" . esc_attr($key) . "' size='50'>";
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
}

// Initialize the settings page
new Settings_Page(); 