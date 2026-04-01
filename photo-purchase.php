<?php
/**
 * Plugin Name: Simple EC
 * Description: 簡易的な写真・デジタルコンテンツ販売プラグイン。Stripe、PayPay、代引き、銀行振込に対応。
 * Version: 4.0.0
 * Author: アートフレア株式会社
 * Author URI: https://www.artflair.co.jp/
 * Text Domain: photo-purchase
 */

if (!defined('ABSPATH')) {
	exit;
}

// Define constants
define('PHOTO_PURCHASE_VERSION', '4.0.0');
define('PHOTO_PURCHASE_PATH', plugin_dir_path(__FILE__));
define('PHOTO_PURCHASE_URL', plugin_dir_url(__FILE__));

/**
 * Create Database Table on Activation
 */
function photo_purchase_create_db_table()
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'photo_orders';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		user_id bigint(20) DEFAULT 0 NOT NULL,
		order_token varchar(255) NOT NULL,
		buyer_name varchar(255) NOT NULL,
		buyer_email varchar(255) NOT NULL,
		order_items text NOT NULL,
		shipping_info text NOT NULL,
		total_amount int(11) NOT NULL,
		payment_method varchar(50) NOT NULL,
		status varchar(50) NOT NULL,
		transaction_id varchar(255) DEFAULT '' NOT NULL,
		stripe_subscription_id varchar(100) DEFAULT '' NOT NULL,
		stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
		tracking_number varchar(255) DEFAULT '' NOT NULL,
		carrier varchar(50) DEFAULT '' NOT NULL,
		coupon_info text NOT NULL,
		order_notes text NOT NULL,
		created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		PRIMARY KEY  (id),
		KEY order_token (order_token)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);

	$coupon_table = $wpdb->prefix . 'photo_coupons';
	$sql_coupon = "CREATE TABLE $coupon_table (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		code varchar(50) NOT NULL,
		discount_type varchar(20) NOT NULL,
		discount_amount int(11) NOT NULL,
		expiry_date date DEFAULT NULL,
		usage_limit int(11) DEFAULT NULL,
		usage_count int(11) DEFAULT 0,
		min_order_amount int(11) DEFAULT 0,
		active tinyint(1) DEFAULT 1,
		stripe_duration varchar(20) DEFAULT 'once' NOT NULL,
		stripe_months int(11) DEFAULT 0 NOT NULL,
		created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY code (code)
	) $charset_collate;";
	dbDelta($sql_coupon);

	// Multi-update support: ensure transaction_id exists for older versions
	$column_exists = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'transaction_id'");
	if (empty($column_exists)) {
		$wpdb->query("ALTER TABLE `$table_name` ADD `transaction_id` varchar(255) DEFAULT '' NOT NULL AFTER `status` ");
	}

	$cust_col_exists = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'stripe_customer_id'");
	if (empty($cust_col_exists)) {
		$wpdb->query("ALTER TABLE `$table_name` ADD `stripe_customer_id` varchar(100) DEFAULT '' NOT NULL AFTER `stripe_subscription_id` ");
	}

	$carrier_exists = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'carrier'");
	if (empty($carrier_exists)) {
		$wpdb->query("ALTER TABLE `$table_name` ADD `carrier` varchar(50) DEFAULT '' NOT NULL AFTER `tracking_number` ");
	}

	$user_col_exists = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'user_id'");
	if (empty($user_col_exists)) {
		$wpdb->query("ALTER TABLE `$table_name` ADD `user_id` bigint(20) DEFAULT 0 NOT NULL AFTER `id` ");
	}

	// Coupon table expansion
	$dur_exists = $wpdb->get_results("SHOW COLUMNS FROM `$coupon_table` LIKE 'stripe_duration'");
	if (empty($dur_exists)) {
		$wpdb->query("ALTER TABLE `$coupon_table` ADD `stripe_duration` varchar(20) DEFAULT 'once' NOT NULL AFTER `active` ");
	}
	$mon_exists = $wpdb->get_results("SHOW COLUMNS FROM `$coupon_table` LIKE 'stripe_months'");
	if (empty($mon_exists)) {
		$wpdb->query("ALTER TABLE `$coupon_table` ADD `stripe_months` int(11) DEFAULT 0 NOT NULL AFTER `stripe_duration` ");
	}

	// Log table support
	$log_table = $wpdb->prefix . 'photo_system_logs';
	$sql_log = "CREATE TABLE $log_table (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		log_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		level varchar(20) NOT NULL,
		message text NOT NULL,
		context text,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql_log);

	// Abandoned Cart table support
	$abandoned_table = $wpdb->prefix . 'photo_abandoned_carts';
	$sql_abandoned = "CREATE TABLE $abandoned_table (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		email varchar(255) NOT NULL,
		cart_json longtext NOT NULL,
		user_id bigint(20) DEFAULT 0 NOT NULL,
		last_active datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		status varchar(20) DEFAULT 'pending' NOT NULL,
		reminder_sent_count int(11) DEFAULT 0 NOT NULL,
		recovery_token varchar(100) NOT NULL,
		clicked_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		recovered_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		unsubscribed tinyint(1) DEFAULT 0 NOT NULL,
		PRIMARY KEY  (id),
		KEY email (email),
		KEY recovery_token (recovery_token)
	) $charset_collate;";
	dbDelta($sql_abandoned);
}

/**
 * Register Custom Post Type: Photo product
 */
function photo_purchase_register_post_type()
{
	$labels = array(
		'name' => _x('商品', 'post type general name', 'photo-purchase'),
		'singular_name' => _x('商品', 'post type singular name', 'photo-purchase'),
		'menu_name' => _x('販売商品', 'admin menu', 'photo-purchase'),
		'name_admin_bar' => _x('商品', 'add new on admin bar', 'photo-purchase'),
		'add_new' => _x('新規追加', 'photo', 'photo-purchase'),
		'add_new_item' => __('商品を新規追加', 'photo-purchase'),
		'new_item' => __('新しい商品', 'photo-purchase'),
		'edit_item' => __('商品を編集', 'photo-purchase'),
		'view_item' => __('商品を表示', 'photo-purchase'),
		'all_items' => __('すべての商品', 'photo-purchase'),
		'search_items' => __('商品を検索', 'photo-purchase'),
		'not_found' => __('商品が見つかりません。', 'photo-purchase'),
		'not_found_in_trash' => __('ゴミ箱内に商品はありません。', 'photo-purchase')
	);

	$args = array(
		'labels' => $labels,
		'public' => false,
		'publicly_queryable' => false,
		'show_ui' => true,
		'show_in_menu' => true,
		'query_var' => true,
		'capability_type' => 'post',
		'has_archive' => false,
		'hierarchical' => false,
		'menu_position' => 5,
		'supports' => array('title', 'editor', 'thumbnail', 'page-attributes'),
		'menu_icon' => 'dashicons-cart',
		'exclude_from_search' => true,
	);

	register_post_type('photo_product', $args);


	// Register Taxonomy: Photo Event
	register_taxonomy('photo_event', 'photo_product', array(
		'label' => __('カテゴリー', 'photo-purchase'),
		'rewrite' => array('slug' => 'photo-event'),
		'hierarchical' => true,
		'show_admin_column' => true,
	));
}
add_action('init', 'photo_purchase_register_post_type');

/**
 * Admin Menus
 */
function photo_purchase_admin_menus()
{
	// 各種設定 (Consolidated settings page)
	add_submenu_page(
		'edit.php?post_type=photo_product',
		__('各種設定', 'photo-purchase'),
		__('各種設定', 'photo-purchase'),
		'manage_options',
		'photo-purchase-settings',
		'photo_purchase_settings_page'
	);

	// 受注一覧
	add_submenu_page(
		'edit.php?post_type=photo_product',
		__('受注一覧', 'photo-purchase'),
		__('受注一覧', 'photo-purchase'),
		'manage_options',
		'photo-purchase-orders',
		'photo_purchase_orders_page'
	);

	// クーポン管理
	add_submenu_page(
		'edit.php?post_type=photo_product',
		__('クーポン管理', 'photo-purchase'),
		__('クーポン管理', 'photo-purchase'),
		'manage_options',
		'photo-purchase-coupons',
		'photo_purchase_coupons_page'
	);


	// システムログ
	add_submenu_page(
		'edit.php?post_type=photo_product',
		__('システムログ', 'photo-purchase'),
		__('システムログ', 'photo-purchase'),
		'manage_options',
		'photo-purchase-logs',
		'photo_purchase_log_page'
	);
	add_action('admin_action_photo_purchase_duplicate_product', 'photo_purchase_handle_duplicate_product');
}
add_action('admin_menu', 'photo_purchase_admin_menus');

/**
 * Add "Duplicate" action to post row actions
 */
add_filter('post_row_actions', 'photo_purchase_add_duplicate_link', 10, 2);
function photo_purchase_add_duplicate_link($actions, $post) {
	if ($post->post_type !== 'photo_product') return $actions;
	
	$url = wp_nonce_url(
		admin_url('admin.php?action=photo_purchase_duplicate_product&post_id=' . $post->ID),
		'photo_duplicate_product_' . $post->ID
	);
	
	$actions['duplicate'] = '<a href="' . $url . '" title="' . esc_attr__('複製して新しい商品を作成', 'photo-purchase') . '">' . __('複製', 'photo-purchase') . '</a>';
	return $actions;
}

/**
 * Handle Product Duplication
 */
