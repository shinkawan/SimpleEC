<?php
/**
 * Order Manager for Simple EC (Buyer Notification Version)
 * Handles DB operations, List Table, and Email Notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculate COD Fee based on total amount
 */
function photo_purchase_calculate_cod_fee($total_amount)
{
    $tier1_limit = intval(get_option('photo_pp_cod_tier1_limit', '10000'));
    $tier1_fee = intval(get_option('photo_pp_cod_tier1_fee', '330'));
    $tier2_limit = intval(get_option('photo_pp_cod_tier2_limit', '30000'));
    $tier2_fee = intval(get_option('photo_pp_cod_tier2_fee', '440'));
    $tier3_limit = intval(get_option('photo_pp_cod_tier3_limit', '100000'));
    $tier3_fee = intval(get_option('photo_pp_cod_tier3_fee', '660'));
    $max_fee = intval(get_option('photo_pp_cod_max_fee', '1100'));

    if ($total_amount < $tier1_limit) {
        return $tier1_fee;
    } elseif ($total_amount < $tier2_limit) {
        return $tier2_fee;
    } elseif ($total_amount < $tier3_limit) {
        return $tier3_fee;
    } else {
        return $max_fee;
    }
}

/**
 * Save Order to Database
 */
function photo_purchase_save_order($order_token, &$order_data)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';

    // バリデーション強化: アイテム配列の存在と型をチェック
    if (empty($order_data['items']) || !is_array($order_data['items'])) {
        return false;
    }

    $rate_standard = intval(get_option('photo_pp_tax_rate_standard', '10'));
    $rate_reduced = intval(get_option('photo_pp_tax_rate_reduced', '8'));

    $auth_email = photo_purchase_get_auth_email();
    $discount_rate = intval(get_option('photo_pp_member_discount_rate', '0'));
    $apply_discount = (!empty($auth_email) && $discount_rate > 0);

    $items_amount = 0;
    $has_physical = false;
    foreach ($order_data['items'] as &$item) {
        $price_key = ($item['format'] === 'l_size') ? '_photo_price_l' : (($item['format'] === '2l_size') ? '_photo_price_2l' : '_photo_price_' . $item['format']);
        if ($item['format'] === 'digital') {
            $price = get_post_meta($item['id'], '_photo_price_digital', true);
            if (!$price)
                $price = get_post_meta($item['id'], '_photo_price', true);
        } elseif ($item['format'] === 'subscription') {
            $price = get_post_meta($item['id'], '_photo_price_subscription', true);
            $sub_req = get_post_meta($item['id'], '_photo_sub_requires_shipping', true);
            if ($sub_req === '1') {
                $has_physical = true;
                $item['sub_requires_shipping'] = '1';
            }
        } else {
            $price = get_post_meta($item['id'], $price_key, true);
            $has_physical = true;
        }

        // Variation Logic: Override price if variation_id exists
        if (!empty($item['variation_id'])) {
            $variations = get_post_meta($item['id'], '_photo_variation_skus', true);
            if (is_array($variations)) {
                $var = null;
                if (isset($variations[$item['variation_id']])) {
                    $var = $variations[$item['variation_id']];
                } else {
                    // Fallback for legacy format
                    foreach ($variations as $v) {
                        if (isset($v['variation_id']) && $v['variation_id'] === $item['variation_id']) {
                            $var = $v;
                            break;
                        }
                    }
                }
                
                if ($var) {
                    if (isset($var['price']) && $var['price'] !== '') {
                        $price = $var['price'];
                    }
                    $item['variation_name'] = $var['name'] ?? ''; // Ensure name is in item array
                }
            }
        }
        
        // Determine tax rate for this item
        $tax_type = get_post_meta($item['id'], '_photo_tax_type', true) ?: 'standard';
        $item['tax_type'] = $tax_type;
        $item['tax_rate'] = ($tax_type === 'reduced') ? $rate_reduced : $rate_standard;
        
        $base_price = intval($price);
        $options_price = 0;
        if (!empty($item['options']) && is_array($item['options'])) {
            $db_options = get_post_meta($item['id'], '_photo_custom_options', true);
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
                    $options_price += intval($opt['price']);
                } else {
                    $opt['price'] = 0; // Invalid option, set price to 0
                }
            }
            unset($opt);
        }
        // Apply member discount
        $final_unit_price = $base_price + $options_price;
        if ($apply_discount && $item['format'] !== 'subscription') {
            $final_unit_price = floor($final_unit_price * (1 - ($discount_rate / 100)));
            $item['member_discount_applied'] = true;
        }

        $item['price'] = $base_price; // Base price for display
        $item['options_total'] = $options_price;
        $item['final_price'] = $final_unit_price; // Actual price charged per unit

        $qty = intval($item['qty'] ?? 1);
        $items_amount += $final_unit_price * max(1, $qty);
        $item['qty'] = max(1, $qty); // Ensure it's saved as positive numeric
    }
    unset($item);

    $items_json = json_encode($order_data['items']);

    // Shipping calculation
    $shipping_fee = 0;
    if ($has_physical) {
        $flat_rate = intval(get_option('photo_pp_shipping_flat_rate', '500'));
        $free_threshold = intval(get_option('photo_pp_shipping_free_threshold', '5000'));
        $pref_rates = get_option('photo_pp_shipping_prefecture_rates', array());
        $pref = $order_data['shipping']['pref'] ?? '';

        if ($free_threshold > 0 && $items_amount >= $free_threshold) {
            $shipping_fee = 0;
        } else {
            $shipping_fee = ($pref && isset($pref_rates[$pref])) ? intval($pref_rates[$pref]) : $flat_rate;
        }
    }

    // COD Fee calculation
    $cod_fee = 0;
    if ($order_data['method'] === 'cod' && $has_physical) {
        $cod_fee = photo_purchase_calculate_cod_fee($items_amount + $shipping_fee);
    }

    $total_amount = $items_amount + $shipping_fee + $cod_fee;

    // Apply Coupon
    $coupon_info_data = null;
    $discount_amount = 0;
    if (!empty($order_data['coupon_info'])) {
        $coupon_details = is_array($order_data['coupon_info']) ? $order_data['coupon_info'] : json_decode($order_data['coupon_info'], true);
        
        // Fallback: if it's just a string code, wrap it
        if (!$coupon_details && is_string($order_data['coupon_info'])) {
            $coupon_details = array('code' => $order_data['coupon_info']);
        }
        
        if ($coupon_details && isset($coupon_details['code'])) {
            $coupon = photo_purchase_get_valid_coupon($coupon_details['code'], $items_amount);
            if (!is_wp_error($coupon)) {
                if ($coupon->discount_type === 'fixed') {
                    $discount_amount = intval($coupon->discount_amount);
                } else {
                    $discount_amount = floor($items_amount * ($coupon->discount_amount / 100));
                }
                
                $total_amount = max(0, $total_amount - $discount_amount);
                $coupon_info_data = array(
                    'code' => $coupon->code,
                    'type' => $coupon->discount_type,
                    'amount' => $coupon->discount_amount,
                    'applied_discount' => $discount_amount,
                    'stripe_duration' => $coupon->stripe_duration,
                    'stripe_months' => $coupon->stripe_months
                );
                // Update the array reference for downstream use
                $order_data['coupon_info'] = json_encode($coupon_info_data);
                $order_data['total_amount'] = $total_amount;
            }
        }
    }
    
    // Ensure total_amount is set even if no coupon
    if (!isset($order_data['total_amount'])) {
        $order_data['total_amount'] = $total_amount;
    }

    // Update shipping JSON with actual fees
    $shipping_data = $order_data['shipping'] ?? array();
    
    // Defensive: If shipping data is missing from array but present in POST (safety fallback)
    if (empty($shipping_data['address']) && isset($_POST['shipping_address'])) {
        $shipping_data['zip'] = sanitize_text_field($_POST['shipping_zip'] ?? '');
        $shipping_data['pref'] = sanitize_text_field($_POST['shipping_pref'] ?? '');
        $shipping_data['address'] = sanitize_textarea_field($_POST['shipping_address'] ?? '');
    }
    
    $shipping_data['fee'] = $shipping_fee;
    $shipping_data['cod_fee'] = $cod_fee;
    $order_data['shipping'] = $shipping_data; // Update the reference
    $shipping_json = json_encode($shipping_data);

    $status = 'pending_payment';
    if ($order_data['method'] === 'bank_transfer') {
        $bank_name = get_option('photo_pp_bank_name');
        $bank_branch = get_option('photo_pp_bank_branch');
        $bank_type = get_option('photo_pp_bank_type');
        $bank_number = get_option('photo_pp_bank_number');
        $bank_holder = get_option('photo_pp_bank_holder');

        $message = "【振込先口座のご案内】\n"; // Fixed: Initialized with = instead of .=
        $message .= "銀行名: " . $bank_name . "\n";
        $message .= "支店名: " . $bank_branch . "\n";
        $message .= "口座種別: " . $bank_type . "\n";
        $message .= "口座番号: " . $bank_number . "\n";
        $message .= "口座名義: " . $bank_holder . "\n\n";
        $message .= "お振込み金額: " . number_format($total_amount) . " 円\n";
        $message .= "※お振込み手数料はお客様負担となります。\n\n";
    }

    if ($order_data['method'] === 'cod') {
        // Server-side safety: block COD if any digital items are present
        foreach ($order_data['items'] as $item) {
            if ($item['format'] === 'digital') {
                $order_data['method'] = 'bank_transfer';
                $status = 'pending_payment';
                
                // Recalculate total (remove cod_fee)
                $total_amount -= $cod_fee;
                $cod_fee = 0;
                $shipping_data['cod_fee'] = 0;
                $order_data['shipping'] = $shipping_data;
                $shipping_json = json_encode($shipping_data);
                break;
            }
        }

        if ($order_data['method'] === 'cod') {
            $status = 'processing'; // Skip pending payment for COD
        }
    }

    $user_id = is_user_logged_in() ? get_current_user_id() : 0;

    $result = $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'order_token' => $order_token,
            'buyer_name' => $order_data['buyer']['name'],
            'buyer_email' => $order_data['buyer']['email'],
            'order_items' => $items_json,
            'shipping_info' => $shipping_json,
            'total_amount' => $total_amount,
            'payment_method' => $order_data['method'],
            'status' => $status,
            'coupon_info' => $coupon_info_data ? json_encode($coupon_info_data) : '',
            'order_notes' => $order_data['notes'] ?? '',
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
    );

    if ($result) {
        // [New] Save shipping info to user meta for logged-in members
        if ($user_id > 0) {
            update_user_meta($user_id, 'billing_phone', $order_data['buyer']['phone']);
            update_user_meta($user_id, 'billing_postcode', $order_data['shipping']['zip']);
            update_user_meta($user_id, 'billing_state', $order_data['shipping']['pref']);
            update_user_meta($user_id, 'billing_address_1', $order_data['shipping']['address']);
            update_user_meta($user_id, 'billing_address_2', ''); // Clear billing_address_2 so billing_address_1 has the full info
        }

        // Increment Coupon usage
        if ($coupon_info_data) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}photo_coupons SET usage_count = usage_count + 1 WHERE code = %s",
                $coupon_info_data['code']
            ));
        }

        // Stock Management: Decrement stock
        foreach ($order_data['items'] as $item) {
            $product_id = intval($item['id']);
            $qty = intval($item['qty']);
            $variation_id = $item['variation_id'] ?? '';

            if ($variation_id) {
                // SKU-level stock decrement
                $variations = get_post_meta($product_id, '_photo_variation_skus', true);
                if (is_array($variations)) {
                    $target_var = null;
                    if (isset($variations[$variation_id])) {
                        $target_var = &$variations[$variation_id];
                    } else {
                        foreach ($variations as &$v) {
                            if (($v['variation_id'] ?? '') === $variation_id) {
                                $target_var = &$v;
                                break;
                            }
                        }
                    }

                    if ($target_var) {
                        $current_v_stock = intval($target_var['stock'] ?? 0);
                        $qty_to_deduct = min($qty, $current_v_stock);
                        $target_var['stock'] = max(0, $current_v_stock - $qty_to_deduct);
                        $updated = true;
                    }
                    if ($updated) {
                        update_post_meta($product_id, '_photo_variation_skus', $variations);
                    }
                }
            } else {
                // Product-level stock decrement
                $manage_stock = get_post_meta($product_id, '_photo_manage_stock', true);
                if ($manage_stock === '1') {
                    // アトミックな在庫減少処理（レースコンディション対策）
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE post_id = %d AND meta_key = '_photo_stock_qty' AND CAST(meta_value AS SIGNED) >= %d",
                        $qty, $product_id, $qty
                    ));
                }
            }

            // Global stock alert check (still useful for general awareness)
            if (function_exists('photo_purchase_check_stock_alert')) {
                photo_purchase_check_stock_alert($product_id);
            }
        }

        $skip_admin_notif = in_array($order_data['method'], array('stripe', 'paypay'));
        if (!$skip_admin_notif) {
            photo_purchase_send_admin_notification($order_token, $order_data, $total_amount);
        }

        // We only send buyer confirmation here if it's bank transfer or COD.
        // Stripe success should send it after payment is confirmed in payment-handler.php.
        if ($order_data['method'] === 'bank_transfer' || $order_data['method'] === 'cod') {
            photo_purchase_send_buyer_notification($order_token, $order_data, $total_amount);
        }

        // --- Abandoned Cart Recovery: Mark as recovered ---
        $wpdb->update(
            $wpdb->prefix . 'photo_abandoned_carts',
            array(
                'status' => 'recovered',
                'recovered_at' => current_time('mysql')
            ),
            array('email' => $order_data['buyer']['email'], 'status' => 'pending', 'unsubscribed' => 0),
            array('%s', '%s'),
            array('%s', '%s', '%d')
        );
    }

    return $result;
}

/**
 * Send Admin Email Notification
 */
function photo_purchase_send_admin_notification($token, $data, $total)
{
    $admin_email = get_option('photo_pp_admin_notification_email', get_option('admin_email'));

    $shop_name = get_option('photo_pp_seller_name');
    if (empty($shop_name)) {
        $shop_name = get_bloginfo('name');
    }

    $subject = '【新規注文】' . $shop_name . 'より新しい注文がありました';

    $reg_num = get_option('photo_pp_tokusho_registration_number');
    $reg_line = $reg_num ? "適格請求書発行事業者登録番号: " . $reg_num . "\n" : "";

    $message = "新しい注文が入りました。\n\n";
    $message .= "注文番号: " . $token . "\n";
    $message .= "購入者: " . $data['buyer']['name'] . " 様\n";
    $message .= "メール: " . $data['buyer']['email'] . "\n";
    $message .= "合計金額: " . number_format($total) . " 円\n";
    $message .= $reg_line;
    
    if (!empty($data['notes'])) {
        $message .= "\n【注文備考】\n" . $data['notes'] . "\n";
    }
    

    $message .= "\n【ご注文内容】\n";
    $items_subtotal = 0;
    foreach ($data['items'] as $item) {
        $photo_id = intval($item['id']);
        $format = $item['format'];
        $qty = intval($item['qty']);
        
        $opt_p = 0;
        $opt_list = "";
        if (!empty($item['options']) && is_array($item['options'])) {
            foreach ($item['options'] as $opt) {
                $p = intval($opt['price'] ?? 0);
                $opt_p += $p;
                $p_label = ($p > 0) ? " (+" . number_format($p) . "円)" : "";
                $g_label = (!empty($opt['group']) && !in_array($opt['group'], array('項目', 'オプション'))) ? $opt['group'] . ": " : "+ ";
                $opt_list .= "  " . $g_label . $opt['name'] . $p_label . "\n";
            }
        }
        
        $price = isset($item['price']) ? intval($item['price']) : 0;
        $items_subtotal += ($price + $opt_p) * $qty;
        $fmt_label = photo_purchase_get_format_label($format);
        $var_label = !empty($item['variation_name']) ? " (" . $item['variation_name'] . ")" : "";
        
        $message .= get_the_title($photo_id) . $var_label . "\n";
        $message .= "形式: " . $fmt_label . " / 数量: " . $qty . "\n";
        if ($opt_list) $message .= $opt_list;
        $message .= "\n";
    }

    $shipping_fee = isset($data['shipping']['fee']) ? intval($data['shipping']['fee']) : 0;
    $cod_fee = isset($data['shipping']['cod_fee']) ? intval($data['shipping']['cod_fee']) : 0;
    $pref = isset($data['shipping']['pref']) ? $data['shipping']['pref'] : '';

    $message .= "商品小計: ¥" . number_format($items_subtotal) . "\n";
    if ($shipping_fee > 0 || !empty($pref)) {
        $message .= "送料 (" . ($pref ?: '一律') . "): ¥" . number_format($shipping_fee) . "\n";
    }
    if ($cod_fee > 0) {
        $message .= "代引き手数料: ¥" . number_format($cod_fee) . "\n";
    }

    // 会員割引の表示
    $member_discount_admin = 0;
    foreach ($data['items'] as $item) {
        $orig_u = intval($item['price'] ?? 0) + intval($item['options_total'] ?? 0);
        $final_u = intval($item['final_price'] ?? $orig_u);
        $member_discount_admin += ($orig_u - $final_u) * intval($item['qty'] ?? 1);
    }
    if ($member_discount_admin > 0) {
        $message .= "会員割引: -" . number_format($member_discount_admin) . " 円\n";
    }

    // クーポン割引情報の表示

    $message .= "合計（税込）: ¥" . number_format($total) . "\n";

    // Tax Breakdown (Invoice)
    $discount_val = 0;
    if (!empty($data['coupon_info'])) {
        $c_info = is_array($data['coupon_info']) ? $data['coupon_info'] : json_decode($data['coupon_info'], true);
        $discount_val = intval($c_info['applied_discount'] ?? 0);
    }
    $tax_results = photo_purchase_get_tax_breakdown($data['items'], $shipping_fee, $cod_fee, $discount_val);
    if (!empty($tax_results)) {
        $message .= "\n【消費税内訳】\n";
        foreach ($tax_results as $rate => $res) {
            $message .= $rate . "%対象額: ¥" . number_format($res['target']) . " (内消費税: ¥" . number_format($res['tax']) . ")\n";
        }
    }
    $message .= "\n";
    $method_label = 'クレジットカード';
    if ($data['method'] === 'bank_transfer') {
        $method_label = '銀行振込';
    } elseif ($data['method'] === 'cod') {
        $method_label = '代金引換';
    } elseif ($data['method'] === 'paypay') {
        $method_label = 'PayPay';
    }
    $message .= "支払い方法: " . $method_label . "\n";
    
    $reg_num = get_option('photo_pp_tokusho_registration_number');
    if ($reg_num) {
        $message .= "適格請求書発行事業者登録番号: " . $reg_num . "\n";
    }
    $message .= "\n";

    if ($data['method'] === 'bank_transfer') {
        $message .= "※銀行振込のため、入金確認後にステータスを更新してください。\n";
    }

    $message .= photo_purchase_get_email_footer($token, $data['buyer']['email']);

    $admin_email = get_option('photo_pp_admin_notification_email', get_option('admin_email'));
    $from_email = get_option('photo_pp_seller_email', get_option('admin_email'));
    $headers = array('Content-Type: text/plain; charset=UTF-8', "From: $shop_name <$from_email>");

    wp_mail($admin_email, $subject, $message, $headers);
}

/**
 * Send Buyer Confirmation Email
 */
