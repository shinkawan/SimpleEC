<?php
/**
 * Plugin Name: Simple EC
 * Description: 簡易的な写真・デジタルコンテンツ販売プラグイン。Stripe、PayPay、代引き、銀行振込に対応。
 * Version: 3.8.0
 * Author: アートフレア株式会社
 * Author URI: https://www.artflair.co.jp/
 * Text Domain: photo-purchase
 */

if (!defined('ABSPATH')) {
	exit;
}

// Define constants
define('PHOTO_PURCHASE_VERSION', '3.8.0');
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
		'supports' => array('title', 'editor', 'thumbnail'),
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

	// 商品CSV一括編集
	add_submenu_page(
		'edit.php?post_type=photo_product',
		__('商品CSV一括編集', 'photo-purchase'),
		__('商品CSV一括編集', 'photo-purchase'),
		'manage_options',
		'photo-purchase-bulk-edit',
		'photo_purchase_bulk_edit_page'
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
}
add_action('admin_menu', 'photo_purchase_admin_menus');

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
        // Actually, print_doc should probably take order_token too for frontend security.
        
        if (function_exists('photo_purchase_order_print_view')) {
            photo_purchase_order_print_view($order_id, $type);
            exit;
        }
    }
});

/**
 * Add Dashboard Widget for Manual
 */
function photo_purchase_add_dashboard_widgets() {
	wp_add_dashboard_widget(
		'photo_purchase_stats_widget',
		'Simple EC 運用状況',
		'photo_purchase_dashboard_widget_content'
	);
}
add_action('wp_dashboard_setup', 'photo_purchase_add_dashboard_widgets');

function photo_purchase_dashboard_widget_content() {
	global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    
    // Stats calculation
    $today_sales = $wpdb->get_var("SELECT SUM(total_amount) FROM $table_name WHERE DATE(created_at) = CURDATE() AND status NOT IN ('cancelled', 'abandoned')");
    $month_sales = $wpdb->get_var("SELECT SUM(total_amount) FROM $table_name WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND status NOT IN ('cancelled', 'abandoned')");
    $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status IN ('pending', 'processing')");

	$manual_url = PHOTO_PURCHASE_URL . 'manual.html';
    $orders_url = admin_url('edit.php?post_type=photo_product&page=photo-purchase-orders');

	echo '<div class="photo-dashboard-stats" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; text-align:center; padding:10px 0;">';
    echo '  <div style="background:#f9f9f9; padding:15px 5px; border-radius:8px;">';
    echo '    <span class="dashicons dashicons-chart-line" style="color:#e8a0bf; font-size:24px; width:auto; height:auto;"></span><br>';
    echo '    <small>本日売上</small><br><strong style="font-size:1.2em;">￥' . number_format(intval($today_sales)) . '</strong>';
    echo '  </div>';
    echo '  <div style="background:#f9f9f9; padding:15px 5px; border-radius:8px;">';
    echo '    <span class="dashicons dashicons-chart-area" style="color:#c47a9a; font-size:24px; width:auto; height:auto;"></span><br>';
    echo '    <small>今月売上</small><br><strong style="font-size:1.2em;">￥' . number_format(intval($month_sales)) . '</strong>';
    echo '  </div>';
    echo '  <div style="background:#f9f9f9; padding:15px 5px; border-radius:8px;">';
    echo '    <span class="dashicons dashicons-warning" style="color:#d98c00; font-size:24px; width:auto; height:auto;"></span><br>';
    echo '    <small>未対応注文</small><br><strong style="font-size:1.2em;">' . number_format(intval($pending_count)) . '件</strong>';
    echo '  </div>';
    echo '</div>';
    
    echo '<div style="margin-top:20px; display:flex; gap:10px;">';
    echo '  <a href="' . esc_url($orders_url) . '" class="button button-primary">注文を確認する</a>';
	echo '  <a href="' . esc_url($manual_url) . '" class="button" target="_blank">マニュアルを開く</a>';
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
			update_option('photo_pp_stock_threshold', intval($_POST['stock_threshold']));
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
		}

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
						<th><label for="stripe_webhook_secret"><?php _e('Webhookシークレット', 'photo-purchase'); ?></label></th>
						<td>
							<input type="password" name="stripe_webhook_secret" id="stripe_webhook_secret"
								value="<?php echo esc_attr(get_option('photo_pp_stripe_webhook_secret', '')); ?>"
								class="regular-text">
							<p class="description">
								Stripeダッシュボードで設定したWebhookの署名シークレットを入力してください。<br>
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
		'photo_customer_portal_page_id' => array(
			'title' => 'マイページ（サブスク管理）',
			'slug'  => 'customer-portal',
			'content' => '[ec_customer_portal]',
		),
	);

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
require_once PHOTO_PURCHASE_PATH . 'includes/admin-bulk-edit.php';
require_once PHOTO_PURCHASE_PATH . 'includes/frontend-display.php';
require_once PHOTO_PURCHASE_PATH . 'includes/payment-handler.php';
require_once PHOTO_PURCHASE_PATH . 'includes/cart-system.php';
require_once PHOTO_PURCHASE_PATH . 'includes/order-manager.php';
require_once PHOTO_PURCHASE_PATH . 'includes/admin-log.php';
require_once PHOTO_PURCHASE_PATH . 'includes/stripe-webhooks.php';

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
	if (strpos($hook, 'photo_product') !== false || strpos($hook, 'photo-purchase') !== false) {
		wp_enqueue_style('photo-purchase-admin', PHOTO_PURCHASE_URL . 'assets/css/admin.css', array(), PHOTO_PURCHASE_VERSION);
	}
}
add_action('admin_enqueue_scripts', 'photo_purchase_enqueue_admin_assets');

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
 * Add Simple EC Dashboard Widget
 */
