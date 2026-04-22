<?php
/**
 * Product CSV Bulk Edit (Import/Export) for Simple EC
 * v5.0.0 Robust Version with Custom Notation for SKUs and Options
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
        <h1><?php _e('商品CSV一括管理', 'photo-purchase'); ?></h1>
        <p>商品の価格、在庫、SKU（バリエーション）、カスタムオプション、画像をCSV形式で一括エクスポート/インポートできます。</p>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>1. 商品データのエクスポート</h2>
            <p>現在登録されている商品のデータをCSVとしてダウンロードします。<br>
            <small style="color: #666;">（Excelで編集可能なBOM付きUTF-8形式です）</small></p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('photo_purchase_bulk_export'); ?>
                <input type="hidden" name="action" value="photo_purchase_export_products_csv">
                <?php submit_button('CSVをダウンロード', 'primary'); ?>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>2. 商品データのインポート (新規登録・更新)</h2>
            <p>編集したCSVファイルをアップロードして、商品情報を一括登録・更新します。</p>
            <ul style="font-size: 13px; color: #555; background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
                <li><strong>IDが空の場合</strong>: 新規商品として登録されます。</li>
                <li><strong>IDがある場合</strong>: その商品を上書き更新します。</li>
                <li><strong>サムネイルURL</strong>: 画像URLを指定すると、自動的にサイドロード（ライブラリ登録）されます。</li>
                <li><strong>バリエーション(SKU)</strong>: <code>名前=価格:加算額=在庫:数値=SKU:コード</code> を <code>|</code> で繋ぎます。</li>
                <li><strong>カスタムオプション</strong>: <code>グループ:名前=価格:加算額=形式:radio=必須:1</code> を <code>|</code> で繋ぎます。</li>
            </ul>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data" style="margin-top: 20px;">
                <?php wp_nonce_field('photo_purchase_bulk_import'); ?>
                <input type="hidden" name="action" value="photo_purchase_import_products_csv">
                <input type="file" name="product_csv" accept=".csv" required><br><br>
                <?php submit_button('CSVをアップロードして実行', 'secondary'); ?>
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
    header('Content-Disposition: attachment; filename="simple_ec_products_' . date('Ymd_Hi') . '.csv"');

    $output = fopen('php://output', 'w');
    // Add BOM for Excel
    fwrite($output, "\xEF\xBB\xBF");

    // Header definition
    $header = array(
        'ID',
        '商品名',
        'カテゴリー',
        '商品説明',
        'サムネイルURL',
        '商品コード(SKU)',
        'DL価格',
        '配送品価格(L)',
        '税率区分(standard/reduced)',
        '売り切れ(1:はい)',
        '在庫管理(1:はい)',
        '在庫数',
        'バリエーション(SKU)',
        'カスタムオプション',
        'サブスク化(1:はい)',
        'サブスク価格',
        'サブスクサイクル',
        'サブスク間隔'
    );
    fputcsv($output, $header);

    $args = array(
        'post_type' => 'photo_product',
        'posts_per_page' => -1,
        'post_status' => 'any',
        'orderby' => 'ID',
        'order' => 'ASC'
    );
    $products = get_posts($args);

    foreach ($products as $p) {
        $meta = get_post_custom($p->ID);
        
        // 1. Categories
        $terms = wp_get_post_terms($p->ID, 'photo_event', array('fields' => 'names'));
        $category_str = ( !is_wp_error($terms) && !empty($terms) ) ? implode(', ', $terms) : '';

        // 2. Thumbnail
        $thumb_id = get_post_thumbnail_id($p->ID);
        $thumb_url = $thumb_id ? wp_get_attachment_url($thumb_id) : '';

        // 3. SKUs Encoding
        $variations = get_post_meta($p->ID, '_photo_variation_skus', true);
        if (!is_array($variations)) $variations = array();
        $sku_parts = array();
        foreach ($variations as $v) {
            // Reconstruct name from attrs for reliable roundtrip or fallback to name
            $display_name = photo_purchase_format_attrs_to_sku_name(isset($v['name']) ? $v['name'] : '', isset($v['attrs']) ? $v['attrs'] : array());

            $sku_parts[] = sprintf(
                "%s=価格:%d=在庫:%d=SKU:%s",
                $display_name,
                isset($v['price']) ? $v['price'] : 0,
                isset($v['stock']) ? $v['stock'] : 0,
                isset($v['sku']) ? $v['sku'] : ''
            );
        }
        $sku_str = implode('|', $sku_parts);

        // 4. Options Encoding
        $options = get_post_meta($p->ID, '_photo_custom_options', true);
        if (!is_array($options)) $options = array();
        $opt_parts = array();
        foreach ($options as $opt) {
            $opt_parts[] = sprintf(
                "%s:%s=価格:%d=形式:%s=必須:%s",
                isset($opt['group']) ? $opt['group'] : '',
                isset($opt['name']) ? $opt['name'] : '',
                isset($opt['price']) ? $opt['price'] : 0,
                isset($opt['type']) ? $opt['type'] : 'radio',
                isset($opt['required']) ? $opt['required'] : '0'
            );
        }
        $opt_str = implode('|', $opt_parts);

        $row = array(
            $p->ID,
            $p->post_title,
            $category_str,
            $p->post_content,
            $thumb_url,
            get_post_meta($p->ID, '_photo_sku', true),
            isset($meta['_photo_price_digital'][0]) ? $meta['_photo_price_digital'][0] : 0,
            isset($meta['_photo_price_l'][0]) ? $meta['_photo_price_l'][0] : 0,
            isset($meta['_photo_tax_type'][0]) ? $meta['_photo_tax_type'][0] : 'standard',
            isset($meta['_photo_is_sold_out'][0]) ? $meta['_photo_is_sold_out'][0] : 0,
            isset($meta['_photo_manage_stock'][0]) ? $meta['_photo_manage_stock'][0] : 0,
            isset($meta['_photo_stock_qty'][0]) ? $meta['_photo_stock_qty'][0] : 0,
            $sku_str,
            $opt_str,
            isset($meta['_photo_is_subscription'][0]) ? $meta['_photo_is_subscription'][0] : 0,
            isset($meta['_photo_price_subscription'][0]) ? $meta['_photo_price_subscription'][0] : 0,
            isset($meta['_photo_sub_interval'][0]) ? $meta['_photo_sub_interval'][0] : 'month',
            isset($meta['_photo_sub_interval_count'][0]) ? $meta['_photo_sub_interval_count'][0] : 1,
        );
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * Helper: Parse SKU Name String to Attrs Array (Universal Parser)
 */
