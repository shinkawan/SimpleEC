<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Coupon Management for Simple EC
 */
function photo_purchase_coupons_page()
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'photo_coupons';

	// v3.2.4: Ensure columns exist (more robust check)
	$columns = $wpdb->get_col("DESC `$table_name`", 0);
	if (!in_array('stripe_duration', $columns)) {
		$wpdb->query("ALTER TABLE `$table_name` ADD `stripe_duration` varchar(20) DEFAULT 'once' NOT NULL AFTER `active` ");
	}
	if (!in_array('stripe_months', $columns)) {
		$wpdb->query("ALTER TABLE `$table_name` ADD `stripe_months` int(11) DEFAULT 0 NOT NULL AFTER `stripe_duration` ");
	}

	// Handle Actions
	$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
	$coupon_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if (isset($_POST['photo_pp_save_coupon'])) {
		if (!check_admin_referer('photo_coupon_action')) {
			echo '<div class="error"><p>デバッグ: セキュリティチェック（Nonce）に失敗しました。</p></div>';
		} else {
			$code = sanitize_text_field($_POST['coupon_code']);
		$type = sanitize_text_field($_POST['discount_type']);
		$amount = intval($_POST['discount_amount']);
		$expiry = !empty($_POST['expiry_date']) ? sanitize_text_field($_POST['expiry_date']) : null;
		$limit = !empty($_POST['usage_limit']) ? intval($_POST['usage_limit']) : null;
		$min_amount = intval($_POST['min_order_amount']);
		$active = isset($_POST['active']) ? 1 : 0;

		$data = array(
			'code' => $code,
			'discount_type' => $type,
			'discount_amount' => $amount,
			'expiry_date' => $expiry,
			'usage_limit' => $limit,
			'min_order_amount' => $min_amount,
			'active' => $active,
			'stripe_duration' => sanitize_text_field($_POST['stripe_duration'] ?? 'once'),
			'stripe_months' => intval($_POST['stripe_months'] ?? 0),
			'updated_at' => current_time('mysql')
		);

		if ($coupon_id) {
			$result = $wpdb->update($table_name, $data, array('id' => $coupon_id));
			if ($result === false) {
				echo '<div class="error"><p>更新に失敗しました: ' . esc_html($wpdb->last_error) . '</p></div>';
				$action = 'edit';
			} else {
				echo '<div class="updated"><p>クーポンを更新しました。</p></div>';
				$action = 'list';
			}
		} else {
			$data['created_at'] = current_time('mysql');
			$result = $wpdb->insert($table_name, $data);
			if ($result === false) {
				echo '<div class="error"><p>作成に失敗しました: ' . esc_html($wpdb->last_error) . '</p></div>';
				$action = 'add';
			} else {
				echo '<div class="updated"><p>クーポンを新規作成しました。</p></div>';
				$action = 'list';
			}
		}
	}
}

