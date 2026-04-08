<?php
/**
 * Shopping Cart System for Simple EC (Japanese) - Format Aware
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue Cart Scripts
 */
function photo_purchase_cart_scripts()
{
    wp_enqueue_script('photo-purchase-cart', PHOTO_PURCHASE_URL . 'assets/js/cart.js', array('jquery'), PHOTO_PURCHASE_VERSION, true);
    $auth_email = photo_purchase_get_auth_email();
    wp_localize_script('photo-purchase-cart', 'photoPurchase', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('photo_purchase_nonce'),
        'is_logged_in' => !empty($auth_email),
        'member_discount_rate' => intval(get_option('photo_pp_member_discount_rate', '0')),
        'labels' => array(
            'digital' => __('ダウンロード', 'photo-purchase'),
            'l_size' => __('配送品', 'photo-purchase'),
            '2l_size' => __('配送品(B)', 'photo-purchase'),
            'subscription' => __('サブスクリプション', 'photo-purchase'),
        ),
        'shipping' => array(
            'flat_rate' => intval(get_option('photo_pp_shipping_flat_rate', '500')),
            'free_threshold' => intval(get_option('photo_pp_shipping_free_threshold', '5000')),
            'pref_rates' => get_option('photo_pp_shipping_prefecture_rates', array()),
            'cod_tiers' => array(
                'tier1_limit' => intval(get_option('photo_pp_cod_tier1_limit', '10000')),
                'tier1_fee' => intval(get_option('photo_pp_cod_tier1_fee', '330')),
                'tier2_limit' => intval(get_option('photo_pp_cod_tier2_limit', '30000')),
                'tier2_fee' => intval(get_option('photo_pp_cod_tier2_fee', '440')),
                'tier3_limit' => intval(get_option('photo_pp_cod_tier3_limit', '100000')),
                'tier3_fee' => intval(get_option('photo_pp_cod_tier3_fee', '660')),
                'max_fee' => intval(get_option('photo_pp_cod_max_fee', '1100')),
            )
        )
    ));
}
add_action('wp_enqueue_scripts', 'photo_purchase_cart_scripts');

/**
 * AJAX: Get Cart Details
 */
