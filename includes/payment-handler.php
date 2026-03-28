<?php
/**
 * Payment and Order Multi-Handling for Simple EC (Japanese) - Buyer Email Integrated
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle Multi-Checkout (JSON + Form Data)
 */
function photo_purchase_handle_multi_checkout()
{
    if (!isset($_POST['cart_json'])) {
        wp_die('不正なリクエストです。');
    }

    // CSRF protection
    if (!isset($_POST['checkout_nonce']) || !wp_verify_nonce($_POST['checkout_nonce'], 'photo_purchase_checkout')) {
        wp_die('セキュリティエラー：不正なリクエストです。ページを再読み込みしてください。');
    }

    $cart_data = json_decode(stripslashes($_POST['cart_json']), true);
    $buyer_name = sanitize_text_field($_POST['buyer_name']);
    $buyer_email = sanitize_email($_POST['buyer_email']);
    $payment_method = sanitize_text_field($_POST['payment_method']);

    // Shipping info
    $shipping_zip = isset($_POST['shipping_zip']) ? sanitize_text_field($_POST['shipping_zip']) : '';
    $shipping_pref = isset($_POST['shipping_pref']) ? sanitize_text_field($_POST['shipping_pref']) : '';
    $shipping_address = isset($_POST['shipping_address']) ? sanitize_textarea_field($_POST['shipping_address']) : '';

    if (empty($cart_data)) {
        wp_die('カートが空です。');
    }

    // Security check: COD and Bank Transfer restrictions
    $has_digital = false;
    $has_subscription = false;
    foreach ($cart_data as $item) {
        if (isset($item['format'])) {
            if ($item['format'] === 'digital') $has_digital = true;
            if ($item['format'] === 'subscription') $has_subscription = true;
        }
    }

    if ($has_subscription) {
        if ($payment_method === 'bank_transfer' || $payment_method === 'cod' || $payment_method === 'paypay') {
            wp_die('サブスクリプション商品が含まれる場合は、クレジットカード決済のみご利用いただけます（PayPay、銀行振込、代金引換はご利用いただけません）。', '支払い方法のエラー', array('response' => 400, 'back_link' => true));
        }
        
        // v3.2.1: Restriction to a single type of subscription per checkout
        $sub_ids = array();
        foreach ($cart_data as $item) {
            if (isset($item['format']) && $item['format'] === 'subscription') {
                $sub_ids[] = intval($item['id']);
            }
        }
        if (count(array_unique($sub_ids)) > 1) {
            wp_die('決済システムの制約により、異なる種類のサブスクリプション商品を同時に購入することはできません。1種類ずつ決済を行ってください。', 'カート内容のエラー', array('response' => 400, 'back_link' => true));
        }
    } elseif ($has_digital && $payment_method === 'cod') {
        wp_die('データダウンロード商品が含まれる場合は、代金引換はご利用いただけません。クレジットカードまたは銀行振込を選択してください。', '支払い方法のエラー', array('response' => 400, 'back_link' => true));
    }

    // Server-side validation: physical items require address (exclude digital and subscription)
    $has_physical_items = false;
    foreach ($cart_data as $item) {
        $fmt = isset($item['format']) ? $item['format'] : '';
        if ($fmt !== 'digital' && $fmt !== 'subscription') {
            $has_physical_items = true;
            break;
        }
        if ($fmt === 'subscription') {
            $sub_req = get_post_meta($item['id'], '_photo_sub_requires_shipping', true);
            if ($sub_req === '1') {
                $has_physical_items = true;
                break;
            }
        }
    }
    if ($has_physical_items) {
        if (empty($shipping_zip) || empty($shipping_pref) || empty($shipping_address)) {
            wp_die('配送が必要な商品が含まれています。お届け先（郵便番号・都道府県・住所）を入力してください。', '入力エラー', array(
                'back_link' => true,
                'response'  => 400,
            ));
        }
    }

    // Generate a unique order token (Simplified format: YYMMDD-XXXX)
    $order_token = current_time('ymd') . '-' . strtoupper(wp_generate_password(4, false, false));

    $coupon_info = isset($_POST['coupon_info']) ? sanitize_text_field(stripslashes($_POST['coupon_info'])) : '';

    $order_data = array(
        'items' => $cart_data,
        'buyer' => array(
            'name' => $buyer_name,
            'email' => $buyer_email,
            'phone' => sanitize_text_field($_POST['buyer_phone']),
        ),
        'shipping' => array(
            'zip' => $shipping_zip,
            'pref' => $shipping_pref,
            'address' => $shipping_address,
        ),
        'method' => $payment_method,
        'coupon_info' => $coupon_info,
        'notes' => isset($_POST['photo_order_notes']) ? sanitize_textarea_field($_POST['photo_order_notes']) : '',
    );

    // Feature: Save shipping info to user profile if logged in
    $current_user_id = get_current_user_id();
    if ($current_user_id) {
        update_user_meta($current_user_id, 'billing_phone', $order_data['buyer']['phone']);
        update_user_meta($current_user_id, 'billing_postcode', $order_data['shipping']['zip']);
        update_user_meta($current_user_id, 'billing_state', $order_data['shipping']['pref']);
        
        // We split the single address textarea into address_1 for simplicity/WooCommerce compatibility if needed, 
        // or just store the whole thing in address_1.
        update_user_meta($current_user_id, 'billing_address_1', $order_data['shipping']['address']);
        update_user_meta($current_user_id, 'billing_address_2', ''); // Clear address_2 to avoid duplicates if we combined them
    }

    // Save to Database
    if (function_exists('photo_purchase_save_order')) {
        photo_purchase_save_order($order_token, $order_data);
    }

    // Cache in transient for immediate display
    set_transient('photo_order_' . $order_token, $order_data, 1 * HOUR_IN_SECONDS);

    // Base redirect URL — use explicitly posted return_url from the cart page form
    // (wp_get_referer() can return admin-post.php or localhost which Stripe rejects)
    if (!empty($_POST['return_url'])) {
        $redirect_base = esc_url_raw($_POST['return_url']);
    } else {
        $redirect_base = wp_get_referer() ? wp_get_referer() : home_url('/');
    }
    $redirect_base = remove_query_arg(array('purchase_success', 'payment_pending', 'order_token'), $redirect_base);

    if ($payment_method === 'stripe' || $payment_method === 'paypay') {
        $secret_key = get_option('photo_pp_stripe_secret_key');
        if (!$secret_key) {
            wp_die('Stripeのシークレットキーが設定されていません。');
        }

        $line_items = array();
        $total_amount = 0;
        $is_subscription_order = false;

        foreach ($cart_data as $item) {
            $photo_id = intval($item['id']);
            $format = sanitize_text_field($item['format']);
            $qty = intval($item['qty']);

            $price_key = ($format === 'l_size') ? '_photo_price_l' : (($format === '2l_size') ? '_photo_price_2l' : '_photo_price_' . $format);
            if ($format === 'digital') {
                $price = get_post_meta($photo_id, '_photo_price_digital', true);
            } elseif ($format === 'subscription') {
                $price = get_post_meta($photo_id, '_photo_price_subscription', true);
                $is_subscription_order = true;
            } else {
                $price = get_post_meta($photo_id, $price_key, true);
            }

            $opt_price = 0;
            if (!empty($item['options']) && is_array($item['options'])) {
                $db_options = get_post_meta($photo_id, '_photo_custom_options', true);
                if (!is_array($db_options)) $db_options = array();

                foreach ($item['options'] as &$opt) {
                    $found = false;
                    foreach ($db_options as $db_opt) {
                        if ($db_opt['name'] === $opt['name']) {
                            $opt['price'] = intval($db_opt['price']); // Overwrite with server price
                            $found = true;
                            break;
                        }
                    }
                    if ($found) {
                        $opt_price += intval($opt['price']);
                    } else {
                        $opt['price'] = 0;
                    }
                }
                unset($opt);
            }
            $final_unit_price = intval($price) + $opt_price;

            $line_items[] = array(
                'price_data' => array(
                    'currency' => 'jpy',
                    'product_data' => array(
                        'name' => get_the_title($photo_id) . ' (' . photo_purchase_get_format_label($format) . ')',
                    ),
                    'unit_amount' => $final_unit_price,
                ),
                'quantity' => $qty,
            );

            if ($format === 'subscription') {
                $interval = get_post_meta($photo_id, '_photo_sub_interval', true) ?: 'month';
                $interval_count = intval(get_post_meta($photo_id, '_photo_sub_interval_count', true)) ?: 1;
                $recurring_info = array(
                    'interval' => $interval,
                    'interval_count' => $interval_count,
                );
                $line_items[count($line_items) - 1]['price_data']['recurring'] = $recurring_info;
            }
            $total_amount += $final_unit_price * $qty;
        }

        // Apply Coupon Discount
        $discount_amount = 0;
        $applied_coupon_code = '';
        if (!empty($coupon_info)) {
            $coupon_details = json_decode($coupon_info, true);
            if ($coupon_details && isset($coupon_details['code'])) {
                if (function_exists('photo_purchase_get_valid_coupon')) {
                    $coupon = photo_purchase_get_valid_coupon($coupon_details['code'], $total_amount);
                    if (!is_wp_error($coupon)) {
                        if ($coupon->discount_type === 'fixed') {
                            $discount_amount = intval($coupon->discount_amount);
                        } else {
                            $discount_amount = floor($total_amount * ($coupon->discount_amount / 100));
                        }
                        $applied_coupon_code = $coupon->code;
                        $applied_coupon_duration = $coupon->stripe_duration;
                        $applied_coupon_months = $coupon->stripe_months;
                    }
                }
            }
        }

        // Add shipping if physical items
        if ($has_physical_items) {
            $flat_rate = intval(get_option('photo_pp_shipping_flat_rate', '0'));
            $free_threshold = intval(get_option('photo_pp_shipping_free_threshold', '0'));
            $pref_rates = get_option('photo_pp_shipping_prefecture_rates', array());
            $pref = $shipping_pref;
            $shipping_fee = ($free_threshold > 0 && $total_amount >= $free_threshold) ? 0 : (($pref && isset($pref_rates[$pref])) ? intval($pref_rates[$pref]) : $flat_rate);

            if ($shipping_fee > 0) {
                $shipping_item = array(
                    'price_data' => array(
                        'currency' => 'jpy',
                        'product_data' => array(
                            'name' => '送料',
                        ),
                        'unit_amount' => $shipping_fee,
                    ),
                    'quantity' => 1,
                );
                // サブスクリプションの場合は送料も定常課金に含める
                if ($is_subscription_order && isset($recurring_info)) {
                    $shipping_item['price_data']['recurring'] = $recurring_info;
                }
                $line_items[] = $shipping_item;
            }
        }

        // Stripe handles payment, COD is handled separately for Bank Transfer/Offline.
        // The total_amount is saved via photo_purchase_save_order before this.

        // Stripe API Call
        // Strict Mode: Only show the specific method selected by the user
        if ($payment_method === 'paypay') {
            $payment_types = array('paypay');
        } else {
            $payment_types = array('card');
        }

        // Prepare Stripe Session Args
        $session_args = array(
            'payment_method_types' => $payment_types,
            'line_items' => $line_items,
            'mode' => $is_subscription_order ? 'subscription' : 'payment',
            'success_url' => add_query_arg(array('purchase_success' => '1', 'order_token' => $order_token, 'session_id' => '{CHECKOUT_SESSION_ID}'), $redirect_base),
            'cancel_url' => $redirect_base,
            'customer_email' => $buyer_email,
            'client_reference_id' => $order_token,
        );

        // Apply Coupon Discount via Stripe API
        if ($discount_amount > 0) {
            $coupon_id = '';
            $coupon_args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body' => http_build_query(array(
                    'amount_off' => $discount_amount,
                    'currency' => 'jpy',
                    'duration' => $applied_coupon_duration ?? 'once',
                    'duration_in_months' => ($applied_coupon_duration === 'repeating') ? $applied_coupon_months : null,
                    'name' => 'クーポン割引 (' . $applied_coupon_code . ')',
                )),
                'timeout' => 20,
            );

            $coupon_response = wp_remote_post('https://api.stripe.com/v1/coupons', $coupon_args);
            if (!is_wp_error($coupon_response)) {
                $coupon_body = json_decode(wp_remote_retrieve_body($coupon_response), true);
                if (isset($coupon_body['id'])) {
                    $coupon_id = $coupon_body['id'];
                    $session_args['discounts'] = array(
                        array('coupon' => $coupon_id)
                    );
                } else {
                    $err = $coupon_body['error']['message'] ?? 'Unknown error';
                    error_log('Stripe Coupon Creation Failed: ' . $err);
                }
            } else {
                error_log('Stripe Coupon Connection Failed: ' . $coupon_response->get_error_message());
            }
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => http_build_query($session_args),
            'timeout' => 45,
        );

        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Stripe Connection Error: ' . $error_message);
            wp_die('Stripe Connection Error: ' . esc_html($error_message));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && isset($body['url'])) {
            wp_redirect($body['url']);
            exit;
        } else {
            $error_detail = (isset($body['error']['message'])) ? $body['error']['message'] : 'Unknown error';
            $error_type = (isset($body['error']['type'])) ? $body['error']['type'] : 'unknown';
            
            // Log for admin investigation
            error_log("Stripe API Error (" . intval($code) . "): $error_type - $error_detail");

            wp_die('Stripe Error: ' . esc_html($error_detail) . ' (Code: ' . intval($code) . ')');
        }

    } else {
        // Bank Transfer
        wp_safe_redirect(add_query_arg(array('payment_pending' => '1', 'order_token' => $order_token), $redirect_base));
        exit;
    }
}
add_action('admin_post_photo_purchase_multi_checkout', 'photo_purchase_handle_multi_checkout');
add_action('admin_post_nopriv_photo_purchase_multi_checkout', 'photo_purchase_handle_multi_checkout');