function photo_purchase_register_dashboard_widget() {
    wp_add_dashboard_widget(
        'photo_purchase_dashboard_status',
        'Simple EC ステータス',
        'photo_purchase_dashboard_widget_content'
    );
}
add_action('wp_dashboard_setup', 'photo_purchase_register_dashboard_widget');

/**
 * Dashboard Widget Content
 */
function photo_purchase_dashboard_widget_content() {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'photo_orders';
    $logs_table = $wpdb->prefix . 'photo_system_logs';

    // Get order counts
    $pending = $wpdb->get_var("SELECT COUNT(*) FROM $orders_table WHERE status = 'pending_payment'");
    $processing = $wpdb->get_var("SELECT COUNT(*) FROM $orders_table WHERE status = 'processing'");
    
    // Get recent error count (last 24h)
    $error_count = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE level = 'error' AND log_date > DATE_SUB(NOW(), INTERVAL 1 DAY)");

    echo '<div class="photo-dashboard-stats">';
    echo '<p><span class="dashicons dashicons-cart"></span> <strong>受信した注文:</strong></p>';
    echo '<ul>';
    echo '<li>入金待ち: <a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-orders') . '">' . intval($pending) . ' 件</a></li>';
    echo '<li>準備中（決済済）: <a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-orders') . '">' . intval($processing) . ' 件</a></li>';
    echo '</ul>';
    
    if ($error_count > 0) {
        echo '<p style="color:#d63638;"><span class="dashicons dashicons-warning"></span> <strong>直近24時間のシステムエラー:</strong> <a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-logs') . '" style="color:#d63638;">' . intval($error_count) . ' 件</a></p>';
    } else {
        echo '<p style="color:#22c55e;"><span class="dashicons dashicons-yes-alt"></span> システムは正常に稼働しています。</p>';
    }
    
    echo '<hr style="margin:15px 0 10px;">';
    echo '<p><a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-settings') . '" class="button">各種設定</a> ';
    echo '<a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-orders') . '" class="button button-primary">注文管理</a></p>';
    echo '</div>';
}