function photo_purchase_handle_duplicate_product() {
	if (!isset($_GET['post_id'])) wp_die('No product ID provided.');
	
	$post_id = intval($_GET['post_id']);
	check_admin_referer('photo_duplicate_product_' . $post_id);
	
	if (!current_user_can('edit_posts')) wp_die('Unauthorized');

	$post = get_post($post_id);
	if (!$post || $post->post_type !== 'photo_product') wp_die('Invalid product.');

	// Create new post data
	$new_post_args = array(
		'post_title' => $post->post_title . ' - コピー',
		'post_content' => $post->post_content,
		'post_status' => 'draft',
		'post_type' => $post->post_type,
		'post_author' => get_current_user_id()
	);

	$new_post_id = wp_insert_post($new_post_args);

	if ($new_post_id) {
		// Copy Taxonomy (Categories)
		$terms = wp_get_object_terms($post_id, 'photo_event');
		if (!empty($terms)) {
			$term_ids = array();
			foreach ($terms as $t) { $term_ids[] = $t->term_id; }
			wp_set_object_terms($new_post_id, $term_ids, 'photo_event');
		}

		// Copy Meta Data
		$meta_data = get_post_meta($post_id);
		foreach ($meta_data as $key => $values) {
			// Skip internal WP keys and restock lists
			if (in_array($key, array('_photo_restock_emails'))) continue;
			
			foreach ($values as $val) {
				// dbDelta/wp_insert_post might handle some keys, but we want all _photo_ and _ec_
				if (strpos($key, '_photo_') === 0 || strpos($key, '_ec_') === 0 || $key === '_thumbnail_id') {
					update_post_meta($new_post_id, $key, maybe_unserialize($val));
				}
			}
		}

		photo_purchase_log('info', '商品を複製しました。', array('source' => $post_id, 'new' => $new_post_id));
		wp_redirect(admin_url('edit.php?post_type=photo_product&duplicated=' . $new_post_id));
		exit;
	} else {
		wp_die('Failed to duplicate product.');
	}
}

/**
 * Product List Columns
 */
add_filter('manage_photo_product_posts_columns', 'photo_purchase_add_product_columns');
function photo_purchase_add_product_columns($columns) {
	$new_columns = array();
	foreach($columns as $key => $value) {
		if ($key === 'title') {
			$new_columns['photo_thumb'] = '画像';
		}
		$new_columns[$key] = $value;
		if ($key === 'title') {
			$new_columns['photo_price'] = '販売価格';
			$new_columns['photo_stock'] = '在庫';
		}
	}
	$new_columns['photo_order'] = '順序'; // Drag handle column
	return $new_columns;
}

add_action('manage_photo_product_posts_custom_column', 'photo_purchase_render_product_columns', 10, 2);
function photo_purchase_render_product_columns($column, $post_id) {
	switch ($column) {
		case 'photo_thumb':
			echo get_the_post_thumbnail($post_id, array(50, 50), array('style' => 'border-radius:4px;'));
			break;
		case 'photo_price':
			$p_l = get_post_meta($post_id, '_photo_price_l', true);
			$p_sub = get_post_meta($post_id, '_photo_price_subscription', true);
			if ($p_sub) {
				echo 'サブスク: ¥' . number_format($p_sub);
			} elseif ($p_l) {
				echo '¥' . number_format($p_l);
			} else {
				echo '-';
			}
			break;
		case 'photo_stock':
			$manage = get_post_meta($post_id, '_photo_manage_stock', true);
			if ($manage === '1') {
				$qty = intval(get_post_meta($post_id, '_photo_stock_qty', true));
				$color = ($qty <= 5) ? '#d63638' : '#2271b1';
				echo '<strong style="color:'.$color.';">' . $qty . '</strong>';
			} else {
				echo '<span style="color:#999;">制限なし</span>';
			}
			break;
		case 'photo_order':
			echo '<span class="dashicons dashicons-move photo-drag-handle" style="cursor:move; color:#ccc;"></span>';
			echo '<input type="hidden" class="photo-item-id" value="' . $post_id . '">';
			break;
	}
}

add_filter('manage_edit-photo_product_sortable_columns', 'photo_purchase_sortable_product_columns');
function photo_purchase_sortable_product_columns($columns) {
	$columns['photo_price'] = 'photo_price';
	$columns['photo_stock'] = 'photo_stock';
	$columns['photo_order'] = 'menu_order';
	return $columns;
}

/**
 * Handle Sorting AJAX
 */
add_action('wp_ajax_photo_purchase_update_order', 'photo_purchase_update_order_callback');
function photo_purchase_update_order_callback() {
	check_ajax_referer('photo_update_order_nonce', 'nonce');
	if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

	$order = isset($_POST['order']) ? array_map('intval', $_POST['order']) : array();
	
	if (!empty($order)) {
		global $wpdb;
		foreach ($order as $index => $post_id) {
			$wpdb->update(
				$wpdb->posts,
				array('menu_order' => $index),
				array('ID' => $post_id)
			);
		}
		wp_send_json_success();
	}
	wp_send_json_error('Invalid data');
}

// CSV エクスポート/インポート ハンドラ登録
add_action('admin_post_photo_purchase_export_products_csv', 'photo_purchase_handle_export_products_csv');
add_action('admin_post_photo_purchase_import_products_csv', 'photo_purchase_handle_import_products_csv');

// 帳票出力 (印刷・PDF) ハンドラ
add_action('init', function() {
    if (isset($_GET['photo_purchase_action']) && $_GET['photo_purchase_action'] === 'print_doc') {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'print_invoice';
        
        // Nonce check for security (for logged in users)
        if (is_admin() && !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // For non-admin, ensure it's their own order if needed, but the token in inquiry handles this.
        $order_token = isset($_GET['order_token']) ? sanitize_text_field($_GET['order_token']) : '';
        
        if (function_exists('photo_purchase_order_print_view')) {
            photo_purchase_order_print_view($order_id, $type, $order_token);
            exit;
        }
    }
});

/**
 * Add Dashboard Widget for Simple EC Status
 */
function photo_purchase_add_dashboard_widgets() {
	wp_add_dashboard_widget(
		'photo_purchase_stats_widget',
		'Simple EC ステータス',
		'photo_purchase_dashboard_widget_content'
	);
}
add_action('wp_dashboard_setup', 'photo_purchase_add_dashboard_widgets');

function photo_purchase_dashboard_widget_content() {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'photo_orders';
    $logs_table = $wpdb->prefix . 'photo_system_logs';
    $abandoned_table = $wpdb->prefix . 'photo_abandoned_carts';

    // Get order counts
    $pending = $wpdb->get_var("SELECT COUNT(*) FROM $orders_table WHERE status = 'pending_payment'");
    $processing = $wpdb->get_var("SELECT COUNT(*) FROM $orders_table WHERE status = 'processing'");
    
    // Get recent error count (last 24h)
    $error_count = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE level = 'error' AND log_date > DATE_SUB(NOW(), INTERVAL 1 DAY)");

    // Get Abandoned Cart Stats
    $abandoned_stats = $wpdb->get_row("
        SELECT 
            SUM(CASE WHEN reminder_sent_count > 0 THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'recovered' THEN 1 ELSE 0 END) as recovered
        FROM $abandoned_table
    ");
    $sent_count = intval($abandoned_stats->sent ?? 0);
    $recovered_count = intval($abandoned_stats->recovered ?? 0);
    $recovery_rate = ($sent_count > 0) ? round(($recovered_count / $sent_count) * 100, 1) : 0;

    echo '<div class="photo-dashboard-stats">';
    echo '<p><span class="dashicons dashicons-cart"></span> <strong>' . __('受信した注文:', 'photo-purchase') . '</strong></p>';
    echo '<ul style="margin-bottom:15px;">';
    echo '<li>' . __('入金待ち:', 'photo-purchase') . ' <a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-orders') . '">' . intval($pending) . ' ' . __('件', 'photo-purchase') . '</a></li>';
    echo '<li>' . __('準備中（決済済）:', 'photo-purchase') . ' <a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-orders') . '">' . intval($processing) . ' ' . __('件', 'photo-purchase') . '</a></li>';
    echo '</ul>';

    echo '<p><span class="dashicons dashicons-email-alt"></span> <strong>' . __('かご落ちリカバリー:', 'photo-purchase') . '</strong></p>';
    echo '<ul style="margin-bottom:15px;">';
    echo '<li>' . __('リマインド送信数:', 'photo-purchase') . ' <strong>' . number_format($sent_count) . '</strong></li>';
    echo '<li>' . __('リカバリー成功:', 'photo-purchase') . ' <strong style="color:#28a745;">' . number_format($recovered_count) . ' (' . $recovery_rate . '%)</strong></li>';
    echo '</ul>';
    
    if ($error_count > 0) {
        echo '<p style="color:#d63638;"><span class="dashicons dashicons-warning"></span> <strong>' . __('直近24時間のシステムエラー:', 'photo-purchase') . '</strong> <a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-logs') . '" style="color:#d63638;">' . intval($error_count) . ' ' . __('件', 'photo-purchase') . '</a></p>';
    } else {
        echo '<p style="color:#22c55e;"><span class="dashicons dashicons-yes-alt"></span> ' . __('システムは正常に稼働しています。', 'photo-purchase') . '</p>';
    }
    
    echo '<hr style="margin:15px 0 10px;">';
    echo '<p><a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-settings') . '" class="button">' . __('各種設定', 'photo-purchase') . '</a> ';
    echo '<a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-orders') . '" class="button button-primary">' . __('注文管理', 'photo-purchase') . '</a></p>';
    echo '</div>';
}

// Include necessary files
require_once PHOTO_PURCHASE_PATH . 'includes/coupon-manager.php';
require_once PHOTO_PURCHASE_PATH . 'includes/stripe-webhooks.php';

// Handle DB updates and initial setup
add_action('admin_init', 'photo_purchase_create_db_table');

// Handle print actions early to avoid admin UI wrapper
add_action('admin_init', 'photo_purchase_handle_print_actions');

/**
 * Settings Page Content (Consolidated with Tabs)
 */