function photo_purchase_get_cart_details()
{
    check_ajax_referer('photo_purchase_nonce', 'nonce');

    $cart_items = (isset($_POST['cart']) && is_array($_POST['cart'])) ? $_POST['cart'] : array();
    $data = array();

	$auth_email = photo_purchase_get_auth_email();
	$discount_rate = intval(get_option('photo_pp_member_discount_rate', '0'));
	$apply_discount = (!empty($auth_email) && $discount_rate > 0);

	if (!empty($cart_items)) {
		$enable_digital = get_option('photo_pp_enable_digital_sales', '1');

		foreach ($cart_items as $item) {
			$id = intval($item['id']);

			// ポイント3: 商品が存在し、公開されているかチェック
			if (get_post_status($id) !== 'publish') {
				continue;
			}

			$format = sanitize_text_field($item['format']);

			if ($format === 'digital' && $enable_digital !== '1') {
				continue; // Skip digital items if disabled
			}

			$is_sold_out = get_post_meta($id, '_photo_is_sold_out', true) === '1';
			$manage_stock = get_post_meta($id, '_photo_manage_stock', true) === '1';
			$stock_qty = intval(get_post_meta($id, '_photo_stock_qty', true));

			if ($is_sold_out || ($manage_stock && $stock_qty <= 0)) {
				continue; // Skip items that are sold out (manually or stock out)
			}

			$price_key = ($format === 'l_size') ? '_photo_price_l' : (($format === '2l_size') ? '_photo_price_2l' : '_photo_price_' . $format);
			if ($format === 'digital') {
				$price = get_post_meta($id, '_photo_price_digital', true);
			} elseif ($format === 'subscription') {
				$price = get_post_meta($id, '_photo_price_subscription', true);
			} else {
				$price = get_post_meta($id, $price_key, true);
			}

			// Variation Overlay
			$variation_id = isset($item['variation_id']) ? sanitize_text_field($item['variation_id']) : '';
			$variation_name = '';
			if ($variation_id) {
				$variations = get_post_meta($id, '_photo_variation_skus', true);
				if (is_array($variations)) {
					$var = null;
					if (isset($variations[$variation_id])) {
						$var = $variations[$variation_id];
					} else {
						// Fallback: Loop search in case of legacy format
						foreach ($variations as $v) {
							if (isset($v['variation_id']) && $v['variation_id'] === $variation_id) {
								$var = $v;
								break;
							}
						}
					}

					if ($var) {
						if (isset($var['price']) && $var['price'] !== '') {
							$price = $var['price'];
						}
						$variation_name = $var['name'] ?? '';
					}
				}
			}

			$sub_requires_shipping = get_post_meta($id, '_photo_sub_requires_shipping', true) === '1';

			$price_val = intval($price);
			if ($apply_discount && $format !== 'subscription') {
				$price_val = floor($price_val * (1 - ($discount_rate / 100)));
			}

			$data[] = array(
				'id' => $id,
				'format' => $format,
				'variation_id' => $variation_id,
				'variation_name' => $variation_name,
				'title' => get_the_title($id),
				'price' => $price_val,
				'original_price' => intval($price),
				'thumb' => get_the_post_thumbnail($id, array(50, 50), array('style' => 'border-radius:4px;')),
				'sub_requires_shipping' => $sub_requires_shipping,
			);
		}
	}

	wp_send_json_success($data);
}
add_action('wp_ajax_photo_get_cart_details', 'photo_purchase_get_cart_details');
add_action('wp_ajax_nopriv_photo_get_cart_details', 'photo_purchase_get_cart_details');

/**
 * AJAX: Sync Abandoned Cart
 */
function photo_purchase_sync_abandoned_cart()
{
    check_ajax_referer('photo_purchase_nonce', 'nonce');

    $email = sanitize_email($_POST['email'] ?? '');
    $cart_json = stripslashes($_POST['cart_json'] ?? '');

    if (empty($email) || empty($cart_json) || $cart_json === '[]' || json_decode($cart_json) === null) {
        wp_send_json_error('Invalid data');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_abandoned_carts';

    // 既に注文済みの場合は何もしない（あるいはstatusチェック）
    // pending状態のエントリを探す
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_name WHERE email = %s AND status = 'pending' ORDER BY id DESC LIMIT 1",
        $email
    ));

    if ($existing) {
        $wpdb->update(
            $table_name,
            array(
                'cart_json' => $cart_json,
                'last_active' => current_time('mysql'),
                'user_id' => get_current_user_id()
            ),
            array('id' => $existing->id),
            array('%s', '%s', '%d'),
            array('%d')
        );
    } else {
        $token = bin2hex(random_bytes(16));
        $wpdb->insert(
            $table_name,
            array(
                'email' => $email,
                'cart_json' => $cart_json,
                'user_id' => get_current_user_id(),
                'last_active' => current_time('mysql'),
                'status' => 'pending',
                'recovery_token' => $token,
                'reminder_sent_count' => 0
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%d')
        );
    }

    wp_send_json_success();
}
add_action('wp_ajax_photo_purchase_sync_abandoned_cart', 'photo_purchase_sync_abandoned_cart');
add_action('wp_ajax_nopriv_photo_purchase_sync_abandoned_cart', 'photo_purchase_sync_abandoned_cart');

/**
 * AJAX: Validate Reorder Stock Status
 */
