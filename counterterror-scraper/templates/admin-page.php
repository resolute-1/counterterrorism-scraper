<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1><?php _e('CounterTerror Scraper Settings', 'counterterror-scraper'); ?></h1>

    <form method="post" action="options.php">
        <?php
        settings_fields('cts_options');
        do_settings_sections('cts_options');
        ?>

        <h2><?php _e('Feed Settings', 'counterterror-scraper'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="cts_sources"><?php _e('RSS Feed Sources', 'counterterror-scraper'); ?></label>
                </th>
                <td>
                    <textarea name="cts_sources" id="cts_sources" rows="5" class="large-text code"><?php echo esc_textarea(get_option('cts_sources')); ?></textarea>
                    <p class="description"><?php _e('Enter one RSS feed URL per line', 'counterterror-scraper'); ?></p>
                    <div class="feed-test-container">
                        <button type="button" class="button" id="test-feed-btn"><?php _e('Test Feeds', 'counterterror-scraper'); ?></button>
                        <div id="feed-test-result"></div>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="cts_keywords"><?php _e('Keywords', 'counterterror-scraper'); ?></label>
                </th>
                <td>
                    <input type="text" name="cts_keywords" id="cts_keywords" value="<?php echo esc_attr(get_option('cts_keywords')); ?>" class="regular-text" />
                    <p class="description"><?php _e('Comma-separated keywords for filtering articles', 'counterterror-scraper'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="cts_summary_length"><?php _e('Summary Length', 'counterterror-scraper'); ?></label>
                </th>
                <td>
                    <input type="number" 
                           name="cts_summary_length" 
                           id="cts_summary_length" 
                           value="<?php echo esc_attr(get_option('cts_summary_length', 700)); ?>" 
                           min="100" 
                           max="700" 
                           step="50" 
                           class="small-text" />
                    <p class="description"><?php _e('Number of words in the article summary (100-700)', 'counterterror-scraper'); ?></p>
                </td>
            </tr>
        </table>

        <div class="cts-section">
            <button type="button" id="fetch-articles-btn" class="button button-primary">
                <?php _e('Fetch Articles', 'counterterror-scraper'); ?>
            </button>
            <div id="fetch-result"></div>
        </div>

        <!-- Rest of the existing code remains unchanged -->
        
        <h2><?php _e('Scheduling Settings', 'counterterror-scraper'); ?></h2>
        <!-- Scheduling settings section remains unchanged -->

        <h2><?php _e('AI Settings', 'counterterror-scraper'); ?></h2>
        <!-- AI settings section remains unchanged -->

        <?php submit_button(); ?>
    </form>
</div>

<style>
.feed-test-container {
    margin-top: 10px;
}

#feed-test-result,
#fetch-result {
    margin-top: 10px;
    padding: 10px;
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
    display: none;
}

.feed-test-result {
    margin: 5px 0;
    padding: 5px;
}

.cts-section {
    padding: 15px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin: 20px 0;
}

.success-message {
    color: #28a745;
}

.error-message {
    color: #dc3545;
}

.day-checkbox {
    margin-right: 15px;
    display: inline-block;
}

/* Add styles for the number input */
input[type="number"].small-text {
    width: 100px;
}
</style>