function photo_purchase_send_buyer_notification($token, $data, $total)
{
    $buyer_email = $data['buyer']['email'];
    $shop_name = get_option('photo_pp_seller_name');
    if (empty($shop_name)) {
        $shop_name = get_bloginfo('name');
    }

    $subject = '【' . $shop_name . '】ご注文ありがとうございます';

    $message = $data['buyer']['name'] . " 様\n\n";
    $message .= "この度はご注文いただき、誠にありがとうございます。\n";
    $message .= "以下の内容でご注文を承りました。\n\n";

    $message .= "注文番号: " . $token . "\n";
    $message .= "お支払い合計: " . number_format($total) . " 円\n";

    if (!empty($data['notes'])) {
        $message .= "\n【注文備考】\n" . $data['notes'] . "\n";
    }

    $method_label = 'クレジットカード';
    if ($data['method'] === 'bank_transfer') {
        $method_label = '銀行振込';
    } elseif ($data['method'] === 'cod') {
        $method_label = '代金引換';
    } elseif ($data['method'] === 'paypay') {
        $method_label = 'PayPay';
    }
    $message .= "お支払い方法: " . $method_label . "\n";
    
    $reg_num = get_option('photo_pp_tokusho_registration_number');
    if ($reg_num) {
        $message .= "適格請求書発行事業者登録番号: " . $reg_num . "\n";
    }
    $message .= "\n";


    $message .= "【ご注文内容】\n";
    $items_subtotal = 0;
    foreach ($data['items'] as $item) {
        $photo_id = intval($item['id']);
        $format = $item['format'];
        $qty = intval($item['qty']);
        
        $opt_p = 0;
        $opt_list = "";
        if (!empty($item['options']) && is_array($item['options'])) {
            foreach ($item['options'] as $opt) {
                $p = intval($opt['price'] ?? 0);
                $opt_p += $p;
                $p_label = ($p > 0) ? " (+" . number_format($p) . "円)" : "";
                $g_label = (!empty($opt['group']) && !in_array($opt['group'], array('項目', 'オプション'))) ? $opt['group'] . ": " : "+ ";
                $opt_list .= "  " . $g_label . $opt['name'] . $p_label . "\n";
            }
        }
        
        $price = isset($item['price']) ? intval($item['price']) : 0;
        $items_subtotal += ($price + $opt_p) * $qty;
        $fmt_label = photo_purchase_get_format_label($format);
        $var_label = !empty($item['variation_name']) ? " (" . $item['variation_name'] . ")" : "";
        
        $message .= get_the_title($photo_id) . $var_label . "\n";
        $message .= "形式: " . $fmt_label . " / 数量: " . $qty . "\n";
        if ($opt_list) $message .= $opt_list;
        $message .= "\n";
    }

    $shipping_fee = isset($data['shipping']['fee']) ? intval($data['shipping']['fee']) : 0;
    $cod_fee = isset($data['shipping']['cod_fee']) ? intval($data['shipping']['cod_fee']) : 0;
    $pref = isset($data['shipping']['pref']) ? $data['shipping']['pref'] : '';

    $message .= "商品小計: ¥" . number_format($items_subtotal) . "\n";
    if ($shipping_fee > 0 || !empty($pref)) {
        $message .= "送料 (" . ($pref ?: '一律') . "): ¥" . number_format($shipping_fee) . "\n";
    }
    if ($cod_fee > 0) {
        $message .= "代引き手数料: ¥" . number_format($cod_fee) . "\n";
    }
    // 割引情報の表示
    $coupon_info_data = null;
    $coupon_raw = $data['coupon_info'] ?? '';
    if (empty($coupon_raw) || !str_contains($coupon_raw, '{')) {
        global $wpdb;
        $order_db = $wpdb->get_row($wpdb->prepare("SELECT coupon_info FROM {$wpdb->prefix}photo_orders WHERE order_token = %s", $token));
        if ($order_db && !empty($order_db->coupon_info)) { $coupon_raw = $order_db->coupon_info; }
    }
    if (!empty($coupon_raw)) {
        $coupon_info_data = is_array($coupon_raw) ? $coupon_raw : json_decode($coupon_raw, true);
    }
    
    // 会員割引の集計
    $member_discount_total = 0;
    foreach ($data['items'] as $item) {
        $orig_unit = intval($item['price'] ?? 0) + intval($item['options_total'] ?? 0);
        $final_unit = intval($item['final_price'] ?? $orig_unit);
        if ($orig_unit > $final_unit) {
            $member_discount_total += ($orig_unit - $final_unit) * intval($item['qty'] ?? 1);
        }
    }

    if ($member_discount_total > 0) {
        $message .= "会員割引: -" . number_format($member_discount_total) . " 円\n";
    }

    $discount_val = 0;
    if ($coupon_info_data && !empty($coupon_info_data['code'])) {
        $discount_val = intval($coupon_info_data['applied_discount'] ?? 0);
        $label = ($coupon_info_data['stripe_duration'] === 'forever') ? ' (継続適用)' : (($coupon_info_data['stripe_duration'] === 'repeating') ? ' (' . $coupon_info_data['stripe_months'] . 'ヶ月間)' : ' (初回のみ適用)');
        $message .= "割引 (" . $coupon_info_data['code'] . "): -" . number_format($discount_val) . " 円" . $label . "\n";
    }

    $message .= "合計（税込）: ¥" . number_format($total) . "\n";

    // Tax Breakdown (Invoice)
    $tax_results = photo_purchase_get_tax_breakdown($data['items'], $shipping_fee, $cod_fee, $discount_val);
    foreach ($tax_results as $rate => $res) {
        $message .= "( " . $rate . "%対象額 ¥" . number_format($res['target']) . " / 内消費税 ¥" . number_format($res['tax']) . " )\n";
    }
    $message .= "\n";

    if (!empty($data['shipping']['address'])) {
        $message .= "【お届け先】\n";
        $message .= "〒" . $data['shipping']['zip'] . "\n";
        $message .= $data['shipping']['address'] . "\n\n";
    }

    if ($data['method'] === 'bank_transfer') {
        $message .= "【お振込先案内】\n";
        $message .= "銀行名: " . get_option('photo_pp_bank_name') . "\n";
        $message .= "支店名: " . get_option('photo_pp_bank_branch') . "\n";
        $message .= "口座種別: " . get_option('photo_pp_bank_type') . "\n";
        $message .= "口座番号: " . get_option('photo_pp_bank_number') . "\n";
        $message .= "口座名義: " . get_option('photo_pp_bank_holder') . "\n";
        $message .= "振込時照会コード: " . $token . "\n";
        $message .= "※お名前の前に、必ず上記の照会コードを入力してください。\n\n";
    }

    $message .= "ご不明な点がございましたら、お気軽にお問い合わせください。\n";
    $message .= photo_purchase_get_email_footer($token, $data['buyer']['email']);

    $from_email = get_option('photo_pp_seller_email', get_option('admin_email'));
    $headers = array('Content-Type: text/plain; charset=UTF-8', "From: $shop_name <$from_email>");

    wp_mail($data['buyer']['email'], $subject, $message, $headers);
}

/**
 * Send Status Update Email to Buyer
 */
function photo_purchase_send_status_update_notification($order_id, $new_status)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $order_id));

    if (!$order) {
        return;
    }

    $shop_name = get_option('photo_pp_seller_name', get_bloginfo('name'));
    $from_email = get_option('photo_pp_seller_email', get_option('admin_email'));
    $headers = array('Content-Type: text/plain; charset=UTF-8', "From: $shop_name <$from_email>");

    $subject = '';
    $message = '';

    if ($new_status === 'processing') {
        if ($order->payment_method === 'cod') return;
        $subject = '【' . $shop_name . '】お支払いの確認が完了しました';
        $message = $order->buyer_name . " 様\n\n";
        $message .= "お支払いの確認が完了いたしました。\n";
        $message .= "ご注文いただいた商品の準備に入ります。発送まで今しばらくお待ちください。\n";
    } elseif ($new_status === 'sub_shipping') {
        $subject = '【' . $shop_name . '】商品の配送が開始されました';
        $message = $order->buyer_name . " 様\n\n";
        $message .= "ご注文いただいた定期購入商品の配送が開始されました。\n";
        $message .= "今後、定期的に商品をお届けいたします。\n\n";
        if (!empty($order->tracking_number)) {
            $carrier_name = !empty($order->carrier) ? photo_purchase_get_carrier_label($order->carrier) : '';
            $message .= "【お荷物伝票番号】\n" . $order->tracking_number . ($carrier_name ? " (" . $carrier_name . ")" : "") . "\n";
            $track_url = !empty($order->carrier) ? photo_purchase_get_carrier_url($order->carrier, $order->tracking_number) : '';
            if ($track_url) {
                $message .= "【配送状況を確認する】\n" . $track_url . "\n";
            }
            $message .= "\n";
        }
        $message .= "商品の到着まで今しばらくお待ちください。\n";
    } elseif ($new_status === 'service_active') {
        $subject = '【' . $shop_name . '】サービスのご利用が可能になりました';
        $message = $order->buyer_name . " 様\n\n";
        $message .= "ご注文いただいたサブスクリプションサービスのご利用準備が整いました。\n";
        $message .= "本日よりサービスをご利用いただけます。\n\n";
        
        // Add download links if digital products exist
        $items = json_decode($order->order_items, true);
        $dl_links = "";
        if ($items) {
            foreach ($items as $item) {
                if ($item['format'] === 'digital' || $item['format'] === 'subscription') {
                    // Check if it's a digital subscription or has digital attachments
                    $secure_url = photo_purchase_generate_download_url($order->order_token, $item['id']);
                    $dl_links .= "- " . get_the_title($item['id']) . "\n  " . $secure_url . "\n";
                }
            }
        }
        if ($dl_links) {
            $message .= "【コンテンツのご案内】\n" . $dl_links . "\n";
            $message .= "※データの取り扱いには十分ご注意ください。\n\n";
        }
    } elseif ($new_status === 'completed') {
        $subject = '【' . $shop_name . '】商品の発送が完了しました';
        $message = $order->buyer_name . " 様\n\n";
        $message .= "ご注文いただいた商品の準備が整いました。\n\n";

        if (!empty($order->tracking_number)) {
            $carrier_name = !empty($order->carrier) ? photo_purchase_get_carrier_label($order->carrier) : '';
            $message .= "【お荷物伝票番号】\n" . $order->tracking_number . ($carrier_name ? " (" . $carrier_name . ")" : "") . "\n";
            $track_url = !empty($order->carrier) ? photo_purchase_get_carrier_url($order->carrier, $order->tracking_number) : '';
            if ($track_url) {
                $message .= "【配送状況を確認する】\n" . $track_url . "\n";
            }
            $message .= "\n";
        }

        // Add download links if digital products exist
        $items = json_decode($order->order_items, true);
        $dl_links = "";
        if ($items) {
            foreach ($items as $item) {
                if ($item['format'] === 'digital') {
                    $secure_url = photo_purchase_generate_download_url($order->order_token, $item['id']);
                    $dl_links .= "- " . get_the_title($item['id']) . "\n  " . $secure_url . "\n";
                }
            }
        }
        if ($dl_links) {
            $message .= "【ダウンロード版商品のご案内】\n" . $dl_links . "\n";
            $message .= "※データの取り扱いには十分ご注意ください。\n\n";
        }

        $message .= "商品の到着まで今しばらくお待ちください。\n";
    }

    if ($subject && $message) {
        $message .= photo_purchase_get_email_footer($order->order_token, $order->buyer_email);
        wp_mail($order->buyer_email, $subject, $message, $headers);
    }
}

/**
 * Get Payment Method Label
 */
function photo_purchase_get_method_label($method)
{
    $labels = array(
        'stripe' => 'クレジットカード',
        'paypay' => 'PayPay',
        'bank_transfer' => '銀行振込',
        'cod' => '代金引換',
    );
    return $labels[$method] ?? $method;
}


/**
 * Send cancellation email to buyer
 */
function photo_purchase_send_cancel_notification($order_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $order_id));

    if (!$order) {
        return;
    }

    $shop_name = get_option('photo_pp_seller_name', get_bloginfo('name'));
    $from_email = get_option('photo_pp_seller_email', get_option('admin_email'));
    $headers = array('Content-Type: text/plain; charset=UTF-8', "From: $shop_name <$from_email>");

    $subject = '【' . $shop_name . '】ご注文キャンセルのお知らせ';
    $message = $order->buyer_name . " 様\n\n";
    $message .= "誠に恐れ入りますが、以下のご注文をキャンセル（返品受付）とさせていただきました。\n";
    $message .= "本メールをもちまして、適格返還請求書（返還インボイス）と代えさせていただきます。\n\n";
    $message .= "注文番号: " . $order->order_token . "\n";
    
    $reg_num = get_option('photo_pp_tokusho_registration_number');
    if ($reg_num) {
        $message .= "適格請求書発行事業者登録番号: " . $reg_num . "\n";
    }
    $message .= "\n";
    
    $message .= "【キャンセル内容】\n";
    $message .= "返還金額合計（税込）: ¥" . number_format($order->total_amount) . "\n";
    
    // 税内訳
    $c_items = json_decode($order->order_items, true);
    $c_shipping = json_decode($order->shipping_info, true);
    $c_coupon = 0;
    if (!empty($order->coupon_info)) {
        $c_coupon_data = json_decode($order->coupon_info, true);
        $c_coupon = intval($c_coupon_data['applied_discount'] ?? 0);
    }
    $c_tax = photo_purchase_get_tax_breakdown($c_items, $c_shipping['fee'] ?? 0, $c_shipping['cod_fee'] ?? 0, $c_coupon);
    if ($c_tax) {
        $message .= "\n【消費税返還内訳】\n";
        foreach ($c_tax as $rate => $res) {
            $message .= $rate . "%対象額: -¥" . number_format($res['target']) . " (内消費税: -¥" . number_format($res['tax']) . ")\n";
        }
    }
    $message .= "\n";

    $message .= "ご不明な点がございましたら、お気軽にお問い合わせください。\n";
    $message .= photo_purchase_get_email_footer($order->order_token, $order->buyer_email);

    wp_mail($order->buyer_email, $subject, $message, $headers);
}

/**
 * Admin Orders List Page
 */
