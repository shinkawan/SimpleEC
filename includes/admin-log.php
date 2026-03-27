<?php
/**
 * System Error Log for Simple EC
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Log Entry
 */
function photo_purchase_log($level, $message, $context = array())
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_system_logs';

    $wpdb->insert(
        $table_name,
        array(
            'log_date' => current_time('mysql'),
            'level'    => sanitize_text_field($level),
            'message'  => sanitize_textarea_field($message),
            'context'  => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
        ),
        array('%s', '%s', '%s', '%s')
    );

    // Keep only last 500 logs to prevent bloat
    $wpdb->query("DELETE FROM $table_name WHERE id <= (SELECT id FROM (SELECT id FROM $table_name ORDER BY id DESC LIMIT 1 OFFSET 500) as t)");
}

/**
 * Admin Page: System Logs
 */
function photo_purchase_log_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_system_logs';
    
    // Handle Clear Logs
    if (isset($_POST['photo_clear_logs']) && check_admin_referer('photo_clear_logs_action')) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="updated"><p>ログを消去しました。</p></div>';
    }

    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY log_date DESC LIMIT 100");

    ?>
    <div class="wrap">
        <h1><?php _e('システムログ', 'photo-purchase'); ?></h1>
        <p>決済エラーやWebhookの失敗など、システムの稼働状況を確認できます。（最新100件を表示）</p>
        
        <form method="post" style="margin-bottom: 20px;">
            <?php wp_nonce_field('photo_clear_logs_action'); ?>
            <input type="submit" name="photo_clear_logs" class="button" value="ログをすべて消去" onclick="return confirm('本当にすべてのログを消去しますか？');">
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 180px;">日時</th>
                    <th style="width: 100px;">レベル</th>
                    <th>メッセージ</th>
                    <th>詳細 (JSON)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 20px;">ログはありません。</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $level_color = ($log->level === 'error') ? '#d63638' : (($log->level === 'warning') ? '#f0ad4e' : '#2271b1');
                        ?>
                        <tr>
                            <td><?php echo esc_html($log->log_date); ?></td>
                            <td>
                                <span style="color: <?php echo $level_color; ?>; font-weight: bold;">
                                    <?php echo esc_html(strtoupper($log->level)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td>
                                <?php if ($log->context): ?>
                                    <code style="font-size: 11px;"><?php echo esc_html($log->context); ?></code>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
