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
    wp_localize_script('photo-purchase-cart', 'photoPurchase', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('photo_purchase_nonce'),
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

    $cart_items = isset($_POST['cart']) ? $_POST['cart'] : array();
    $data = array();

	if (!empty($cart_items)) {
		$enable_digital = get_option('photo_pp_enable_digital_sales', '1');

		foreach ($cart_items as $item) {
			$id = intval($item['id']);
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

			$sub_requires_shipping = get_post_meta($id, '_photo_sub_requires_shipping', true) === '1';

			$data[] = array(
				'id' => $id,
				'format' => $format,
				'title' => get_the_title($id),
				'price' => intval($price),
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

    echo '<div class="photo-checkout-wrap" style="max-width:800px; margin:20px auto; padding:40px; background:#fff; border-radius:15px; box-shadow:0 20px 50px rgba(0,0,0,0.05);">';
    echo '<h1>' . __('ショッピングカート', 'photo-purchase') . '</h1>';


    echo '<div id="checkout-footer" style="display:none; margin-top:30px; border-top:2px solid #eee; padding-top:30px;">';
    ?>
    <form id="photo-purchase-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="photo_purchase_multi_checkout">
        <input type="hidden" name="cart_json" id="cart_json" value="">
        <input type="hidden" name="coupon_info" id="coupon_info" value="">
        <input type="hidden" name="return_url" value="<?php echo esc_url(get_permalink() ?: home_url(add_query_arg(null, null))); ?>">
        <?php wp_nonce_field('photo_purchase_checkout', 'checkout_nonce'); ?>

        <div class="checkout-sections" style="display: grid; grid-template-columns: 1fr; gap: 30px;">
            <?php 
            $current_user = wp_get_current_user();
            // Show for guests OR admins (for preview)
            if (!$current_user->exists() || current_user_can('manage_options')): ?>
                <div class="checkout-login-prompt" style="background: #f8f9fa; border: 1px solid #e9ecef; padding: 25px; border-radius: 12px; margin-bottom: 30px; text-align: center;">
                    <h3 style="margin-top: 0; font-size: 1.1rem; color: #333;"><?php _e('会員の方はログインして購入', 'photo-purchase'); ?></h3>
                    <?php if (current_user_can('manage_options') && $current_user->exists()): ?>
                        <p style="background:#fff3cd; color:#856404; padding:8px; border-radius:6px; font-size:12px; margin-bottom:15px; border:1px solid #ffeeba;">
                            <?php _e('【管理者プレビュー】ログイン中ですが、確認用に表示しています。', 'photo-purchase'); ?>
                        </p>
                    <?php endif; ?>
                    <?php echo photo_purchase_render_sns_login_buttons(); ?>
                    
                    <div id="photo-otp-login-area" style="margin-top:20px; border-top: 1px dashed #ccc; padding-top: 20px;">
                        <p style="font-size: 0.9rem; color: #666; margin-bottom:15px;"><?php _e('メールアドレスで認証（過去にご利用のある方向け）', 'photo-purchase'); ?></p>
                        
                        <div id="photo-otp-step-1">
                            <input type="email" id="photo-otp-email" placeholder="<?php _e('メールアドレス', 'photo-purchase'); ?>" style="width: 100%; max-width: 300px; padding: 10px; border-radius: 6px; border: 1px solid #ddd; margin-bottom: 10px;">
                            <br>
                            <button type="button" id="photo-otp-send-btn" class="button button-secondary" style="padding: 8px 20px; border-radius: 20px;"><?php _e('認証コードを送信', 'photo-purchase'); ?></button>
                            <div id="photo-otp-msg-1" style="color: red; font-size: 13px; margin-top: 10px; display:none;"></div>
                        </div>

                        <div id="photo-otp-step-2" style="display:none;">
                            <p style="font-size:13px; color:#333; margin-bottom:10px;"><?php _e('メール宛に届いた6桁のコードを入力してください', 'photo-purchase'); ?></p>
                            <input type="text" id="photo-otp-code" placeholder="123456" maxlength="6" style="width: 100%; max-width: 150px; padding: 10px; border-radius: 6px; border: 1px solid #ddd; margin-bottom: 10px; text-align:center; font-size: 1.2rem; letter-spacing: 5px;">
                            <br>
                            <button type="button" id="photo-otp-verify-btn" class="button button-primary" style="padding: 8px 30px; border-radius: 20px;"><?php _e('ログイン', 'photo-purchase'); ?></button>
                            <div id="photo-otp-msg-2" style="color: red; font-size: 13px; margin-top: 10px; display:none;"></div>
                        </div>
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
                    <input type="text" name="buyer_name" required value="<?php echo esc_attr($u_name); ?>"
                        style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;">
                </p>
                <p>
                    <label><?php _e('メールアドレス', 'photo-purchase'); ?> <span style="color:red;">*</span></label><br>
                    <input type="email" name="buyer_email" required value="<?php echo esc_attr($u_email); ?>"
                        style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;">
                </p>
                <p>
                    <label><?php _e('電話番号', 'photo-purchase'); ?></label><br>
                    <input type="tel" name="buyer_phone" value="<?php echo esc_attr($u_phone); ?>"
                        style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;">
                </p>
                <p>
                    <label><?php _e('備考欄 (配送の希望、ギフトメッセージ等)', 'photo-purchase'); ?></label><br>
                    <textarea name="photo_order_notes" rows="3" placeholder="<?php _e('例：配達前に電話をください、ギフト用ラッピング希望等', 'photo-purchase'); ?>"
                        style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;"></textarea>
                </p>
            </div>

            <div id="shipping-info" style="display:none;">
                <h3><?php _e('お届け先情報', 'photo-purchase'); ?></h3>
                <p>
                    <label><?php _e('郵便番号', 'photo-purchase'); ?> <span style="color:red;">*</span></label><br>
                    <input type="text" name="shipping_zip" placeholder="123-4567" value="<?php echo esc_attr($u_zip); ?>"
                        style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;">
                </p>
                <p>
                    <label><?php _e('都道府県', 'photo-purchase'); ?> <span style="color:red;">*</span></label><br>
                    <select name="shipping_pref" id="shipping_pref"
                        style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;">
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
                    <textarea name="shipping_address" rows="2"
                        style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;"><?php echo esc_textarea($u_addr); ?></textarea>
                </p>
            </div>

            <div class="payment-method"
                style="border: 2px solid #f0f0f0; padding: 20px; border-radius: 12px; height: fit-content;">
                <h3 style="margin-top:0;"><?php _e('お支払い方法', 'photo-purchase'); ?></h3>
                <p style="display: flex; flex-direction: column; gap: 10px;">
                    <?php
                    $enable_stripe = get_option('photo_pp_enable_stripe', '1');
                    $enable_bank = get_option('photo_pp_enable_bank', '1');
                    $enable_cod = get_option('photo_pp_enable_cod', '0');

                    $methods_found = false;

                    if ($enable_stripe === '1'): $methods_found = true; ?>
                        <label style="cursor: pointer;">
                            <input type="radio" name="payment_method" value="stripe" checked>
                            <?php _e('クレジットカード (Stripe)', 'photo-purchase'); ?>
                        </label>
                    <?php endif;

                    if (get_option('photo_pp_enable_paypay', '0') === '1'): ?>
                        <label style="cursor: pointer;">
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
                        <label style="cursor: pointer;">
                            <input type="radio" name="payment_method" value="cod" <?php echo (!$methods_found) ? 'checked' : ''; ?>>
                            <?php $methods_found = true; ?>
                            <?php _e('代金引換', 'photo-purchase'); ?>
                        </label>
                    <?php endif;

                    if (!$methods_found) {
                        echo '<span style="color:red;">' . __('現在利用可能な決済方法がありません。', 'photo-purchase') . '</span>';
                    }
                    ?>
                </p>
            </div>
            </div>
        </div>

        <div id="photo-checkout-items" data-cart-details="1" style="min-height:100px; margin-top:30px; border-top:1px solid #eee; padding-top:20px;">
            <?php _e('カートを読み込んでいます...', 'photo-purchase'); ?>
        </div>

        <div
            style="text-align:right; display: flex; justify-content: flex-end; gap: 10px; align-items: center; margin-top: 30px;">
            <button type="button" class="clear-cart-btn button"
                style="background: #f8f9fa; color: #666; border: 1px solid #ddd; padding: 10px 20px; border-radius: 30px;">
                <?php _e('カートを空にする', 'photo-purchase'); ?>
            </button>
            <button type="submit" class="button button-primary"
                style="padding: 15px 40px; font-size:1.2rem; border-radius:30px; background:#0073aa; color:#fff; cursor:pointer;">
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
            formData.append('nonce', typeof photoPurchase !== 'undefined' ? photoPurchase.nonce : '');
            formData.append('email', email);

            fetch(typeof photoPurchase !== 'undefined' ? photoPurchase.ajax_url : '', {
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
            formData.append('nonce', typeof photoPurchase !== 'undefined' ? photoPurchase.nonce : '');
            formData.append('email', email);
            formData.append('code', code);

            fetch(typeof photoPurchase !== 'undefined' ? photoPurchase.ajax_url : '', {
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