if ($action === 'delete' && $coupon_id) {
		check_admin_referer('photo_delete_coupon_' . $coupon_id);
		$wpdb->delete($table_name, array('id' => $coupon_id));
		echo '<div class="updated"><p>クーポンを削除しました。</p></div>';
		$action = 'list';
	}

	// UI
	?>
	<div class="wrap">
		<h1><?php _e('クーポン管理', 'photo-purchase'); ?>
			<?php if ($action === 'list'): ?>
				<a href="?post_type=photo_product&page=photo-purchase-coupons&action=add" class="page-title-action">新規追加</a>
			<?php endif; ?>
		</h1>

		<?php if ($action === 'add' || $action === 'edit'): 
			$coupon = $coupon_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $coupon_id)) : null;
			$code = $coupon ? $coupon->code : '';
			$type = $coupon ? $coupon->discount_type : 'percent';
			$amount = $coupon ? $coupon->discount_amount : 0;
			$expiry = $coupon ? $coupon->expiry_date : '';
			$limit = $coupon ? $coupon->usage_limit : '';
			$min_amount = $coupon ? $coupon->min_order_amount : 0;
			$active = $coupon ? $coupon->active : 1;
			$s_duration = $coupon ? $coupon->stripe_duration : 'once';
			$s_months = $coupon ? $coupon->stripe_months : 0;
		?>
			<form method="post" action="?post_type=photo_product&page=photo-purchase-coupons&id=<?php echo $coupon_id; ?>">
				<?php wp_nonce_field('photo_coupon_action'); ?>
				<table class="form-table">
					<tr>
						<th><label for="coupon_code">クーポンコード</label></th>
						<td><input name="coupon_code" type="text" id="coupon_code" value="<?php echo esc_attr($code); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="discount_type">割引形式</label></th>
						<td>
							<select name="discount_type" id="discount_type">
								<option value="percent" <?php selected($type, 'percent'); ?>>パーセント割引 (%)</option>
								<option value="fixed" <?php selected($type, 'fixed'); ?>>定額割引 (円)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="discount_amount">割引額/率</label></th>
						<td><input name="discount_amount" type="number" id="discount_amount" value="<?php echo esc_attr($amount); ?>" class="small-text" required min="1"></td>
					</tr>
					<tr>
						<th><label for="min_order_amount">最低購入金額</label></th>
						<td><input name="min_order_amount" type="number" id="min_order_amount" value="<?php echo esc_attr($min_amount); ?>" class="small-text"> 円 以上で利用可能</td>
					</tr>
					<tr>
						<th><label for="expiry_date">有効期限</label></th>
						<td><input name="expiry_date" type="date" id="expiry_date" value="<?php echo esc_attr($expiry); ?>"></td>
					</tr>
					<tr>
						<th><label for="usage_limit">使用回数制限</label></th>
						<td><input name="usage_limit" type="number" id="usage_limit" value="<?php echo esc_attr($limit); ?>" class="small-text"> 回 数制限なしは空欄</td>
					</tr>
					<tr>
						<th>利用可能</th>
						<td>
							<label><input name="active" type="checkbox" value="1" <?php checked($active, 1); ?>> クーポンを有効にする</label>
						</td>
					</tr>
					<tr style="background:#f0f7ff;">
						<th><label for="stripe_duration">割引の期間 (Stripe)</label></th>
						<td>
							<select name="stripe_duration" id="stripe_duration" style="background:#fff;">
								<option value="once" <?php selected($s_duration, 'once'); ?>>初回のみ (1回限り)</option>
								<option value="forever" <?php selected($s_duration, 'forever'); ?>>永続 (サブスク継続中ずっと)</option>
								<option value="repeating" <?php selected($s_duration, 'repeating'); ?>>指定した期間 (複数回)</option>
							</select>
							<p class="description">サブスクリプション商品を購入する場合の割引期間を設定します。</p>
						</td>
					</tr>
					<tr id="row_stripe_months" style="background:#f0f7ff; <?php echo ($s_duration !== 'repeating') ? 'display:none;' : ''; ?>">
						<th><label for="stripe_months">割引を継続する月数</label></th>
						<td>
							<input name="stripe_months" type="number" id="stripe_months" value="<?php echo esc_attr($s_months); ?>" class="small-text"> ヶ月
							<p class="description">「指定した期間」を選択した場合のみ有効です。</p>
						</td>
					</tr>
					<script>
					document.addEventListener('DOMContentLoaded', function() {
						const durSelect = document.getElementById('stripe_duration');
						const monRow = document.getElementById('row_stripe_months');
						durSelect.addEventListener('change', function() {
							monRow.style.display = (this.value === 'repeating') ? 'table-row' : 'none';
						});
					});
					</script>
				</table>
				<input type="hidden" name="photo_pp_save_coupon" value="1">
				<?php submit_button('保存'); ?>
				<a href="?post_type=photo_product&page=photo-purchase-coupons" class="button">キャンセル</a>
			</form>

		<?php else: // List View ?>
			<?php
			$coupons = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
			?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>コード</th>
						<th>割引内容</th>
						<th>期限</th>
						<th>使用数 / 上限</th>
						<th>最低注文額</th>
						<th>状態</th>
						<th>操作</th>
					</tr>
				</thead>
				<tbody>
					<?php if ($coupons): foreach ($coupons as $c): ?>
						<tr>
							<td><strong><?php echo esc_html($c->code); ?></strong></td>
							<td>
								<?php 
								if ($c->discount_type === 'percent') {
									echo esc_html($c->discount_amount) . '% OFF';
								} else {
									echo number_format($c->discount_amount) . '円引き';
								}
								echo '<br><small style="color:#666;">期間: ';
								if ($c->stripe_duration === 'forever') echo '永続';
								else if ($c->stripe_duration === 'repeating') echo esc_html($c->stripe_months) . 'ヶ月間';
								else echo '初回のみ';
								echo '</small>';
								?>
							</td>
							<td><?php echo $c->expiry_date ? esc_html($c->expiry_date) : 'なし'; ?></td>
							<td><?php echo $c->usage_count; ?> / <?php echo $c->usage_limit ? esc_html($c->usage_limit) : '∞'; ?></td>
							<td><?php echo number_format($c->min_order_amount); ?>円</td>
							<td><?php echo $c->active ? '<span style="color:green;">有効</span>' : '<span style="color:red;">無効</span>'; ?></td>
							<td>
								<a href="?post_type=photo_product&page=photo-purchase-coupons&action=edit&id=<?php echo $c->id; ?>">編集</a> |
								<a href="<?php echo wp_nonce_url("?post_type=photo_product&page=photo-purchase-coupons&action=delete&id=" . $c->id, 'photo_delete_coupon_' . $c->id); ?>" style="color:#a00;" onclick="return confirm('本当に削除しますか？');">削除</a>
							</td>
						</tr>
					<?php endforeach; else: ?>
						<tr><td colspan="7">クーポンが登録されていません。</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Validate Coupon via AJAX
 */