function photo_purchase_orders_page()
{
	if (!current_user_can('manage_options')) {
		wp_die(__('このページにアクセスする権限がありません。', 'photo-purchase'));
	}
	global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';

    // Ensure database table is up to date (adds missing columns like tracking_number)
    if (function_exists('photo_purchase_create_db_table')) {
        photo_purchase_create_db_table();

        // Fail-safe: Manually check if column exists because dbDelta can be finicky
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'tracking_number'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE `$table_name` ADD `tracking_number` varchar(255) DEFAULT '' NOT NULL AFTER `status` ");
        }
    }

    // Handle Update Order (POST)
    if (isset($_POST['photo_update_order'])) {
        check_admin_referer('photo_update_order_action');
        $order_id = intval($_POST['order_id']);

        // Get old status to check if it changed
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $order_id));
        $old_status = $order->status;
        $old_shipping = json_decode($order->shipping_info, true);

        $buyer_name = sanitize_text_field($_POST['buyer_name']);
        $buyer_email = sanitize_email($_POST['buyer_email']);
        $status = sanitize_text_field($_POST['status']);
        $tracking_number = sanitize_text_field($_POST['tracking_number']);

        $shipping_info = $old_shipping;
        $shipping_info['zip'] = sanitize_text_field($_POST['shipping_zip']);
        $shipping_info['address'] = isset($_POST['shipping_address']) ? sanitize_textarea_field($_POST['shipping_address']) : ($old_shipping['address'] ?? '');

        $stripe_customer_id = sanitize_text_field($_POST['stripe_customer_id'] ?? '');
        $stripe_subscription_id = sanitize_text_field($_POST['stripe_subscription_id'] ?? '');

        $wpdb->update(
            $table_name,
            array(
                'buyer_name' => $buyer_name,
                'buyer_email' => $buyer_email,
                'status' => $status,
                'tracking_number' => $tracking_number,
                'carrier' => sanitize_text_field($_POST['carrier'] ?? ''),
                'stripe_customer_id' => $stripe_customer_id,
                'stripe_subscription_id' => $stripe_subscription_id,
                'shipping_info' => json_encode($shipping_info),
                'order_notes' => isset($_POST['order_notes']) ? sanitize_textarea_field($_POST['order_notes']) : '',
            ),
            array('id' => $order_id)
        );

        // If status changed to completed or processing, send notification
        if ($status !== $old_status && in_array($status, array('processing', 'completed'))) {
            photo_purchase_send_status_update_notification($order_id, $status);
        }

        // Redirect to avoid resubmission if possible, but don't exit to avoid blank pages
        if (!headers_sent()) {
            wp_redirect(remove_query_arg('photo_update_order'));
            exit;
        } else {
            echo '<div class="updated"><p>注文情報を更新しました。</p></div>';
        }
    }

    // Handle Bulk Action
    if (isset($_POST['photo_bulk_action']) && $_POST['photo_bulk_action'] !== '-1' && !empty($_POST['order_ids'])) {
        check_admin_referer('photo_bulk_orders_action');
        $action = sanitize_text_field($_POST['photo_bulk_action']);
        $order_ids = array_map('intval', $_POST['order_ids']);

        foreach ($order_ids as $id) {
            if ($action === 'delete') {
                $wpdb->delete($table_name, array('id' => $id));
            } elseif ($action === 'mark_processing') {
                $wpdb->update($table_name, array('status' => 'processing'), array('id' => $id));
                photo_purchase_send_status_update_notification($id, 'processing');
            } elseif ($action === 'mark_completed') {
                $wpdb->update($table_name, array('status' => 'completed'), array('id' => $id));
                photo_purchase_send_status_update_notification($id, 'completed');
            }
        }
        echo '<div class="updated"><p>一括操作を実行しました。</p></div>';
    }

    // Handle Edit View
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['order_id'])) {
        photo_purchase_order_edit_view(intval($_GET['order_id']));
        return;
    }

    // Handle Print View
    if (isset($_GET['action']) && in_array($_GET['action'], array('print_receipt', 'print_delivery')) && isset($_GET['order_id'])) {
        return;
    }

    // Handle Status Update
    if (isset($_GET['action']) && $_GET['action'] === 'update_status' && isset($_GET['order_id']) && isset($_GET['status'])) {
        check_admin_referer('photo_update_order_status');
        $order_id = intval($_GET['order_id']);
        $new_status = sanitize_text_field($_GET['status']);

        $wpdb->update(
            $table_name,
            array('status' => $new_status),
            array('id' => $order_id)
        );

        // Stock Management: If cancelled, return stock
        if ($new_status === 'cancelled') {
            photo_purchase_update_stock_for_order($order_id, true);
        }

        // Send Notification to Buyer
        photo_purchase_send_status_update_notification($order_id, $new_status);

        echo '<div class="updated"><p>ステータスを更新し、購入者へメールを送信しました。</p></div>';
    }

    // Handle Cancel Order (Feature 6)
    if (isset($_GET['action']) && $_GET['action'] === 'cancel_order' && isset($_GET['order_id'])) {
        check_admin_referer('photo_cancel_order');
        $order_id = intval($_GET['order_id']);

        // Feature: Automatic Refund
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $order_id));
        $refund_message = '';
        if ($order && !empty($order->transaction_id)) {
            $secret_key = get_option('photo_pp_stripe_secret_key');
            $refund_resp = wp_remote_post('https://api.stripe.com/v1/refunds', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body' => http_build_query(array(
                    'payment_intent' => $order->transaction_id,
                )),
            ));

            if (is_wp_error($refund_resp)) {
                $refund_message = '（自動返金通信エラー: ' . $refund_resp->get_error_message() . '）';
            } else {
                $code = wp_remote_retrieve_response_code($refund_resp);
                $body = json_decode(wp_remote_retrieve_body($refund_resp), true);
                if ($code === 200) {
                    $refund_message = '（自動返金が完了しました）';
                } else {
                    $error_detail = $body['error']['message'] ?? '不明なエラー';
                    $refund_message = '（自動返金エラー: ' . $error_detail . '）';
                }
            }
        }

        // Directly update status and send cancel email
        $wpdb->update($table_name, array('status' => 'cancelled'), array('id' => $order_id));
        photo_purchase_update_stock_for_order($order_id, true);
        photo_purchase_send_cancel_notification($order_id);
        echo '<div class="updated"><p>注文をキャンセルし、購入者へキャンセルメールを送信しました。' . esc_html($refund_message) . '</p></div>';
    }

    // Handle Delete Order
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['order_id'])) {
        check_admin_referer('photo_delete_order');
        $order_id = intval($_GET['order_id']);

        // Stock Management: If not already cancelled, return stock before deleting
        $order = $wpdb->get_row($wpdb->prepare("SELECT status FROM $table_name WHERE id = %d", $order_id));
        if ($order && $order->status !== 'cancelled') {
            photo_purchase_update_stock_for_order($order_id, true);
        }

        $wpdb->delete($table_name, array('id' => $order_id));
        echo '<div class="updated"><p>注文を削除しました。</p></div>';
    }

    // Handle Bulk Delete Selected Orders
    $bulk_action = (isset($_POST['photo_bulk_action']) && $_POST['photo_bulk_action'] !== '-1') ? $_POST['photo_bulk_action'] : (isset($_POST['photo_bulk_action2']) ? $_POST['photo_bulk_action2'] : '-1');
    if ($bulk_action === 'delete' && !empty($_POST['order_ids'])) {
        check_admin_referer('photo_bulk_orders_action');
        $order_ids = array_map('intval', $_POST['order_ids']);
        $deleted_count = 0;
        foreach ($order_ids as $id) {
            // Stock Management: If not already cancelled, return stock before deleting
            $order = $wpdb->get_row($wpdb->prepare("SELECT status FROM $table_name WHERE id = %d", $id));
            if ($order && $order->status !== 'cancelled') {
                photo_purchase_update_stock_for_order($id, true);
            }
            if ($wpdb->delete($table_name, array('id' => $id))) {
                $deleted_count++;
            }
        }
        echo '<div class="updated"><p>' . sprintf('%d 件の注文を削除しました。', $deleted_count) . '</p></div>';
    }

    // Filter Logic
    $filter = isset($_GET['order_filter']) ? sanitize_text_field($_GET['order_filter']) : 'active';
    $where_clause = "WHERE 1=1";
    
    if ($filter === 'active') {
        $where_clause .= " AND NOT (status = 'pending_payment' AND payment_method IN ('stripe', 'paypay'))";
    } elseif ($filter === 'subscription') {
        $where_clause .= " AND stripe_subscription_id != ''";
    } elseif ($filter === 'abandoned') {
        $days = 7;
        $threshold_date = date('Y-m-d H:i:s', strtotime("-$days days"));
        $where_clause .= " AND ( (status = 'pending_payment' AND payment_method IN ('stripe', 'paypay')) OR (status = 'pending_payment' AND created_at < '$threshold_date') )";
    }
    // 'all' filter doesn't add anything to $where_clause

    $orders = $wpdb->get_results("
        SELECT * FROM $table_name 
        $where_clause
        ORDER BY created_at DESC
    ");

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php _e('受注一覧', 'photo-purchase'); ?></h1>
        <a href="<?php echo wp_nonce_url(admin_url('edit.php?post_type=photo_product&page=photo-purchase-orders&action=export_csv'), 'photo_export_csv'); ?>" class="page-title-action">💾 CSVエクスポート</a>
        <hr class="wp-header-end">

        <ul class="subsubsub">
            <li class="all"><a href="<?php echo remove_query_arg('order_filter'); ?>" class="<?php echo ($filter === 'active') ? 'current' : ''; ?>">通常注文</a> |</li>
            <li class="subscription"><a href="<?php echo add_query_arg('order_filter', 'subscription'); ?>" class="<?php echo ($filter === 'subscription') ? 'current' : ''; ?>">サブスクリプション</a> |</li>
            <li class="abandoned"><a href="<?php echo add_query_arg('order_filter', 'abandoned'); ?>" class="<?php echo ($filter === 'abandoned') ? 'current' : ''; ?>">放置注文</a> |</li>
            <li class="all_orders"><a href="<?php echo add_query_arg('order_filter', 'all'); ?>" class="<?php echo ($filter === 'all') ? 'current' : ''; ?>">すべて表示</a></li>
        </ul>

        <form method="post" action="">
            <?php wp_nonce_field('photo_bulk_orders_action'); ?>
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="photo_bulk_action">
                        <option value="-1">一括操作</option>
                        <option value="delete">削除</option>
                        <option value="mark_processing">ステータス: 準備中に変更</option>
                        <option value="mark_completed">ステータス: 完了（発送済み）に変更</option>
                    </select>
                    <input type="submit" class="button action" value="適用">
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td id="cb" class="manage-column column-cb check-column"><input id="cb-select-all-1" type="checkbox"></td>
                        <th><?php _e('注文日時', 'photo-purchase'); ?></th>
                        <th><?php _e('購入者', 'photo-purchase'); ?></th>
                        <th><?php _e('商品 / 形式', 'photo-purchase'); ?></th>
                    <th><?php _e('配送先', 'photo-purchase'); ?></th>
                    <th><?php _e('送り状番号', 'photo-purchase'); ?></th>
                    <th><?php _e('合計金額', 'photo-purchase'); ?></th>
                    <th><?php _e('支払い', 'photo-purchase'); ?></th>
                    <th><?php _e('備考', 'photo-purchase'); ?></th>
                    <th><?php _e('ステータス', 'photo-purchase'); ?></th>
                    <th><?php _e('操作', 'photo-purchase'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="10"><?php _e('まだ注文はありません。', 'photo-purchase'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $order):
                        $items = json_decode($order->order_items, true);
                        $shipping = json_decode($order->shipping_info, true);
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="order_ids[]" value="<?php echo $order->id; ?>">
                            </th>
                            <td><?php echo esc_html($order->created_at); ?></td>
                            <td>
                                <strong><?php echo esc_html($order->buyer_name); ?></strong><br>
                                <small><?php echo esc_html($order->buyer_email); ?></small>
                            </td>
                            <td>
                                <?php foreach ($items as $item): ?>
                                    <div style="margin-bottom:5px;">
                                        <?php echo esc_html(get_the_title($item['id'])); ?> 
                                        <?php if (!empty($item['variation_name'])): ?>
                                            <span style="color:#2563eb; font-weight:bold;">[<?php echo esc_html($item['variation_name']); ?>]</span>
                                        <?php endif; ?>
                                        <span style="color:#777;">(<?php echo photo_purchase_get_format_label($item['format']); ?> x <?php echo esc_html($item['qty']); ?>)</span>
                                        <?php if (!empty($item['options']) && is_array($item['options'])): ?>
                                            <?php foreach ($item['options'] as $opt): ?>
                                                <div style="font-size:11px; margin-left:10px; color:#16a34a;">・<?php echo esc_html($opt['name']); ?></div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if (!empty($shipping['address'])): ?>
                                    〒<?php echo esc_html($shipping['zip']); ?><br>
                                    <?php echo esc_html($shipping['address']); ?>
                                <?php else:
                                    // サブスク商品かつ配送不要かチェック
                                    $is_sub_shipping = false;
                                    if ($items) {
                                        foreach ($items as $it_s) {
                                            if ($it_s['format'] === 'subscription') { $is_sub_shipping = true; break; }
                                        }
                                    }
                                    if ($is_sub_shipping): ?>
                                    <span class="description">配送不要（サービス）</span>
                                    <?php else: ?>
                                    <span class="description">ダウンロードのみ</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html($order->tracking_number); ?>
                            </td>
                             <td>
                                <strong><?php echo number_format($order->total_amount); ?> 円</strong>
                                <?php 
                                $fee_breakdown = array();
                                if ($f = ($shipping['fee'] ?? 0)) $fee_breakdown[] = '送料:' . number_format($f);
                                if ($c = ($shipping['cod_fee'] ?? 0)) $fee_breakdown[] = '代引き:' . number_format($c);
                                if (!empty($fee_breakdown)) echo '<br><small style="color:#888;">(' . implode(', ', $fee_breakdown) . ' 含む)</small>';
                                
                                if (!empty($order->coupon_info)) {
                                    $coupon_data = json_decode($order->coupon_info, true);
                                    if ($coupon_data && !empty($coupon_data['code'])) {
                                        $dur_lbl = '';
                                        if (isset($coupon_data['stripe_duration'])) {
                                            $dur_lbl = ($coupon_data['stripe_duration'] === 'forever') ? ' (永続)' : (($coupon_data['stripe_duration'] === 'repeating') ? ' (' . ($coupon_data['stripe_months'] ?? 0) . 'ヶ月)' : ' (初回のみ)');
                                        }
                                        echo '<br><small style="color:#c00;">割引(' . esc_html($coupon_data['code']) . '): -' . number_format($coupon_data['applied_discount']) . '円' . $dur_lbl . '</small>';
                                    }
                                }
                                ?>
                             </td>
                            <td>
                                <?php
                                if ($order->payment_method === 'bank_transfer') {
                                    echo '銀行振込';
                                } elseif ($order->payment_method === 'cod') {
                                    echo '代引き';
                                } elseif ($order->payment_method === 'paypay') {
                                    echo 'PayPay';
                                } else {
                                    echo 'クレカ';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($order->order_notes)): ?>
                                    <span title="<?php echo esc_attr($order->order_notes); ?>" style="cursor:help; background:#f0f0f0; padding:4px 8px; border-radius:4px; font-size:16px;">📝</span>
                                <?php else: ?>
                                    <span style="color:#ccc;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($order->status === 'pending_payment'): ?>
                                    <span class="photo-status photo-status-pending">入金待ち</span>
                                <?php elseif ($order->status === 'processing'): ?>
                                    <?php
                                        $is_sub_proc = false;
                                        $has_shipping_proc = false;
                                        $items_proc = json_decode($order->order_items, true);
                                        if ($items_proc) {
                                            foreach ($items_proc as $it_p) {
                                                if ($it_p['format'] === 'subscription') {
                                                    $is_sub_proc = true;
                                                    if (get_post_meta($it_p['id'], '_photo_sub_requires_shipping', true) === '1') {
                                                        $has_shipping_proc = true;
                                                    }
                                                } elseif ($it_p['format'] !== 'digital') {
                                                    $has_shipping_proc = true;
                                                }
                                            }
                                        }
                                        if ($is_sub_proc && !$has_shipping_proc) {
                                            echo '<span class="photo-status photo-status-processing" style="background:#f5f3ff; color:#7c3aed; border-color:#ddd6fe;">有効 / 継続中</span>';
                                        } else {
                                            echo '<span class="photo-status photo-status-processing">決済完 / 準備中</span>';
                                        }
                                    ?>
                                <?php elseif ($order->status === 'active'): ?>
                                    <span class="photo-status photo-status-active">サブスク有効</span>
                                <?php elseif ($order->status === 'completed'): ?>
                                    <?php 
                                        $has_shipping = false;
                                        $is_sub_item = false;
                                        $cycle_label = '';
                                        $items_tmp = json_decode($order->order_items, true);
                                        if ($items_tmp) {
                                            foreach($items_tmp as $it) {
                                                if ($it['format'] === 'subscription') {
                                                    $is_sub_item = true;
                                                    if (get_post_meta($it['id'], '_photo_sub_requires_shipping', true) === '1') {
                                                        $has_shipping = true;
                                                        // 発送サイクル情報を取得
                                                        $cnt = get_post_meta($it['id'], '_photo_sub_interval_count', true) ?: '1';
                                                        $inv = get_post_meta($it['id'], '_photo_sub_interval', true) ?: 'month';
                                                        $inv_labels = array('day' => '日', 'week' => '週', 'month' => 'ヶ月', 'year' => '年');
                                                        $cycle_label = $cnt . ($inv_labels[$inv] ?? $inv) . 'ごと';
                                                    }
                                                } elseif ($it['format'] !== 'digital') {
                                                    $has_shipping = true;
                                                }
                                            }
                                        }
                                        $lbl = ($is_sub_item && $has_shipping) ? '配送開始 / 継続中' : '発送済み / 完了';
                                    ?>
                                    <span class="photo-status photo-status-completed"><?php echo $lbl; ?></span>
                                    <?php if ($is_sub_item && $has_shipping && $cycle_label): ?>
                                        <br><small style="color:#4f46e5; font-size:11px;">🔄 <?php echo esc_html($cycle_label); ?></small>
                                    <?php endif; ?>
                                <?php elseif ($order->status === 'cancelled'): ?>
                                    <span class="photo-status photo-status-cancelled">キャンセル</span>
                                <?php elseif ($order->status === 'service_active'): ?>
                                    <span class="photo-status photo-status-service-active">🟣 サブスク有効 / サービス中</span>
                                <?php elseif ($order->status === 'sub_shipping'): ?>
                                    <?php
                                        $cycle_sub = '';
                                        $_items_sub = json_decode($order->order_items, true);
                                        if ($_items_sub) foreach ($_items_sub as $_it_sub) {
                                            if ($_it_sub['format'] === 'subscription') {
                                                $_cnt = get_post_meta($_it_sub['id'], '_photo_sub_interval_count', true) ?: '1';
                                                $_inv = get_post_meta($_it_sub['id'], '_photo_sub_interval', true) ?: 'month';
                                                $_inv_l = array('day' => '日', 'week' => '週', 'month' => 'ヶ月', 'year' => '年');
                                                $cycle_sub = $_cnt . ($_inv_l[$_inv] ?? $_inv) . 'ごと';
                                                break;
                                            }
                                        }
                                    ?>
                                    <span class="photo-status photo-status-sub-shipping">🔄 サブスク有効 / 配送中</span>
                                    <?php if ($cycle_sub): ?><br><small style="color:#4f46e5; font-size:11px;">🔄 <?php echo esc_html($cycle_sub); ?></small><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($order->status === 'pending_payment'): ?>
                                    <a href="#" class="button button-small photo-update-status-ajax" data-id="<?php echo $order->id; ?>" data-status="processing">入金確認</a>
                                <?php elseif ($order->status === 'processing'): ?>
                                    <?php
                                        // サービスサブスクは「発送完了」ではなく「サービス開始」に
                                        $is_service_sub = false;
                                        $has_ship_btn = false;
                                        $_items_btn = json_decode($order->order_items, true);
                                        if ($_items_btn) {
                                            foreach ($_items_btn as $_it) {
                                                if ($_it['format'] === 'subscription') {
                                                    $is_service_sub = true;
                                                    if (get_post_meta($_it['id'], '_photo_sub_requires_shipping', true) === '1') {
                                                        $has_ship_btn = true;
                                                    }
                                                } elseif ($_it['format'] !== 'digital') {
                                                    $has_ship_btn = true;
                                                }
                                            }
                                        }
                                        if ($is_service_sub && !$has_ship_btn):
                                    ?>
                                    <a href="#" class="button button-small photo-update-status-ajax" data-id="<?php echo $order->id; ?>" data-status="completed" style="color:#7c3aed; border-color:#7c3aed;">サービス開始</a>
                                    <?php else: ?>
                                    <a href="#" class="button button-small photo-update-status-ajax" data-id="<?php echo $order->id; ?>" data-status="completed">発送完了</a>
                                    <?php endif; ?>
                                <?php elseif ($order->status === 'active'): ?>
                                    <?php
                                        $has_shipping_active = false;
                                        $_items_active = json_decode($order->order_items, true);
                                        if ($_items_active) {
                                            foreach ($_items_active as $_it_active) {
                                                if ($_it_active['format'] === 'subscription' && get_post_meta($_it_active['id'], '_photo_sub_requires_shipping', true) === '1') {
                                                    $has_shipping_active = true;
                                                    break;
                                                }
                                            }
                                        }
                                        if ($has_shipping_active):
                                    ?>
                                    <a href="#" class="button button-small photo-update-status-ajax" data-id="<?php echo $order->id; ?>" data-status="sub_shipping" style="background:#4f46e5; color:#fff; border-color:#4338ca;">発送開始</a>
                                    <?php else: ?>
                                    <a href="#" class="button button-small photo-update-status-ajax" data-id="<?php echo $order->id; ?>" data-status="service_active" style="color:#7c3aed; border-color:#7c3aed;">サービス開始</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a href="<?php echo add_query_arg(array('action' => 'edit', 'order_id' => $order->id)); ?>"
                                    class="button button-small">編集</a>
                                <?php if ($order->status !== 'cancelled' && $order->status !== 'completed'): ?>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'cancel_order', 'order_id' => $order->id)), 'photo_cancel_order'); ?>"
                                        class="button button-small" style="color:#a00; border-color:#a00;"
                                        onclick="return confirm('キャンセルしますか？購入者へメールを送信します。');">キャンセル</a>
                                <?php endif; ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?action=photo_resend_email&order_id=' . $order->id), 'photo_resend_email'); ?>"
                                    class="button button-small" onclick="return confirm('確認メールを再送しますか？');">📧 再送</a>
                                <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'delete', 'order_id' => $order->id)), 'photo_delete_order'); ?>"
                                    class="button button-small" style="color:#a00; border-color:#a00;"
                                    onclick="return confirm('本当に削除してもよろしいですか？');">削除</a>
                                <br>
                                <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'print_delivery', 'order_id' => $order->id)), 'photo_print_order'); ?>"
                                    target="_blank" class="button button-small" style="margin-top:5px;">納品書</a>
                                <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'print_receipt', 'order_id' => $order->id)), 'photo_print_order'); ?>"
                                    target="_blank" class="button button-small" style="margin-top:5px;">領収書</a>
                                <?php if (!empty($order->stripe_subscription_id) || $is_sub_item): ?>
                                    <a href="https://dashboard.stripe.com/subscriptions/<?php echo esc_attr($order->stripe_subscription_id ?: 'customers'); ?>" target="_blank" class="button button-small" style="margin-top:5px;">サブスク管理</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr id="order-detail-<?php echo $order->id; ?>" class="photo-detail-row" style="display: none;">
                            <td colspan="11" class="photo-detail-content">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                    <div>
                                        <h4 style="margin: 0 0 10px 0;">📦 注文商品詳細</h4>
                                        <table class="photo-detail-table">
                                            <thead>
                                                <tr><th>商品名</th><th>形式</th><th>単価</th><th>数量</th><th>小計</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $subtotal_items = 0;
                                                foreach ($items as $it): 
                                                    $p = intval($it['price'] ?? 0);
                                                    $q = intval($it['qty'] ?? 1);
                                                    
                                                    // Add option prices
                                                    $opt_total = 0;
                                                    if (!empty($it['options'])) {
                                                        foreach ($it['options'] as $opt) { $opt_total += intval($opt['price'] ?? 0); }
                                                    }
                                                    $line_sub = ($p + $opt_total) * $q;
                                                    $subtotal_items += $line_sub;
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo esc_html(get_the_title($it['id'])); ?>
                                                            <?php if (!empty($it['variation_name'])): ?>
                                                                <div style="color:#2563eb; font-weight:bold; margin-top:2px;">[<?php echo esc_html($it['variation_name']); ?>]</div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($it['options'])): ?>
                                                                <div style="font-size:0.85em; color:#666; margin-top:2px;">
                                                                    <?php foreach ($it['options'] as $opt): ?>
                                                                        ・<?php echo esc_html($opt['name']); ?> (+<?php echo number_format($opt['price']); ?>円)<br>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo photo_purchase_get_format_label($it['format']); ?></td>
                                                        <td>¥<?php echo number_format($p + $opt_total); ?></td>
                                                        <td><?php echo $q; ?></td>
                                                        <td>¥<?php echo number_format($line_sub); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr><th colspan="4" style="text-align: right;">商品小計</th><td>¥<?php echo number_format($subtotal_items); ?></td></tr>
                                                <?php if ($f = ($shipping['fee'] ?? 0)): ?>
                                                    <tr><th colspan="4" style="text-align: right;">送料</th><td>¥<?php echo number_format($f); ?></td></tr>
                                                <?php endif; ?>
                                                <?php if ($c = ($shipping['cod_fee'] ?? 0)): ?>
                                                    <tr><th colspan="4" style="text-align: right;">代引き手数料</th><td>¥<?php echo number_format($c); ?></td></tr>
                                                <?php endif; ?>
                                                <?php if (!empty($order->coupon_info)): 
                                                    $c_data = json_decode($order->coupon_info, true);
                                                    if ($c_data && !empty($c_data['code'])): ?>
                                                    <tr><th colspan="4" style="text-align: right;">割引 (<?php echo esc_html($c_data['code']); ?>)</th><td style="color:#c00;">-¥<?php echo number_format($c_data['applied_discount']); ?></td></tr>
                                                <?php endif; endif; ?>
                                                <tr style="font-size: 1.1em;"><th colspan="4" style="text-align: right;">合計</th><td><strong>¥<?php echo number_format($order->total_amount); ?></strong></td></tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    <div>
                                        <h4 style="margin: 0 0 10px 0;">👤 購入者・お届け先情報</h4>
                                        <p><strong>名前:</strong> <?php echo esc_html($order->buyer_name); ?> 様</p>
                                        <p><strong>メール:</strong> <?php echo esc_html($order->buyer_email); ?></p>
                                        <?php if (!empty($shipping['address'])): ?>
                                            <p><strong>お届け先:</strong><br>
                                            〒<?php echo esc_html($shipping['zip']); ?><br>
                                            <?php echo nl2br(esc_html($shipping['address'])); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($order->order_notes)): ?>
                                            <p><strong>備考:</strong><br><?php echo nl2br(esc_html($order->order_notes)); ?></p>
                                        <?php endif; ?>
                                        <div style="margin-top: 20px;">
                                            <a href="<?php echo wp_nonce_url(add_query_arg(array('photo_purchase_action' => 'print_doc', 'order_id' => $order->id, 'type' => 'print_invoice', 'order_token' => $order->order_token)), 'photo_print_doc'); ?>" class="button" target="_blank">📄 請求書印刷</a>
                                            <a href="<?php echo wp_nonce_url(add_query_arg(array('photo_purchase_action' => 'print_doc', 'order_id' => $order->id, 'type' => 'print_delivery', 'order_token' => $order->order_token)), 'photo_print_doc'); ?>" class="button" target="_blank">🚚 納品書印刷</a>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="manage-column column-cb check-column"><input id="cb-select-all-2" type="checkbox"></td>
                    <th><?php _e('注文日時', 'photo-purchase'); ?></th>
                    <th><?php _e('購入者', 'photo-purchase'); ?></th>
                    <th><?php _e('商品 / 形式', 'photo-purchase'); ?></th>
                    <th><?php _e('配送先', 'photo-purchase'); ?></th>
                    <th><?php _e('送り状番号', 'photo-purchase'); ?></th>
                    <th><?php _e('合計金額', 'photo-purchase'); ?></th>
                    <th><?php _e('支払い', 'photo-purchase'); ?></th>
                    <th><?php _e('ステータス', 'photo-purchase'); ?></th>
                    <th><?php _e('操作', 'photo-purchase'); ?></th>
                </tr>
            </tfoot>
        </table>
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select name="photo_bulk_action2">
                    <option value="-1">一括操作</option>
                    <option value="delete">削除</option>
                </select>
                <input type="submit" class="button action" value="適用">
            </div>
		</div>
        </form>
    </div>
    <?php
}

/**
 * Order Edit View
 */