function photo_purchase_parse_sku_name_to_attrs($name_str) {
    $attrs = array();
    // Use multi-delimiters: /, |, or ,
    $segments = preg_split('/[\/|,]/u', $name_str);
    
    foreach ($segments as $idx => $segment) {
        $segment = trim($segment);
        if (empty($segment)) continue;

        $key = '';
        $val = '';

        // Case 1: Key:Value (Colon)
        if (strpos($segment, ':') !== false || strpos($segment, '：') !== false) {
            $kv = preg_split('/[:：]/u', $segment, 2);
            $key = trim($kv[0]);
            $val = trim($kv[1]);
        } 
        // Case 2: Key Value (Space) - Try splitting by the last space if there's text on both sides
        elseif (preg_match('/^(.+)[ 　](.+)$/u', $segment, $matches)) {
            $key = trim($matches[1]);
            $val = trim($matches[2]);
        }
        // Case 3: Fallback (Value only)
        else {
            $key = ($idx === 0) ? 'オプション' : '項目' . ($idx + 1);
            $val = $segment;
        }

        if ($key && $val) {
            $attrs[] = array(
                'name' => $key,
                'value' => $val
            );
        }
    }
    return $attrs;
}

/**
 * Helper: Format Attrs Array back to SKU Name String
 */