function photo_purchase_validate_coupon_ajax()
{
	check_ajax_referer('photo_purchase_nonce', 'nonce');

	$code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
	$cart_items = isset($_POST['cart']) ? $_POST['cart'] : array();

	if (!$code) {
		wp_send_json_error(['message' => 'コードを入力してください。']);
	}

	$cart_total = 0;
	if (!empty($cart_items)) {
		foreach ($cart_items as $item) {
			$id = intval($item['id']);
			$format = sanitize_text_field($item['format']);
			$qty = intval($item['qty']);

			$price_key = ($format === 'l_size') ? '_photo_price_l' : (($format === '2l_size') ? '_photo_price_2l' : '_photo_price_' . $format);
			$item_price = intval(get_post_meta($id, $price_key, true));

			$extra_price = 0;
			if (isset($item['options']) && is_array($item['options'])) {
				foreach ($item['options'] as $opt) {
					$extra_price += intval($opt['price']);
				}
			}

			$cart_total += ($item_price + $extra_price) * $qty;
		}
	}

	$coupon = photo_purchase_get_valid_coupon($code, $cart_total);

	if (is_wp_error($coupon)) {
		wp_send_json_error(['message' => $coupon->get_error_message()]);
	}

	wp_send_json_success([
		'code' => $coupon->code,
		'type' => $coupon->discount_type,
		'amount' => $coupon->discount_amount,
		'stripe_duration' => $coupon->stripe_duration,
		'stripe_months' => $coupon->stripe_months
	]);
}
add_action('wp_ajax_photo_purchase_validate_coupon', 'photo_purchase_validate_coupon_ajax');
add_action('wp_ajax_nopriv_photo_purchase_validate_coupon', 'photo_purchase_validate_coupon_ajax');

/**
 * Helper to get and validate a coupon
 */
function photo_purchase_get_valid_coupon($code, $cart_total = 0)
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'photo_coupons';

	$coupon = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE code = %s AND active = 1", $code));

	if (!$coupon) {
		return new WP_Error('invalid_coupon', '無効なクーポンコードです。');
	}

	// Expiry Check
	if ($coupon->expiry_date && strtotime($coupon->expiry_date) < strtotime(date('Y-m-d'))) {
		return new WP_Error('expired_coupon', 'このクーポンは期限切れです。');
	}

	// Usage Limit Check
	if ($coupon->usage_limit && $coupon->usage_count >= $coupon->usage_limit) {
		return new WP_Error('limit_reached', 'このクーポンは使用上限に達しています。');
	}

	// Min Order Amount Check
	if ($cart_total < $coupon->min_order_amount) {
		return new WP_Error('min_amount', '合計金額が足りません（' . number_format($coupon->min_order_amount) . '円以上で利用可能）。');
	}

	return $coupon;
}