function photo_purchase_order_edit_view($order_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $order_id));

    if (!$order) {
        echo '<div class="error"><p>注文が見つかりません。</p></div>';
        return;
    }

    $shipping = json_decode($order->shipping_info, true);
    ?>
    <div class="wrap">
        <h1>注文の編集</h1>
        <form method="post" action="<?php echo remove_query_arg('action'); ?>">
            <?php wp_nonce_field('photo_update_order_action'); ?>
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order->id); ?>">
            <input type="hidden" name="photo_update_order" value="1">

            <table class="form-table">
                <tr>
                    <th><label>注文番号</label></th>
                    <td><code><?php echo esc_html($order->order_token); ?></code></td>
                </tr>
                <tr>
                    <th><label for="buyer_name">購入者名</label></th>
                    <td><input type="text" name="buyer_name" id="buyer_name"
                            value="<?php echo esc_attr($order->buyer_name); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="buyer_email">メールアドレス</label></th>
                    <td><input type="email" name="buyer_email" id="buyer_email"
                            value="<?php echo esc_attr($order->buyer_email); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="shipping_zip">郵便番号</label></th>
                    <td><input type="text" name="shipping_zip" id="shipping_zip"
                            value="<?php echo esc_attr($shipping['zip']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="shipping_address">住所</label></th>
                    <td><textarea name="shipping_address" id="shipping_address" rows="3"
                            class="large-text"><?php echo esc_textarea($shipping['address']); ?></textarea></td>
                </tr>
                <tr>
                    <th><label for="status">ステータス</label></th>
                    <td>
                        <select name="status" id="status">
                            <option value="pending_payment" <?php selected($order->status, 'pending_payment'); ?>>入金待ち
                            </option>
                            <option value="processing" <?php selected($order->status, 'processing'); ?>>入金済み / 準備中（発送待ち）</option>
                            <option value="completed" <?php selected($order->status, 'completed'); ?>>発送済み・配送開始（完了）</option>
                            <?php
                                // サービスサブスクかどうかチェック
                                $_items_edit = json_decode($order->order_items, true);
                                $is_service_edit = false;
                                $is_ship_sub_edit = false;
                                if ($_items_edit) {
                                    foreach ($_items_edit as $_it_e) {
                                        if ($_it_e['format'] === 'subscription') {
                                            if (get_post_meta($_it_e['id'], '_photo_sub_requires_shipping', true) === '1') {
                                                $is_ship_sub_edit = true;
                                            } else {
                                                $is_service_edit = true;
                                            }
                                        }
                                    }
                                }
                            ?>
                            <option value="active" <?php selected($order->status, 'active'); ?>>✅ サブスク有効</option>
                            <?php if ($is_service_edit): ?>
                            <option value="service_active" <?php selected($order->status, 'service_active'); ?>>🟣 サブスク有効 / サービス中</option>
                            <?php endif; ?>
                            <?php if ($is_ship_sub_edit): ?>
                            <option value="sub_shipping" <?php selected($order->status, 'sub_shipping'); ?>>🔄 サブスク有効 / 配送中</option>
                            <?php endif; ?>
                            <option value="cancelled" <?php selected($order->status, 'cancelled'); ?>>キャンセル</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="tracking_number">送り状番号</label></th>
                    <td>
                        <input type="text" name="tracking_number" id="tracking_number"
                            value="<?php echo esc_attr($order->tracking_number); ?>" class="regular-text">
                        <p class="description">発送完了時に購入者へ通知されます。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="carrier">配送業者</label></th>
                    <td>
                        <select name="carrier" id="carrier">
                            <option value="">-- 指定なし --</option>
                            <option value="yamato" <?php selected($order->carrier, 'yamato'); ?>>ヤマト運輸</option>
                            <option value="sagawa" <?php selected($order->carrier, 'sagawa'); ?>>佐川急便</option>
                            <option value="japanpost" <?php selected($order->carrier, 'japanpost'); ?>>日本郵便 (ゆうパック等)</option>
                            <option value="seino" <?php selected($order->carrier, 'seino'); ?>>西濃運輸</option>
                            <option value="other" <?php selected($order->carrier, 'other'); ?>>その他</option>
                        </select>
                        <?php if ($order->tracking_number && !empty($order->carrier)): 
                            $url = photo_purchase_get_carrier_url($order->carrier, $order->tracking_number);
                            if ($url): ?>
                                <a href="<?php echo esc_url($url); ?>" target="_blank" style="margin-left: 10px; text-decoration: none;">🔍 追跡ページを開く</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="stripe_customer_id">Stripe 顧客ID</label></th>
                    <td>
                        <input type="text" name="stripe_customer_id" id="stripe_customer_id"
                            value="<?php echo esc_attr($order->stripe_customer_id); ?>" class="regular-text"
                            placeholder="cus_XXXXXXXXXXXXXXXXXX">
                        <p class="description">Stripeダッシュボードで確認できる Customer ID（サブスク管理・解除ボタンに必要）</p>
                    </td>
                </tr>
                <tr>
                    <th><label>帳票出力</label></th>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg(array('photo_purchase_action' => 'print_doc', 'order_id' => $order->id, 'type' => 'print_invoice', 'order_token' => $order->order_token), home_url('/'))); ?>" class="button" target="_blank">納品書（請求書）を表示</a>
                        <a href="<?php echo esc_url(add_query_arg(array('photo_purchase_action' => 'print_doc', 'order_id' => $order->id, 'type' => 'print_receipt', 'order_token' => $order->order_token), home_url('/'))); ?>" class="button" target="_blank">領収書を表示</a>
                        <p class="description">別ウィンドウで開き、ブラウザの印刷機能（Ctrl+P）でPDF保存や印刷が可能です。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="order_notes">注文備考</label></th>
                    <td>
                        <textarea name="order_notes" id="order_notes" rows="5" class="large-text"><?php echo esc_textarea($order->order_notes); ?></textarea>
                        <p class="description">お客様が購入時に入力した備考欄です。管理者側で追記・修正も可能です。</p>
                    </td>
                </tr>
                <tr>
                    <th><label>注文内容明細</label></th>
                    <td>
                        <div style="background:#f9f9f9; border:1px solid #ccd0d4; padding:15px; border-radius:4px; max-width:600px;">
                            <?php 
                            $items = json_decode($order->order_items, true);
                            if ($items):
                                foreach ($items as $item): ?>
                                    <div style="margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:10px;">
                                        <div style="font-weight:bold;">
                                            <?php echo get_the_title($item['id']); ?>
                                            <?php if (!empty($item['variation_name'])): ?>
                                                <span style="color:#2563eb;"> [<?php echo esc_html($item['variation_name']); ?>]</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="color:#666; font-size:13px;">形式: <?php echo photo_purchase_get_format_label($item['format']); ?> / 数量: <?php echo esc_html($item['qty']); ?></div>
                                        <?php if (!empty($item['options']) && is_array($item['options'])): ?>
                                            <?php foreach ($item['options'] as $opt): ?>
                                                <?php 
                                                $p_val = intval($opt['price']);
                                                $p_label = ($p_val > 0) ? ' (+' . number_format($p_val) . '円)' : '';
                                                ?>
                                                <div style="color:#28a745; font-size:12px; margin-left:10px;">・ <?php 
                                                $g_lbl = (!empty($opt['group']) && !in_array($opt['group'], array('項目', 'オプション'))) ? esc_html($opt['group']) . ': ' : 'オプション: ';
                                                echo $g_lbl . esc_html($opt['name']); ?><?php echo $p_label; ?></div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach;
                            endif; ?>
                            
                            <div style="margin-top:15px; border-top:2px solid #ccd0d4; pt:10px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                                    <span>商品小計:</span>
                                    <span>¥<?php 
                                        $items_sum = 0;
                                        if ($items) {
                                            foreach ($items as $item) {
                                                // Use buy-time price snapshot if available, fallback to current meta ONLY if snapshot is missing
                                                $p = isset($item['price']) ? intval($item['price']) : -1;
                                                if ($p === -1) {
                                                    $p = intval(get_post_meta($item['id'], ($item['format'] === 'l_size') ? '_photo_price_l' : (($item['format'] === '2l_size') ? '_photo_price_2l' : '_photo_price_' . $item['format']), true) ?: get_post_meta($item['id'], '_photo_price', true));
                                                }
                                                $opt_p = 0;
                                                if (!empty($item['options']) && is_array($item['options'])) {
                                                    foreach ($item['options'] as $opt) { $opt_p += intval($opt['price'] ?? 0); }
                                                }
                                                $items_sum += ($p + $opt_p) * intval($item['qty']);
                                            }
                                        }
                                        echo number_format($items_sum);
                                    ?></span>
                                </div>
                                <?php if ($saved_shipping_fee = (isset($shipping['fee']) ? intval($shipping['fee']) : 0)): ?>
                                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                                        <span>送料 (<?php echo esc_html($shipping['pref'] ?? ''); ?>):</span>
                                        <span>¥<?php echo number_format($saved_shipping_fee); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($saved_cod_fee = (isset($shipping['cod_fee']) ? intval($shipping['cod_fee']) : 0)): ?>
                                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                                        <span>代引き手数料:</span>
                                        <span>¥<?php echo number_format($saved_cod_fee); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($order->coupon_info)): ?>
                                    <?php $coupon_data = json_decode($order->coupon_info, true); ?>
                                    <?php if ($coupon_data): ?>
                                        <div style="display:flex; justify-content:space-between; margin-bottom:5px; color:#d63384;">
                                            <span>クーポン割引 (<?php echo esc_html($coupon_data['code']); ?>):</span>
                                            <span>-¥<?php echo number_format($coupon_data['applied_discount']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                </div>
                                <?php 
                                $coupon_applied = isset($coupon_data['applied_discount']) ? intval($coupon_data['applied_discount']) : 0;
                                $tax_results_edit = photo_purchase_get_tax_breakdown($items, $saved_shipping_fee, $saved_cod_fee, $coupon_applied);
                                if ($tax_results_edit): ?>
                                    <div style="margin-top:10px; padding-top:10px; border-top:1px dashed #eee; font-size:12px; color:#666;">
                                        <div style="font-weight:bold; margin-bottom:5px;">【消費税内訳】</div>
                                        <?php foreach ($tax_results_edit as $rate => $res): ?>
                                            <div style="display:flex; justify-content:space-between; margin-bottom:2px;">
                                                <span><?php echo $rate; ?>%対象 (税額):</span>
                                                <span>¥<?php echo number_format($res['target']); ?> (¥<?php echo number_format($res['tax']); ?>)</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php 
                                $reg_num = get_option('photo_pp_tokusho_registration_number');
                                if ($reg_num): ?>
                                    <div style="margin-top:10px; font-size:11px; color:#888; text-align:right;">
                                        登録番号: <?php echo esc_html($reg_num); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-primary" value="変更を保存">
                <a href="<?php echo remove_query_arg(array('action', 'order_id')); ?>" class="button">戻る</a>
            </p>
        </form>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('.photo-quickview-toggle').on('click', function() {
            var id = $(this).data('id');
            $('#order-detail-' + id).toggle();
            $(this).text($('#order-detail-' + id).is(':visible') ? '詳細を閉じる' : 'クイックビュー');
        });
    });
    </script>
    <?php
}

/**
 * Order Print View (Strict HTML for PDF/Print)
 */
function photo_purchase_order_print_view($order_id, $type, $order_token = '')
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $order_id));

    if (!$order) {
        wp_die('Order not found.');
    }

    // Security check: If not admin, token must match
    if (!current_user_can('manage_options')) {
        if (empty($order_token) || !hash_equals($order->order_token, $order_token)) {
            wp_die('Invalid access token.');
        }
    }

    $items = json_decode($order->order_items, true);
    $shipping = json_decode($order->shipping_info, true);
    $title = ($type === 'print_receipt') ? '領収書' : '納品書 (請求書)';
    $filename_pfx = ($type === 'print_receipt') ? 'receipt' : 'invoice';
    $site_name = get_bloginfo('name');
    ?>
    <!DOCTYPE html>
    <html lang="ja">

    <head>
        <meta charset="UTF-8">
        <title>
            <?php echo $title; ?>_<?php echo esc_html($order->order_token); ?>_<?php echo esc_html($order->buyer_name); ?>
        </title>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <style>
            @page {
                size: A4;
                margin: 0;
            }

            body {
                font-family: "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif;
                color: #333;
                line-height: 1.6;
                margin: 0;
                padding: 0;
                background: #f4f4f4;
                -webkit-print-color-adjust: exact;
            }

            .container {
                max-width: 800px;
                margin: 40px auto;
                background: #fff;
                padding: 40px;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
                min-height: 1000px;
                position: relative;
                box-sizing: border-box;
            }

            @media print {
                body {
                    background: #fff;
                }

                .container {
                    margin: 0;
                    padding: 20px;
                    width: 100%;
                    max-width: none;
                    box-shadow: none;
                    min-height: auto;
                }

                .no-print {
                    display: none;
                }
            }

            .header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 60px;
                border-bottom: 3px solid #333;
                padding-bottom: 20px;
            }

            h1 {
                font-size: 32px;
                margin: 0;
                letter-spacing: 0.2em;
            }

            .info-section {
                display: flex;
                justify-content: space-between;
                margin-bottom: 50px;
            }

            .buyer-info,
            .seller-info {
                width: 48%;
            }

            .table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 40px;
            }

            .table th,
            .table td {
                border-bottom: 1px solid #ddd;
                padding: 15px 10px;
                text-align: left;
            }

            .table th {
                background: #f8f8f8;
                font-weight: bold;
            }

            .total-row td {
                font-size: 1.2em;
                font-weight: bold;
                border-top: 2px solid #333;
                border-bottom: 2px solid #333;
                padding: 20px 10px;
            }

            .table tr {
                page-break-inside: avoid;
            }

            .footer {
                margin-top: 80px;
                text-align: center;
                font-size: 0.9em;
                color: #777;
                border-top: 1px dashed #ccc;
                padding-top: 20px;
            }

            .stamp-box {
                width: 80px;
                height: 80px;
                border: 1px solid #ddd;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 0.8em;
                color: #ccc;
                margin-left: auto;
                margin-top: 20px;
            }

            @media print {
                .no-print {
                    display: none !important;
                }

                body {
                    background: #fff;
                    padding: 0;
                }

                .container {
                    margin: 0;
                    padding: 10px;
                    box-shadow: none;
                    width: 100%;
                    max-width: none;
                }
            }
        <style>
            html, body {
                margin: 0;
                padding: 0;
                height: 100vh;
                width: 100vw;
            }
            #loading-screen {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: #f8fafc;
                z-index: 9999;
                font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            }
            .spinner {
                border: 4px solid #e2e8f0;
                border-top: 4px solid #0ea5e9;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                margin-bottom: 16px;
            }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            /* Original print container is hidden by the fixed loading screen overlay */
            #print-wrap {
                visibility: hidden;
            }
        </style>
    </head>

    <body>
        <div id="loading-screen">
            <div class="spinner"></div>
            <h2 style="color:#334155; font-size:18px; font-weight:bold; margin:0 0 8px;">PDFを生成しています...</h2>
            <p style="color:#64748b; font-size:14px; margin:0;">そのままお待ちください。</p>
        </div>
        
        <div id="print-wrap">
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const element = document.getElementById('print-container');
                
                const opt = {
                    margin: [10, 10, 10, 10],
                    filename: '<?php echo $filename_pfx; ?>_<?php echo esc_html($order->order_token); ?>.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, letterRendering: true },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
                };

                html2pdf().set(opt).from(element).outputPdf('bloburl').then((pdfUrl) => {
                    // Hide loading screen
                    document.getElementById('loading-screen').style.display = 'none';
                    // Remove the off-screen element to save memory and prevent double display
                    const wrap = document.getElementById('print-wrap');
                    if(wrap) wrap.remove();
                    
                    document.body.style.margin = '0';
                    document.body.style.overflow = 'hidden';
                    
                    // Directly navigate the top frame to the blob URL
                    // This mimics Welcart's behavior, allowing browser extensions (like Acrobat)
                    // to natively intercept and display the PDF.
                    window.location.replace(pdfUrl);
                }).catch(err => {
                    console.error('PDF generation error:', err);
                    document.getElementById('loading-screen').innerHTML = '<h3>PDFの生成に失敗しました。再読み込みしてください。</h3>';
                });
            });
        </script>

        <div id="print-container" class="container">
            <div class="header">
                <h1>
                    <?php 
                    if ($type !== 'print_receipt' && get_option('photo_pp_tokusho_registration_number')) {
                        echo '適格請求書 (納品書)';
                    } else {
                        echo $title;
                    }
                    ?>
                </h1>
                <div>
                    <div>発行日: <?php echo date('Y年m月d日'); ?></div>
                    <?php if ($type === 'print_receipt'): ?>
                        <div>領収番号: <?php echo esc_html($order->order_token); ?></div>
                    <?php else: ?>
                        <div>注文番号: <?php echo esc_html($order->order_token); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-section">
                <div class="buyer-info">
                    <div style="font-size: 1.2em; margin-bottom: 10px;"><strong><?php echo esc_html($order->buyer_name); ?>
                            様</strong></div>
                    <?php if (!empty($shipping['address'])): ?>
                        <div>〒<?php echo esc_html($shipping['zip']); ?></div>
                        <div><?php echo nl2br(esc_html($shipping['address'])); ?></div>
                    <?php endif; ?>
                </div>
                <div class="seller-info" style="text-align: right;">
                    <div style="font-size: 1.1em; font-weight: bold;">
                        <?php echo esc_html(get_option('photo_pp_tokusho_name', get_option('photo_pp_seller_name', get_bloginfo('name')))); ?>
                    </div>
                    <?php 
                    $seller_address = get_option('photo_pp_tokusho_address', '');
                    $seller_tel = get_option('photo_pp_tokusho_tel', '');
                    $seller_email = get_option('photo_pp_tokusho_email', get_option('photo_pp_seller_email', get_option('admin_email')));
                    if ($seller_address) echo '<div>住所: ' . esc_html($seller_address) . '</div>';
                    if ($seller_tel) echo '<div>電話: ' . esc_html($seller_tel) . '</div>';
                    if ($seller_email) echo '<div>Email: ' . esc_html($seller_email) . '</div>';
                    $reg_num = get_option('photo_pp_tokusho_registration_number', '');
                    if ($reg_num) echo '<div>登録番号: ' . esc_html($reg_num) . '</div>';
                    ?>
                </div>
            </div>

            <?php if ($type === 'print_receipt'): ?>
                <div style="font-size:1.5em; text-align:center; padding:20px; border:1px solid #333; margin-bottom:40px;">
                    金額： ￥<?php echo number_format($order->total_amount); ?> -
                </div>
            <?php endif; ?>

            <table class="table">
                <thead>
                    <tr>
                        <th>商品内容 / 形式</th>
                        <th style="text-align:right;">単価</th>
                        <th style="text-align:center;">数量</th>
                        <th style="text-align:right;">金額</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $tax_breakdown = array();
                    foreach ($items as $item):
                        $photo_id = intval($item['id']);
                        $format = sanitize_text_field($item['format']);
                        $qty = intval($item['qty']);
                        $rate = intval($item['tax_rate'] ?? 10);

                        // Use snapshot price if available, fallback to meta only if missing
                        $price = isset($item['price']) ? intval($item['price']) : -1;
                        if ($price === -1) {
                            $price_key = ($format === 'l_size') ? '_photo_price_l' : (($format === '2l_size') ? '_photo_price_2l' : '_photo_price_' . $format);
                            if ($format === 'digital') {
                                $price = get_post_meta($photo_id, '_photo_price_digital', true);
                                if (!$price)
                                    $price = get_post_meta($photo_id, '_photo_price', true);
                            } else {
                                $price = get_post_meta($photo_id, $price_key, true);
                            }
                        }
                        $opt_p = 0;
                        $opt_names = '';
                        if (!empty($item['options']) && is_array($item['options'])) {
                            foreach ($item['options'] as $opt) {
                                $opt_p += intval($opt['price'] ?? 0);
                                $g_pfx = (!empty($opt['group']) && !in_array($opt['group'], array('項目', 'オプション'))) ? $opt['group'] . ': ' : '';
                                $opt_names .= ' / ' . $g_pfx . $opt['name'];
                            }
                        }

                        $unit_price = intval($price) + $opt_p;
                        $subtotal = $unit_price * $qty;
                        
                        if (!isset($tax_breakdown[$rate])) $tax_breakdown[$rate] = 0;
                        $tax_breakdown[$rate] += $subtotal;
                        ?>
                        <tr>
                            <td>
                                <?php echo get_the_title($photo_id); ?> (<?php echo photo_purchase_get_format_label($format); ?>)
                                <?php if ($opt_names) echo '<br><small style="color:#666;">詳細: ' . esc_html(ltrim($opt_names, ' / ')) . '</small>'; ?>
                            </td>
                            <td style="text-align:right;">￥<?php echo number_format($unit_price); ?></td>
                            <td style="text-align:center;"><?php echo esc_html($qty); ?></td>
                            <td style="text-align:right;">￥<?php echo number_format($subtotal); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php
                    $shipping = json_decode($order->shipping_info, true);
                    $saved_shipping_fee = isset($shipping['fee']) ? intval($shipping['fee']) : 0;
                    $saved_cod_fee = isset($shipping['cod_fee']) ? intval($shipping['cod_fee']) : 0;

                    if ($saved_shipping_fee > 0): ?>
                        <tr>
                            <td>配送料<?php echo !empty($shipping['pref']) ? ' (' . esc_html($shipping['pref']) . ')' : ''; ?></td>
                            <td style="text-align:right;">￥<?php echo number_format($saved_shipping_fee); ?></td>
                            <td style="text-align:center;">1</td>
                            <td style="text-align:right;">￥<?php echo number_format($saved_shipping_fee); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ($saved_cod_fee > 0): ?>
                        <tr>
                            <td>代引き手数料</td>
                            <td style="text-align:right;">￥<?php echo number_format($saved_cod_fee); ?></td>
                            <td style="text-align:center;">1</td>
                            <td style="text-align:right;">￥<?php echo number_format($saved_cod_fee); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php 
                    // 会員割引の集計 (印刷ビュー用)
                    $print_member_discount = 0;
                    foreach ($items as $item) {
                        $orig_u = intval($item['price'] ?? 0) + intval($item['options_total'] ?? 0);
                        $final_u = intval($item['final_price'] ?? $orig_u);
                        $print_member_discount += ($orig_u - $final_u) * intval($item['qty'] ?? 1);
                    }
                    if ($print_member_discount > 0): ?>
                        <tr>
                            <td>会員特別割引</td>
                            <td style="text-align:right;">-￥<?php echo number_format($print_member_discount); ?></td>
                            <td style="text-align:center;">1</td>
                            <td style="text-align:right;">-￥<?php echo number_format($print_member_discount); ?></td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php if (!empty($order->coupon_info)): ?>
                        <?php $coupon_data = json_decode($order->coupon_info, true); ?>
                        <?php if ($coupon_data): ?>
                            <tr>
                                <td>クーポン割引 (<?php echo esc_html($coupon_data['code']); ?>)</td>
                                <td style="text-align:right;">-￥<?php echo number_format($coupon_data['applied_discount']); ?></td>
                                <td style="text-align:center;">1</td>
                                <td style="text-align:right;">-￥<?php echo number_format($coupon_data['applied_discount']); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="3" style="text-align:right;">合計金額 (税込)</td>
                        <td style="text-align:right;">￥<?php echo number_format($order->total_amount); ?></td>
                    </tr>
                    <?php
                    // Use helper for accurate tax breakdown (including coupon distribution)
                    $discount_val = 0;
                    if (!empty($order->coupon_info)) {
                        $c_info = json_decode($order->coupon_info, true);
                        $discount_val = intval($c_info['applied_discount'] ?? 0);
                    }
                    $tax_results_print = photo_purchase_get_tax_breakdown($items, $saved_shipping_fee, $saved_cod_fee, $discount_val);

                    foreach ($tax_results_print as $r => $res):
                    ?>
                    <tr>
                        <td colspan="3" style="text-align:right; border-top:none; padding: 5px 10px;">( <?php echo $r; ?>%対象額 ￥<?php echo number_format($res['target']); ?> )</td>
                        <td style="text-align:right; border-top:none; padding: 5px 10px;">( 内消費税額 ￥<?php echo number_format($res['tax']); ?> )</td>
                    </tr>
                    <?php endforeach; ?>
                </tfoot>
            </table>



            <div class="footer">
                <p>ご利用いただきありがとうございます。</p>
            </div>
        </div> <!-- /#print-container -->
        </div> <!-- /#print-wrap -->
    </body>

    </html>
    <?php
    exit;
}

