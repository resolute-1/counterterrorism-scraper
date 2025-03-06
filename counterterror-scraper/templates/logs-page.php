<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?php _e('CounterTerror Scraper Logs', 'counterterror-scraper'); ?></h1>

    <div class="cts-log-filters">
        <form method="get">
            <input type="hidden" name="page" value="cts-logs">
            
            <select name="level">
                <option value=""><?php _e('All Levels', 'counterterror-scraper'); ?></option>
                <?php
                $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];
                foreach ($levels as $level) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($level),
                        selected($_GET['level'] ?? '', $level, false),
                        esc_html(ucfirst($level))
                    );
                }
                ?>
            </select>

            <input type="search" name="s" value="<?php echo esc_attr($_GET['s'] ?? ''); ?>" 
                   placeholder="<?php esc_attr_e('Search logs...', 'counterterror-scraper'); ?>">
            
            <?php submit_button(__('Filter', 'counterterror-scraper'), 'secondary', 'filter', false); ?>
            
            <a href="<?php echo add_query_arg('export', 'csv'); ?>" class="button">
                <?php _e('Export CSV', 'counterterror-scraper'); ?>
            </a>
        </form>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Time', 'counterterror-scraper'); ?></th>
                <th><?php _e('Level', 'counterterror-scraper'); ?></th>
                <th><?php _e('Message', 'counterterror-scraper'); ?></th>
                <th><?php _e('Source', 'counterterror-scraper'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($log->timestamp))); ?></td>
                    <td><span class="log-level log-level-<?php echo esc_attr($log->level); ?>">
                        <?php echo esc_html(ucfirst($log->level)); ?>
                    </span></td>
                    <td><?php echo esc_html($log->message); ?></td>
                    <td><?php echo esc_html($log->source); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$this->logger->table_name}");
    $total_pages = ceil($total_logs / $logs_per_page);
    
    echo '<div class="tablenav"><div class="tablenav-pages">';
    echo paginate_links([
        'base' => add_query_arg('paged', '%#%'),
        'format' => '',
        'prev_text' => __('&laquo;'),
        'next_text' => __('&raquo;'),
        'total' => $total_pages,
        'current' => $current_page
    ]);
    echo '</div></div>';
    ?>
</div>