/**
 * Get Status HTML - Matches order_token to DB if transient is missing
 */
function photo_purchase_get_status_html()
{
    global $wpdb;
    $token = isset($_GET['order_token']) ? sanitize_text_field($_GET['order_token']) : '';
    if (!$token)
        return '';

    // Try transient first, then DB
    $order_data = get_transient('photo_order_' . $token);

    if (!$order_data) {
        $table_name = $wpdb->prefix . 'photo_orders';
        $db_order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE order_token = %s", $token));
        if ($db_order) {
            $order_data = array(
                'items'    => json_decode($db_order->order_items, true),
                'buyer'    => array('name' => $db_order->buyer_name, 'email' => $db_order->buyer_email),
                'shipping' => json_decode($db_order->shipping_info, true),
                'method'   => $db_order->payment_method,
                'total_amount' => $db_order->total_amount, // データベースの保存額を優先
            );
        }
    } else {
        // Always sync payment method from DB so COD→bank_transfer fallback displays correctly
        $table_name = $wpdb->prefix . 'photo_orders';
        $db_method  = $wpdb->get_var($wpdb->prepare("SELECT payment_method FROM {$table_name} WHERE order_token = %s", $token));
        if ($db_method) {
            $order_data['method'] = $db_method;
        }
    }

    if (!$order_data) {
        return '<div class="purchase-status-wrap">注文情報が見つかりません。</div>';
    }

    ob_start();
    echo '<div class="purchase-status-wrap" style="max-width:100%; margin:20px 0; padding:40px; background:#fff; border-radius:15px; box-shadow:0 10px 40px rgba(0,0,0,0.1); border: 1px solid #eee;">';

    if (isset($_GET['purchase_success'])) {
        // Update DB status if coming back from Stripe
        $table_name = $wpdb->prefix . 'photo_orders';
        $current_order = $wpdb->get_row($wpdb->prepare("SELECT status, total_amount FROM $table_name WHERE order_token = %s", $token));

        if ($current_order && $current_order->status === 'pending_payment') {
            // Feature: Save Stripe Transaction ID, Customer ID, and Subscription ID
            $transaction_id = '';
            $customer_id = '';
            $subscription_id = '';
            $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
            if ($session_id) {
                $secret_key = get_option('photo_pp_stripe_secret_key');
                $session_resp = wp_remote_get('https://api.stripe.com/v1/checkout/sessions/' . $session_id, array(
                    'headers' => array('Authorization' => 'Bearer ' . $secret_key)
                ));
                if (!is_wp_error($session_resp)) {
                    $session_body = json_decode(wp_remote_retrieve_body($session_resp), true);
                    if (isset($session_body['payment_intent']) && $session_body['payment_intent']) {
                        $transaction_id = $session_body['payment_intent'];
                    }
                    if (isset($session_body['customer']) && $session_body['customer']) {
                        $customer_id = $session_body['customer'];
                    }
                    if (isset($session_body['subscription']) && $session_body['subscription']) {
                        $subscription_id = $session_body['subscription'];
                    }
                }
            }

            // Atomic update: only send email if this request actually changed the status
            // This prevents duplicate emails if the user reloads the success page or webhook already ran
            $new_status = $subscription_id ? 'active' : 'processing';
            $update_data = array('status' => $new_status);
            if ($transaction_id) {
                $update_data['transaction_id'] = $transaction_id;
            }
            if ($customer_id) {
                $update_data['stripe_customer_id'] = $customer_id;
            }
            if ($subscription_id) {
                $update_data['stripe_subscription_id'] = $subscription_id;
            }

            $rows_updated = $wpdb->update(
                $table_name,
                $update_data,
                array('order_token' => $token, 'status' => 'pending_payment')
            );

            // Send Confirmation Email only when we were responsible for the status change
            if ($rows_updated > 0 && function_exists('photo_purchase_send_buyer_notification')) {
                photo_purchase_send_buyer_notification($token, $order_data, $current_order->total_amount);
                
                // Feature: Also notify admin when payment is confirmed
                if (function_exists('photo_purchase_send_admin_notification')) {
                    photo_purchase_send_admin_notification($token, $order_data, $current_order->total_amount);
                }
            }
        }

        echo '<div style="text-align:center; margin-bottom:30px;">';
        echo '<svg viewBox="0 0 24 24" fill="#28a745" width="50" height="50" style="margin-bottom:20px;"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
        echo '<h2 style="color:#28a745; margin:10px 0;">ご購入ありがとうございます！</h2>';
        echo '<p style="font-size:1.1rem;">' . esc_html($order_data['buyer']['name']) . ' 様、決済が完了しました。</p>';
        echo '</div>';

        // Items breakdown
        echo '<div style="margin-top:20px; border-top:1px solid #eee; padding-top:20px;">';
        echo '<h3>注文詳細:</h3>';
        echo '<ul style="list-style:none; padding:0;">';
        foreach ($order_data['items'] as $item) {
            $format_label = photo_purchase_get_format_label($item['format']);
            echo '<li style="margin-bottom:10px; padding:15px; border:1px solid #f0f0f0; border-radius:10px; display:flex; justify-content:space-between; align-items:center; background:#fafafa;">';
            echo '<div><strong>' . esc_html(get_the_title($item['id'])) . '</strong><br><small>' . esc_html($format_label) . ' (x' . esc_html($item['qty']) . ')</small>';
            if (!empty($item['options']) && is_array($item['options'])) {
                foreach ($item['options'] as $opt) {
                    echo '<br><span style="color:#28a745; font-size:0.8rem;">↳ ' . esc_html($opt['name']) . ' (+¥' . number_format($opt['price']) . ')</span>';
                }
            }
            echo '</div>';

            if ($item['format'] === 'digital') {
                $download_url = photo_purchase_generate_download_url($token, $item['id']);
                echo '<a href="' . esc_url($download_url) . '" class="button button-primary" style="padding:8px 20px; border-radius:30px;">ダウンロード</a>';
            } elseif ($item['format'] === 'subscription') {
                $sub_req = get_post_meta($item['id'], '_photo_sub_requires_shipping', true);
                if ($sub_req === '1') {
                    echo '<span style="color:#28a745;">発送準備中</span>';
                } else {
                    echo '<span style="color:#28a745;">サービス有効</span>';
                }
            } else {
                echo '<span style="color:#28a745;">発送準備中</span>';
            }
            echo '</li>';
        }
        ?>
        <script>
            localStorage.removeItem('photo_cart');
            localStorage.removeItem('photo_applied_coupon');
        </script>
        <?php
    } elseif (isset($_GET['payment_pending'])) {
        if ($order_data['method'] === 'cod') {
            echo '<div style="text-align:center; margin-bottom:30px;">';
            echo '<svg viewBox="0 0 24 24" fill="#666" width="50" height="50" style="margin-bottom:20px;"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>';
            echo '<h2 style="color:#333; margin:10px 0;">' . __('ご注文ありがとうございます（代金引換）', 'photo-purchase') . '</h2>';
            echo '<p style="font-size:1.1rem;">' . sprintf(__('%s 様、代金引換にて承りました。', 'photo-purchase'), esc_html($order_data['buyer']['name'])) . '</p>';
            echo '</div>';

            echo '<div style="background:#f8f9fa; padding:30px; border-radius:12px; border:1px solid #ddd; margin:20px 0; text-align:center; line-height:1.8;">';
            echo __('商品到着時に、配送員に代金をお支払いください。', 'photo-purchase') . '<br>';
            echo '<strong>' . __('照会コード:', 'photo-purchase') . ' ' . esc_html($token) . '</strong>';
            echo '</div>';
        } else {
            echo '<div style="text-align:center; margin-bottom:30px;">';
            echo '<svg viewBox="0 0 24 24" fill="#ffb100" width="50" height="50" style="margin-bottom:20px;"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z"/><path d="M12.5 7H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>';
            echo '<h2 style="color:#ffb100; margin:10px 0;">' . __('ご注文ありがとうございます（お振込待ち）', 'photo-purchase') . '</h2>';
            echo '<p style="font-size:1.1rem;">' . sprintf(__('%s 様、以下の口座へお振込みをお願いいたします。', 'photo-purchase'), esc_html($order_data['buyer']['name'])) . '</p>';
            echo '</div>';

            $items_total = 0;
            $has_physical = false;
            foreach ($order_data['items'] as $item) {
                $photo_id = intval($item['id']);
                $format = sanitize_text_field($item['format']);
                $qty = intval($item['qty']);

                $price_key = ($format === 'l_size') ? '_photo_price_l' : (($format === '2l_size') ? '_photo_price_2l' : '_photo_price_' . $format);
                if ($format === 'digital') {
                    $price = get_post_meta($photo_id, '_photo_price_digital', true);
                } else {
                    $price = get_post_meta($photo_id, $price_key, true);
                }
                $opt_p = 0;
                if (!empty($item['options']) && is_array($item['options'])) {
                    foreach ($item['options'] as $opt) { $opt_p += intval($opt['price'] ?? 0); }
                }
                $items_total += (intval($price) + $opt_p) * $qty;
                if ($format !== 'digital')
                    $has_physical = true;
            }

            $shipping_fee = 0;
            if ($has_physical) {
                $flat_rate = intval(get_option('photo_pp_shipping_flat_rate', '0'));
                $free_threshold = intval(get_option('photo_pp_shipping_free_threshold', '0'));
                $pref_rates = get_option('photo_pp_shipping_prefecture_rates', array());
                $pref = $order_data['shipping']['pref'] ?? '';
                $shipping_fee = ($free_threshold > 0 && $items_total >= $free_threshold) ? 0 : (($pref && isset($pref_rates[$pref])) ? intval($pref_rates[$pref]) : $flat_rate);
            }
            $total = isset($order_data['total_amount']) ? $order_data['total_amount'] : ($items_total + $shipping_fee);

            $bank_name = get_option('photo_pp_bank_name', '');
            $bank_branch = get_option('photo_pp_bank_branch', '');
            $bank_type = get_option('photo_pp_bank_type', '');
            $bank_number = get_option('photo_pp_bank_number', '');
            $bank_holder = get_option('photo_pp_bank_holder', '');

            echo '<div style="background:#fff9e6; padding:30px; border-radius:12px; border:2px dashed #ffb100; margin:20px 0;">';
            echo '<div style="font-size:1.4rem; font-weight:bold; color:#d98c00; margin-bottom:20px; text-align:center;">' . sprintf(__('お振込み合計金額: %s 円', 'photo-purchase'), number_format($total)) . '</div>';
            echo '<div style="line-height:2;">';
            echo __('銀行名:', 'photo-purchase') . ' ' . esc_html($bank_name) . '<br>' . __('支店名:', 'photo-purchase') . ' ' . esc_html($bank_branch) . '<br>' . __('口座種別:', 'photo-purchase') . ' ' . esc_html($bank_type) . '<br>' . __('口座番号:', 'photo-purchase') . ' ' . esc_html($bank_number) . '<br>' . __('口座名義:', 'photo-purchase') . ' ' . esc_html($bank_holder) . '<br>';
            echo '<strong>' . __('照会コード:', 'photo-purchase') . ' ' . esc_html($token) . '</strong>';
            echo '</div></div>';
        }
        ?>
        <script>
            localStorage.removeItem('photo_cart');
            localStorage.removeItem('photo_applied_coupon');
        </script>
        <?php
    }

    echo '<div style="text-align:center; margin-top:30px;"><a href="' . home_url() . '" class="button">トップへ戻る</a></div>';
    echo '</div>';
    return ob_get_clean();
}

/**
 * Helper: Get Format Label
 */
if (!function_exists('photo_purchase_get_format_label')) {
    function photo_purchase_get_format_label($format)
    {
        $labels = array(
            'digital' => 'ダウンロード',
            'l_size'  => '配送品',
            '2l_size' => '配送品(B)',
            'subscription' => 'サブスクリプション',
        );
        return isset($labels[$format]) ? $labels[$format] : $format;
    }
}

/**
 * Create Stripe Billing Portal Session
 */
function photo_purchase_create_portal_session($customer_id)
{
    $secret_key = get_option('photo_pp_stripe_secret_key');
    if (!$secret_key || !$customer_id) return false;

    $my_page_id = get_option('photo_my_page_id');
    $return_url = ($my_page_id) ? get_permalink($my_page_id) : home_url();

    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'body' => http_build_query(array(
            'customer' => $customer_id,
            'return_url' => $return_url,
        )),
        'timeout' => 20,
    );

    $response = wp_remote_post('https://api.stripe.com/v1/billing_portal/sessions', $args);

    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['url'] ?? false;
}