/**
 * Handle print actions early to avoid admin-ajax or admin-page wrapper
 */
function photo_purchase_handle_print_actions()
{
    if (isset($_GET['page']) && $_GET['page'] === 'photo-purchase-orders' && isset($_GET['action'])) {
        if (!current_user_can('manage_options')) {
            wp_die(__('この操作を行う権限がありません。', 'photo-purchase'));
        }
        if (in_array($_GET['action'], array('print_receipt', 'print_delivery')) && isset($_GET['order_id'])) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'photo_print_order')) {
                wp_die(__('セキュリティエラー：リンクの期限が切れています。受注一覧からやり直してください。', 'photo-purchase'));
            }
            $order_id = intval($_GET['order_id']);
            global $wpdb;
            $order_token = $wpdb->get_var($wpdb->prepare("SELECT order_token FROM {$wpdb->prefix}photo_orders WHERE id = %d", $order_id));
            photo_purchase_order_print_view($order_id, $_GET['action'], $order_token);
        }
        if ($_GET['action'] === 'export_csv') {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'photo_export_csv')) {
                wp_die(__('セキュリティエラー：リンクの期限が切れています。受注一覧からやり直してください。', 'photo-purchase'));
            }
            photo_purchase_export_orders_csv();
        }
    }
}

/**
 * Handle Server-Side Temp PDF Upload to prevent WAF blocks (ERR_CONNECTION_RESET)
 * and AntiVirus false positives for Blob URLs.
 */
add_action('wp_ajax_photo_purchase_upload_temp_pdf', 'photo_purchase_upload_temp_pdf');
add_action('wp_ajax_nopriv_photo_purchase_upload_temp_pdf', 'photo_purchase_upload_temp_pdf');

function photo_purchase_upload_temp_pdf()
{
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'photo_pdf_upload_nonce')) {
        wp_send_json_error(['message' => 'セキュリティチェックに失敗しました。']);
    }

    if (empty($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(['message' => 'ファイルのアップロードに失敗しました。']);
    }

    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/simpleec_temp_pdf';
    
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
        // Protect directory from direct web access
        file_put_contents($temp_dir . '/.htaccess', 'Deny from all');
        file_put_contents($temp_dir . '/index.html', '');
    }

    $file_id = wp_generate_password(32, false);
    $temp_file = $temp_dir . '/' . $file_id . '.tmp';

    if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $temp_file)) {
        wp_send_json_error(['message' => 'サーバー内でのファイル保存に失敗しました。']);
    }

    $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : 'document.pdf';

    $download_url = add_query_arg([
        'action'   => 'photo_purchase_download_temp_pdf',
        'file_id'  => $file_id,
        'filename' => urlencode($filename),
        '_wpnonce' => wp_create_nonce('photo_pdf_download_temp_' . $file_id)
    ], admin_url('admin-post.php'));

    wp_send_json_success(['download_url' => $download_url]);
}

/**
 * Handle GET download for temp PDF file
 */
add_action('admin_post_photo_purchase_download_temp_pdf', 'photo_purchase_download_temp_pdf');
add_action('admin_post_nopriv_photo_purchase_download_temp_pdf', 'photo_purchase_download_temp_pdf');

function photo_purchase_download_temp_pdf()
{
    $file_id = isset($_GET['file_id']) ? sanitize_text_field($_GET['file_id']) : '';
    $filename = isset($_GET['filename']) ? sanitize_text_field(urldecode($_GET['filename'])) : 'document.pdf';
    
    if (empty($file_id) || !preg_match('/^[a-zA-Z0-9]+$/', $file_id)) {
        wp_die(__('不正なファイルIDです。', 'photo-purchase'));
    }
    
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'photo_pdf_download_temp_' . $file_id)) {
        wp_die(__('セキュリティチェックに失敗しました。', 'photo-purchase'));
    }

    if (empty($filename)) {
        $filename = 'document.pdf';
    }

    $upload_dir = wp_upload_dir();
    $temp_file = $upload_dir['basedir'] . '/simpleec_temp_pdf/' . $file_id . '.tmp';

    if (!file_exists($temp_file)) {
        wp_die(__('ファイルが見つかりません。すでにダウンロードされた可能性があります。', 'photo-purchase'));
    }

    $filesize = filesize($temp_file);

    // Clean output buffer to ensure pure PDF file download
    if (ob_get_length()) {
        ob_end_clean();
    }
    flush();

    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf'); // Force real PDF
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . $filesize);
    header('Connection: close');

    readfile($temp_file);
    unlink($temp_file); // Cleanup immediately after parsing
    exit;
}

/**
 * Generate and download orders CSV
 */
function photo_purchase_export_orders_csv()
{
	if (!current_user_can('manage_options')) {
		wp_die(__('この処理を実行する権限がありません。', 'photo-purchase'));
	}
	global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $orders = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

    $filename = 'orders_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Output UTF-8 BOM for Excel
    fwrite($output, "\xEF\xBB\xBF");

    // CSV Header
    fputcsv($output, array(
        '注文ID',
        '注文番号',
        '登録番号',
        '購入者名',
        'メールアドレス',
        '購入商品',
        '商品小計',
        '送料',
        '代引き手数料',
        'クーポンコード',
        'クーポン割引額',
        '合計金額',
        '合計消費税',
        '10%対象額（税込）',
        '10%消費税額',
        '8%対象額（税込）',
        '8%消費税額',
        '会員割引額',
        '支払い方法',
        'ステータス',
        '送り状番号',
        '注文日時',
        '郵便番号',
        '都道府県',
        '住所',
        '備考',
        'Stripe 顧客ID',
        'Stripe サブスクID'
    ));

    foreach ($orders as $order) {
        $items = json_decode($order->order_items, true);
        $shipping = json_decode($order->shipping_info, true);

        $item_details = array();
        $items_amount = 0;
        foreach ($items as $item) {
            $photo_id = intval($item['id']);
            $format = $item['format'];
            $qty = intval($item['qty']);

            // Get Price (Server Side Lookup for accuracy in CSV)
            $price_key = ($format === 'l_size') ? '_photo_price_l' : (($format === '2l_size') ? '_photo_price_2l' : '_photo_price_' . $format);
            $p = intval(get_post_meta($photo_id, $price_key, true) ?: get_post_meta($photo_id, '_photo_price', true));
            $opt_p = 0;
            $opt_names = array();

            if (!empty($item['options']) && is_array($item['options'])) {
                foreach ($item['options'] as $opt) {
                    $opt_p += intval($opt['price'] ?? 0);
                    $g_pfx = (!empty($opt['group']) && !in_array($opt['group'], array('項目', 'オプション'))) ? $opt['group'] . ': ' : '';
                    $opt_names[] = $g_pfx . $opt['name'];
                }
            }

            $line_total = ($p + $opt_p) * $qty;
            $items_amount += $line_total;

            $label = get_the_title($photo_id) . '(' . photo_purchase_get_format_label($format) . ' x ' . $qty . ')';
            if (!empty($opt_names)) {
                $label .= ' [' . implode(', ', $opt_names) . ']';
            }
            $item_details[] = $label;
        }

        // 会員割引の集計 (CSV用)
        $member_discount_val = 0;
        foreach ($items as $item) {
            $orig_u = intval($item['price'] ?? 0) + intval($item['options_total'] ?? 0);
            $final_u = intval($item['final_price'] ?? $orig_u);
            $member_discount_val += ($orig_u - $final_u) * intval($item['qty'] ?? 1);
        }

        $coupon_code = '';
        $coupon_discount = 0;
        if (!empty($order->coupon_info)) {
            $coupon_data = json_decode($order->coupon_info, true);
            if ($coupon_data) {
                $coupon_code = $coupon_data['code'] ?? '';
                $coupon_discount = $coupon_data['applied_discount'] ?? 0;
            }
        }

        $shipping_str = '';
        if (!empty($shipping['address'])) {
            $shipping_str = '〒' . $shipping['zip'] . ' ' . ($shipping['pref'] ?? '') . $shipping['address'];
        } else {
            $shipping_str = 'ダウンロードのみ';
        }

        $payment_lbl = '';
        if ($order->payment_method === 'bank_transfer') {
            $payment_lbl = '銀行振込';
        } elseif ($order->payment_method === 'cod') {
            $payment_lbl = '代引き';
        } elseif ($order->payment_method === 'paypay') {
            $payment_lbl = 'PayPay';
        } else {
            $payment_lbl = 'クレジットカード';
        }

        $has_shipping = false;
        if (!empty($order->order_items)) {
            $items_tmp = json_decode($order->order_items, true);
            if (is_array($items_tmp)) {
                foreach ($items_tmp as $it) {
                    if ($it['format'] !== 'digital' && $it['format'] !== 'subscription') {
                        $has_shipping = true;
                        break;
                    }
                    if ($it['format'] === 'subscription') {
                        if (get_post_meta($it['id'], '_photo_sub_requires_shipping', true) === '1') {
                            $has_shipping = true;
                            break;
                        }
                    }
                }
            }
        }

        $status_lbl = '';
        if ($order->status === 'pending_payment') {
            $status_lbl = '入金待ち';
        } elseif ($order->status === 'processing') {
            $status_lbl = $has_shipping ? '決済完了 / 発送準備中' : '決済完了 / サービス有効';
        } elseif ($order->status === 'completed') {
            $is_sub = !empty($order->stripe_subscription_id);
            if ($is_sub && $has_shipping) {
                $status_lbl = 'サブスク有効 / 配送中';
            } else {
                $status_lbl = $has_shipping ? '発送済み' : '完了';
            }
        }
 elseif ($order->status === 'cancelled') {
            $status_lbl = 'キャンセル';
        }

        $tax_results = photo_purchase_get_tax_breakdown($items_tmp, $shipping['fee'] ?? 0, $shipping['cod_fee'] ?? 0, $coupon_discount);
        $tax_total = 0;
        foreach ($tax_results as $tr) $tax_total += $tr['tax'];

        fputcsv($output, array(
            $order->id,
            $order->order_token,
            get_option('photo_pp_tokusho_registration_number', ''),
            $order->buyer_name,
            $order->buyer_email,
            implode(' / ', $item_details),
            $items_amount,
            $shipping['fee'] ?? 0,
            $shipping['cod_fee'] ?? 0,
            $coupon_code,
            $coupon_discount,
            $order->total_amount,
            $tax_total,
            $tax_results[10]['target'] ?? 0,
            $tax_results[10]['tax'] ?? 0,
            $tax_results[8]['target'] ?? 0,
            $tax_results[8]['tax'] ?? 0,
            $member_discount_val,
            $payment_lbl,
            $status_lbl,
            $order->tracking_number,
            $order->created_at,
            $shipping['zip'] ?? '',
            $shipping['pref'] ?? '',
            $shipping['address'] ?? '',
            $order->order_notes,
            $order->stripe_customer_id,
            $order->stripe_subscription_id
        ));
    }

    fclose($output);
    exit;
}

/**
 * Common helper to get readable labels for photo formats
 */
if (!function_exists('photo_purchase_get_format_label')) {
    function photo_purchase_get_format_label($format)
    {
        $labels = array(
            'digital' => 'ダウンロード',
            'l_size'  => '配送品',
            '2l_size' => '配送品(B)',
        );
        return $labels[$format] ?? $format;
    }
}
add_action('init', 'photo_purchase_handle_secure_download');

/**
 * Generate a secure, signed download URL for a specific photo in an order
 */
function photo_purchase_generate_download_url($order_token, $photo_id)
{
    $base_url = home_url('/');

    // Create a signature using the order token and photo ID
    $salt = defined('AUTH_KEY') ? AUTH_KEY : 'photo_purchase_salt';
    $signature = substr(hash_hmac('sha256', $order_token . '|' . $photo_id, $salt), 0, 16);

    // Pack into a single opaque key
    $raw_token = $order_token . '|' . $photo_id . '|' . $signature;
    $pdk = base64_encode($raw_token);
    // URL-safe base64
    $pdk = str_replace(array('+', '/', '='), array('-', '_', ''), $pdk);

    return add_query_arg('pdk', $pdk, $base_url);
}

/**
 * Secure Download URL Signature verification
 */
function photo_purchase_verify_download_sig($order_token, $photo_id, $signature)
{
    $salt = defined('AUTH_KEY') ? AUTH_KEY : 'photo_purchase_salt';
    $expected = substr(hash_hmac('sha256', $order_token . '|' . $photo_id, $salt), 0, 16);
    return hash_equals($expected, $signature);
}

/**
 * Get Email Footer with Seller Information
 */
function photo_purchase_get_email_footer($token = '', $email = '')
{
    $seller_name = get_option('photo_pp_tokusho_name', get_option('photo_pp_seller_name', get_bloginfo('name')));
    $seller_address = get_option('photo_pp_tokusho_address', '');
    $seller_tel = get_option('photo_pp_tokusho_tel', '');
    $seller_email = get_option('photo_pp_seller_email', get_option('admin_email'));

    $footer = "\n\n--------------------------------------------------\n";
    $footer .= $seller_name . "\n";
    $site_url = get_option('photo_pp_tokusho_url', home_url());
    if ($site_url) {
        $footer .= $site_url . "\n";
    }
    if (!empty($seller_address)) {
        $footer .= "住所: " . $seller_address . "\n";
    }
    if (!empty($seller_tel)) {
        $footer .= "電話: " . $seller_tel . "\n";
    }
    $footer .= "Email: " . $seller_email . "\n";
    
    $registration_number = get_option('photo_pp_tokusho_registration_number', '');
    if (!empty($registration_number)) {
        $footer .= "登録番号: " . $registration_number . "\n";
    }

    if ($token) {
        $footer .= "照会コード: " . $token . "\n";
    }

    $inquiry_page_id = get_option('photo_order_inquiry_page_id');
    if ($inquiry_page_id) {
        $inquiry_url = get_permalink($inquiry_page_id);
        if ($token && $email) {
            $quick_url = add_query_arg(array('pp_token' => $token, 'pp_email' => $email), $inquiry_url);
            $footer .= "【ご注文内容の確認・ダウンロード】\n" . $quick_url . "\n";
        } else {
            $footer .= "【注文照会ページ】\n" . $inquiry_url . "\n";
        }
    }

    $my_page_url = home_url('/my-account/');
    $footer .= "\n【マイページ（購入履歴一覧）】\n" . $my_page_url . "\n";

    $footer .= "--------------------------------------------------\n";

    return $footer;
}

/**
 * Handle Secure Download Request
 */
function photo_purchase_handle_secure_download()
{
    if (!isset($_GET['pdk'])) {
        return;
    }

    $pdk = sanitize_text_field($_GET['pdk']);

    // Decode URL-safe base64
    $raw_token_encoded = str_replace(array('-', '_'), array('+', '/'), $pdk);
    $raw_token = base64_decode($raw_token_encoded);

    if (!$raw_token) {
        wp_die('無効なトークンです。');
    }

    $parts = explode('|', $raw_token);
    if (count($parts) !== 3) {
        wp_die('トークン形式が正しくありません。');
    }

    list($order_token, $photo_id, $signature) = $parts;

    // Verify Signature
    if (!photo_purchase_verify_download_sig($order_token, $photo_id, $signature)) {
        wp_die('不正なアクセスです。署名が一致しません。');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE order_token = %s", $order_token));

    if (!$order) {
        wp_die('注文が見つかりません。');
    }

    // Verify order status
    if (!in_array($order->status, array('processing', 'completed'))) {
        wp_die('この注文はまだダウンロード可能な状態ではありません。');
    }

    // Verify photo is in the order
    $items = json_decode($order->order_items, true);
    $found = false;
    foreach ($items as $item) {
        if (intval($item['id']) === intval($photo_id) && $item['format'] === 'digital') {
            $found = true;
            break;
        }
    }

    if (!$found) {
        wp_die('この写真をダウンロードする権限がありません。');
    }

    // FEATURE: Enforce expiry and count limits before serving (Default: 7 days, 5 downloads)
    if (function_exists('photo_purchase_check_download_limits')) {
        photo_purchase_check_download_limits($order_token, $photo_id);
    }
    // Get the high-res file
    $file_id = get_post_meta($photo_id, '_photo_high_res_id', true);
    $file_path = '';

    if ($file_id) {
        $file_path = get_attached_file($file_id);
    } else {
        // Fallback to URL if ID is missing (for older items)
        $file_url = get_post_meta($photo_id, '_photo_high_res_file', true);
        if ($file_url) {
            // Try to find the file path from URL
            $upload_dir = wp_upload_dir();
            $base_url = $upload_dir['baseurl'];
            if (strpos($file_url, $base_url) !== false) {
                $file_path = str_replace($base_url, $upload_dir['basedir'], $file_url);
            }
        }
    }

    if (empty($file_path) || !file_exists($file_path)) {
        wp_die('ファイルが見つかりません。管理者に連絡してください。');
    }

    // Serve the file
    $file_name = basename($file_path);
    $file_size = filesize($file_path);
    $file_info = wp_check_filetype($file_name);

    // Anonymize filename: photo-[order_token]-[photo_id].ext
    $ext = !empty($file_info['ext']) ? $file_info['ext'] : 'jpg';
    $new_file_name = sprintf('photo-%s-%d.%s', $order_token, $photo_id, $ext);

    header('Content-Description: File Transfer');
    header('Content-Type: ' . ($file_info['type'] ? $file_info['type'] : 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . $new_file_name . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . $file_size);

    readfile($file_path);

    // FEATURE: Log this download
    if (function_exists('photo_purchase_log_download')) {
        photo_purchase_log_download($order_token, $photo_id);
    }
    exit;
}

/* =============================================================================
 * FEATURE 3: Resend Buyer Confirmation Email
 * ============================================================================= */
function photo_purchase_handle_resend_email()
{
    if (!current_user_can('manage_options'))
        wp_die('権限がありません。');
    check_admin_referer('photo_resend_email');

    $order_id = intval($_GET['order_id'] ?? 0);
    if (!$order_id)
        wp_die('注文IDが指定されていません。');

    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $order_id));
    if (!$order)
        wp_die('注文が見つかりません。');

    $order_data = array(
        'items' => json_decode($order->order_items, true),
        'buyer' => array('name' => $order->buyer_name, 'email' => $order->buyer_email),
        'shipping' => json_decode($order->shipping_info, true),
        'method' => $order->payment_method,
    );

    if (function_exists('photo_purchase_send_buyer_notification')) {
        photo_purchase_send_buyer_notification($order->order_token, $order_data, $order->total_amount);
    }

    wp_safe_redirect(add_query_arg(array('page' => 'photo-purchase-orders', 'pp_notice' => 'resent'), admin_url('edit.php?post_type=photo_product')));
    exit;
}
add_action('admin_action_photo_resend_email', 'photo_purchase_handle_resend_email');


/* =============================================================================
 * FEATURE 5: Download Expiry + Count Limit
 * ============================================================================= */

/**
 * Check download is within time and count limits. Called before serving file.
 * Returns true if OK, otherwise calls wp_die().
 */
function photo_purchase_check_download_limits($order_token, $photo_id)
{
    $download_limit = intval(get_option('photo_pp_download_limit', 5));
    $download_expiry = intval(get_option('photo_pp_download_expiry', 7));

    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $log_table = $wpdb->prefix . 'photo_download_log';

    // Create log table if missing
    if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") !== $log_table) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $log_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_token VARCHAR(64) NOT NULL,
            photo_id BIGINT UNSIGNED NOT NULL,
            downloaded_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY order_photo (order_token, photo_id)
        ) " . $wpdb->get_charset_collate() . ";");
    }

    // Check expiry
    if ($download_expiry > 0) {
        $created = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM $table_name WHERE order_token = %s", $order_token));
        if ($created && time() > strtotime($created) + ($download_expiry * DAY_IN_SECONDS)) {
            wp_die('このダウンロードリンクの有効期限（' . $download_expiry . '日間）が切れています。販売者にお問い合わせください。');
        }
    }

    // Check count
    if ($download_limit > 0) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $log_table WHERE order_token = %s AND photo_id = %d",
            $order_token,
            intval($photo_id)
        ));
        if ($count >= $download_limit) {
            wp_die('このファイルのダウンロード上限（' . $download_limit . '回）に達しました。', 'ダウンロード制限', array('response' => 403));
        }
    }
    return true;
}