function photo_purchase_settings_page()
{
	// Handle Saving
	if (isset($_POST['photo_pp_save_settings']) && check_admin_referer('photo_pp_settings_action', 'photo_pp_settings_nonce')) {
		$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

		if ($active_tab == 'general') {
			update_option('photo_pp_admin_notification_email', sanitize_email($_POST['admin_email_notif']));
			update_option('photo_pp_seller_name', sanitize_text_field($_POST['seller_name']));
			update_option('photo_pp_seller_email', sanitize_email($_POST['seller_email']));
			update_option('photo_pp_gallery_columns', $_POST['gallery_columns'] !== '' ? intval($_POST['gallery_columns']) : 4);
			update_option('photo_pp_member_discount_rate', isset($_POST['member_discount_rate']) ? intval($_POST['member_discount_rate']) : 0);
			update_option('photo_my_page_id', isset($_POST['my_page_id']) ? intval($_POST['my_page_id']) : 0);
			update_option('photo_pp_stock_threshold', isset($_POST['stock_threshold']) ? intval($_POST['stock_threshold']) : 5);
		} elseif ($active_tab == 'payment') {
			update_option('photo_pp_stripe_publishable_key', sanitize_text_field($_POST['stripe_pk']));
			update_option('photo_pp_stripe_secret_key', sanitize_text_field($_POST['stripe_sk']));
			update_option('photo_pp_stripe_webhook_secret', sanitize_text_field($_POST['stripe_webhook_secret']));
			update_option('photo_pp_enable_stripe', isset($_POST['enable_stripe']) ? '1' : '0');
			update_option('photo_pp_enable_paypay', isset($_POST['enable_paypay']) ? '1' : '0');
			update_option('photo_pp_enable_bank', isset($_POST['enable_bank']) ? '1' : '0');
			update_option('photo_pp_enable_cod', isset($_POST['enable_cod']) ? '1' : '0');
			update_option('photo_pp_enable_digital_sales', isset($_POST['enable_digital_sales']) ? '1' : '0');
			update_option('photo_pp_download_expiry', intval($_POST['download_expiry']));
			update_option('photo_pp_download_limit', intval($_POST['download_limit']));
			update_option('photo_pp_cod_tier1_limit', intval($_POST['cod_tier1_limit']));
			update_option('photo_pp_cod_tier1_fee', intval($_POST['cod_tier1_fee']));
			update_option('photo_pp_cod_tier2_limit', intval($_POST['cod_tier2_limit']));
			update_option('photo_pp_cod_tier2_fee', intval($_POST['cod_tier2_fee']));
			update_option('photo_pp_cod_tier3_limit', intval($_POST['cod_tier3_limit']));
			update_option('photo_pp_cod_tier3_fee', intval($_POST['cod_tier3_fee']));
			update_option('photo_pp_cod_max_fee', intval($_POST['cod_max_fee']));
		} elseif ($active_tab == 'bank') {
			update_option('photo_pp_bank_name', sanitize_text_field($_POST['bank_name']));
			update_option('photo_pp_bank_branch', sanitize_text_field($_POST['bank_branch']));
			update_option('photo_pp_bank_type', sanitize_text_field($_POST['bank_type']));
			update_option('photo_pp_bank_number', sanitize_text_field($_POST['bank_number']));
			update_option('photo_pp_bank_holder', sanitize_text_field($_POST['bank_holder']));
		} elseif ($active_tab == 'shipping') {
			$flat_rate_input = isset($_POST['shipping_flat_rate']) ? mb_convert_kana(sanitize_text_field($_POST['shipping_flat_rate']), 'n', 'UTF-8') : '';
			$free_threshold_input = isset($_POST['shipping_free_threshold']) ? mb_convert_kana(sanitize_text_field($_POST['shipping_free_threshold']), 'n', 'UTF-8') : '';

			update_option('photo_pp_shipping_flat_rate', $flat_rate_input !== '' ? intval($flat_rate_input) : '');
			update_option('photo_pp_shipping_free_threshold', $free_threshold_input !== '' ? intval($free_threshold_input) : '');
			update_option('photo_pp_shipping_carrier', sanitize_text_field($_POST['shipping_carrier']));
			update_option('photo_pp_shipping_delivery_days', sanitize_text_field($_POST['delivery_days']));
			update_option('photo_pp_shipping_time_slots', sanitize_text_field($_POST['time_slots']));
			update_option('photo_pp_shipping_international', sanitize_text_field($_POST['international_shipping']));
			update_option('photo_pp_payment_fee_desc', sanitize_textarea_field($_POST['payment_fee_desc']));

			$pref_rates = [];
			if (isset($_POST['pref_rates']) && is_array($_POST['pref_rates'])) {
				foreach ($_POST['pref_rates'] as $pref => $rate) {
					if ($rate !== '') {
						$pref_rates[sanitize_text_field($pref)] = intval($rate);
					}
				}
			}
			update_option('photo_pp_shipping_prefecture_rates', $pref_rates);
		} elseif ($active_tab == 'tokushoho') {
			update_option('photo_pp_tokusho_name', sanitize_text_field($_POST['tokusho_name']));
			update_option('photo_pp_tokusho_ceo', sanitize_text_field($_POST['tokusho_ceo']));
			update_option('photo_pp_tokusho_address', sanitize_text_field($_POST['tokusho_address']));
			update_option('photo_pp_tokusho_tel', sanitize_text_field($_POST['tokusho_tel']));
			update_option('photo_pp_tokusho_email', sanitize_email($_POST['tokusho_email']));
			update_option('photo_pp_tokusho_url', esc_url_raw($_POST['tokusho_url']));
			update_option('photo_pp_tax_rate_standard', intval($_POST['tax_rate_standard']));
			update_option('photo_pp_tax_rate_reduced', intval($_POST['tax_rate_reduced']));
			update_option('photo_pp_tokusho_registration_number', sanitize_text_field($_POST['tokusho_registration_number']));
			update_option('photo_pp_tokusho_price', sanitize_textarea_field($_POST['tokusho_price']));
			update_option('photo_pp_tokusho_price_other', sanitize_textarea_field($_POST['tokusho_price_other']));
			update_option('photo_pp_tokusho_payment_method', sanitize_textarea_field($_POST['tokusho_payment_method']));
			update_option('photo_pp_tokusho_payment_deadline', sanitize_textarea_field($_POST['tokusho_payment_deadline']));
			update_option('photo_pp_tokusho_delivery_time', sanitize_textarea_field($_POST['tokusho_delivery_time']));
			update_option('photo_pp_tokusho_return', sanitize_textarea_field($_POST['tokusho_return']));
			update_option('photo_pp_tokusho_sub_cycle', sanitize_textarea_field($_POST['tokusho_sub_cycle']));
			update_option('photo_pp_tokusho_sub_cancellation', sanitize_textarea_field($_POST['tokusho_sub_cancellation']));
		} elseif ($active_tab == 'sns') {
			update_option('photo_pp_enable_sns_login', isset($_POST['enable_sns_login']) ? '1' : '0');
			update_option('photo_pp_google_client_id', sanitize_text_field($_POST['google_client_id']));
			update_option('photo_pp_google_client_secret', sanitize_text_field($_POST['google_client_secret']));
			update_option('photo_pp_line_client_id', sanitize_text_field($_POST['line_client_id']));
			update_option('photo_pp_line_client_secret', sanitize_text_field($_POST['line_client_secret']));
		} elseif ($active_tab == 'abandoned_cart') {
			update_option('photo_pp_enable_abandoned_cart', isset($_POST['enable_abandoned_cart']) ? '1' : '0');
			update_option('photo_pp_abandoned_cart_delay', intval($_POST['abandoned_cart_delay']));
		} elseif ($active_tab == 'membership_terms') {
			update_option('photo_pp_membership_terms', wp_kses_post($_POST['membership_terms']));
		}

		photo_purchase_log('info', '各種設定を更新しました。', array('tab' => $active_tab, 'user' => get_current_user_id()));

		echo '<div class="updated"><p>' . __('設定を保存しました。', 'photo-purchase') . '</p></div>';
	}

	$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
	?>
	<div class="wrap">
		<h1><?php _e('各種設定', 'photo-purchase'); ?></h1>

		<h2 class="nav-tab-wrapper">
			<a href="?post_type=photo_product&page=photo-purchase-settings&tab=general"
				class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('一般設定', 'photo-purchase'); ?></a>
			<a href="?post_type=photo_product&page=photo-purchase-settings&tab=payment"
				class="nav-tab <?php echo $active_tab == 'payment' ? 'nav-tab-active' : ''; ?>"><?php _e('決済設定', 'photo-purchase'); ?></a>
			<a href="?post_type=photo_product&page=photo-purchase-settings&tab=bank"
				class="nav-tab <?php echo $active_tab == 'bank' ? 'nav-tab-active' : ''; ?>"><?php _e('銀行口座', 'photo-purchase'); ?></a>
			<a href="?post_type=photo_product&page=photo-purchase-settings&tab=shipping"
				class="nav-tab <?php echo $active_tab == 'shipping' ? 'nav-tab-active' : ''; ?>"><?php _e('送料設定', 'photo-purchase'); ?></a>
			<a href="?post_type=photo_product&page=photo-purchase-settings&tab=tokushoho"
				class="nav-tab <?php echo $active_tab == 'tokushoho' ? 'nav-tab-active' : ''; ?>"><?php _e('特定商取引法', 'photo-purchase'); ?></a>
			<a href="?post_type=photo_product&page=photo-purchase-settings&tab=sns"
				class="nav-tab <?php echo $active_tab == 'sns' ? 'nav-tab-active' : ''; ?>"><?php _e('SNS連携', 'photo-purchase'); ?></a>
			<a href="?post_type=photo_product&page=photo-purchase-settings&tab=abandoned_cart"
				class="nav-tab <?php echo $active_tab == 'abandoned_cart' ? 'nav-tab-active' : ''; ?>"><?php _e('かご落ち対策', 'photo-purchase'); ?></a>
			<a href="?post_type=photo_product&page=photo-purchase-settings&tab=membership_terms"
				class="nav-tab <?php echo $active_tab == 'membership_terms' ? 'nav-tab-active' : ''; ?>"><?php _e('会員規約', 'photo-purchase'); ?></a>
		</h2>

		<form method="post"
			action="?post_type=photo_product&page=photo-purchase-settings&tab=<?php echo esc_attr($active_tab); ?>">
			<?php wp_nonce_field('photo_pp_settings_action', 'photo_pp_settings_nonce'); ?>

			<?php if ($active_tab == 'general'): ?>
				<table class="form-table">
					<tr>
						<th><label for="admin_email_notif"><?php _e('受注通知メール送信先', 'photo-purchase'); ?></label></th>
						<td>
							<input type="email" name="admin_email_notif" id="admin_email_notif"
								value="<?php echo esc_attr(get_option('photo_pp_admin_notification_email', get_option('admin_email'))); ?>"
								class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="seller_name"><?php _e('表示用店舗名', 'photo-purchase'); ?></label></th>
						<td>
							<input type="text" name="seller_name" id="seller_name"
								value="<?php echo esc_attr(get_option('photo_pp_seller_name', get_bloginfo('name'))); ?>"
								class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="seller_email"><?php _e('表示用メールアドレス', 'photo-purchase'); ?></label></th>
						<td>
							<input type="email" name="seller_email" id="seller_email"
								value="<?php echo esc_attr(get_option('photo_pp_seller_email', get_option('admin_email'))); ?>"
								class="regular-text">
						</td>
					</tr>
					<tr>
						<th><?php _e('ギャラリーのカラム数', 'photo-purchase'); ?></th>
						<td>
							<select name="gallery_columns">
								<?php
								$cols = get_option('photo_pp_gallery_columns', '3');
								for ($i = 1; $i <= 6; $i++): ?>
									<option value="<?php echo $i; ?>" <?php selected($cols, $i); ?>>
										<?php echo sprintf(__('%dカラム', 'photo-purchase'), $i); ?>
									</option>
								<?php endfor; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="stock_threshold"><?php _e('在庫アラートのしきい値', 'photo-purchase'); ?></label></th>
						<td>
							<input type="number" name="stock_threshold" id="stock_threshold"
								value="<?php echo esc_attr(get_option('photo_pp_stock_threshold', '5')); ?>"
								class="small-text"> 個以下で管理者に通知
						</td>
					</tr>
					<tr>
						<th><label for="member_discount_rate"><?php _e('会員割引率 (%)', 'photo-purchase'); ?></label></th>
						<td>
							<input type="number" name="member_discount_rate" id="member_discount_rate"
								value="<?php echo esc_attr(get_option('photo_pp_member_discount_rate', '0')); ?>"
								class="small-text" min="0" max="100"> %
							<p class="description"><?php _e('ログイン中の会員に自動適用される割引率です。0で無効。', 'photo-purchase'); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="my_page_id"><?php _e('マイページ（ダッシュボード）', 'photo-purchase'); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(array(
								'name'              => 'my_page_id',
								'id'                => 'my_page_id',
								'selected'          => get_option('photo_my_page_id'),
								'show_option_none'  => '-- ' . __('自動検索', 'photo-purchase') . ' --',
								'option_none_value' => '0',
							));
							?>
							<p class="description"><?php echo sprintf(__('ショートコード %s を設置した固定ページを選択してください。', 'photo-purchase'), '<code>[ec_member_dashboard]</code>'); ?></p>
						</td>
					</tr>
				</table>

			<?php elseif ($active_tab == 'payment'): ?>
				<h3 class="title"><?php _e('決済方法の有効化', 'photo-purchase'); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php _e('利用を許可する決済', 'photo-purchase'); ?></th>
						<td>
							<label><input type="checkbox" name="enable_stripe" value="1" <?php checked(get_option('photo_pp_enable_stripe', '1'), '1'); ?>>
								<?php _e('クレジットカード (Stripe)', 'photo-purchase'); ?></label><br>
							<label><input type="checkbox" name="enable_paypay" value="1" <?php checked(get_option('photo_pp_enable_paypay', '0'), '1'); ?>>
								<?php _e('PayPay (Stripe)', 'photo-purchase'); ?></label><br>
							<label><input type="checkbox" name="enable_bank" value="1" <?php checked(get_option('photo_pp_enable_bank', '1'), '1'); ?>>
								<?php _e('銀行振込', 'photo-purchase'); ?></label><br>
							<label><input type="checkbox" name="enable_cod" value="1" <?php checked(get_option('photo_pp_enable_cod', '0'), '1'); ?>>
								<?php _e('代金引換', 'photo-purchase'); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php _e('デジタル販売', 'photo-purchase'); ?></th>
						<td>
							<label style="display:block; margin-bottom:10px;">
								<input type="checkbox" name="enable_digital_sales" value="1" <?php checked(get_option('photo_pp_enable_digital_sales', '1'), '1'); ?>>
								<?php _e('デジタルダウンロード販売を有効にする', 'photo-purchase'); ?>
							</label>
							<div style="margin-top:10px; border-top:1px solid #eee; padding-top:10px;">
								<label for="download_expiry" style="display:inline-block; width:140px;"><?php _e('DL有効期限', 'photo-purchase'); ?></label>
								<input type="number" name="download_expiry" id="download_expiry" value="<?php echo esc_attr(get_option('photo_pp_download_expiry', '7')); ?>" class="small-text"> 日間 (0で無期限)
							</div>
							<div style="margin-top:10px;">
								<label for="download_limit" style="display:inline-block; width:140px;"><?php _e('DL上限回数', 'photo-purchase'); ?></label>
								<input type="number" name="download_limit" id="download_limit" value="<?php echo esc_attr(get_option('photo_pp_download_limit', '5')); ?>" class="small-text"> 回 (0で無制限)
							</div>
						</td>
					</tr>
					<tr>
						<th><?php _e('代引き手数料 (円)', 'photo-purchase'); ?></th>
						<td>
							<p class="description">
								配送総額（商品代金＋送料）に応じた以下の段階料金が自動適用されます。
							</p>

							<table class="widefat fixed striped" style="max-width: 500px; margin-top: 10px;">
								<thead>
									<tr>
										<th>総額（送料込）</th>
										<th>手数料（税込）</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><input type="number" name="cod_tier1_limit" value="<?php echo esc_attr(get_option('photo_pp_cod_tier1_limit', '10000')); ?>" style="width: 80px;"> 円未満</td>
										<td><input type="number" name="cod_tier1_fee" value="<?php echo esc_attr(get_option('photo_pp_cod_tier1_fee', '330')); ?>" style="width: 80px;"> 円</td>
									</tr>
									<tr>
										<td><input type="number" name="cod_tier2_limit" value="<?php echo esc_attr(get_option('photo_pp_cod_tier2_limit', '30000')); ?>" style="width: 80px;"> 円未満</td>
										<td><input type="number" name="cod_tier2_fee" value="<?php echo esc_attr(get_option('photo_pp_cod_tier2_fee', '440')); ?>" style="width: 80px;"> 円</td>
									</tr>
									<tr>
										<td><input type="number" name="cod_tier3_limit" value="<?php echo esc_attr(get_option('photo_pp_cod_tier3_limit', '100000')); ?>" style="width: 80px;"> 円未満</td>
										<td><input type="number" name="cod_tier3_fee" value="<?php echo esc_attr(get_option('photo_pp_cod_tier3_fee', '660')); ?>" style="width: 80px;"> 円</td>
									</tr>
									<tr>
										<td>上記以上</td>
										<td><input type="number" name="cod_max_fee" value="<?php echo esc_attr(get_option('photo_pp_cod_max_fee', '1100')); ?>" style="width: 80px;"> 円</td>
									</tr>
								</tbody>
							</table>
						</td>
					</tr>
				</table>

				<h3 class="title"><?php _e('Stripe連携設定', 'photo-purchase'); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="stripe_pk"><?php _e('公開可能キー', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="stripe_pk" id="stripe_pk"
								value="<?php echo esc_attr(get_option('photo_pp_stripe_publishable_key', '')); ?>"
								class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="stripe_sk"><?php _e('シークレットキー', 'photo-purchase'); ?></label></th>
						<td><input type="password" name="stripe_sk" id="stripe_sk"
								value="<?php echo esc_attr(get_option('photo_pp_stripe_secret_key', '')); ?>"
								class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="stripe_webhook_secret"><?php _e('Webhook署名シークレット', 'photo-purchase'); ?></label></th>
						<td>
							<input type="password" name="stripe_webhook_secret" id="stripe_webhook_secret"
								value="<?php echo esc_attr(get_option('photo_pp_stripe_webhook_secret', '')); ?>"
								class="regular-text">
							<p class="description">
								<strong>【推奨】</strong> セキュリティ向上のため、Stripeダッシュボードの「Webhook」セクションで発行される<strong>署名シークレット（whsec_...）</strong>を入力してください。<br>
								設定すると、リクエストが本当にStripeから送信されたものか検証されるようになります。<br>
								Webhook URL: <code><?php echo esc_url(add_query_arg('photo_purchase_action', 'stripe_webhook', home_url('/'))); ?></code>
							</p>
						</td>
					</tr>
				</table>

			<?php elseif ($active_tab == 'bank'): ?>
				<table class="form-table">
					<tr>
						<th><label for="bank_name"><?php _e('銀行名', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="bank_name" id="bank_name"
								value="<?php echo esc_attr(get_option('photo_pp_bank_name', '')); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bank_branch"><?php _e('支店名', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="bank_branch" id="bank_branch"
								value="<?php echo esc_attr(get_option('photo_pp_bank_branch', '')); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="bank_type"><?php _e('口座種別', 'photo-purchase'); ?></label></th>
						<td>
							<select name="bank_type" id="bank_type">
								<?php $bt = get_option('photo_pp_bank_type', ''); ?>
								<option value="普通" <?php selected($bt, '普通'); ?>>普通</option>
								<option value="当座" <?php selected($bt, '当座'); ?>>当座</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="bank_number"><?php _e('口座番号', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="bank_number" id="bank_number"
								value="<?php echo esc_attr(get_option('photo_pp_bank_number', '')); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="bank_holder"><?php _e('口座名義', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="bank_holder" id="bank_holder"
								value="<?php echo esc_attr(get_option('photo_pp_bank_holder', '')); ?>" class="regular-text">
						</td>
					</tr>
				</table>

			<?php elseif ($active_tab == 'shipping'): ?>
				<h3 class="title"><?php _e('基本送料設定', 'photo-purchase'); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="shipping_flat_rate"><?php _e('全国一律送料 (円)', 'photo-purchase'); ?></label></th>
						<td><input type="number" name="shipping_flat_rate" id="shipping_flat_rate"
								value="<?php echo esc_attr(get_option('photo_pp_shipping_flat_rate', '500')); ?>"
								class="small-text"> 円</td>
					</tr>
					<tr>
						<th><label for="shipping_free_threshold"><?php _e('送料無料になる合計金額 (円)', 'photo-purchase'); ?></label></th>
						<td><input type="number" name="shipping_free_threshold" id="shipping_free_threshold"
								value="<?php echo esc_attr(get_option('photo_pp_shipping_free_threshold', '5000')); ?>"
								class="small-text"> 円以上で送料無料（0で無効）</td>
					</tr>
					<tr>
						<th><label for="shipping_carrier"><?php _e('配送業者名', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="shipping_carrier" id="shipping_carrier"
								value="<?php echo esc_attr(get_option('photo_pp_shipping_carrier', '日本郵便')); ?>"
								class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="delivery_days"><?php _e('お届けまでの日数目安', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="delivery_days" id="delivery_days"
								value="<?php echo esc_attr(get_option('photo_pp_shipping_delivery_days', 'ご注文確定後、通常3〜5営業日以内に発送いたします。')); ?>"
								class="large-text"></td>
					</tr>
					<tr>
						<th><label for="time_slots"><?php _e('日時指定の可否・時間帯', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="time_slots" id="time_slots"
								value="<?php echo esc_attr(get_option('photo_pp_shipping_time_slots', '日時指定は承っておりません。')); ?>"
								class="large-text"></td>
					</tr>
					<tr>
						<th><label for="international_shipping"><?php _e('海外発送の可否', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="international_shipping" id="international_shipping"
								value="<?php echo esc_attr(get_option('photo_pp_shipping_international', '配送は日本国内のみとさせていただきます。')); ?>"
								class="large-text"></td>
					</tr>
					<tr>
						<th><label for="payment_fee_desc"><?php _e('各決済方法の手数料について', 'photo-purchase'); ?></label></th>
						<td><textarea name="payment_fee_desc" id="payment_fee_desc" rows="3"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_payment_fee_desc', '銀行振込手数料はお客様負担となります。代引きをご利用の場合は、別途代引き手数料がかかります。')); ?></textarea>
						</td>
					</tr>
				</table>

				<h3 class="title"><?php _e('都道府県別料金設定', 'photo-purchase'); ?></h3>
				<p class="description"><?php _e('特定の都道府県のみ送料を変えたい場合に入力してください。空欄の場合は一律送料が適用されます。', 'photo-purchase'); ?></p>

				<div style="margin-bottom: 15px; background: #fff; padding: 15px; border: 1px solid #ccd0d4;">
					<strong><?php _e('一括操作:', 'photo-purchase'); ?></strong>
					<input type="number" id="global-bulk-input" placeholder="<?php _e('金額', 'photo-purchase'); ?>"
						style="width: 80px;">
					<button type="button" class="button"
						id="global-bulk-apply"><?php _e('選択中の県に適用', 'photo-purchase'); ?></button>
					<button type="button" class="button"
						id="global-bulk-clear"><?php _e('選択中をクリア', 'photo-purchase'); ?></button>
					<span
						style="margin-left: 15px; color: #666; font-size: 0.9rem;"><?php _e('※チェックを入れた都道府県に対して一括操作を行います。', 'photo-purchase'); ?></span>
				</div>

				<div style="max-height: 600px; overflow-y: auto; background: #fff; border: 1px solid #ccd0d4;">
					<?php
					$regions = photo_purchase_get_regions();
					$current_rates = get_option('photo_pp_shipping_prefecture_rates', array());
					?>
					<style>
						.shipping-rates-table th,
						.shipping-rates-table td {
							vertical-align: middle;
						}

						.region-header {
							background: #f0f0f1 !important;
						}

						.region-header td {
							font-weight: bold;
							border-top: 2px solid #ccd0d4 !important;
							padding: 10px !important;
						}

						.pref-row td:nth-child(2) {
							padding-left: 30px !important;
							position: relative;
						}

						.pref-row td:nth-child(2)::before {
							content: "└";
							position: absolute;
							left: 15px;
							color: #999;
						}

						.region-bulk-input {
							width: 80px !important;
							margin-right: 5px !important;
						}
					</style>
					<table class="wp-list-table widefat fixed striped shipping-rates-table">
						<thead>
							<tr>
								<th style="width: 40px;"><input type="checkbox" id="check-all-prefs"></th>
								<th><?php _e('都道府県 / 地方', 'photo-purchase'); ?></th>
								<th><?php _e('送料 (円)', 'photo-purchase'); ?></th>
								<th><?php _e('地域一括設定', 'photo-purchase'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($regions as $region_name => $prefs): ?>
								<tr class="region-header">
									<td><input type="checkbox" class="region-checkbox"
											data-region="<?php echo esc_attr($region_name); ?>"></td>
									<td colspan="2"><?php echo esc_html($region_name); ?></td>
									<td>
										<input type="number" class="region-bulk-input"
											placeholder="<?php _e('地域価格', 'photo-purchase'); ?>">
										<button type="button" class="button region-apply-btn"
											data-region="<?php echo esc_attr($region_name); ?>"><?php _e('適用', 'photo-purchase'); ?></button>
									</td>
								</tr>
								<?php foreach ($prefs as $pref): ?>
									<tr class="pref-row" data-region="<?php echo esc_attr($region_name); ?>">
										<td><input type="checkbox" class="pref-checkbox" data-pref="<?php echo esc_attr($pref); ?>">
										</td>
										<td><?php echo esc_html($pref); ?></td>
										<td>
											<input type="number" name="pref_rates[<?php echo esc_attr($pref); ?>]"
												value="<?php echo isset($current_rates[$pref]) ? esc_attr($current_rates[$pref]) : ''; ?>"
												class="small-text pref-rate-input"> 円
										</td>
										<td></td>
									</tr>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

			<?php elseif ($active_tab == 'tokushoho'): ?>
				<table class="form-table">
					<tr>
						<th><label for="tokusho_name"><?php _e('販売業者', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="tokusho_name" id="tokusho_name"
								value="<?php echo esc_attr(get_option('photo_pp_tokusho_name', get_option('photo_pp_seller_name'))); ?>"
								class="regular-text">
							<p class="description">法人の場合は登記されている正式な会社名。個人の場合は屋号、または氏名。</p>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_ceo"><?php _e('運営統括責任者名', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="tokusho_ceo" id="tokusho_ceo"
								value="<?php echo esc_attr(get_option('photo_pp_tokusho_ceo', '')); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_address"><?php _e('所在地', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="tokusho_address" id="tokusho_address"
								value="<?php echo esc_attr(get_option('photo_pp_tokusho_address', '')); ?>" class="large-text">
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_tel"><?php _e('電話番号', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="tokusho_tel" id="tokusho_tel"
								value="<?php echo esc_attr(get_option('photo_pp_tokusho_tel', '')); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_email"><?php _e('連絡先メールアドレス', 'photo-purchase'); ?></label></th>
						<td><input type="email" name="tokusho_email" id="tokusho_email"
								value="<?php echo esc_attr(get_option('photo_pp_tokusho_email', get_option('admin_email'))); ?>"
								class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="tokusho_url"><?php _e('サイトURL', 'photo-purchase'); ?></label></th>
						<td><input type="url" name="tokusho_url" id="tokusho_url"
								value="<?php echo esc_attr(get_option('photo_pp_tokusho_url', home_url())); ?>"
								class="large-text">
							<p class="description">メールのフッターや特定商取引法の表記に使用されます。</p>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_registration_number"><?php _e('インボイス登録番号', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="tokusho_registration_number" id="tokusho_registration_number"
								value="<?php echo esc_attr(get_option('photo_pp_tokusho_registration_number', '')); ?>"
								class="regular-text" placeholder="T1234567890123">
							<p class="description">適格請求書発行事業者の登録番号を入力してください。</p>
						</td>
					</tr>
					<tr>
						<th><label for="tax_rate_standard"><?php _e('標準税率 (%)', 'photo-purchase'); ?></label></th>
						<td><input type="number" name="tax_rate_standard" id="tax_rate_standard"
								value="<?php echo esc_attr(get_option('photo_pp_tax_rate_standard', '10')); ?>"
								class="small-text"> %</td>
					</tr>
					<tr>
						<th><label for="tax_rate_reduced"><?php _e('軽減税率 (%)', 'photo-purchase'); ?></label></th>
						<td><input type="number" name="tax_rate_reduced" id="tax_rate_reduced"
								value="<?php echo esc_attr(get_option('photo_pp_tax_rate_reduced', '8')); ?>"
								class="small-text"> %</td>
					</tr>
					<tr>
						<th><label for="tokusho_price"><?php _e('販売価格', 'photo-purchase'); ?></label></th>
						<td><textarea name="tokusho_price" id="tokusho_price" rows="2"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_tokusho_price', '各商品詳細ページに税込価格で表示しています。')); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_price_other"><?php _e('商品代金以外の必要料金', 'photo-purchase'); ?></label></th>
						<td><textarea name="tokusho_price_other" id="tokusho_price_other" rows="3"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_tokusho_price_other', "送料（詳細は「送料・お支払いについて」をご確認ください）\n消費税（商品代金に含まれます）\n銀行振込手数料、代引き手数料（お客様負担）")); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_payment_method"><?php _e('お支払方法', 'photo-purchase'); ?></label></th>
						<td>
							<textarea name="tokusho_payment_method" id="tokusho_payment_method" rows="2"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_tokusho_payment_method', photo_purchase_get_active_payment_methods_text('desc'))); ?></textarea>
							<p class="description"><?php echo sprintf(__('現在の有効な決済: %s', 'photo-purchase'), photo_purchase_get_active_payment_methods_text('label')); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_payment_deadline"><?php _e('お支払時期（期限）', 'photo-purchase'); ?></label></th>
						<td><textarea name="tokusho_payment_deadline" id="tokusho_deadline" rows="3"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_tokusho_payment_deadline', "クレジットカード・PayPay：注文時に決済されます。\n銀行振込：注文後7日以内にお振り込みください。\n代金引換：商品引渡時にお支払いください。")); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_delivery_time"><?php _e('商品の引渡時期', 'photo-purchase'); ?></label></th>
						<td><textarea name="tokusho_delivery_time" id="tokusho_delivery" rows="3"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_tokusho_delivery_time', '通常、ご注文（銀行振込の場合はご入金）確認後、5営業日以内に発送いたします。')); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_return"><?php _e('返品・交換・キャンセルについて', 'photo-purchase'); ?></label></th>
						<td><textarea name="tokusho_return" id="tokusho_return" rows="5"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_tokusho_return', "【不良品の場合】商品到着後7日以内にご連絡ください。弊社負担にて良品と交換させていただきます。\n【お客様都合による返品】商品の性質上、イメージ違いなどお客様都合による返品・交換は受け付けておりません。")); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_sub_cycle"><?php _e('定期購入の課金サイクル', 'photo-purchase'); ?></label></th>
						<td><textarea name="tokusho_sub_cycle" id="tokusho_sub_cycle" rows="3"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_tokusho_sub_cycle', '各商品ごとに設定された周期（1ヶ月ごと等）で自動的に課金されます。次回決済日の前日までにお客様自身でマイページより解約手続きを行うことで、次回の課金を停止できます。')); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="tokusho_sub_cancellation"><?php _e('定期購入の解約について', 'photo-purchase'); ?></label></th>
						<td><textarea name="tokusho_sub_cancellation" id="tokusho_sub_cancellation" rows="5"
								class="large-text"><?php echo esc_textarea(get_option('photo_pp_tokusho_sub_cancellation', "マイページの「サブスク管理」よりいつでも解約手続きが可能です。\n解約手続き後も、現在の有効期間（すでにお支払い済みの期間）終了まではサービスをご利用いただけます。\n期間途中の解約による日割り計算での返金は行われません。")); ?></textarea>
						</td>
					</tr>
				</table>
			<?php elseif ($active_tab == 'sns'): ?>
				<h3 class="title"><?php _e('SNS連携の有効化', 'photo-purchase'); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php _e('SNSログイン機能', 'photo-purchase'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enable_sns_login" value="1" <?php checked(get_option('photo_pp_enable_sns_login', '1'), '1'); ?>>
								<?php _e('SNSログインを有効にする', 'photo-purchase'); ?>
							</label>
						</td>
					</tr>
				</table>

				<h3 class="title"><?php _e('Google ログイン設定', 'photo-purchase'); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="google_client_id"><?php _e('クライアントID', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="google_client_id" id="google_client_id" value="<?php echo esc_attr(get_option('photo_pp_google_client_id', '')); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th><label for="google_client_secret"><?php _e('クライアントシークレット', 'photo-purchase'); ?></label></th>
						<td><input type="password" name="google_client_secret" id="google_client_secret" value="<?php echo esc_attr(get_option('photo_pp_google_client_secret', '')); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th><?php _e('リダイレクトURI', 'photo-purchase'); ?></th>
						<td><code><?php echo esc_url(home_url('/?pp_sns_callback=google')); ?></code></td>
					</tr>
				</table>

				<hr>

				<h3 class="title"><?php _e('LINE ログイン設定', 'photo-purchase'); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="line_client_id"><?php _e('Channel ID', 'photo-purchase'); ?></label></th>
						<td><input type="text" name="line_client_id" id="line_client_id" value="<?php echo esc_attr(get_option('photo_pp_line_client_id', '')); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th><label for="line_client_secret"><?php _e('Channel Secret', 'photo-purchase'); ?></label></th>
						<td><input type="password" name="line_client_secret" id="line_client_secret" value="<?php echo esc_attr(get_option('photo_pp_line_client_secret', '')); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th><?php _e('リダイレクトURI', 'photo-purchase'); ?></th>
						<td><code><?php echo esc_url(home_url('/?pp_sns_callback=line')); ?></code></td>
					</tr>
				</table>
				</table>
			<?php elseif ($active_tab == 'abandoned_cart'): ?>
				<h3 class="title"><?php _e('かご落ちリカバリーの設定', 'photo-purchase'); ?></h3>
				<p class="description">
					カートに商品を入れたまま離脱したユーザーに対して、自動的にリマインドメールを送信します。
				</p>
				<table class="form-table">
					<tr>
						<th><?php _e('機能の有効化', 'photo-purchase'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enable_abandoned_cart" value="1" <?php checked(get_option('photo_pp_enable_abandoned_cart', '0'), '1'); ?>>
								<?php _e('かご落ちリカバリーメールを自動送信する', 'photo-purchase'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="abandoned_cart_delay"><?php _e('送信タイミング', 'photo-purchase'); ?></label></th>
						<td>
							<?php $delay = get_option('photo_pp_abandoned_cart_delay', '24'); ?>
							<select name="abandoned_cart_delay" id="abandoned_cart_delay">
								<option value="3" <?php selected($delay, '3'); ?>><?php _e('3時間後', 'photo-purchase'); ?></option>
								<option value="24" <?php selected($delay, '24'); ?>><?php _e('24時間後 (推奨)', 'photo-purchase'); ?></option>
								<option value="48" <?php selected($delay, '48'); ?>><?php _e('48時間後', 'photo-purchase'); ?></option>
							</select>
							<p class="description"><?php _e('カートが最後に更新されてから、指定した時間が経過した後にメールを送信します。', 'photo-purchase'); ?></p>
						</td>
					</tr>
				</table>

				<h3 class="title"><?php _e('リカバリー状況 (統計)', 'photo-purchase'); ?></h3>
				<?php
				global $wpdb;
				$table_name = $wpdb->prefix . 'photo_abandoned_carts';
				$stats = $wpdb->get_row("
					SELECT 
						COUNT(*) as total,
						SUM(CASE WHEN reminder_sent_count > 0 THEN 1 ELSE 0 END) as sent,
						SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked,
						SUM(CASE WHEN status = 'recovered' THEN 1 ELSE 0 END) as recovered,
						SUM(CASE WHEN unsubscribed = 1 THEN 1 ELSE 0 END) as unsubscribed
					FROM $table_name
				");
				$recovery_rate = ($stats->sent > 0) ? round(($stats->recovered / $stats->sent) * 100, 1) : 0;
				$click_rate = ($stats->sent > 0) ? round(($stats->clicked / $stats->sent) * 100, 1) : 0;
				?>
				<div class="photo-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-top: 20px;">
					<div style="background:#fff; padding:20px; border-radius:8px; border:1px solid #ddd; text-align:center;">
						<div style="font-size:12px; color:#666;"><?php _e('捕捉済みカート', 'photo-purchase'); ?></div>
						<div style="font-size:24px; font-weight:bold; color:#333;"><?php echo number_format($stats->total); ?></div>
					</div>
					<div style="background:#fff; padding:20px; border-radius:8px; border:1px solid #ddd; text-align:center;">
						<div style="font-size:12px; color:#666;"><?php _e('メール送信数', 'photo-purchase'); ?></div>
						<div style="font-size:24px; font-weight:bold; color:#0073aa;"><?php echo number_format($stats->sent); ?></div>
					</div>
					<div style="background:#fff; padding:20px; border-radius:8px; border:1px solid #ddd; text-align:center;">
						<div style="font-size:12px; color:#666;"><?php _e('クリック数 (率)', 'photo-purchase'); ?></div>
						<div style="font-size:24px; font-weight:bold; color:#dfb613;"><?php echo number_format($stats->clicked); ?> <span style="font-size:14px; font-weight:normal;">(<?php echo $click_rate; ?>%)</span></div>
					</div>
					<div style="background:#fff; padding:20px; border-radius:8px; border:1px solid #ddd; text-align:center;">
						<div style="font-size:12px; color:#666;"><?php _e('リカバリー成功 (率)', 'photo-purchase'); ?></div>
						<div style="font-size:24px; font-weight:bold; color:#28a745;"><?php echo number_format($stats->recovered); ?> <span style="font-size:14px; font-weight:normal;">(<?php echo $recovery_rate; ?>%)</span></div>
					</div>
					<div style="background:#fff; padding:20px; border-radius:8px; border:1px solid #ddd; text-align:center;">
						<div style="font-size:12px; color:#666;"><?php _e('配信停止数', 'photo-purchase'); ?></div>
						<div style="font-size:24px; font-weight:bold; color:#dc3545;"><?php echo number_format($stats->unsubscribed); ?></div>
					</div>
				</div>
			<?php elseif ($active_tab == 'membership_terms'): ?>
				<h3 class="title"><?php _e('会員規約の設定', 'photo-purchase'); ?></h3>
				<p class="description">
					会員登録や購入時に適用される会員規約、または利用規約をこちらに入力してください。<br>
					入力した内容は <code>[ec_membership_terms]</code> ショートコードで表示できます。<br>
					（`/membership-terms/` というスラッグの固定ページを作成し、ショートコードを貼り付けることを推奨します）
				</p>
				<table class="form-table">
					<tr>
						<th><label for="membership_terms"><?php _e('規約本文', 'photo-purchase'); ?></label></th>
						<td>
							<textarea name="membership_terms" id="membership_terms" rows="20" class="large-text" style="font-family: inherit;"><?php echo esc_textarea(get_option('photo_pp_membership_terms', '')); ?></textarea>
							<p class="description"> HTML（p, br, strong, aタグ等）が使用可能です。</p>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<p class="submit">
				<input type="submit" name="photo_pp_save_settings" class="button button-primary"
					value="<?php _e('設定を保存', 'photo-purchase'); ?>">
			</p>
		</form>
	</div>
	<script>
		jQuery(document).ready(function ($) {
			// Region toggle: check/uncheck all prefs in region
			$('.region-checkbox').on('change', function () {
				var region = $(this).data('region');
				$('.pref-row[data-region="' + region + '"] .pref-checkbox').prop('checked', $(this).prop('checked'));
			});

			// Master toggle: check/uncheck all
			$('#check-all-prefs').on('change', function () {
				$('.pref-checkbox, .region-checkbox').prop('checked', $(this).prop('checked'));
			});

			// Region bulk apply
			$('.region-apply-btn').on('click', function (e) {
				e.preventDefault();
				var region = $(this).data('region');
				var val = $(this).closest('tr').find('.region-bulk-input').val();

				$('.pref-row[data-region="' + region + '"]').each(function () {
					if ($(this).find('.pref-checkbox').prop('checked')) {
						$(this).find('.pref-rate-input').val(val);
					}
				});
			});

			// Global bulk apply
			$('#global-bulk-apply').on('click', function (e) {
				e.preventDefault();
				var val = $('#global-bulk-input').val();

				$('.pref-row').each(function () {
					if ($(this).find('.pref-checkbox').prop('checked')) {
						$(this).find('.pref-rate-input').val(val);
					}
				});
			});

			// Global bulk clear
			$('#global-bulk-clear').on('click', function (e) {
				e.preventDefault();
				if (!confirm('<?php _e('選択中の都道府県の送料をすべて空欄にしますか？', 'photo-purchase'); ?>')) return;
				$('.pref-row').each(function () {
					if ($(this).find('.pref-checkbox').prop('checked')) {
						$(this).find('.pref-rate-input').val('');
					}
				});
			});
		});
	</script>
	<?php
}

/**
 * Shortcode: Specified Commercial Transactions Act
 */
function photo_purchase_tokushoho_shortcode()
{
	$name = get_option('photo_pp_tokusho_name', get_option('photo_pp_seller_name'));
	$ceo = get_option('photo_pp_tokusho_ceo');
	$address = get_option('photo_pp_tokusho_address');
	$tel = get_option('photo_pp_tokusho_tel');
	$email = get_option('photo_pp_tokusho_email');
	$registration_number = get_option('photo_pp_tokusho_registration_number');
	$price = get_option('photo_pp_tokusho_price', '各商品詳細ページに税込価格で表示しています。');
	$price_other = get_option('photo_pp_tokusho_price_other');
	$payment_method = get_option('photo_pp_tokusho_payment_method');
	if (empty($payment_method)) {
		$payment_method = photo_purchase_get_active_payment_methods_text('desc');
	}
	$limit = get_option('photo_pp_tokusho_payment_deadline');
	$time = get_option('photo_pp_tokusho_delivery_time');
	$return = get_option('photo_pp_tokusho_return');
	$sub_cycle = get_option('photo_pp_tokusho_sub_cycle');
	$sub_cancellation = get_option('photo_pp_tokusho_sub_cancellation');

	ob_start();
	?>
	<div class="photo-tokushoho" style="margin: 20px 0; font-family: sans-serif; color: #333; line-height: 1.6;">
		<table
			style="width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #eee;">
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="width: 30%; padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					販売業者</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo esc_html($name); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					運営統括責任者名</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo esc_html($ceo); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					所在地</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($address)); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					電話番号</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo esc_html($tel); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					連絡先メールアドレス</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo esc_html($email); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					URL</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;">
					<a href="<?php echo esc_url(get_option('photo_pp_tokusho_url', home_url())); ?>" target="_blank">
						<?php echo esc_html(get_option('photo_pp_tokusho_url', home_url())); ?>
					</a>
				</td>
			</tr>
			<?php if (!empty($registration_number)): ?>
				<tr style="border-bottom: 1px solid #f0f0f0;">
					<th
						style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
						登録番号</th>
					<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo esc_html($registration_number); ?></td>
				</tr>
			<?php endif; ?>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					販売価格</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($price)); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					商品代金以外の必要料金</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($price_other)); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					お支払方法</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($payment_method)); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					お支払時期（期限）</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($limit)); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					商品の引渡時期</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($time)); ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					返品・交換・キャンセルについて</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($return)); ?></td>
			</tr>
			<?php if (!empty($sub_cycle)): ?>
			<tr style="border-bottom: 1px solid #f0f0f0;">
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					定期購入の課金サイクル</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($sub_cycle)); ?></td>
			</tr>
			<?php endif; ?>
			<?php if (!empty($sub_cancellation)): ?>
			<tr>
				<th
					style="padding: 15px 20px; background: #f8f9fa; text-align: left; font-weight: 600; font-size: 0.95rem;">
					定期購入の解約について</th>
				<td style="padding: 15px 20px; font-size: 0.95rem;"><?php echo nl2br(esc_html($sub_cancellation)); ?></td>
			</tr>
			<?php endif; ?>
		</table>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode('ec_tokushoho', 'photo_purchase_tokushoho_shortcode');

/**
 * Shortcode: Membership Terms
 */
function photo_purchase_membership_terms_shortcode()
{
	$terms = get_option('photo_pp_membership_terms');
	if (empty($terms)) {
		return '<p>' . __('会員規約が設定されていません。', 'photo-purchase') . '</p>';
	}

	ob_start();
	?>
	<div class="photo-membership-terms" style="margin: 20px 0; font-family: sans-serif; color: #333; line-height: 1.8; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #eee;">
		<?php echo wpautop(do_shortcode(wp_kses_post($terms))); ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode('ec_membership_terms', 'photo_purchase_membership_terms_shortcode');

/**
 * Shortcode: Shop Name
 */
function photo_purchase_shop_name_shortcode()
{
	return get_bloginfo('name');
}
add_shortcode('ec_shop_name', 'photo_purchase_shop_name_shortcode');
add_shortcode('ショップ名', 'photo_purchase_shop_name_shortcode');

/**
 * Shortcode: Shipping and Payment Info
 */
function photo_purchase_shipping_payment_shortcode()
{
	$carrier = get_option('photo_pp_shipping_carrier');
	$flat_rate = get_option('photo_pp_shipping_flat_rate', '500');
	$free_threshold = get_option('photo_pp_shipping_free_threshold', '5000');
	$pref_rates = get_option('photo_pp_shipping_prefecture_rates', array());
	$delivery_days = get_option('photo_pp_shipping_delivery_days');
	$time_slots = get_option('photo_pp_shipping_time_slots');
	$international = get_option('photo_pp_shipping_international', '配送は日本国内のみとさせていただきます。');
	$fee_desc = get_option('photo_pp_payment_fee_desc');
	$method = get_option('photo_pp_tokusho_payment_method');
	if (empty($method)) {
		$method = photo_purchase_get_active_payment_methods_text('desc');
	}

	$bank_name = get_option('photo_pp_bank_name');
	$bank_branch = get_option('photo_pp_bank_branch');
	$bank_type = get_option('photo_pp_bank_type');
	$bank_num = get_option('photo_pp_bank_number');
	$bank_holder = get_option('photo_pp_bank_holder');

	ob_start();
	?>
	<div class="photo-shipping-payment" style="margin: 20px 0; font-family: sans-serif; color: #333; line-height: 1.6;">
		<h3 style="margin-bottom: 15px; border-left: 5px solid #007bff; padding-left: 15px; font-size: 1.25rem;">配送について</h3>
		<div
			style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #eee; margin-bottom: 25px;">
			<p style="margin-bottom: 15px;"><strong>配送業者:</strong> <?php echo esc_html($carrier); ?></p>

			<?php
			$show_flat = ($flat_rate !== '' && $flat_rate !== false && $flat_rate !== null);
			$show_free = (is_numeric($free_threshold) && intval($free_threshold) > 0);
			if ($show_flat || $show_free): ?>
				<p style="margin-bottom: 15px;">
					<strong>送料について:</strong><br>
					<?php if ($show_flat): ?>
						全国一律: <?php echo number_format(intval($flat_rate)); ?> 円<br>
					<?php endif; ?>
					<?php if ($show_free): ?>
						※ <?php echo number_format(intval($free_threshold)); ?> 円以上のお買い上げで<strong>送料無料</strong><br>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if (!empty($pref_rates)): ?>
				<div
					style="margin-top: 10px; font-size: 0.9rem; background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
					<strong>【地域別送料】</strong><br>
					<?php
					$regions_list = photo_purchase_get_regions();
					foreach ($regions_list as $region_name => $prefs):
						$region_output = array();
						foreach ($prefs as $pref) {
							if (isset($pref_rates[$pref]) && $pref_rates[$pref] !== '') {
								$region_output[] = esc_html($pref) . ': ' . number_format(intval($pref_rates[$pref])) . '円';
							}
						}
						if (!empty($region_output)):
							?>
							<div style="margin-top: 8px;">
								<span style="font-weight: bold; color: #555;">[<?php echo esc_html($region_name); ?>]</span><br>
								<div
									style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 5px; margin-top: 3px;">
									<?php foreach ($region_output as $row): ?>
										<span><?php echo $row; ?></span>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; endforeach; ?>
				</div>
			<?php endif; ?>

			<p style="margin-bottom: 15px;"><strong>お届けにかかる日数:</strong> <?php echo esc_html($delivery_days); ?></p>
			<p style="margin-bottom: 15px;"><strong>配送日時指定:</strong> <?php echo esc_html($time_slots); ?></p>
			<p style="margin-bottom: 0;"><strong>海外発送:</strong> <?php echo esc_html($international); ?></p>
		</div>

		<h3 style="margin-bottom: 15px; border-left: 5px solid #28a745; padding-left: 15px; font-size: 1.25rem;">お支払いについて
		</h3>
		<div
			style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #eee;">
			<p style="margin-bottom: 15px;"><strong>お支払方法:</strong><br><?php echo nl2br(esc_html($method)); ?></p>

			<?php if (!empty($fee_desc)): ?>
				<p style="margin-bottom: 15px;"><strong>手数料について:</strong><br><?php echo nl2br(esc_html($fee_desc)); ?></p>
			<?php endif; ?>

			<?php if (!empty($bank_num)): ?>
				<div
					style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #dee2e6; margin-top: 20px;">
					<h4 style="margin: 0 0 10px 0; font-size: 1rem; color: #495057;">銀行振込先</h4>
					<p style="margin: 0; font-size: 0.95rem;">
						<strong>銀行名:</strong> <?php echo esc_html($bank_name); ?><br>
						<strong>支店名:</strong> <?php echo esc_html($bank_branch); ?><br>
						<strong>口座種別:</strong> <?php echo esc_html($bank_type); ?><br>
						<strong>口座番号:</strong> <?php echo esc_html($bank_num); ?><br>
						<strong>口座名義:</strong> <?php echo esc_html($bank_holder); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode('ec_shipping_payment', 'photo_purchase_shipping_payment_shortcode');

/**
 * Activation Hook
 */
function photo_purchase_activate()
{
	photo_purchase_register_post_type();
	photo_purchase_create_db_table();

	// Create required pages
	$pages = array(
		'photo_tokusho_page_id' => array(
			'title' => '特定商取引法に基づく表記',
			'slug'  => 'tokushoho',
			'content' => '[ec_tokushoho]',
		),
		'photo_shipping_payment_page_id' => array(
			'title' => '送料・お支払いについて',
			'slug'  => 'shipping-payment',
			'content' => '[ec_shipping_payment]',
		),
		'photo_products_page_id' => array(
			'title' => '商品一覧',
			'slug'  => 'products',
			'content' => '[ec_gallery] [ec_cart_indicator]',
		),
		'photo_cart_page_id' => array(
			'title' => 'ショッピングカート',
			'slug'  => 'cart',
			'content' => '[ec_checkout]',
		),
		'photo_order_inquiry_page_id' => array(
			'title' => '注文照会',
			'slug'  => 'order-inquiry',
			'content' => '[ec_order_inquiry]',
		),
		'photo_my_page_id' => array(
			'title' => 'マイページ',
			'slug'  => 'my-page',
			'content' => '[ec_member_dashboard]',
		),
		'photo_membership_terms_page_id' => array(
			'title' => '会員規約',
			'slug'  => 'membership-terms',
			'content' => '[ec_membership_terms]',
		),
	);

	// 旧設定からの移行処理（名称変更に伴う対応）
	$old_portal_id = get_option('photo_customer_portal_page_id');
	if ($old_portal_id && !get_option('photo_my_page_id')) {
		update_option('photo_my_page_id', $old_portal_id);
	}

	// デフォルトの会員規約を設定
	if (!get_option('photo_pp_membership_terms')) {
		$default_terms = "第1条（適用）\n本規約は、[ショップ名]（以下「当ショップ」）が運営するオンラインショップ（以下「本サービス」）の利用条件を定めるものです。本サービスの利用者（以下「会員」）は、本規約に従って本サービスを利用するものとします。\n\n第2条（会員登録）\n入会希望者が当ショップの定める方法によって利用登録を申請し、当ショップがこれを承認することによって、利用登録が完了するものとします。\n\n当ショップは、以下の事由があると判断した場合、利用登録の申請を承認しないことがあり、その理由については一切の開示義務を負わないものとします。\n\n虚偽の事項を届け出た場合\n\n本規約に違反したことがある者からの申請である場合\n\nその他、当ショップが利用登録を相当でないと判断した場合\n\n第3条（IDおよびパスワードの管理）\n会員は、自己の責任において、本サービスのユーザーIDおよびパスワードを適切に管理するものとします。\n\n会員は、いかなる場合にも、ユーザーIDおよびパスワードを第三者に譲渡または貸与し、もしくは第三者と共用することはできません。\n\n第4条（売買契約）\n本サービスにおいては、会員が当ショップに対して購入の申し込みをし、これに対して当ショップが当該申し込みを承諾した旨の通知を送付した時点で、売買契約が成立するものとします。\n\n商品の所有権は、当ショップが商品を配送業者に引き渡した時点で、会員に移転するものとします。\n\n第5条（返品・交換）\n商品の返品または交換は、商品到着後[7]日以内、かつ未使用の場合に限り受け付けるものとします。ただし、商品の欠陥や不良など当ショップの責めに帰すべき事由がある場合は、この限りではありません。\n\n第6条（禁止事項）\n会員は、本サービスの利用にあたり、以下の行為をしてはなりません。\n\n法令または公序良俗に違反する行為\n\n犯罪行為に関連する行為\n\n本サービスに含まれる著作権、商標権ほか知的財産権を侵害する行為\n\n他の会員または第三者に不利益、損害、不快感を与える行為\n\n本サービスの運営を妨害するおそれのある行為\n\n第7条（本サービスの提供の停止等）\n当ショップは、以下のいずれかの事由があると判断した場合、会員に事前に通知することなく本サービスの全部または一部の提供を停止または中断することができるものとします。\n\nシステムの保守点検または更新を行う場合\n\n地震、落雷、火災、停電または天災などの不可抗力により、本サービスの提供が困難となった場合\n\nその他、当ショップが本サービスの提供が困難と判断した場合\n\n第8条（利用制限および登録抹消）\n当ショップは、会員が本規約のいずれかの条項に違反した場合、事前の通知なく、会員に対して本サービスの全部もしくは一部の利用を制限し、または会員としての登録を抹消することができるものとします。\n\n第9条（退会）\n会員は、当ショップの定める退会手続により、本サービスから退会できるものとします。\n\n第10条（規約の変更）\n当ショップは、必要と判断した場合には、会員に通知することなくいつでも本規約を変更することができるものとします。\n\n第11条（個人情報の取扱い）\n当ショップは、本サービスの利用によって取得する個人情報については、当ショップ「プライバシーポリシー」に従い適切に取り扱うものとします。\n\n第12条（準拠法・裁判管轄）\n本規約の解釈にあたっては、日本法を準拠法とします。\n\n本サービスに関して紛争が生じた場合には、当ショップの本店所在地を管轄する裁判所を専属的合意管轄とします。";
		update_option('photo_pp_membership_terms', $default_terms);
	}

	foreach ($pages as $option_key => $page_data) {
		$page_id = get_option($option_key);
		if (!$page_id || !get_post($page_id)) {
			$new_page_id = wp_insert_post(array(
				'post_title' => $page_data['title'],
				'post_name'  => $page_data['slug'],
				'post_content' => $page_data['content'],
				'post_status' => 'publish',
				'post_type' => 'page',
			));
			if (!is_wp_error($new_page_id)) {
				update_option($option_key, $new_page_id);
			}
		}
	}

	flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'photo_purchase_activate');

/**
 * Deactivation Hook
 */
function photo_purchase_deactivate()
{
	flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'photo_purchase_deactivate');

// Include required files
require_once PHOTO_PURCHASE_PATH . 'includes/admin-meta.php';
require_once PHOTO_PURCHASE_PATH . 'includes/frontend-display.php';
require_once PHOTO_PURCHASE_PATH . 'includes/payment-handler.php';
require_once PHOTO_PURCHASE_PATH . 'includes/cart-system.php';
require_once PHOTO_PURCHASE_PATH . 'includes/order-manager.php';
require_once PHOTO_PURCHASE_PATH . 'includes/admin-log.php';
require_once PHOTO_PURCHASE_PATH . 'includes/stripe-webhooks.php';
require_once PHOTO_PURCHASE_PATH . 'includes/sns-handler.php';
require_once PHOTO_PURCHASE_PATH . 'includes/auth-system.php';
require_once PHOTO_PURCHASE_PATH . 'includes/abandoned-cart.php';

/**
 * Enqueue Frontend Assets
 */
function photo_purchase_enqueue_assets()
{
	// Google Fonts: Inter
	wp_enqueue_style('photo-purchase-fonts', 'https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap', array(), null);
	
	wp_enqueue_style('photo-purchase-style', PHOTO_PURCHASE_URL . 'assets/css/style.css', array(), PHOTO_PURCHASE_VERSION);
}
add_action('wp_enqueue_scripts', 'photo_purchase_enqueue_assets');

/**
 * Enqueue Admin Assets
 */
function photo_purchase_enqueue_admin_assets($hook)
{
	$screen = get_current_screen();
	if (!$screen) return;

	if (strpos($hook, 'photo_product') !== false || strpos($hook, 'photo-purchase') !== false || $screen->post_type === 'photo_product') {
		wp_enqueue_style('photo-purchase-admin', PHOTO_PURCHASE_URL . 'assets/css/admin.css', array(), PHOTO_PURCHASE_VERSION);
		
		// Enqueue Sorting Script on Product List
		if ($screen->id === 'edit-photo_product') {
			wp_enqueue_script('jquery-ui-sortable');
			wp_enqueue_script('photo-purchase-admin-sort', PHOTO_PURCHASE_URL . 'assets/js/admin-sort.js', array('jquery', 'jquery-ui-sortable'), PHOTO_PURCHASE_VERSION, true);
			wp_localize_script('photo-purchase-admin-sort', 'photoSortData', array(
				'nonce' => wp_create_nonce('photo_update_order_nonce')
			));
		}
	}
}
add_action('admin_enqueue_scripts', 'photo_purchase_enqueue_admin_assets');

/**
 * Force menu_order sorting for photo_product
 */
add_action('pre_get_posts', 'photo_purchase_force_menu_order');
function photo_purchase_force_menu_order($query) {
    if (!is_admin() && $query->is_main_query() && is_post_type_archive('photo_product')) {
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }
    
    if (is_admin() && $query->get('post_type') === 'photo_product') {
        if ($query->get('orderby') === '' || $query->get('orderby') === 'date') {
            $query->set('orderby', 'menu_order');
            $query->set('order', 'ASC');
        }
    }
}

/**
 * Highlight duplicated items in post list
 */
add_filter('post_class', 'photo_purchase_highlight_duplicated_post', 10, 3);
function photo_purchase_highlight_duplicated_post($classes, $class, $post_id) {
    if (isset($_GET['duplicated']) && intval($_GET['duplicated']) === $post_id) {
        $classes[] = 'photo-row-duplicated';
    }
    return $classes;
}

/**
 * Get Regions and Prefectures
 */
function photo_purchase_get_regions()
{
	return array(
		'北海道' => array('北海道'),
		'東北地方' => array('青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県'),
		'関東地方' => array('茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県'),
		'中部地方' => array('新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県', '静岡県', '愛知県'),
		'近畿地方' => array('三重県', '滋賀県', '京都府', '大阪府', '兵庫県', '奈良県', '和歌山県'),
		'中国地方' => array('鳥取県', '島根県', '岡山県', '広島県', '山口県'),
		'四国地方' => array('徳島県', '香川県', '愛媛県', '高知県'),
		'九州・沖縄地方' => array('福岡県', '佐賀県', '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県'),
	);
}

/**
 * Add manual link to plugin action links
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'photo_purchase_add_manual_link');
function photo_purchase_add_manual_link($links) {
	$manual_link = '<a href="' . PHOTO_PURCHASE_URL . 'manual.html" target="_blank">マニュアル</a>';
	array_unshift($links, $manual_link);
	return $links;
}

/**
 * Helper: Get active payment methods as text
 */
function photo_purchase_get_active_payment_methods_text($type = 'label')
{
	$methods = array();
	if (get_option('photo_pp_enable_stripe', '1') === '1') $methods[] = 'クレジットカード';
	if (get_option('photo_pp_enable_paypay', '0') === '1') $methods[] = 'PayPay';
	if (get_option('photo_pp_enable_bank', '1') === '1') $methods[] = '銀行振込';
	if (get_option('photo_pp_enable_cod', '0') === '1') $methods[] = '代金引換';

	if (empty($methods)) return '';

	if ($type === 'desc') {
		return implode('、', $methods) . 'がご利用いただけます。';
	}
	return implode('、', $methods);
}

/**
 * Register query variable for product deep linking
 */
add_filter('query_vars', function($vars) {
    if (!in_array('photo_id', $vars)) {
        $vars[] = 'photo_id';
    }
    return $vars;
});
