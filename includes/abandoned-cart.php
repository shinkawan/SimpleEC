<?php
/**
 * Abandoned Cart Recovery System for Simple EC
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Hourly Cron for Abandoned Cart Check
 */
function photo_purchase_schedule_abandoned_cart_cron() {
    if (!wp_next_scheduled('photo_purchase_abandoned_cart_cron')) {
        wp_schedule_event(time(), 'hourly', 'photo_purchase_abandoned_cart_cron');
    }
}
add_action('wp', 'photo_purchase_schedule_abandoned_cart_cron');

/**
 * Main Cron Logic: Find and Process Abandoned Carts
 */
function photo_purchase_process_abandoned_carts() {
    // Check if enabled
    if (get_option('photo_pp_enable_abandoned_cart', '0') !== '1') {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_abandoned_carts';
    $orders_table = $wpdb->prefix . 'photo_orders';

    // 1. Find carts that are 'pending', haven't been reminded yet, 
    //    and the last action was between configured delay and delay + 24 hours ago.
    $delay_hours = intval(get_option('photo_pp_abandoned_cart_delay', '24'));
    if ($delay_hours < 1) $delay_hours = 24;

    $max_delay_hours = $delay_hours + 24; // Don't send reminder for very old carts

    $abandoned_carts = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name 
         WHERE status = 'pending' 
         AND reminder_sent_count = 0 
         AND unsubscribed = 0 
         AND last_active <= %s 
         AND last_active >= %s",
        date('Y-m-d H:i:s', current_time('timestamp') - ($delay_hours * HOUR_IN_SECONDS)),
        date('Y-m-d H:i:s', current_time('timestamp') - ($max_delay_hours * HOUR_IN_SECONDS))
    ));

    if (empty($abandoned_carts)) {
        return;
    }

    foreach ($abandoned_carts as $cart) {
        // 2. Double check if a completed order exists for this email since last active
        $has_order = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $orders_table WHERE buyer_email = %s AND created_at >= %s",
            $cart->email,
            $cart->last_active
        ));

        if ($has_order > 0) {
            // Mark as recovered (manually completed or handled elsewhere)
            $wpdb->update($table_name, array('status' => 'recovered'), array('id' => $cart->id));
            continue;
        }

        // 3. Send the recovery email
        if (photo_purchase_send_recovery_email($cart)) {
            // 4. Update reminder count and sent timestamp
            $wpdb->update($table_name, array(
                'reminder_sent_count' => 1,
                'sent_at' => current_time('mysql')
            ), array('id' => $cart->id));

            // Log the recovery email send
            if (function_exists('photo_purchase_log')) {
                photo_purchase_log('info', 'かご落ちリカバリーメールを送信しました。', array(
                    'email' => $cart->email,
                    'cart_id' => $cart->id
                ));
            }
        }
    }
}
add_action('photo_purchase_abandoned_cart_cron', 'photo_purchase_process_abandoned_carts');

/**
 * Send Recovery Email
 */
function photo_purchase_send_recovery_email($cart_record) {
    $email = $cart_record->email;
    $cart_data = json_decode($cart_record->cart_json, true);
    if (empty($cart_data)) return false;

    $shop_name = get_option('photo_pp_seller_name', get_bloginfo('name'));
    $subject = '【' . $shop_name . '】お買い忘れはありませんか？';

    // Get Cart Recovery Link (point to cart page with token)
    // We assume the cart is usually on a page with [photo_purchase_cart]
    $cart_page_id = get_option('photo_cart_page_id');
    $cart_url = $cart_page_id ? get_permalink($cart_page_id) : home_url('/cart');
    $recovery_url = add_query_arg('recover_cart', $cart_record->recovery_token, $cart_url);

    // Build emotional email content
    $message = "こんにちは。\n\n";
    $message .= "以前、" . $shop_name . "でカートに追加された商品がございますが、お手続きは完了しておりません。\n";
    $message .= "気になる商品はございましたでしょうか？\n\n";
    $message .= "【カートに残っている商品】\n";

    foreach ($cart_data as $item) {
        $title = get_the_title($item['id']);
        $format = photo_purchase_get_format_label($item['format'] ?? '');
        $message .= "- " . $title . " (" . $format . ")\n";
    }

    $message .= "\nこちらのリンクから、すぐにカートを再開いただけます：\n";
    $message .= $recovery_url . "\n\n";

    $unsubscribe_url = add_query_arg('unsubscribe_cart', $cart_record->recovery_token, $cart_url);
    $message .= "※今後このようなメールを希望されない場合は、以下のリンクから解除いただけます：\n";
    $message .= $unsubscribe_url . "\n\n";
    
    $message .= "※既に購入済みの場合は、このメールを破棄してください。\n\n";
    $message .= "--------------- \n";
    $message .= $shop_name . "\n";
    $message .= home_url() . "\n";

    $from_email = get_option('photo_pp_seller_email', get_option('admin_email'));
    $headers = array('Content-Type: text/plain; charset=UTF-8', "From: $shop_name <$from_email>");

    return wp_mail($email, $subject, $message, $headers);
}

/**
 * Hook into [photo_purchase_cart] shortcode to restore from token
 */
function photo_purchase_restore_cart_from_token() {
    if (!isset($_GET['recover_cart'])) return;

    $token = sanitize_text_field($_GET['recover_cart']);
    if (empty($token)) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_abandoned_carts';
    $cart_record = $wpdb->get_row($wpdb->prepare("SELECT cart_json FROM $table_name WHERE recovery_token = %s", $token));

    if ($cart_record) {
        // Record clicked datetime
        $wpdb->update($table_name, array('clicked_at' => current_time('mysql')), array('recovery_token' => $token));

        // We output a script to update localStorage and redirect back to clean URL
        ?>
        <script>
        (function() {
            var cartData = <?php echo $cart_record->cart_json; ?>;
            if (cartData && cartData.length > 0) {
                localStorage.setItem('photo_cart', JSON.stringify(cartData));
                // Redirect to same URL without the query arg to prevent re-running
                var url = new URL(window.location.href);
                url.searchParams.delete('recover_cart');
                window.location.href = url.toString();
            }
        })();
        </script>
        <?php
    }
}
add_action('wp_head', 'photo_purchase_restore_cart_from_token');

/**
 * Handle Unsubscribe Link
 */
function photo_purchase_handle_unsubscribe() {
    if (!isset($_GET['unsubscribe_cart'])) return;

    $token = sanitize_text_field($_GET['unsubscribe_cart']);
    if (empty($token)) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'photo_abandoned_carts';
    
    $wpdb->update(
        $table_name,
        array('unsubscribed' => 1),
        array('recovery_token' => $token)
    );

    wp_die(
        '<h3>配信停止が完了しました</h3><p>今後、かご落ちリマインドメールは送信されません。</p><p><a href="' . home_url() . '">ショップへ戻る</a></p>',
        '配信停止完了',
        array('response' => 200)
    );
}
add_action('init', 'photo_purchase_handle_unsubscribe');