/** Record a completed download in the log table */
function photo_purchase_log_download($order_token, $photo_id)
{
    global $wpdb;
    $log_table = $wpdb->prefix . 'photo_download_log';
    // Table may not exist yet on very first download — check
    if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") === $log_table) {
        $wpdb->insert($log_table, array(
            'order_token' => $order_token,
            'photo_id' => intval($photo_id),
            'downloaded_at' => current_time('mysql'),
        ));
    }
}

/* =============================================================================
 * FEATURE 6: Order Cancellation
 * ============================================================================= */

/**
 * (Empty space for removal of old cancel notification)
 */

/**
 * Helper to add admin notice on orders page
 */
add_action('admin_notices', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'photo-purchase-orders')
        return;
    if (isset($_GET['pp_notice'])) {
        $notices = array(
            'resent' => 'メールを再送しました。',
            'cancelled' => '注文をキャンセルし、購入者へメールを送信しました。',
        );
        $msg = $notices[sanitize_key($_GET['pp_notice'])] ?? '';
        if ($msg) {
            echo '<div class="updated notice is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }
});

/* =============================================================================
 * 注文照会ショートコード [photo_order_inquiry]
 * 使い方: WordPressの任意のページに [photo_order_inquiry] を貼るだけ
 * ============================================================================= */
