<?php
/**
 * Product CSV Bulk Edit (Import/Export) for Simple EC
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page UI
 */
function photo_purchase_bulk_edit_page()
{
    ?>
    <div class="wrap">
        <h1><?php _e('商品CSV一括編集', 'photo-purchase'); ?></h1>
        <p>商品の価格や在庫数、ステータスなどをCSV形式で一括エクスポート/インポートできます。</p>

        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>1. 商品データのエクスポート</h2>
            <p>現在登録されている商品のデータをCSVとしてダウンロードします。</p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('photo_purchase_bulk_export'); ?>
                <input type="hidden" name="action" value="photo_purchase_export_products_csv">
                <?php submit_button('CSVをダウンロード', 'primary'); ?>
            </form>
        </div>

        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>2. 商品データのインポート (更新)</h2>
            <p>編集したCSVファイルをアップロードして、商品情報を一括更新します。<br>
            <small style="color: #666;">※IDが一致する商品が更新されます。IDを変更したり削除したりしないでください。</small></p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('photo_purchase_bulk_import'); ?>
                <input type="hidden" name="action" value="photo_purchase_import_products_csv">
                <input type="file" name="product_csv" accept=".csv" required><br><br>
                <?php submit_button('CSVをアップロードして更新', 'secondary'); ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Handle Export
 */
function photo_purchase_handle_export_products_csv()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_admin_referer('photo_purchase_bulk_export');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products_export_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    // Add BOM for Excel
    fwrite($output, "\xEF\xBB\xBF");

    // Header
    $header = array(
        'ID',
        '商品名',
        'ダウンロード価格',
        '配送品価格(L)',
        '税率区分(standard/reduced)',
        '売り切れフラグ(1:売り切れ)',
        '在庫管理(1:有効)',
        '在庫数',
        'サブスクフラグ(1:有効)',
        'サブスク価格',
        'サブスクサイクル(day/week/month/year)',
        'サブスク間隔(1,2...)'
    );
    fputcsv($output, $header);

    $args = array(
        'post_type' => 'photo_product',
        'posts_per_page' => -1,
        'post_status' => 'any'
    );
    $products = get_posts($args);

    foreach ($products as $p) {
        $meta = get_post_custom($p->ID);
        
        $row = array(
            $p->ID,
            $p->post_title,
            $meta['_photo_price_digital'][0] ?? 0,
            $meta['_photo_price_l'][0] ?? 0,
            $meta['_photo_tax_type'][0] ?? 'standard',
            $meta['_photo_is_sold_out'][0] ?? 0,
            $meta['_photo_manage_stock'][0] ?? 0,
            $meta['_photo_stock_qty'][0] ?? 0,
            $meta['_photo_is_subscription'][0] ?? 0,
            $meta['_photo_price_subscription'][0] ?? 0,
            $meta['_photo_sub_interval'][0] ?? 'month',
            $meta['_photo_sub_interval_count'][0] ?? 1,
        );
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * Handle Import
 */
function photo_purchase_handle_import_products_csv()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_admin_referer('photo_purchase_bulk_import');

    if (!isset($_FILES['product_csv']) || $_FILES['product_csv']['error'] !== UPLOAD_ERR_OK) {
        wp_die('ファイルのアップロードに失敗しました。');
    }

    $file = $_FILES['product_csv']['tmp_name'];

    // Robustly handle line endings (Mac/PC/Linux)
    ini_set('auto_detect_line_endings', true);
    setlocale(LC_ALL, 'ja_JP.UTF-8');

    $updated_count = 0;
    $error_count = 0;
    $skipped_count = 0;

    // Read the whole file and handle encoding
    $content = file_get_contents($file);
    $encoding = mb_detect_encoding($content, 'UTF-8,SJIS-win,cp932,EUC-JP', true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }
    // Remove BOM if present
    $content = ltrim($content, "\xEF\xBB\xBF");

    $lines = preg_split('/\r\n|\r|\n/', $content);
    $is_header = true;

    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        
        $data = str_getcsv($line);
        if ($is_header) {
            $is_header = false;
            continue;
        }

        if (empty($data[0]) || !is_numeric($data[0])) {
            $skipped_count++;
            continue;
        }

        $post_id = intval($data[0]);
        $title = isset($data[1]) ? trim($data[1]) : '';
        
        // Find if post exists
        $post = get_post($post_id);
        if (!$post || get_post_type($post_id) !== 'photo_product') {
            $error_count++;
            continue;
        }

        // Prepare update
        $post_data = array('ID' => $post_id);
        if (!empty($title)) {
            $post_data['post_title'] = $title;
        }

        wp_update_post($post_data);

        // Update Meta with validation
        if (isset($data[2])) update_post_meta($post_id, '_photo_price_digital', max(0, intval($data[2])));
        if (isset($data[3])) update_post_meta($post_id, '_photo_price_l', max(0, intval($data[3])));
        if (isset($data[4])) update_post_meta($post_id, '_photo_tax_type', sanitize_text_field($data[4]));
        if (isset($data[5])) update_post_meta($post_id, '_photo_is_sold_out', ($data[5] == '1' ? '1' : '0'));
        if (isset($data[6])) update_post_meta($post_id, '_photo_manage_stock', ($data[6] == '1' ? '1' : '0'));
        if (isset($data[7])) update_post_meta($post_id, '_photo_stock_qty', max(0, intval($data[7])));
        if (isset($data[8])) update_post_meta($post_id, '_photo_is_subscription', ($data[8] == '1' ? '1' : '0'));
        if (isset($data[9])) update_post_meta($post_id, '_photo_price_subscription', max(0, intval($data[9])));
        if (isset($data[10])) update_post_meta($post_id, '_photo_sub_interval', sanitize_text_field($data[10]));
        if (isset($data[11])) update_post_meta($post_id, '_photo_sub_interval_count', max(1, intval($data[11])));

        // Check for stock alert
        if (isset($data[7]) && function_exists('photo_purchase_check_stock_alert')) {
            photo_purchase_check_stock_alert($post_id);
        }

        $updated_count++;
    }

    // Redirect with message
    $redirect_url = add_query_arg(array(
        'page' => 'photo-purchase-bulk-edit',
        'updated' => $updated_count,
        'errors' => $error_count,
        'skipped' => $skipped_count
    ), admin_url('edit.php?post_type=photo_product'));
    
    wp_redirect($redirect_url);
    exit;
}

/**
 * Handle success message on the page (could be added to photo_purchase_bulk_edit_page)
 */
add_action('admin_notices', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'photo-purchase-bulk-edit') {
        if (isset($_GET['updated'])) {
            $u = intval($_GET['updated']);
            $e = intval($_GET['errors']);
            echo '<div class="updated"><p>' . sprintf('%d件の商品を更新しました。', $u);
            if ($e > 0) {
                echo ' ' . sprintf('%d件のエラーがありました。', $e);
            }
            echo '</p></div>';
        }
    }
});