function photo_purchase_format_attrs_to_sku_name($name, $attrs) {
    if (empty($attrs) || !is_array($attrs)) {
        return $name;
    }
    $parts = array();
    foreach ($attrs as $attr) {
        $parts[] = sprintf("%s:%s", $attr['name'], $attr['value']);
    }
    return implode(' / ', $parts);
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

    // Encoding handle
    $content = file_get_contents($file);
    $encoding = mb_detect_encoding($content, 'UTF-8,SJIS-win,cp932,EUC-JP', true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }
    $content = ltrim($content, "\xEF\xBB\xBF"); // Remove BOM

    $temp_file = wp_tempnam('product_import_');
    file_put_contents($temp_file, $content);
    
    $handle = fopen($temp_file, 'r');
    $header = fgetcsv($handle); // Skip header

    $updated = 0;
    $created = 0;
    $errors = 0;

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    while (($data = fgetcsv($handle)) !== false) {
        $post_id = !empty($data[0]) ? intval($data[0]) : 0;
        $title = !empty($data[1]) ? sanitize_text_field($data[1]) : '';
        
        if (empty($title)) {
            $errors++;
            continue;
        }

        $post_args = array(
            'post_title'   => $title,
            'post_content' => $data[3] ?? '',
            'post_status'  => 'publish',
            'post_type'    => 'photo_product'
        );

        if ($post_id) {
            $post_args['ID'] = $post_id;
            $res_id = wp_update_post($post_args);
            $updated++;
        } else {
            $res_id = wp_insert_post($post_args);
            $created++;
            $post_id = $res_id;
        }

        if (!$res_id || is_wp_error($res_id)) {
            $errors++;
            continue;
        }

        // Meta Updates
        update_post_meta($post_id, '_photo_sku', sanitize_text_field($data[5] ?? ''));
        update_post_meta($post_id, '_photo_price_digital', intval($data[6] ?? 0));
        update_post_meta($post_id, '_photo_price_l', intval($data[7] ?? 0));
        update_post_meta($post_id, '_photo_tax_type', sanitize_text_field($data[8] ?? 'standard'));
        update_post_meta($post_id, '_photo_is_sold_out', (($data[9] ?? '') == '1' ? '1' : '0'));
        update_post_meta($post_id, '_photo_manage_stock', (($data[10] ?? '') == '1' ? '1' : '0'));
        update_post_meta($post_id, '_photo_stock_qty', intval($data[11] ?? 0));
        update_post_meta($post_id, '_photo_is_subscription', (($data[14] ?? '') == '1' ? '1' : '0'));
        update_post_meta($post_id, '_photo_price_subscription', intval($data[15] ?? 0));
        update_post_meta($post_id, '_photo_sub_interval', sanitize_text_field($data[16] ?? 'month'));
        update_post_meta($post_id, '_photo_sub_interval_count', max(1, intval($data[17] ?? 1)));

        // Category Sync
        if (!empty($data[2])) {
            $cat_names = array_map('trim', explode(',', $data[2]));
            wp_set_object_terms($post_id, $cat_names, 'photo_event');
        }

        // Image Sideload with Cache Check
        if (!empty($data[4]) && filter_var($data[4], FILTER_VALIDATE_URL)) {
            $img_url = trim($data[4]);
            
            // Check if already sideloaded
            global $wpdb;
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_ec_csv_source_url' AND meta_value = %s LIMIT 1",
                $img_url
            ));

            if ($existing_id) {
                set_post_thumbnail($post_id, $existing_id);
            } else {
                $thumb_id = media_sideload_image($img_url, $post_id, $title, 'id');
                if (!is_wp_error($thumb_id)) {
                    set_post_thumbnail($post_id, $thumb_id);
                    update_post_meta($thumb_id, '_ec_csv_source_url', $img_url);
                }
            }
        }

        // SKU Parser
        if (!empty($data[12])) {
            $variations = array();
            $sku_blocks = explode('|', $data[12]);
            foreach ($sku_blocks as $idx => $block) {
                $parts = explode('=', $block);
                if (count($parts) < 2) continue;
                
                $v_name = trim($parts[0]);
                $v_id = 'v_csv_' . time() . '_' . $idx;
                $v_data = array(
                    'name' => $v_name, 
                    'price' => 0, 
                    'stock' => 0, 
                    'sku' => '', 
                    'variation_id' => $v_id,
                    'attrs' => photo_purchase_parse_sku_name_to_attrs($v_name) // Universal Parsing
                );
                
                for ($i = 1; $i < count($parts); $i++) {
                    $kv = preg_split('/[:：]/u', $parts[$i], 2);
                    if (count($kv) !== 2) continue;
                    $key = trim($kv[0]); $val = trim($kv[1]);
                    if ($key === '価格' || strtolower($key) === 'price') $v_data['price'] = intval($val);
                    if ($key === '在庫' || strtolower($key) === 'stock') $v_data['stock'] = intval($val);
                    if ($key === 'SKU' || strtolower($key) === 'sku') $v_data['sku'] = $val;
                }
                $variations[$v_id] = $v_data;
            }
            if (!empty($variations)) {
                update_post_meta($post_id, '_photo_use_variations', '1');
                update_post_meta($post_id, '_photo_variation_skus', $variations);
            }
        }

        // Options Parser (Also enhanced with universal logic)
        if (!empty($data[13])) {
            $options = array();
            $opt_blocks = explode('|', $data[13]);
            foreach ($opt_blocks as $block) {
                $parts = explode('=', $block);
                if (count($parts) < 2) continue;
                
                // Parse group and name using colon/space logic
                $group_name_raw = $parts[0];
                $group = 'オプション';
                $name = trim($group_name_raw);
                
                if (strpos($group_name_raw, ':') !== false) {
                    $kv = explode(':', $group_name_raw, 2);
                    $group = trim($kv[0]);
                    $name = trim($kv[1]);
                } elseif (preg_match('/^(.+)[ 　](.+)$/u', $group_name_raw, $matches)) {
                    $group = trim($matches[1]);
                    $name = trim($matches[2]);
                }
                
                $opt_data = array('group' => $group, 'name' => $name, 'price' => 0, 'type' => 'radio', 'required' => '0', 'category' => 'attribute');
                
                for ($i = 1; $i < count($parts); $i++) {
                    $kv = preg_split('/[:：]/u', $parts[$i], 2);
                    if (count($kv) !== 2) continue;
                    $key = trim($kv[0]); $val = trim($kv[1]);
                    if ($key === '価格' || strtolower($key) === 'price') $opt_data['price'] = intval($val);
                    if ($key === '形式' || strtolower($key) === 'type') {
                        $opt_data['type'] = (stripos($val, 'check') !== false ? 'checkbox' : 'radio');
                    }
                    if ($key === '必須' || strtolower($key) === 'required') $opt_data['required'] = ($val == '1' ? '1' : '0');
                }
                $options[] = $opt_data;
            }
            if (!empty($options)) {
                update_post_meta($post_id, '_photo_custom_options', $options);
            }
        }
    }

    fclose($handle);
    unlink($temp_file);

    $msg = sprintf('%d件作成、%d件更新しました。', $created, $updated);
    if ($errors > 0) $msg .= sprintf(' (%d件のエラー)', $errors);

    wp_redirect(add_query_arg(array('page' => 'photo-purchase-bulk-edit', 'msg' => urlencode($msg)), admin_url('edit.php?post_type=photo_product')));
    exit;
}

/**
 * Handle success message
 */
add_action('admin_notices', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'photo-purchase-bulk-edit' && isset($_GET['msg'])) {
        echo '<div class="updated"><p>' . esc_html(urldecode($_GET['msg'])) . '</p></div>';
    }
});