function photo_purchase_order_inquiry_shortcode()
{
    ob_start();
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';

    $order = null;
    $error = '';
    $searched = false;

    // フォーム送信または GET パラメータによる照会
    $input_token = '';
    $input_email = '';

    if (isset($_POST['pp_inquiry_token'], $_POST['pp_inquiry_email'], $_POST['pp_inquiry_nonce'])) {
        if (!wp_verify_nonce($_POST['pp_inquiry_nonce'], 'pp_order_inquiry')) {
            $error = 'セキュリティエラーが発生しました。再度お試しください。';
        } else {
            $input_token = sanitize_text_field($_POST['pp_inquiry_token']);
            $input_email = sanitize_email($_POST['pp_inquiry_email']);
        }
    } elseif (isset($_GET['pp_token'], $_GET['pp_email'])) {
        $input_token = sanitize_text_field($_GET['pp_token']);
        $input_email = sanitize_email($_GET['pp_email']);
    }

    if ($input_token && $input_email) {
        $searched = true;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_token = %s AND buyer_email = %s",
            $input_token,
            $input_email
        ));
        if (!$order) {
            $error = '注文が見つかりませんでした。注文番号とメールアドレスをご確認ください。';
        }
    }

    // ステータスラベル
    $status_labels = array(
        'pending_payment' => array('label' => '入金待ち', 'color' => '#d98c00', 'bg' => '#fff9e6'),
        'processing' => array('label' => '決済完了 / 発送待ち', 'color' => '#1a7a2e', 'bg' => '#eaffea'),
        'active' => array('label' => '✅ サブスク有効', 'color' => '#22c55e', 'bg' => '#f0fdf4'),
        'service_active' => array('label' => '🟣 サブスク有効 / サービス中', 'color' => '#7c3aed', 'bg' => '#f5f3ff'),
        'sub_shipping' => array('label' => '🔄 サブスク有効 / 配送中', 'color' => '#4f46e5', 'bg' => '#eef2ff'),
        'completed' => array('label' => (!empty($order->stripe_subscription_id) ? '継続中' : '発送済み'), 'color' => '#555', 'bg' => '#eee'),
        'cancelled' => array('label' => 'キャンセル', 'color' => '#a00', 'bg' => '#fde'),
    );
    $method_labels = array(
        'stripe' => 'クレジットカード',
        'paypay' => 'PayPay',
        'bank_transfer' => '銀行振込',
        'cod' => '代金引換',
    );

    ?>
    <div style="max-width:640px; margin:0 auto; font-family:sans-serif;">

        <?php if (!$order): ?>
            <!-- 照会フォーム -->
            <div style="background:#f9f9f9; border:1px solid #ddd; border-radius:12px; padding:32px;">
                <h3 style="margin-top:0;">📦 注文照会</h3>
                <p style="color:#666; font-size:14px;">注文番号（確認メールに記載）とご注文時のメールアドレスを入力してください。</p>

                <?php if ($error): ?>
                    <div
                        style="background:#fde; border-left:4px solid #a00; padding:12px 16px; border-radius:6px; margin-bottom:16px; color:#a00;">
                        <?php echo esc_html($error); ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field('pp_order_inquiry', 'pp_inquiry_nonce'); ?>
                    <p>
                        <label style="display:block; font-weight:bold; margin-bottom:6px;">注文番号 <span
                                style="color:red;">*</span></label>
                        <input type="text" name="pp_inquiry_token" required placeholder="例: 260310-ABCD"
                            value="<?php echo esc_attr($_POST['pp_inquiry_token'] ?? ''); ?>"
                            style="width:100%; padding:10px 14px; border:1px solid #ccc; border-radius:8px; font-size:15px; box-sizing:border-box;">
                    </p>
                    <p>
                        <label style="display:block; font-weight:bold; margin-bottom:6px;">メールアドレス <span
                                style="color:red;">*</span></label>
                        <input type="email" name="pp_inquiry_email" required placeholder="ご注文時のメールアドレス"
                            value="<?php echo esc_attr($_POST['pp_inquiry_email'] ?? ''); ?>"
                            style="width:100%; padding:10px 14px; border:1px solid #ccc; border-radius:8px; font-size:15px; box-sizing:border-box;">
                    </p>
                    <button type="submit"
                        style="background:#333; color:#fff; border:none; padding:12px 28px; border-radius:8px; font-size:15px; cursor:pointer; width:100%;">
                        照会する
                    </button>
                </form>
            </div>

        <?php else: ?>
            <!-- 注文詳細 -->
            <?php
            $items = json_decode($order->order_items, true);
            $shipping = json_decode($order->shipping_info, true);

            $has_shipping = false;
            if (is_array($items)) {
                foreach ($items as $it) {
                    if ($it['format'] !== 'digital' && $it['format'] !== 'subscription') {
                        $has_shipping = true;
                        break;
                    }
                    if ($it['format'] === 'subscription') {
                        if (get_post_meta($it['id'], '_photo_sub_requires_shipping', true) === '1') {
                            $has_shipping = true;
                            break;
                        }
                    }
                }
            }

            $status_info = $status_labels[$order->status] ?? array('label' => $order->status, 'color' => '#333', 'bg' => '#eee');
            if (!$has_shipping) {
                if ($order->status === 'processing') {
                    $status_info['label'] = '決済完了 / サービス有効';
                } elseif ($order->status === 'completed') {
                    $status_info['label'] = '完了';
                }
            }
            ?>
            <div style="background:#f9f9f9; border:1px solid #ddd; border-radius:12px; padding:32px;">
                <h3 style="margin-top:0;">📦 注文詳細</h3>

                <!-- ステータスバッジ -->
                <div style="margin-bottom:20px;">
                    <span
                        style="background:<?php echo esc_attr($status_info['bg']); ?>; color:<?php echo esc_attr($status_info['color']); ?>; padding:6px 16px; border-radius:20px; font-weight:bold; font-size:15px;">
                        <?php echo esc_html($status_info['label']); ?>
                    </span>
                </div>

                <table style="width:100%; border-collapse:collapse; font-size:14px;">
                    <tr style="border-bottom:1px solid #eee;">
                        <th style="text-align:left; padding:10px 0; color:#888; width:140px;">注文番号</th>
                        <td style="padding:10px 0;"><?php echo esc_html($order->order_token); ?></td>
                    </tr>
                    <tr style="border-bottom:1px solid #eee;">
                        <th style="text-align:left; padding:10px 0; color:#888;">注文日</th>
                        <td style="padding:10px 0;"><?php echo esc_html($order->created_at); ?></td>
                    </tr>
                    <tr style="border-bottom:1px solid #eee;">
                        <th style="text-align:left; padding:10px 0; color:#888;">お支払方法</th>
                        <td style="padding:10px 0;">
                            <?php echo esc_html($method_labels[$order->payment_method] ?? $order->payment_method); ?></td>
                    </tr>
                    <tr style="border-bottom:1px solid #eee;">
                        <th style="text-align:left; padding:10px 0; color:#888;">合計金額</th>
                        <td style="padding:10px 0;"><strong>¥<?php echo number_format(intval($order->total_amount)); ?></strong>
                            <div style="font-size:12px; color:#777; font-weight:normal; margin-top:4px;">
                                <?php
                                $shipping_fee = isset($shipping['fee']) ? intval($shipping['fee']) : 0;
                                $cod_fee = isset($shipping['cod_fee']) ? intval($shipping['cod_fee']) : 0;
                                
                                $coupon_discount = 0;
                                $coupon_code = '';
                                if (!empty($order->coupon_info)) {
                                    $coupon_data = json_decode($order->coupon_info, true);
                                    if ($coupon_data) {
                                        $coupon_discount = intval($coupon_data['applied_discount'] ?? 0);
                                        $coupon_code = $coupon_data['code'] ?? '';
                                    }
                                }

                                $items_amount = intval($order->total_amount) - $shipping_fee - $cod_fee + $coupon_discount;
                                
                                // 会員割引額の算出
                                $member_discount_inquiry = 0;
                                foreach ($items as $item) {
                                    $orig_u = intval($item['price'] ?? 0) + intval($item['options_total'] ?? 0);
                                    $final_u = intval($item['final_price'] ?? $orig_u);
                                    $member_discount_inquiry += ($orig_u - $final_u) * intval($item['qty'] ?? 1);
                                }
                                $orig_items_total = $items_amount + $member_discount_inquiry;

                                // Tax Breakdown using common helper
                                $tax_results = photo_purchase_get_tax_breakdown($items, $shipping_fee, $cod_fee, $coupon_discount);

                                echo '【内訳】<br>';
                                echo '商品: ¥' . number_format($orig_items_total);
                                if ($member_discount_inquiry > 0) {
                                    echo ' - 会員割引: ¥' . number_format($member_discount_inquiry);
                                }
                                if ($shipping_fee > 0) echo ' + 送料: ¥' . number_format($shipping_fee);
                                if ($cod_fee > 0) echo ' + 代引き手数料: ¥' . number_format($cod_fee);
                                if ($coupon_discount > 0) {
                                    echo ' - 割引' . ($coupon_code ? ' (' . esc_html($coupon_code) . ')' : '') . ': ¥' . number_format($coupon_discount);
                                }
                                
                                echo '<div style="margin-top:8px; border-top:1px dashed #ddd; padding-top:8px;">';
                                foreach ($tax_results as $rate => $data) {
                                    if ($data['target'] > 0) {
                                        echo '・' . $rate . '%対象: ¥' . number_format($data['target']) . ' (内税 ¥' . number_format($data['tax']) . ')<br>';
                                    }
                                }
                                echo '</div>';
                                ?>
                            </div>
                        </td>
                    </tr>
                    <?php if (!empty($order->tracking_number)): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <th style="text-align:left; padding:10px 0; color:#888;">送り状番号</th>
                            <td style="padding:10px 0; font-weight:bold;">
                                <?php echo esc_html($order->tracking_number); ?>
                                <?php 
                                $carrier_name = !empty($order->carrier) ? photo_purchase_get_carrier_label($order->carrier) : '';
                                if ($carrier_name) echo ' (' . esc_html($carrier_name) . ')';
                                
                                $track_url = !empty($order->carrier) ? photo_purchase_get_carrier_url($order->carrier, $order->tracking_number) : '';
                                if ($track_url): ?>
                                    <div style="margin-top:5px;">
                                        <a href="<?php echo esc_url($track_url); ?>" target="_blank" style="font-size:13px; color:var(--pp-primary); text-decoration:none;">🔍 配送状況を確認する</a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>
                
                <?php if (!empty($order->stripe_subscription_id)): ?>
                    <div style="margin-top:24px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:12px; padding:24px;">
                        <h4 style="margin:0 0 8px 0; color:#4338ca;">💳 サブスクリプション管理</h4>
                        <p style="margin:0 0 16px 0; font-size:13px; color:#6366f1;">お支払い方法の変更や、キャンセルの手続きはStripeのカスタマーポータルから行えます。</p>
                        <?php
                        $portal_url = function_exists('photo_purchase_create_portal_session') ? photo_purchase_create_portal_session($order->stripe_customer_id ?: '') : false;
                        if ($portal_url): ?>
                            <a href="<?php echo esc_url($portal_url); ?>" target="_blank" style="display:inline-block; background:#4338ca; color:#fff; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:bold; font-size:14px;">
                                管理ポータルを開く
                            </a>
                        <?php else: ?>
                            <p style="color:#e11d48; font-size:13px; margin:0;">管理ポータルへの接続に失敗しました。管理者にお問い合わせください。</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- 購入商品一覧 -->
                <h4 style="margin:24px 0 12px;">ご購入商品</h4>
                <div style="border:1px solid #eee; border-radius:8px; overflow:hidden;">
                    <?php
                    if (is_array($items)):
                        foreach ($items as $i => $item):
                            $photo_id = intval($item['id']);
                            $format = $item['format'] ?? '';
                            $qty = intval($item['qty'] ?? 1);
                            $title = get_the_title($photo_id) ?: '写真 #' . $photo_id;
                            $fmt_lbl = photo_purchase_get_format_label($format);
                            $bg = ($i % 2 === 0) ? '#fff' : '#f9f9f9';

                            // デジタル商品のダウンロードURL生成
                            $dl_url = '';
                            if ($format === 'digital' && $order->status === 'processing' || $format === 'digital' && $order->status === 'completed') {
                                if (function_exists('photo_purchase_generate_download_url')) {
                                    $dl_url = photo_purchase_generate_download_url($order->order_token, $photo_id);
                                }
                            }
                            ?>
                            <div style="display:flex; align-items:center; padding:12px 16px; background:<?php echo $bg; ?>; gap:12px;">
                                <div style="flex:1;">
                                    <div style="font-weight:bold;"><?php echo esc_html($title); ?></div>
                                    <?php if (!empty($item['variation_name'])): ?>
                                        <div style="color:var(--pp-accent); font-size:12px; margin-top:2px;"><?php echo esc_html($item['variation_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['options']) && is_array($item['options'])): 
                                        foreach ($item['options'] as $opt): ?>
                                            <div style="color:#666; font-size:11px; margin-top:1px;">+ <?php echo esc_html($opt['name']); ?></div>
                                        <?php endforeach;
                                    endif; ?>
                                    <div style="color:#888; font-size:13px; margin-top:4px;"><?php echo esc_html($fmt_lbl); ?> × <?php echo $qty; ?>
                                    </div>
                                </div>
                                <?php if ($dl_url): ?>
                                    <a class="button button-primary" href="<?php echo esc_url($dl_url); ?>" style="display:inline-block; margin-top:5px; padding: 4px 12px; font-size: 13px;">
                                        ⬇ ダウンロード
                                    </a>
                                <?php endif; ?>
                            </div>
                            <?php
                        endforeach;
                    endif;
                    ?>
                </div>

                <div style="margin-top:32px; padding-top:20px; border-top:1px solid #eee; display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                    <a href="<?php echo esc_url(get_permalink()); ?>" style="color:#666; font-size:13px; text-decoration:none;">← 別の注文を照会する</a>
                    <div style="flex:1;"></div>
                    <a href="<?php echo esc_url(add_query_arg(array('photo_purchase_action' => 'print_doc', 'order_id' => $order->id, 'type' => 'print_invoice', 'order_token' => $order->order_token), home_url('/'))); ?>" target="_blank" style="display:inline-block; padding:10px 20px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; text-decoration:none; color:#495057; font-weight:bold; font-size:14px;">
                        📄 納品書（請求書）
                    </a>
                    <?php if ($order->status !== 'pending_payment' && $order->status !== 'cancelled'): ?>
                        <a href="<?php echo esc_url(add_query_arg(array('photo_purchase_action' => 'print_doc', 'order_id' => $order->id, 'type' => 'print_receipt', 'order_token' => $order->order_token), home_url('/'))); ?>" target="_blank" style="display:inline-block; padding:10px 20px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; text-decoration:none; color:#495057; font-weight:bold; font-size:14px;">
                            💰 領収書を発行
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ec_order_inquiry', 'photo_purchase_order_inquiry_shortcode');


/**
 * Helper: Securely hash email for transients
 */
function photo_purchase_hash_email($email) {
    return md5(strtolower(trim($email)));
}

/**
 * Handle Auth Request (Step 1: Send Code)
 */
function photo_purchase_handle_auth_request() {
    if (!isset($_POST['pp_auth_email']) || !isset($_POST['pp_auth_nonce'])) return;
    if (!wp_verify_nonce($_POST['pp_auth_nonce'], 'pp_auth_request')) {
        return 'セキュリティエラーが発生しました。';
    }

    $email = sanitize_email($_POST['pp_auth_email']);
    if (!is_email($email)) return '有効なメールアドレスを入力してください。';

    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE buyer_email = %s", $email));

    if (!$order_exists) {
        return 'そのメールアドレスでの注文履歴が見つかりませんでした。';
    }

    // Generate 6-digit code
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $email_hash = photo_purchase_hash_email($email);
    
    // Store in transient for 15 minutes
    set_transient('photo_pp_auth_' . $email_hash, $code, 15 * MINUTE_IN_SECONDS);

    // Send Email
    $seller_name = get_option('photo_pp_seller_name', get_bloginfo('name'));
    $seller_email = get_option('photo_pp_seller_email', get_option('admin_email'));

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $seller_name . ' <' . $seller_email . '>'
    );

    $subject = '【認証コード】マイページログイン - ' . $seller_name;
    $message = esc_html($email) . " 様\n\n";
    $message .= "マイページへのログイン用認証コードをお送りいたします。\n";
    $message .= "以下のコードを画面に入力してください：\n\n";
    $message .= "--------------------------------------------------\n";
    $message .= "認証コード: " . $code . "\n";
    $message .= "--------------------------------------------------\n\n";
    $message .= "※このコードの有効期限は15分間です。\n";
    $message .= photo_purchase_get_email_footer('', $email);

    wp_mail($email, $subject, $message, $headers);

    // Store email in session to know who we're waiting for (Step 2)
    $_SESSION['pp_auth_pending_email'] = $email;
    return true;
}

/**
 * Handle Auth Verify (Step 2: Check Code)
 */
function photo_purchase_handle_auth_verify() {
    if (!isset($_POST['pp_auth_code']) || !isset($_POST['pp_auth_nonce_verify'])) return;
    if (!wp_verify_nonce($_POST['pp_auth_nonce_verify'], 'pp_auth_verify')) {
        return 'セキュリティエラーが発生しました。';
    }

    $email = $_SESSION['pp_auth_pending_email'] ?? '';
    if (!$email) return 'セッションがタイムアウトしました。最初からやり直してください。';

    $input_code = sanitize_text_field($_POST['pp_auth_code']);
    $email_hash = photo_purchase_hash_email($email);
    $stored_code = get_transient('photo_pp_auth_' . $email_hash);

    if (!$stored_code || $input_code !== $stored_code) {
        return '認証コードが正しくないか、有効期限が切れています。';
    }

    // Success: Create Session
    delete_transient('photo_pp_auth_' . $email_hash);
    unset($_SESSION['pp_auth_pending_email']);

    $token = bin2hex(random_bytes(32));
    set_transient('photo_pp_session_' . $token, $email, 24 * HOUR_IN_SECONDS);

    // Set cookie for 24 hours
    setcookie('photo_pp_auth_token', $token, time() + (24 * HOUR_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    
    // Redirect to clear POST
    wp_safe_redirect(get_permalink());
    exit;
}

/**
 * Handle Logout
 */
function photo_purchase_handle_auth_logout() {
    if (isset($_GET['pp_logout'])) {
        $token = $_COOKIE['photo_pp_auth_token'] ?? '';
        if ($token) {
            delete_transient('photo_pp_session_' . $token);
            setcookie('photo_pp_auth_token', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }
        wp_safe_redirect(get_permalink());
        exit;
    }
}
add_action('template_redirect', 'photo_purchase_handle_auth_logout');

/**
 * Verify Session and Get Current User Email
 */
function photo_purchase_get_auth_email() {
    if (is_user_logged_in()) {
        return wp_get_current_user()->user_email;
    }
    
    $token = $_COOKIE['photo_pp_auth_token'] ?? '';
    if (!$token) return false;

    $email = get_transient('photo_pp_session_' . $token);
    return $email ?: false;
}

/**
 * Handle Member Profile Update
 */
function photo_purchase_handle_profile_update() {
    if (!isset($_POST['profile_nonce']) || !wp_verify_nonce($_POST['profile_nonce'], 'photo_purchase_update_profile')) {
        wp_die('セキュリティエラーが発生しました。');
    }
    if (!is_user_logged_in()) {
        wp_die('ログインが必要です。');
    }

    $user_id = get_current_user_id();
    
    // Update Display Name
    if (!empty($_POST['display_name'])) {
        wp_update_user(array(
            'ID'           => $user_id,
            'display_name' => sanitize_text_field($_POST['display_name'])
        ));
    }

    update_user_meta($user_id, 'billing_phone', sanitize_text_field($_POST['billing_phone']));
    update_user_meta($user_id, 'billing_postcode', sanitize_text_field($_POST['billing_postcode']));
    update_user_meta($user_id, 'billing_state', sanitize_text_field($_POST['billing_state']));
    update_user_meta($user_id, 'billing_address_1', sanitize_text_field($_POST['billing_address_1']));
    update_user_meta($user_id, 'billing_address_2', ''); // Keep simple

    wp_safe_redirect(add_query_arg('profile_updated', '1', wp_get_referer()));
    exit;
}
add_action('admin_post_photo_purchase_update_profile', 'photo_purchase_handle_profile_update');

/**
 * 統合版マイページ: 履歴確認およびサブスクリプション管理機能を提供。
 * WordPressログインユーザーと、メール認証によるゲスト購入者の両方に対応。
 * リッチな UI、サブスクリプション管理、詳細な注文履歴を提供。
 */
function photo_purchase_member_dashboard_shortcode($atts)
{
    // セッション開始
    if (!session_id()) session_start();

    $auth_email = photo_purchase_get_auth_email();
    $auth_error = '';

    // 認証アクションの処理 (ゲスト用)
    if (isset($_POST['pp_auth_email'])) {
        $res = photo_purchase_handle_auth_request();
        if ($res !== true) $auth_error = $res;
    } elseif (isset($_POST['pp_auth_code'])) {
        $res = photo_purchase_handle_auth_verify();
        if ($res !== true) $auth_error = $res;
    }

    ob_start();
    ?>
    <div class="photo-purchase-portal" style="max-width:850px; margin:0 auto; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;">

    <?php if (!$auth_email): ?>
        <!-- ログインフォーム -->
        <div style="background:#fcfcfd; border:1px solid #e2e8f0; border-radius:24px; padding:60px 40px; text-align:center; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);">
            <div style="background:#eef2ff; width:64px; height:64px; border-radius:20px; display:flex; align-items:center; justify-content:center; margin:0 auto 24px;">
                <svg viewBox="0 0 24 24" width="32" height="32" stroke="#4f46e5" stroke-width="2" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            </div>
            <h2 style="margin:0 0 12px; color:#1e293b; font-size:28px; font-weight:800;">マイページログイン</h2>
            <p style="color:#64748b; margin-bottom:32px; font-size:16px;">ご注文履歴の確認、サブスクリプションの管理が可能です。</p>
            
            <?php if ($auth_error): ?>
                <div style="background:#fff1f2; border:1px solid #fda4af; border-radius:12px; padding:16px; margin-bottom:24px; color:#9f1239; text-align:left; font-size:14px; display:flex; gap:12px; align-items:center;">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php echo esc_html($auth_error); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['pp_auth_pending_email'])): ?>
                <!-- ステップ 2: 認証コードの確認 -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px; padding:24px; margin-bottom:24px;">
                    <p style="color:#475569; margin:0 0 16px; font-size:15px;">
                        <strong><?php echo esc_html($_SESSION['pp_auth_pending_email']); ?></strong> 宛てに送信された<br>6桁のコードを入力してください。
                    </p>
                    <form method="post">
                        <?php wp_nonce_field('pp_auth_verify', 'pp_auth_nonce_verify'); ?>
                        <div style="margin-bottom:20px;">
                            <input type="text" name="pp_auth_code" placeholder="000000" required maxlength="6"
                                   style="padding:15px; border-radius:12px; border:2px solid #cbd5e1; width:100%; max-width:240px; text-align:center; font-size:32px; letter-spacing:10px; font-weight:800; color:#1e293b; outline:none; transition:border-color 0.2s;">
                        </div>
                        <button type="submit" style="background:#4f46e5; color:#fff; padding:16px 48px; border:none; border-radius:14px; cursor:pointer; font-weight:bold; font-size:16px; width:100%; max-width:240px; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.4);">
                            ログインする
                        </button>
                    </form>
                    <div style="margin-top:20px;">
                        <a href="<?php echo add_query_arg('reset_auth', '1'); ?>" style="color:#6366f1; font-size:14px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                            メールアドレスを修正する
                        </a>
                    </div>
                </div>
                <?php if (isset($_GET['reset_auth'])) { unset($_SESSION['pp_auth_pending_email']); wp_safe_redirect(get_permalink()); exit; } ?>
            <?php else: ?>
                <!-- ログイン方法の提供 -->
                <div style="max-width:440px; margin:0 auto;">
                    <?php 
                    // 1. SNSログインを上に配置
                    $sns_config = photo_purchase_get_sns_config();
                    $has_sns_setup = !empty($sns_config['google']['client_id']) || !empty($sns_config['line']['client_id']);
                    
                    if ($has_sns_setup || current_user_can('manage_options')): ?>
                        <div style="margin-bottom:32px;">
                            <?php echo photo_purchase_render_sns_login_buttons(); ?>
                        </div>
                    <?php endif; ?>

                    <!-- 2. メールアドレス入力を下に配置 -->
                    <div style="border-top: 1px dashed #e2e8f0; padding-top: 32px;">
                        <p style="color:#64748b; margin-bottom:20px; font-size:15px;"><?php _e('メールアドレスで認証（過去にご利用のある方向け）', 'photo-purchase'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('pp_auth_request', 'pp_auth_nonce'); ?>
                            <div style="margin-bottom:16px; text-align:left;">
                                <input type="email" name="pp_auth_email" placeholder="メールアドレス" required
                                    style="padding:14px 18px; border-radius:12px; border:2px solid #e2e8f0; width:100%; font-size:16px; outline:none; transition:border-color 0.2s;">
                            </div>
                            <button type="submit" style="background:#4f46e5; color:#fff; padding:16px 32px; border:none; border-radius:12px; cursor:pointer; font-weight:bold; font-size:16px; width:100%; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);">
                                認証コードを送信
                            </button>
                        </form>
                        
                        <p style="font-size: 11px; color: #888; margin-top: 20px; line-height: 1.5;">
                            <?php printf(__('認証を行うことで、%sに同意したものとみなされます。', 'photo-purchase'), '<a href="' . esc_url(home_url('/membership-terms/')) . '" target="_blank" style="color: #4f46e5; text-decoration: underline;">' . __('会員規約', 'photo-purchase') . '</a>'); ?>
                        </p>
                    </div>
                </div>
<?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ログイン済み: ダッシュボード表示 -->
        <?php 
        $is_member = is_user_logged_in();
        $discount_rate = intval(get_option('photo_pp_member_discount_rate', '0'));
        if ($is_member && $discount_rate > 0): ?>
            <div class="member-status-banner">
                <div style="background:rgba(255,255,255,0.2); width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center;">
                    <svg viewBox="0 0 24 24" width="24" height="24" stroke="white" stroke-width="2.5" fill="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                </div>
                <div>
                    <div style="font-size:13px; opacity:0.9; margin-bottom:2px;">MEMBER EXCLUSIVE</div>
                    <div style="font-size:18px; font-weight:800;"><?php echo esc_html($discount_rate); ?>% 会員特別割引が適用中です</div>
                </div>
            </div>
        <?php endif; ?>

        <header style="margin-bottom:32px; display:flex; justify-content:space-between; align-items:center; background:#fff; padding:24px; border-radius:20px; border:1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="background:#f1f5f9; width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:#64748b;">
                    <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <div>
                    <span style="color:#94a3b8; font-size:12px; font-weight:bold; letter-spacing:0.05em;"><?php echo is_user_logged_in() ? '会員アカウント' : 'ゲスト認証'; ?></span><br>
                    <strong style="font-size:18px; color:#1e293b;"><?php echo esc_html($auth_email); ?></strong>
                </div>
            </div>
            <?php if (!is_user_logged_in()): ?>
                <a href="<?php echo add_query_arg('pp_logout', '1'); ?>" style="color:#ef4444; font-size:14px; font-weight:bold; text-decoration:none; background:#fff1f2; padding:10px 20px; border-radius:10px; border:1px solid #fee2e2; transition:all 0.2s;">ログアウト</a>
            <?php else: ?>
                <a href="<?php echo wp_logout_url(get_permalink()); ?>" style="color:#64748b; font-size:14px; font-weight:bold; text-decoration:none; background:#f8fafc; padding:10px 20px; border-radius:10px; border:1px solid #e2e8f0;">ログアウト</a>
            <?php endif; ?>
        </header>

        <?php
        global $wpdb;
        $table_name = $wpdb->prefix . 'photo_orders';
        
        // 注文データの取得 (email または user_id)
        // 管理画面のデフォルトタブと同様に「未決済のまま中断されたStripe/PayPay注文(放置注文)」を非表示にする
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $orders = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE (user_id = %d OR buyer_email = %s) AND NOT (status = 'pending_payment' AND payment_method IN ('stripe', 'paypay')) ORDER BY created_at DESC", 
                $user_id, $auth_email
            ));
        } else {
            $orders = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE buyer_email = %s AND NOT (status = 'pending_payment' AND payment_method IN ('stripe', 'paypay')) ORDER BY created_at DESC", 
                $auth_email
            ));
        }
        
        // サブスクリプション管理用の顧客ID取得
        $subscription_customer_id = '';
        foreach ($orders as $o) {
            if (!empty($o->stripe_customer_id)) {
                $subscription_customer_id = $o->stripe_customer_id;
                break;
            }
        }
        ?>

        <?php if ($subscription_customer_id): ?>
            <!-- Stripe ポータル連携 -->
            <div style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%); border-radius:24px; padding:40px; color:#fff; text-align:center; margin-bottom:48px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); position:relative; overflow:hidden;">
                <div style="position:relative; z-index:1;">
                    <h3 style="margin:0 0 16px; color:#fff; display:flex; align-items:center; justify-content:center; gap:12px; font-size:20px; font-weight:700;">
                        <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        定期プラン・お支払い方法の管理
                    </h3>
                    <p style="margin:0 0 32px; color:#94a3b8; font-size:15px; max-width:480px; margin-left:auto; margin-right:auto;">登録済みカードの更新、お支払い履歴の確認、解約手続きなどはStripeポータルから安全に行えます。</p>
                    <?php 
                    $portal_url = function_exists('photo_purchase_create_portal_session') ? photo_purchase_create_portal_session($subscription_customer_id) : false;
                    if ($portal_url): ?>
                        <a href="<?php echo esc_url($portal_url); ?>" target="_blank"
                           style="display:inline-block; background:#fff; color:#1e293b; text-decoration:none; padding:16px 40px; border-radius:14px; font-weight:800; font-size:16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2);">
                            管理ポータルを開く
                        </a>
                    <?php endif; ?>
                </div>
                <div style="position:absolute; bottom:-20px; right:-20px; opacity:0.1; transform: rotate(-15deg);">
                    <svg viewBox="0 0 24 24" width="200" height="200" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.97 0-1.92 1.41-3.26 3.11-3.66V3.36h2.67v2c1.3.15 2.5 1.02 2.67 2.84h-2c-.08-1-1-1.29-1.95-1.29-1.11 0-2.31.42-2.31 1.51 0 .91.82 1.4 2.13 1.76 2.48.69 4.74 1.57 4.74 4.3 0 2.22-1.43 3.65-3.32 4.11z"/></svg>
                </div>
            </div>
        <?php endif; ?>

        <!-- [New] Profile / Shipping Settings Section -->
        <?php if (is_user_logged_in()): 
            $current_user = wp_get_current_user();
            $u_phone = get_user_meta($current_user->ID, 'billing_phone', true);
            $u_zip = get_user_meta($current_user->ID, 'billing_postcode', true);
            $u_pref = get_user_meta($current_user->ID, 'billing_state', true);
            $u_addr = get_user_meta($current_user->ID, 'billing_address_1', true);
        ?>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:20px; padding:32px; margin-bottom:48px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
            <h3 style="margin:0 0 24px; color:#1e293b; font-size:20px; font-weight:800; display:flex; align-items:center; gap:12px;">
                <svg viewBox="0 0 24 24" width="24" height="24" stroke="#4f46e5" stroke-width="2" fill="none"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                会員情報・配送先設定
            </h3>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="h-adr">
                <span class="p-country-name" style="display:none;">Japan</span>
                <input type="hidden" name="action" value="photo_purchase_update_profile">
                <?php wp_nonce_field('photo_purchase_update_profile', 'profile_nonce'); ?>
                
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#64748b; margin-bottom:8px;">お名前</label>
                    <input type="text" name="display_name" value="<?php echo esc_attr($current_user->display_name); ?>" required style="width:100%; padding:12px; border-radius:10px; border:1px solid #e2e8f0;">
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:20px;">
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#64748b; margin-bottom:8px;">電話番号</label>
                        <input type="tel" name="billing_phone" value="<?php echo esc_attr($u_phone); ?>" style="width:100%; padding:12px; border-radius:10px; border:1px solid #e2e8f0;">
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#64748b; margin-bottom:8px;">郵便番号</label>
                        <input type="text" name="billing_postcode" value="<?php echo esc_attr($u_zip); ?>" placeholder="123-4567" class="p-postal-code" style="width:100%; padding:12px; border-radius:10px; border:1px solid #e2e8f0;">
                    </div>
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#64748b; margin-bottom:8px;">都道府県</label>
                    <select name="billing_state" class="p-region" style="width:100%; padding:12px; border-radius:10px; border:1px solid #e2e8f0;">
                        <option value="">-- 選択してください --</option>
                        <?php
                        $prefectures = ["北海道", "青森県", "岩手県", "宮城県", "秋田県", "山形県", "福島県", "茨城県", "栃木県", "群馬県", "埼玉県", "千葉県", "東京都", "神奈川県", "新潟県", "富山県", "石川県", "福井県", "山梨県", "長野県", "岐阜県", "静岡県", "愛知県", "三重県", "滋賀県", "京都府", "大阪府", "兵庫県", "奈良県", "和歌山県", "鳥取県", "島根県", "岡山県", "広島県", "山口県", "徳島県", "香川県", "愛媛県", "高知県", "福岡県", "佐賀県", "長崎県", "熊本県", "大分県", "宮崎県", "鹿児島県", "沖縄県"];
                        foreach ($prefectures as $p) {
                            echo '<option value="' . esc_attr($p) . '" ' . selected($u_pref, $p, false) . '>' . esc_html($p) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div style="margin-bottom:24px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#64748b; margin-bottom:8px;">市区町村・番地・建物名</label>
                    <textarea name="billing_address_1" rows="2" class="p-locality p-street-address p-extended-address" style="width:100%; padding:12px; border-radius:10px; border:1px solid #e2e8f0;"><?php echo esc_textarea($u_addr); ?></textarea>
                </div>
                
                <button type="submit" style="background:#4f46e5; color:#fff; border:none; padding:12px 32px; border-radius:10px; font-weight:700; cursor:pointer; font-size:14px; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);">
                    情報を更新する
                </button>
                
                <?php if (isset($_GET['profile_updated'])): ?>
                    <span style="margin-left:16px; color:#10b981; font-size:14px; font-weight:600;">✓ 保存しました</span>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>


        <!-- Favorite Products Section -->
        <div id="ec-favorites-dashboard-wrapper" style="display:none; background:#fff; border:1px solid #e2e8f0; border-radius:24px; padding:32px; margin-bottom:48px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
                <h3 style="margin:0; color:#1e293b; font-size:22px; font-weight:800; display:flex; align-items:center; gap:12px;">
                    <div style="background:#fff1f2; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#f43f5e;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                    </div>
                    <?php _e('お気に入り商品', 'photo-purchase'); ?>
                </h3>
            </div>
            <div id="ec-favorites-dashboard-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px;">
                <!-- Injected via JS -->
            </div>
        </div>

        <!-- 注文履歴一覧 -->
        <h3 style="display:flex; align-items:center; gap:12px; color:#1e293b; margin-bottom:24px; font-size:22px; font-weight:800;">
            <div style="background:#eef2ff; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#4f46e5;">
                <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            購入履歴
        </h3>

        <?php if ($orders): ?>
            <div style="display:flex; flex-direction:column; gap:20px;">
                <?php foreach ($orders as $order): 
                    $items_data = json_decode($order->order_items, true) ?: array();
                    $status_info = array(
                        'pending_payment' => array('label' => '入金待ち', 'color' => '#f59e0b', 'bg' => '#fef3c7'),
                        'processing'      => array('label' => '支払い済み / 準備中', 'color' => '#10b981', 'bg' => '#ecfdf5'),
                        'completed'       => array('label' => '発送済み / 完了', 'color' => '#4f46e5', 'bg' => '#eef2ff'),
                        'active'          => array('label' => '有効 (サブスク)', 'color' => '#7c3aed', 'bg' => '#f5f3ff'),
                        'cancelled'       => array('label' => 'キャンセル', 'color' => '#ef4444', 'bg' => '#fef2f2'),
                    );
                    $current_status = $status_info[$order->status] ?? array('label' => $order->status, 'color' => '#64748b', 'bg' => '#f1f5f9');
                    
                    // サブスク判定
                    $is_sub_order = !empty($order->stripe_subscription_id);
                    if ($is_sub_order && $order->status === 'completed') {
                        $current_status['label'] = '継続中';
                    }
                ?>
                    <div class="order-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:20px; padding:28px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); transition: transform 0.2s;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                            <div>
                                <div style="font-family: 'JetBrains Mono', 'Courier New', monospace; font-size:16px; font-weight:bold; color:#1e293b; margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                                    <?php echo esc_html($order->order_token); ?>
                                    <span style="font-size:12px; font-weight:normal; background:#f1f5f9; color:#94a3b8; padding:2px 8px; border-radius:6px; font-family:sans-serif;">ID: #<?php echo $order->id; ?></span>
                                </div>
                                <div style="font-size:13px; color:#94a3b8; display:flex; align-items:center; gap:8px;">
                                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <?php echo date('Y/m/d H:i', strtotime($order->created_at)); ?>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                                <div style="background:<?php echo $current_status['bg']; ?>; color:<?php echo $current_status['color']; ?>; padding:6px 16px; border-radius:100px; font-size:13px; font-weight:800; border: 1px solid rgba(0,0,0,0.05);">
                                    <?php echo esc_html($current_status['label']); ?>
                                </div>
                            </div>
                        </div>

                        <div style="background:#f1f5f9; border-radius:14px; padding:16px; margin-bottom:20px;">
                            <div style="display:flex; flex-wrap:wrap; gap:20px; margin-bottom:12px;">
                                <div style="flex:1; min-width:120px;">
                                    <label style="display:block; font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; margin-bottom:4px;">合計金額</label>
                                    <span style="font-size:18px; font-weight:800; color:#1e293b;"><?php echo number_format($order->total_amount); ?> <small style="font-weight:600; font-size:13px; color:#64748b;">円</small></span>
                                </div>
                                <div style="flex:1; min-width:120px;">
                                    <label style="display:block; font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; margin-bottom:4px;">お支払い方法</label>
                                    <span style="font-size:15px; font-weight:600; color:#475569;">
                                        <?php 
                                            $methods = array('stripe' => 'クレジットカード', 'cod' => '代金引換', 'bank_transfer' => '銀行振込', 'paypay' => 'PayPay');
                                            echo $methods[$order->payment_method] ?? $order->payment_method;
                                        ?>
                                    </span>
                                </div>
                                <?php if (!empty($order->coupon_info)): 
                                    $coupon_data = json_decode($order->coupon_info, true);
                                    if ($coupon_data && !empty($coupon_data['code'])): ?>
                                    <div style="flex:1; min-width:120px;">
                                        <label style="display:block; font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; margin-bottom:4px;">適用クーポン</label>
                                        <span style="font-size:13px; font-weight:700; color:#be123c;">
                                            <?php echo esc_html($coupon_data['code']); ?> (-<?php echo number_format($coupon_data['applied_discount'] ?? 0); ?>円)
                                        </span>
                                    </div>
                                <?php endif; endif; ?>
                            </div>
                            
                            <!-- Tax Breakdown -->
                            <div style="border-top: 1px dashed #cbd5e1; pt:10px; mt:10px; font-size:12px; color:#64748b;">
                                <?php
                                    $shipping_info = json_decode($order->shipping_info, true);
                                    $s_fee = intval($shipping_info['fee'] ?? 0);
                                    $c_fee = intval($shipping_info['cod_fee'] ?? 0);
                                    $coupon_disc = 0;
                                    if (!empty($order->coupon_info)) {
                                        $c_data = json_decode($order->coupon_info, true);
                                        $coupon_disc = intval($c_data['applied_discount'] ?? 0);
                                    }
                                    $tax_res = photo_purchase_get_tax_breakdown($items_data, $s_fee, $c_fee, $coupon_disc);
                                    foreach ($tax_res as $rate => $tax_data) {
                                        if ($tax_data['target'] > 0) {
                                            echo '<div style="display:flex; justify-content:space-between; margin-bottom:2px;">';
                                            echo '<span>' . $rate . '%対象額 (税込):</span>';
                                            echo '<span>¥' . number_format($tax_data['target']) . ' (内消費税 ¥' . number_format($tax_data['tax']) . ')</span>';
                                            echo '</div>';
                                        }
                                    }
                                ?>
                            </div>
                        </div>

                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($items_data as $it): 
                                $is_digital = ($it['format'] === 'digital');
                                $dl_url = '';
                                if ($is_digital && in_array($order->status, array('processing', 'completed'))) {
                                    if (function_exists('photo_purchase_generate_download_url')) {
                                        $dl_url = photo_purchase_generate_download_url($order->order_token, $it['id']);
                                    }
                                }
                                $is_sub = ($it['format'] === 'subscription');
                            ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom: 1px solid #f1f5f9;">
                                    <div style="display:flex; align-items:flex-start; gap:12px;">
                                        <div style="background:#fff; border:1px solid #e2e8f0; width:44px; height:44px; border-radius:8px; overflow:hidden; flex-shrink:0;">
                                            <?php echo get_the_post_thumbnail($it['id'], 'thumbnail', array('style' => 'width:100%; height:100%; object-fit:cover;')); ?>
                                        </div>
                                        <div>
                                            <div style="font-size:15px; font-weight:600; color:#334155; margin-bottom:2px;">
                                                <?php echo esc_html(get_the_title($it['id'])); ?>
                                            </div>
                                            <?php if (!empty($it['variation_name'])): ?>
                                                <div style="color:var(--pp-accent); font-size:12px; margin-bottom:3px;"><?php echo esc_html($it['variation_name']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($it['options']) && is_array($it['options'])): 
                                                foreach ($it['options'] as $opt): ?>
                                                    <div style="color:#64748b; font-size:11px; margin-bottom:1px;">+ <?php echo esc_html($opt['name']); ?></div>
                                                <?php endforeach;
                                            endif; ?>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span style="font-size:12px; color:#94a3b8; font-weight:500;">
                                                    <?php echo photo_purchase_get_format_label($it['format'] ?? ''); ?> x<?php echo $it['qty'] ?? 1; ?>
                                                </span>
                                                <?php if ($is_sub): 
                                                    $count = get_post_meta($it['id'], '_photo_sub_interval_count', true) ?: '1';
                                                    $interval = get_post_meta($it['id'], '_photo_sub_interval', true) ?: 'month';
                                                    $intervals = array('day' => '日', 'week' => '週', 'month' => 'ヶ月', 'year' => '年');
                                                    $cycle = $count . ($intervals[$interval] ?? $interval) . 'ごと';
                                                ?>
                                                    <span style="font-size:11px; background:#eef2ff; color:#4f46e5; font-weight:700; padding:2px 8px; border-radius:4px; border:1px solid #e0e7ff;">🔄 <?php echo esc_html($cycle); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($dl_url): ?>
                                        <a href="<?php echo esc_url($dl_url); ?>" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:bold; color:#fff; text-decoration:none; background:#4f46e5; padding:8px 16px; border-radius:10px; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);">
                                            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                            ダウンロード
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (!empty($order->tracking_number)): ?>
                            <div style="margin-top:20px; padding:16px; background:#f0f9ff; border-radius:14px; border:1px solid #e0f2fe; display:flex; justify-content:space-between; align-items:center;">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div style="color:#0369a1;">
                                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><rect x="1" y="3" width="15" height="13"/><polyline points="16 8 20 8 23 11 23 16 16 16"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                    </div>
                                    <div>
                                        <label style="display:block; font-size:11px; font-weight:700; color:#0369a1; text-transform:uppercase; margin-bottom:2px;">配送状況 (送り状番号)</label>
                                        <span style="font-size:15px; font-weight:800; color:#0c4a6e;"><?php echo esc_html($order->tracking_number); ?></span>
                                    </div>
                                </div>
                                <?php 
                                $url = !empty($order->carrier) ? photo_purchase_get_carrier_url($order->carrier, $order->tracking_number) : '';
                                if ($url): ?>
                                    <a href="<?php echo esc_url($url); ?>" target="_blank" style="display:inline-flex; align-items:center; gap:8px; background:#fff; color:#0369a1; text-decoration:none; padding:8px 20px; border-radius:10px; font-size:14px; font-weight:bold; border:1px solid #bae6fd; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                        追跡サイトを開く
                                        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($is_sub_order && !empty($order->stripe_customer_id)): ?>
                            <div style="margin-top:20px; padding-top:20px; border-top: 1px dashed #e2e8f0; text-align:right;">
                                <a href="<?php echo esc_url(add_query_arg(['action' => 'photo_purchase_billing_portal', 'customer_id' => $order->stripe_customer_id, '_wpnonce' => wp_create_nonce('photo_billing_' . $order->stripe_customer_id)], admin_url('admin-post.php'))); ?>" target="_blank" style="display:inline-flex; align-items:center; gap:8px; background:#fff; color:#475569; border:2px solid #e2e8f0; padding:10px 24px; border-radius:12px; font-size:14px; font-weight:bold; cursor:pointer; transition:all 0.2s; text-decoration:none;">
                                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg>
                                    プランの管理・詳細（Stripe）
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Document Print Actions -->
                        <div style="margin-top:20px; padding-top:20px; border-top: 1px solid #f1f5f9; display:flex; justify-content:flex-end; gap:12px; flex-wrap:wrap;">
                            <button class="buy-again-btn" 
                                    data-items='<?php echo esc_attr(json_encode($items_data)); ?>'
                                    style="display:inline-flex; align-items:center; gap:8px; background:#f0fdf4; color:#16a34a; text-decoration:none; padding:8px 20px; border-radius:10px; font-size:13px; font-weight:bold; border:1px solid #bbf7d0; cursor:pointer;">
                                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                                再購入（一括カート追加）
                            </button>
                            <a href="<?php echo esc_url(add_query_arg(array('photo_purchase_action' => 'print_doc', 'order_id' => $order->id, 'type' => 'print_invoice', 'order_token' => $order->order_token), home_url('/'))); ?>" 
                               target="_blank" 
                               style="display:inline-flex; align-items:center; gap:8px; background:#fff; color:#475569; text-decoration:none; padding:8px 20px; border-radius:10px; font-size:13px; font-weight:bold; border:1px solid #e2e8f0;">
                                📄 納品書（請求書）
                            </a>
                            <?php if ($order->status !== 'pending_payment' && $order->status !== 'cancelled'): ?>
                                <a href="<?php echo esc_url(add_query_arg(array('photo_purchase_action' => 'print_doc', 'order_id' => $order->id, 'type' => 'print_receipt', 'order_token' => $order->order_token), home_url('/'))); ?>" 
                                   target="_blank" 
                                   style="display:inline-flex; align-items:center; gap:8px; background:#fff; color:#475569; text-decoration:none; padding:8px 20px; border-radius:10px; font-size:13px; font-weight:bold; border:1px solid #e2e8f0;">
                                    💰 領収書
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="background:#fff; border:2px dashed #e2e8f0; border-radius:24px; padding:80px 40px; text-align:center;">
                <div style="background:#f1f5f9; width:64px; height:64px; border-radius:20px; display:flex; align-items:center; justify-content:center; margin:0 auto 24px; color:#94a3b8;">
                    <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                </div>
                <h3 style="color:#1e293b; font-size:20px; font-weight:700; margin:0 0 8px;">注文履歴が見つかりません</h3>
                <p style="color:#94a3b8; font-size:16px;">まだ商品をご注文いただいていないか、別のアカウントでのご注文の可能性があります。</p>
            </div>
        <?php endif; ?>

    <?php endif; ?>
    </div>
    <style>
    .photo-purchase-portal button:hover { transform: translateY(-1px); }
    .photo-purchase-portal input:focus { border-color: #4f46e5 !important; }
    @media (max-width: 600px) {
        .photo-purchase-portal header { flex-direction: column; gap: 16px; text-align: center; }
        .photo-purchase-portal header a { width: 100%; text-align: center; }
    }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('ec_member_dashboard', 'photo_purchase_member_dashboard_shortcode');


/**
 * Handle Billing Portal Redirect
 */
function photo_purchase_handle_billing_portal_redirect() {
    if (!isset($_REQUEST['customer_id'])) {
        wp_die('Missing customer ID');
    }
    $customer_id = sanitize_text_field($_REQUEST['customer_id']);

    if (empty($customer_id)) {
        wp_die('Customer ID が設定されていません。管理画面の注文編集画面から Stripe Customer ID を入力してください。');
    }

    // Verify nonce instead of cookie to avoid WAF/Browser dropping cookies on target="_blank" GET requests
    if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'photo_billing_' . $customer_id)) {
        wp_die('Unauthorized: セッションまたはセキュリティトークンが切れています。マイページを再読み込みしてください。');
    }

    $secret_key = get_option('photo_pp_stripe_secret_key');
    if (!$secret_key) {
        wp_die('Stripe シークレットキーが設定されていません。管理画面の各種設定を確認してください。');
    }

    $return_url = home_url();
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

    if (is_wp_error($response)) {
        wp_die('Stripe API エラー: ' . esc_html($response->get_error_message()));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($body['url'])) {
        wp_redirect($body['url']);
        exit;
    }

    // Show detailed Stripe error
    $stripe_error = $body['error']['message'] ?? '不明なエラー';
    $stripe_type = $body['error']['type'] ?? '';
    wp_die(
        '<h2>Stripe カスタマーポータルのセッション作成に失敗しました</h2>' .
        '<p><strong>エラー:</strong> ' . esc_html($stripe_error) . '</p>' .
        '<p><strong>種別:</strong> ' . esc_html($stripe_type) . '</p>' .
        '<p><strong>Customer ID:</strong> ' . esc_html($customer_id) . '</p>' .
        '<p>Stripeダッシュボードで <a href="https://dashboard.stripe.com/settings/billing/portal" target="_blank">カスタマーポータルの設定</a> を有効化しているか確認してください。</p>' .
        '<p><a href="javascript:history.back()">← 戻る</a></p>'
    );
}
add_action('admin_post_photo_purchase_billing_portal', 'photo_purchase_handle_billing_portal_redirect');
add_action('admin_post_nopriv_photo_purchase_billing_portal', 'photo_purchase_handle_billing_portal_redirect');

