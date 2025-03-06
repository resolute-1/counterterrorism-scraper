<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?php _e('CounterTerror Scraper Status', 'counterterror-scraper'); ?></h1>

    <div class="cts-status-grid">
        <div class="cts-status-box">
            <h2><?php _e('System Information', 'counterterror-scraper'); ?></h2>
            <table class="widefat">
                <tr>
                    <td><?php _e('PHP Version', 'counterterror-scraper'); ?></td>
                    <td>
                        <span class="<?php echo version_compare(PHP_VERSION, '7.4', '>=') ? 'status-good' : 'status-bad'; ?>">
                            <?php echo PHP_VERSION; ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('WordPress Version', 'counterterror-scraper'); ?></td>
                    <td><?php echo $status['wp_version']; ?></td>
                </tr>
                <tr>
                    <td><?php _e('Plugin Version', 'counterterror-scraper'); ?></td>
                    <td><?php echo $status['plugin_version']; ?></td>
                </tr>
                <tr>
                    <td><?php _e('Memory Limit', 'counterterror-scraper'); ?></td>
                    <td><?php echo $status['memory_limit']; ?></td>
                </tr>
                <tr>
                    <td><?php _e('Max Execution Time', 'counterterror-scraper'); ?></td>
                    <td><?php echo $status['max_execution_time']; ?> seconds</td>
                </tr>
            </table>
        </div>

        <div class="cts-status-box">
            <h2><?php _e('API Status', 'counterterror-scraper'); ?></h2>
            <table class="widefat">
                <tr>
                    <td><?php _e('OpenAI API', 'counterterror-scraper'); ?></td>
                    <td>
                        <span class="status-indicator <?php echo $status['api_status']['openai'] ? 'status-good' : 'status-bad'; ?>">
                            <?php echo $status['api_status']['openai'] ? __('Configured', 'counterterror-scraper') : __('Not Configured', 'counterterror-scraper'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('Claude API', 'counterterror-scraper'); ?></td>
                    <td>
                        <span class="status-indicator <?php echo $status['api_status']['claude'] ? 'status-good' : 'status-warning'; ?>">
                            <?php echo $status['api_status']['claude'] ? __('Configured', 'counterterror-scraper') : __('Not Configured', 'counterterror-scraper'); ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cts-status-box">
            <h2><?php _e('Scheduled Tasks', 'counterterror-scraper'); ?></h2>
            <table class="widefat">
                <tr>
                    <td><?php _e('Last Run', 'counterterror-scraper'); ?></td>
                    <td><?php echo $status['last_cron']; ?></td>
                </tr>
                <tr>
                    <td><?php _e('Next Run', 'counterterror-scraper'); ?></td>
                    <td><?php echo date_i18n('F j, Y g:i a', $status['next_cron']); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Database Size', 'counterterror-scraper'); ?></td>
                    <td><?php echo size_format($status['database_size']); ?></td>
                </tr>
            </table>
        </div>

        <div class="cts-status-box">
            <h2><?php _e('Error Log', 'counterterror-scraper'); ?></h2>
            <table class="widefat">
                <tr>
                    <td><?php _e('Recent Errors', 'counterterror-scraper'); ?></td>
                    <td>
                        <span class="status-indicator <?php echo $status['error_count'] > 0 ? 'status-bad' : 'status-good'; ?>">
                            <?php echo $status['error_count']; ?>
                        </span>
                    </td>
                </tr>
            </table>
            <p>
                <a href="<?php echo admin_url('admin.php?page=cts-logs&level=error'); ?>" class="button">
                    <?php _e('View Error Log', 'counterterror-scraper'); ?>
                </a>
            </p>
        </div>
    </div>

    <div class="cts-tools-section">
        <h2><?php _e('Maintenance Tools', 'counterterror-scraper'); ?></h2>
        <div class="cts-tool-buttons">
            <button type="button" class="button" id="cts-clear-cache">
                <?php _e('Clear Cache', 'counterterror-scraper'); ?>
            </button>
            <button type="button" class="button" id="cts-test-feeds">
                <?php _e('Test All Feeds', 'counterterror-scraper'); ?>
            </button>
            <button type="button" class="button" id="cts-run-scraper">
                <?php _e('Run Scraper Now', 'counterterror-scraper'); ?>
            </button>
        </div>
        <div id="cts-tool-results"></div>
    </div>
</div>