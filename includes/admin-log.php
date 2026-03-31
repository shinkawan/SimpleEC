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

    // Handle Filtering
    $filter_level = isset($_GET['log_level']) ? sanitize_text_field($_GET['log_level']) : '';
    $where_clause = '';
    if ($filter_level) {
        $where_clause = $wpdb->prepare("WHERE level = %s", $filter_level);
    }

    $logs = $wpdb->get_results("SELECT * FROM $table_name $where_clause ORDER BY log_date DESC LIMIT 100");

    ?>
    <div class="wrap">
        <h1><?php _e('システムログ', 'photo-purchase'); ?></h1>
        <p>決済エラーやWebhookの失敗など、システムの稼働状況を確認できます。（最新100件を表示）</p>
        
        <div class="photo-log-controls" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px;">
            <form method="get" style="display: flex; align-items: center; gap: 10px;">
                <input type="hidden" name="post_type" value="photo_product">
                <input type="hidden" name="page" value="photo-purchase-logs">
                <label for="log_level"><strong>レベルで絞り込み:</strong></label>
                <select name="log_level" id="log_level" onchange="this.form.submit()">
                    <option value=""><?php _e('すべて', 'photo-purchase'); ?></option>
                    <option value="error" <?php selected($filter_level, 'error'); ?>>ERROR</option>
                    <option value="warning" <?php selected($filter_level, 'warning'); ?>>WARNING</option>
                    <option value="info" <?php selected($filter_level, 'info'); ?>>INFO</option>
                </select>
            </form>

            <form method="post">
                <?php wp_nonce_field('photo_clear_logs_action'); ?>
                <input type="submit" name="photo_clear_logs" class="button" value="ログをすべて消去" onclick="return confirm('本当にすべてのログを消去しますか？');">
            </form>
        </div>

        <style>
            .photo-log-table .column-date { width: 180px; }
            .photo-log-table .column-level { width: 100px; }
            .photo-log-table .column-message { width: auto; }
            .photo-log-table .column-context { width: 300px; }
            
            .photo-log-context-box {
                background: #f6f7f7;
                padding: 10px;
                border-radius: 4px;
                font-size: 11px;
                max-width: 100%;
                overflow-x: auto;
                border: 1px solid #dcdcde;
            }
            .photo-log-context-box summary {
                cursor: pointer;
                color: #2271b1;
                font-weight: 500;
                outline: none;
            }
            .photo-log-context-box summary:hover {
                color: #135e96;
            }
            .photo-log-context-box pre {
                margin-top: 10px;
                white-space: pre-wrap;
                word-break: break-all;
            }
        </style>

        <table class="wp-list-table widefat fixed striped photo-log-table">
            <thead>
                <tr>
                    <th class="column-date">日時</th>
                    <th class="column-level">レベル</th>
                    <th class="column-message">メッセージ</th>
                    <th class="column-context">詳細 (JSON)</th>
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
                        $level_label = ($log->level === 'error') ? 'エラー' : (($log->level === 'warning') ? '警告' : '情報');
                        $bg_color = ($log->level === 'error') ? '#fcf9f9' : '';
                        ?>
                        <tr style="background-color: <?php echo $bg_color; ?>;">
                            <td><?php echo esc_html($log->log_date); ?></td>
                            <td>
                                <span style="background: <?php echo $level_color; ?>; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; display: inline-block;">
                                    <?php echo esc_html($level_label); ?>
                                </span>
                            </td>
                            <td><strong><?php echo esc_html($log->message); ?></strong></td>
                            <td>
                                <?php if ($log->context): 
                                    $pretty_context = json_decode($log->context, true);
                                    if ($pretty_context) {
                                        $context_display = json_encode($pretty_context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                                    } else {
                                        $context_display = $log->context;
                                    }
                                    ?>
                                    <details class="photo-log-context-box">
                                        <summary>詳細を表示</summary>
                                        <pre><code><?php echo esc_html($context_display); ?></code></pre>
                                    </details>
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