/**
 * AJAX: Update Order Status
 */
function photo_purchase_handle_order_status_ajax()
{
    check_ajax_referer('photo_update_order_status_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
	
	
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order_id = intval($_POST['order_id']);
    $status = sanitize_text_field($_POST['status']);

    $updated = $wpdb->update($table_name, array('status' => $status), array('id' => $order_id));
    if ($updated !== false) {
        // Stock Management: If changed to cancelled, return stock
        if ($status === 'cancelled') {
            photo_purchase_update_stock_for_order($order_id, true);
        }
        
        photo_purchase_send_status_update_notification($order_id, $status);
        wp_send_json_success();
    } else {
        wp_send_json_error('Database error');
    }
}
add_action('wp_ajax_photo_update_order_status_ajax', 'photo_purchase_handle_order_status_ajax');

/**
 * Helper: Calculate tax breakdown for invoice compliance
 */
function photo_purchase_get_tax_breakdown($items, $shipping = 0, $cod = 0, $discount = 0) {
    if (!is_array($items)) return array();
    
    $breakdown = array();
    $total_before_discount = 0;
    
    $rate_standard = intval(get_option('photo_pp_tax_rate_standard', '10'));
    
    foreach ($items as $item) {
        $rate = intval($item['tax_rate'] ?? $rate_standard);
        $price = intval($item['price'] ?? 0);
        $opts = intval($item['options_total'] ?? 0);
        $final_price = intval($item['final_price'] ?? ($price + $opts));
        $qty = intval($item['qty'] ?? 1);
        $subtotal = $final_price * $qty;
        
        if (!isset($breakdown[$rate])) $breakdown[$rate] = 0;
        $breakdown[$rate] += $subtotal;
        $total_before_discount += $subtotal;
    }
    
    // Add fees to standard rate
    if (!isset($breakdown[$rate_standard])) $breakdown[$rate_standard] = 0;
    $breakdown[$rate_standard] += intval($shipping) + intval($cod);
    $total_before_discount += intval($shipping) + intval($cod);
    
    // Apply discount proportionally if exists
    if ($discount > 0 && $total_before_discount > 0) {
        $remaining_discount = $discount;
        $rates = array_keys($breakdown);
        foreach ($rates as $index => $rate) {
            if ($index === count($rates) - 1) {
                $breakdown[$rate] = max(0, $breakdown[$rate] - $remaining_discount); // Apply remainder safely
            } else {
                $proportion = $breakdown[$rate] / $total_before_discount;
                $dist_discount = floor($discount * $proportion);
                $breakdown[$rate] = max(0, $breakdown[$rate] - $dist_discount);
                $remaining_discount -= $dist_discount;
            }
        }
    }
    
    $results = array();
    foreach ($breakdown as $rate => $target) {
        $tax = floor($target * ($rate / (100 + $rate)));
        $results[$rate] = array(
            'target' => $target,
            'tax' => $tax
        );
    }
    ksort($results); // Sort by rate (8, 10...)
    return $results;
}

/**
 * Helper: Update stock for an order (increment/decrement)
 */
function photo_purchase_update_stock_for_order($order_id, $is_increment = true) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_orders';
    $order = $wpdb->get_row($wpdb->prepare("SELECT order_items, coupon_info FROM $table_name WHERE id = %d", $order_id));
    
    if (!$order) return;
    
    // Product stock management
    if (!empty($order->order_items)) {
        $items = json_decode($order->order_items, true);
        if (is_array($items)) {
            foreach ($items as $item) {
                $product_id = intval($item['id']);
                $qty = intval($item['qty'] ?? 1);
                
                $manage_stock = get_post_meta($product_id, '_photo_manage_stock', true) === '1';
                $use_variations = get_post_meta($product_id, '_photo_use_variations', true) === '1';
                $v_id = $item['variation_id'] ?? '';

                if ($manage_stock) {
                    if ($use_variations && $v_id) {
                        $variations = get_post_meta($product_id, '_photo_variation_skus', true);
                        if (is_array($variations)) {
                            $target_var = null;
                            if (isset($variations[$v_id])) {
                                $target_var = &$variations[$v_id];
                            } else {
                                foreach ($variations as &$v) {
                                    if (($v['variation_id'] ?? '') === $v_id) {
                                        $target_var = &$v;
                                        break;
                                    }
                                }
                            }

                            if ($target_var) {
                                $current_v_stock = intval($target_var['stock'] ?? 0);
                                $target_var['stock'] = $is_increment ? ($current_v_stock + $qty) : max(0, $current_v_stock - $qty);
                            }
                            update_post_meta($product_id, '_photo_variation_skus', $variations);
                        }
                    } else {
                        $current_stock = intval(get_post_meta($product_id, '_photo_stock_qty', true));
                        $new_stock = $is_increment ? ($current_stock + $qty) : max(0, $current_stock - $qty);
                        update_post_meta($product_id, '_photo_stock_qty', $new_stock);
                    }
                    
                    // Trigger alert check
                    photo_purchase_check_stock_alert($product_id);
                }
            }
        }
    }

    // Coupon usage management
    if (!empty($order->coupon_info)) {
        $coupon_data = json_decode($order->coupon_info, true);
        if ($coupon_data && !empty($coupon_data['code'])) {
            $coupon_code = $coupon_data['code'];
            $coupon_table = $wpdb->prefix . 'photo_coupons';
            if ($is_increment) {
                // Return coupon usage (decrement usage_count)
                $wpdb->query($wpdb->prepare(
                    "UPDATE $coupon_table SET usage_count = GREATEST(0, usage_count - 1) WHERE code = %s",
                    $coupon_code
                ));
            } else {
                // Normally handled in save_order, but for completeness:
                $wpdb->query($wpdb->prepare(
                    "UPDATE $coupon_table SET usage_count = usage_count + 1 WHERE code = %s",
                    $coupon_code
                ));
            }
        }
    }
}

/**
 * Check if stock alert notification should be sent
 */
function photo_purchase_check_stock_alert($product_id) {
    $manage_stock = get_post_meta($product_id, '_photo_manage_stock', true) === '1';
    $use_variations = get_post_meta($product_id, '_photo_use_variations', true) === '1';

    if (!$manage_stock && !$use_variations) {
        return;
    }

    $threshold = intval(get_option('photo_pp_stock_threshold', '5'));
    $low_stock_items = array();
    $alert_sent_vars = get_post_meta($product_id, '_photo_stock_vars_alert_sent', true) ?: array();
    $needs_email = false;
    $has_updated_sent_meta = false;

    if ($use_variations) {
        $variations = get_post_meta($product_id, '_photo_variation_skus', true);
        if (is_array($variations)) {
            foreach ($variations as $key => $v) {
                $actual_v_id = $v['variation_id'] ?? $key;
                $stock = intval($v['stock'] ?? 0);
                if ($stock <= $threshold) {
                    $low_stock_items[] = array(
                        'name' => $v['name'] ?? $actual_v_id,
                        'stock' => $stock
                    );
                    // Check if we already sent alert for this specific variation
                    if (empty($alert_sent_vars[$actual_v_id])) {
                        $needs_email = true;
                        $alert_sent_vars[$actual_v_id] = true;
                        $has_updated_sent_meta = true;
                    }
                } else {
                    // Reset alert flag if stock is replenished
                    if (!empty($alert_sent_vars[$actual_v_id])) {
                        unset($alert_sent_vars[$actual_v_id]);
                        $has_updated_sent_meta = true;
                    }
                }
            }
        }
    } else {
        $stock = intval(get_post_meta($product_id, '_photo_stock_qty', true));
        if ($stock <= $threshold) {
            $low_stock_items[] = array('name' => '通常在庫', 'stock' => $stock);
            if (get_post_meta($product_id, '_photo_stock_alert_sent', true) !== '1') {
                $needs_email = true;
                update_post_meta($product_id, '_photo_stock_alert_sent', '1');
            }
        } else {
            delete_post_meta($product_id, '_photo_stock_alert_sent');
        }
    }

    if ($has_updated_sent_meta) {
        update_post_meta($product_id, '_photo_stock_vars_alert_sent', $alert_sent_vars);
    }

    // Only send if there's a new item below threshold or first time
    if (!$needs_email || empty($low_stock_items)) {
        return;
    }

    // Send Alert Email
    $admin_email = get_option('photo_pp_admin_notification_email', get_option('admin_email'));
    $shop_name = get_option('photo_pp_seller_name', get_bloginfo('name'));
    $product_title = get_the_title($product_id);

    $subject = '【在庫不足アラート】' . $product_title;
    
    $message = "以下の商品の在庫が設定したしきい値を下回りました。\n\n";
    $message .= "商品名: " . $product_title . "\n";
    $message .= "----------------------------------------\n";
    foreach ($low_stock_items as $item) {
        $message .= "■ " . $item['name'] . " : 残り " . $item['stock'] . " 個\n";
    }
    $message .= "----------------------------------------\n";
    $message .= "設定しきい値: " . $threshold . " 個以下\n\n";
    $message .= "商品管理画面から在庫の補充を行ってください。\n";
    $message .= admin_url('post.php?post=' . $product_id . '&action=edit') . "\n\n";
    
    $from_email = get_option('photo_pp_seller_email', get_option('admin_email'));
    $headers = array('Content-Type: text/plain; charset=UTF-8', "From: $shop_name <$from_email>");

    $footer = function_exists('photo_purchase_get_email_footer') ? photo_purchase_get_email_footer() : '';
    $message .= "\n---\n" . $footer;

    wp_mail($admin_email, $subject, $message, $headers);
}

/**
 * Helper: Get Carrier Tracking URL
 */
function photo_purchase_get_carrier_url($carrier, $num) {
    if (empty($num)) return '';
    $num = trim($num);
    
    switch ($carrier) {
        case 'yamato':
            return 'https://toi.kuronekoyamato.co.jp/cgi-bin/tneko?kuroneko=' . $num;
        case 'sagawa':
            return 'https://k2k.sagawa-exp.co.jp/p/web/okurijosearch.do?okurijoNo=' . $num;
        case 'japanpost':
            return 'https://trackings.post.japanpost.jp/services/srv/search/direct?searchKind=S004&locale=ja&reqCodeNo1=' . $num;
        case 'seino':
            return 'https://track.seino.co.jp/shisetsu/CheckAll.do?kbNo1=' . $num;
        default:
            return '';
    }
}

/**
 * Helper: Get Carrier Label
 */
function photo_purchase_get_carrier_label($carrier) {
    $carriers = array(
        'yamato' => 'ヤマト運輸',
        'sagawa' => '佐川急便',
        'japanpost' => '日本郵便',
        'seino' => '西濃運輸',
        'other' => 'その他'
    );
    return $carriers[$carrier] ?? $carrier;
}



/**
 * Helper: Get Status Label (Japanese)
 */
function photo_purchase_get_status_label($status) {
    $statuses = array(
        'pending_payment' => 'お支払い待ち',
        'processing'      => '準備中 / 支払い済み',
        'completed'       => '発送済み / 完了',
        'active'          => '有効 (サブスク)',
        'cancelled'       => 'キャンセル',
        'refunded'        => '返金済み'
    );
    return $statuses[$status] ?? $status;
}

/**
 * Send restock notifications to subscribed users
 */
function photo_purchase_send_restock_notifications($product_id)
{
    $emails = get_post_meta($product_id, '_photo_restock_emails', true);
    if (empty($emails) || !is_array($emails)) {
        return;
    }

    $url_mapping = get_post_meta($product_id, '_photo_restock_urls', true) ?: array();

    $shop_name = get_option('photo_pp_seller_name');
    if (empty($shop_name)) {
        $shop_name = get_bloginfo('name');
    }

    $product_title = get_the_title($product_id);
    $product_url = home_url();

    $subject = '【' . $shop_name . '】再入荷のお知らせ：' . $product_title;
    
    foreach ($emails as $email) {
        if (!is_email($email)) continue;

        $message = $email . " 様\n\n";
        $message .= "お待たせいたしました！お問い合わせいただいておりました以下の商品が再入荷いたしました。\n\n";
        $message .= "■商品名　：" . $product_title . "\n";
        
        $base_url = $url_mapping[$email] ?? home_url('/');
        $message .= "■商品URL ：" . add_query_arg('photo_id', $product_id, $base_url) . "\n\n";
        $message .= "人気商品のため、お早めのご確認をおすすめいたします。\n\n";
        $message .= "--------------------------------------------------\n";
        $message .= $shop_name . "\n";
        $message .= home_url() . "\n";

        wp_mail($email, $subject, $message);
    }

    // Clear the notification list after sending
    delete_post_meta($product_id, '_photo_restock_emails');
    delete_post_meta($product_id, '_photo_restock_urls');
}

/**
 * Prevent canonical redirects when photo_id is present
 */
add_filter('redirect_canonical', function($redirect_url, $requested_url) {
    if (isset($_GET['photo_id']) || get_query_var('photo_id')) {
        return false;
    }
    return $redirect_url;
}, 10, 2);