function photo_purchase_validate_reorder()
{
    check_ajax_referer('photo_purchase_nonce', 'nonce');

    $items = (isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : array();
    $available_items = array();
    $sold_out_titles = array();

    if (!empty($items)) {
        foreach ($items as $item) {
            $id = intval($item['id']);
            $format = sanitize_text_field($item['format'] ?? 'digital');
            $variation_id = !empty($item['variation_id']) ? sanitize_text_field($item['variation_id']) : '';

            $is_sold_out = get_post_meta($id, '_photo_is_sold_out', true) === '1';
            $manage_stock = get_post_meta($id, '_photo_manage_stock', true) === '1';
            $stock_qty = intval(get_post_meta($id, '_photo_stock_qty', true));

            // Variation Stock Check
            if ($variation_id) {
                $variations = get_post_meta($id, '_photo_variation_skus', true);
                if (is_array($variations)) {
                    $v = null;
                    if (isset($variations[$variation_id])) {
                        $v = $variations[$variation_id];
                    } else {
                        foreach ($variations as $tmp_v) {
                            if (($tmp_v['variation_id'] ?? '') === $variation_id) {
                                $v = $tmp_v;
                                break;
                            }
                        }
                    }

                    if ($v && isset($v['stock']) && intval($v['stock']) <= 0) {
                        $is_sold_out = true; 
                    }
                }
            }

            if ($is_sold_out || ($manage_stock && $stock_qty <= 0)) {
                $sold_out_titles[] = get_the_title($id);
            } else {
                // Ensure variation fields exist to prevent JS errors
                $item['variation_id'] = $variation_id;
                $item['variation_name'] = $item['variation_name'] ?? '';

                // Feature: Repair missing shippability flag for old order data
                if ($format === 'subscription') {
                    $sub_req = get_post_meta($id, '_photo_sub_requires_shipping', true);
                    $item['sub_requires_shipping'] = ($sub_req === '1');
                }
                $available_items[] = $item;
            }
        }
    }

    wp_send_json_success(array(
        'available_items' => $available_items,
        'sold_out_titles' => $sold_out_titles
    ));
}
add_action('wp_ajax_photo_purchase_validate_reorder', 'photo_purchase_validate_reorder');
add_action('wp_ajax_nopriv_photo_purchase_validate_reorder', 'photo_purchase_validate_reorder');

/**
 * AJAX: Register Restock Notification
 */
function photo_purchase_register_restock()
{
    check_ajax_referer('photo_purchase_nonce', 'nonce');

    $product_id = intval($_POST['product_id'] ?? 0);
    $email = sanitize_email($_POST['email'] ?? '');

    if (!$product_id || !is_email($email)) {
        wp_send_json_error(array('message' => __('有効なメールアドレスを入力してください。', 'photo-purchase')));
    }

    $emails = get_post_meta($product_id, '_photo_restock_emails', true) ?: array();
    
    if (!is_array($emails)) {
        $emails = array();
    }

    if (!in_array($email, $emails)) {
        $emails[] = $email;
        update_post_meta($product_id, '_photo_restock_emails', $emails);
    }

    // Store page-specific URL for better mapping
    $page_url = esc_url_raw($_POST['page_url'] ?? '');
    if ($page_url) {
        $url_mapping = get_post_meta($product_id, '_photo_restock_urls', true) ?: array();
        if (!is_array($url_mapping)) $url_mapping = array();
        $url_mapping[$email] = $page_url;
        update_post_meta($product_id, '_photo_restock_urls', $url_mapping);
    }

    wp_send_json_success(array('message' => __('通知予約が完了しました。入荷次第メールをお送りします。', 'photo-purchase')));
}
add_action('wp_ajax_photo_register_restock', 'photo_purchase_register_restock');
add_action('wp_ajax_nopriv_photo_register_restock', 'photo_purchase_register_restock');

/**
 * Shortcode: Cart Indicator
 */
function photo_purchase_cart_indicator_shortcode()
{
    ob_start();
    ?>
    <div class="photo-cart-indicator"
        style="background:rgba(255,255,255,0.8); backdrop-filter:blur(10px); padding:10px 20px; border-radius:50px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:9999; display:flex; align-items:center; gap:10px;">
        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" style="vertical-align: middle;"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>
        <span class="cart-count">0</span> <?php _e('点', 'photo-purchase'); ?>
        <a href="<?php echo esc_url(home_url('/cart')); ?>" class="button button-primary"
            style="border-radius:20px;"><?php _e('カートを見る', 'photo-purchase'); ?></a>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ec_cart_indicator', 'photo_purchase_cart_indicator_shortcode');

/**
 * Shortcode: Checkout Page
 */
function photo_purchase_checkout_shortcode()
{
    ob_start();

    // Check if we should display a status message (success/pending)
    if (isset($_GET['order_token'])) {
        if (function_exists('photo_purchase_get_status_html')) {
            echo photo_purchase_get_status_html();
            // Base redirect URL — use explicitly posted return_url from the cart page form
            // (wp_get_referer() can return admin-post.php or localhost which Stripe rejects)
            if (!empty($_POST['return_url'])) {
                $redirect_base = esc_url_raw($_POST['return_url']);
            } else {
                // fallback: try referer, then home
                $redirect_base = wp_get_referer() ? wp_get_referer() : home_url('/');
            }
            $redirect_base = remove_query_arg(array('purchase_success', 'payment_pending', 'order_token'), $redirect_base);
            $order = get_transient('photo_order_' . sanitize_text_field($_GET['order_token']));
            if ($order) {
                return ob_get_clean();
            }
        }
    }

    echo '<div class="photo-checkout-wrap">';
    echo '<h1>' . __('ショッピングカート', 'photo-purchase') . '</h1>';


    echo '<div id="checkout-footer">';
    ?>
    <form id="photo-purchase-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="h-adr">
        <span class="p-country-name ec-hidden">Japan</span>
        <input type="hidden" name="action" value="photo_purchase_multi_checkout">
        <input type="hidden" name="cart_json" id="cart_json" value="">
        <input type="hidden" name="coupon_info" id="coupon_info" value="">
        <input type="hidden" name="return_url" value="<?php echo esc_url(get_permalink() ?: home_url(add_query_arg(null, null))); ?>">
        <?php wp_nonce_field('photo_purchase_checkout', 'checkout_nonce'); ?>

        <div class="checkout-sections">
            <?php 
            $current_user = wp_get_current_user();
            // Show for guests OR admins (for preview)
            if (!$current_user->exists() || current_user_can('manage_options')): ?>
                <div class="checkout-login-prompt">
                    <h3><?php _e('会員の方はログインして購入', 'photo-purchase'); ?></h3>
                    <?php if (current_user_can('manage_options') && $current_user->exists()): ?>
                        <p class="ec-admin-preview-msg">
                            <?php _e('【管理者プレビュー】ログイン中ですが、確認用に表示しています。', 'photo-purchase'); ?>
                        </p>
                    <?php endif; ?>
                    <?php echo photo_purchase_render_sns_login_buttons(); ?>
                    
                    <div id="photo-otp-login-area">
                        <p class="ec-text-muted"><?php _e('メールアドレスで認証（過去にご利用のある方向け）', 'photo-purchase'); ?></p>
                        
                        <div id="photo-otp-step-1">
                            <input type="email" id="photo-otp-email" placeholder="<?php _e('メールアドレス', 'photo-purchase'); ?>" class="ec-otp-email-input">
                            <br>
                            <button type="button" id="photo-otp-send-btn" class="button button-secondary ec-otp-send-btn"><?php _e('認証コードを送信', 'photo-purchase'); ?></button>
                            <div id="photo-otp-msg-1" style="color: red; font-size: 13px; margin-top: 10px; display:none;"></div>
                        </div>

                        <div id="photo-otp-step-2" style="display:none;">
                            <p style="font-size:13px; color:#333; margin-bottom:10px;"><?php _e('メール宛に届いた6桁のコードを入力してください', 'photo-purchase'); ?></p>
                            <input type="text" id="photo-otp-code" placeholder="123456" maxlength="6" class="ec-otp-code-input">
                            <br>
                            <button type="button" id="photo-otp-verify-btn" class="button button-primary ec-otp-verify-btn"><?php _e('ログイン', 'photo-purchase'); ?></button>
                            <div id="photo-otp-msg-2" style="color: red; font-size: 13px; margin-top: 10px; display:none;"></div>
                        </div>

                        <p style="font-size: 11px; color: #888; margin-top: 15px; text-align: center;">
                            <?php printf(__('認証を行うことで、%sに同意したものとみなされます。', 'photo-purchase'), '<a href="' . esc_url(home_url('/membership-terms/')) . '" target="_blank" style="color: #0073aa; text-decoration: underline;">' . __('会員規約', 'photo-purchase') . '</a>'); ?>
                        </p>
                    </div>

                </div>
            <?php endif; ?>

            <?php 
            $u_name = $current_user->exists() ? ($current_user->display_name ?: $current_user->user_login) : '';
            $u_email = $current_user->exists() ? $current_user->user_email : '';
            $u_phone = $current_user->exists() ? get_user_meta($current_user->ID, 'billing_phone', true) : '';
            $u_zip = $current_user->exists() ? get_user_meta($current_user->ID, 'billing_postcode', true) : '';
            $u_pref = $current_user->exists() ? get_user_meta($current_user->ID, 'billing_state', true) : '';
            $u_addr = $current_user->exists() ? get_user_meta($current_user->ID, 'billing_address_1', true) . get_user_meta($current_user->ID, 'billing_address_2', true) : '';
            ?>
            <div class="buyer-info">
                <h3><?php _e('お客様情報', 'photo-purchase'); ?></h3>
                <p>
                    <label><?php _e('お名前', 'photo-purchase'); ?> <span style="color:red;">*</span></label><br>
                    <input type="text" name="buyer_name" required value="<?php echo esc_attr($u_name); ?>" class="ec-form-input">
                </p>
                <p>
                    <label><?php _e('メールアドレス', 'photo-purchase'); ?> <span style="color:red;">*</span></label><br>
                    <input type="email" name="buyer_email" required value="<?php echo esc_attr($u_email); ?>" class="ec-form-input">
                </p>
                <p>
                    <label><?php _e('電話番号', 'photo-purchase'); ?></label><br>
                    <input type="tel" name="buyer_phone" value="<?php echo esc_attr($u_phone); ?>" class="ec-form-input">
                </p>
                <p>
                    <label><?php _e('備考欄 (配送の希望、ギフトメッセージ等)', 'photo-purchase'); ?></label><br>
                    <textarea name="photo_order_notes" rows="3" placeholder="<?php _e('例：配達前に電話をください、ギフト用ラッピング希望等', 'photo-purchase'); ?>" class="ec-form-input"></textarea>
                </p>
            </div>

            <div id="shipping-info">
                <h3><?php _e('お届け先情報', 'photo-purchase'); ?></h3>
                <p>
                    <label><?php _e('郵便番号', 'photo-purchase'); ?> <span style="color:red;">*</span></label><br>
                    <input type="text" name="shipping_zip" placeholder="123-4567" value="<?php echo esc_attr($u_zip); ?>"
                        class="p-postal-code ec-form-input">
                </p>
                <p>
                    <label><?php _e('都道府県', 'photo-purchase'); ?> <span style="color:red;">*</span></label><br>
                    <select name="shipping_pref" id="shipping_pref" class="p-region ec-form-input">
                        <option value=""><?php _e('-- 選択してください --', 'photo-purchase'); ?></option>
                        <?php
                        $prefectures = ["北海道", "青森県", "岩手県", "宮城県", "秋田県", "山形県", "福島県", "茨城県", "栃木県", "群馬県", "埼玉県", "千葉県", "東京都", "神奈川県", "新潟県", "富山県", "石川県", "福井県", "山梨県", "長野県", "岐阜県", "静岡県", "愛知県", "三重県", "滋賀県", "京都府", "大阪府", "兵庫県", "奈良県", "和歌山県", "鳥取県", "島根県", "岡山県", "広島県", "山口県", "徳島県", "香川県", "愛媛県", "高知県", "福岡県", "佐賀県", "長崎県", "熊本県", "大分県", "宮崎県", "鹿児島県", "沖縄県"];
                        foreach ($prefectures as $p) {
                            echo '<option value="' . esc_attr($p) . '" ' . selected($u_pref, $p, false) . '>' . esc_html($p) . '</option>';
                        }
                        ?>
                    </select>
                </p>
                <p>
                    <label><?php _e('市区町村・番地', 'photo-purchase'); ?> <span style="color:red;">*</span></label><br>
                    <textarea name="shipping_address" rows="2" class="p-locality p-street-address p-extended-address ec-form-input"><?php echo esc_textarea($u_addr); ?></textarea>
                </p>
            </div>

            <div class="payment-method ec-payment-method-box">
                <h3 class="ec-mt-0"><?php _e('お支払い方法', 'photo-purchase'); ?></h3>
                <p class="ec-payment-list">
                    <?php
                    $enable_stripe = get_option('photo_pp_enable_stripe', '1');
                    $enable_bank = get_option('photo_pp_enable_bank', '1');
                    $enable_cod = get_option('photo_pp_enable_cod', '0');

                    $methods_found = false;

                    if ($enable_stripe === '1'): $methods_found = true; ?>
                        <label class="ec-clickable">
                            <input type="radio" name="payment_method" value="stripe" checked>
                            <?php _e('クレジットカード (Stripe)', 'photo-purchase'); ?>
                        </label>
                    <?php endif;

                    if (get_option('photo_pp_enable_paypay', '0') === '1'): ?>
                        <label class="ec-clickable">
                            <input type="radio" name="payment_method" value="paypay" <?php checked(!$methods_found); ?>>
                            <?php $methods_found = true; ?>
                            <?php _e('PayPay', 'photo-purchase'); ?>
                        </label>
                    <?php endif;

                    if ($enable_bank === '1'): ?>
                        <label style="cursor: pointer;">
                            <input type="radio" name="payment_method" value="bank_transfer" <?php echo (!$methods_found) ? 'checked' : ''; ?>>
                            <?php $methods_found = true; ?>
                            <?php _e('銀行振込', 'photo-purchase'); ?>
                        </label>
                    <?php endif;

                    if ($enable_cod === '1'): ?>
                        <label class="ec-clickable">
                            <input type="radio" name="payment_method" value="cod" <?php echo (!$methods_found) ? 'checked' : ''; ?>>
                            <?php $methods_found = true; ?>
                            <?php _e('代金引換', 'photo-purchase'); ?>
                        </label>
                    <?php endif;

                    if (!$methods_found) {
                        echo '<span class="ec-text-danger">' . __('現在利用可能な決済方法がありません。', 'photo-purchase') . '</span>';
                    }
                    ?>
                </p>
            </div>
            </div>
        </div>

        <div id="photo-checkout-items" data-cart-details="1" class="ec-checkout-items-area">
            <?php _e('カートを読み込んでいます...', 'photo-purchase'); ?>
        </div>

        <div class="ec-mt-30 ec-text-right">
            <p class="ec-terms-agreement-text">
                <?php printf(__('「注文を確定する」をクリックすることで、%sに同意したものとみなされます。', 'photo-purchase'), '<a href="' . esc_url(home_url('/membership-terms/')) . '" target="_blank" class="ec-link-underlined">' . __('会員規約', 'photo-purchase') . '</a>'); ?>
            </p>
        </div>

        <div class="ec-submit-section">
            <button type="button" class="clear-cart-btn button ec-btn-clear-cart">
                <?php _e('カートを空にする', 'photo-purchase'); ?>
            </button>
            <button type="submit" class="button button-primary ec-btn-submit-order">
                <?php _e('注文を確定する', 'photo-purchase'); ?>
            </button>
        </div>
    </form>
    <?php
    echo '</div></div>';
    
    // Inject OTP login JS
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var sendBtn = document.getElementById('photo-otp-send-btn');
        var verifyBtn = document.getElementById('photo-otp-verify-btn');
        if (!sendBtn || !verifyBtn) return;

        sendBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var email = document.getElementById('photo-otp-email').value;
            var msg1 = document.getElementById('photo-otp-msg-1');
            
            if (!email) {
                msg1.style.color = 'red';
                msg1.textContent = '<?php _e("メールアドレスを入力してください。", "photo-purchase"); ?>';
                msg1.style.display = 'block';
                return;
            }

            msg1.style.color = '#666';
            msg1.textContent = '<?php _e("送信中...", "photo-purchase"); ?>';
            msg1.style.display = 'block';
            sendBtn.disabled = true;

            var formData = new FormData();
            formData.append('action', 'photo_send_otp');
            formData.append('nonce', typeof photoPurchase !== 'undefined' ? photoPurchase.nonce : '<?php echo wp_create_nonce("photo_purchase_nonce"); ?>');
            formData.append('email', email);

            fetch(typeof photoPurchase !== 'undefined' ? photoPurchase.ajax_url : '<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                sendBtn.disabled = false;
                if (data.success) {
                    msg1.style.display = 'none';
                    document.getElementById('photo-otp-step-1').style.display = 'none';
                    document.getElementById('photo-otp-step-2').style.display = 'block';
                } else {
                    msg1.style.color = 'red';
                    msg1.textContent = data.data.message || '<?php _e("エラーが発生しました。", "photo-purchase"); ?>';
                }
            })
            .catch(err => {
                sendBtn.disabled = false;
                msg1.style.color = 'red';
                msg1.textContent = '<?php _e("通信エラーが発生しました。", "photo-purchase"); ?>';
            });
        });

        verifyBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var email = document.getElementById('photo-otp-email').value;
            var code = document.getElementById('photo-otp-code').value;
            var msg2 = document.getElementById('photo-otp-msg-2');
            
            if (!code || code.length !== 6) {
                msg2.style.color = 'red';
                msg2.textContent = '<?php _e("6桁の認証コードを入力してください。", "photo-purchase"); ?>';
                msg2.style.display = 'block';
                return;
            }

            msg2.style.color = '#666';
            msg2.textContent = '<?php _e("認証中...", "photo-purchase"); ?>';
            msg2.style.display = 'block';
            verifyBtn.disabled = true;

            var formData = new FormData();
            formData.append('action', 'photo_verify_otp');
            formData.append('nonce', typeof photoPurchase !== 'undefined' ? photoPurchase.nonce : '<?php echo wp_create_nonce("photo_purchase_nonce"); ?>');
            formData.append('email', email);
            formData.append('code', code);

            fetch(typeof photoPurchase !== 'undefined' ? photoPurchase.ajax_url : '<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                verifyBtn.disabled = false;
                if (data.success) {
                    msg2.style.color = '#28a745';
                    msg2.textContent = data.data.message || '<?php _e("認証成功！リロードしています...", "photo-purchase"); ?>';
                    setTimeout(function(){
                        window.location.reload();
                    }, 1000);
                } else {
                    msg2.style.color = 'red';
                    msg2.textContent = data.data.message || '<?php _e("エラーが発生しました。", "photo-purchase"); ?>';
                }
            })
            .catch(err => {
                verifyBtn.disabled = false;
                msg2.style.color = 'red';
                msg2.textContent = '<?php _e("通信エラーが発生しました。", "photo-purchase"); ?>';
            });
        });

        // 注文確定ボタンの連打防止
        var orderForm = document.getElementById('photo-purchase-order-form');
        if (orderForm) {
            orderForm.addEventListener('submit', function() {
                var submitBtn = orderForm.querySelector('.ec-btn-submit-order');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<?php _e("処理中...", "photo-purchase"); ?>';
                }
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('ec_checkout', 'photo_purchase_checkout_shortcode');

/**
 * Output Mini Cart Drawer HTML in Footer
 */
function photo_purchase_cart_drawer_html()
{
    ?>
    <!-- Mini Cart Drawer -->
    <div class="ec-mini-cart-overlay" id="ec-cart-overlay"></div>
    <div class="ec-mini-cart-drawer" id="ec-cart-drawer">
        <div class="ec-drawer-header">
            <h2><?php _e('現在のカート', 'photo-purchase'); ?></h2>
            <button class="ec-drawer-close" id="ec-drawer-close">&times;</button>
        </div>
        <div class="ec-drawer-items" id="ec-drawer-items">
            <!-- Items injected by JS -->
            <p style="text-align:center; color:#999; margin-top:40px;"><?php _e('カートは空です', 'photo-purchase'); ?></p>
        </div>
        <div class="ec-drawer-footer">
            <div class="ec-drawer-total">
                <span><?php _e('合計', 'photo-purchase'); ?></span>
                <span id="ec-drawer-total-amount">¥0</span>
            </div>
            <div class="ec-drawer-actions">
                <a href="<?php echo esc_url(home_url('/cart')); ?>" class="button button-primary" style="display:block; text-align:center; padding:15px; border-radius:30px; text-decoration:none; font-weight:700;">
                    <?php _e('ご購入手続きへ進む', 'photo-purchase'); ?>
                </a>
                <button class="clear-cart-btn button" style="background:none; border:none; color:#999; font-size:0.85rem; cursor:pointer; margin-top:10px;">
                    <?php _e('カートを空にする', 'photo-purchase'); ?>
                </button>
            </div>
        </div>
    </div>
    <?php
}
add_action('wp_footer', 'photo_purchase_cart_drawer_html');

/**
 * AJAX: Get Favorite Product Details
 */
function photo_purchase_get_favorite_details()
{
    check_ajax_referer('photo_purchase_nonce', 'nonce');

    $ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();
    $data = array();

    if (!empty($ids)) {
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'photo_product' || $post->post_status !== 'publish') {
                continue;
            }

            $prices = array();
            $p_digital = get_post_meta($id, '_photo_price_digital', true);
            if (!$p_digital) $p_digital = get_post_meta($id, '_photo_price', true);
            $p_l = get_post_meta($id, '_photo_price_l', true);
            $p_sub = get_post_meta($id, '_photo_price_subscription', true);

            if ($p_digital > 0) $prices[] = intval($p_digital);
            if ($p_l > 0) $prices[] = intval($p_l);
            if ($p_sub > 0) $prices[] = intval($p_sub);

            // Member Discount check
            $auth_email = photo_purchase_get_auth_email();
            $discount_rate = intval(get_option('photo_pp_member_discount_rate', '0'));
            $apply_discount = (!empty($auth_email) && $discount_rate > 0);

            $min_price = !empty($prices) ? min($prices) : 0;
            if ($apply_discount && $min_price > 0) {
                $min_price = floor($min_price * (1 - ($discount_rate / 100)));
            }

            $data[] = array(
                'id' => $id,
                'title' => get_the_title($id),
                'price_display' => '¥' . number_format($min_price),
                'thumbnail' => get_the_post_thumbnail_url($id, 'medium') ?: PHOTO_PURCHASE_URL . 'assets/images/no-image.png',
                'permalink' => add_query_arg('photo_id', $id, photo_purchase_get_gallery_url())
            );
        }
    }

    wp_send_json_success($data);
}
add_action('wp_ajax_photo_purchase_get_favorite_details', 'photo_purchase_get_favorite_details');
add_action('wp_ajax_nopriv_photo_purchase_get_favorite_details', 'photo_purchase_get_favorite_details');
