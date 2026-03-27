<?php
/**
 * Stripe Webhook Handler for Simple EC
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'photo_purchase_handle_stripe_webhook');

function photo_purchase_handle_stripe_webhook() {
    if (isset($_GET['photo_purchase_action']) && $_GET['photo_purchase_action'] === 'stripe_webhook') {
        $payload = file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $webhook_secret = get_option('photo_pp_stripe_webhook_secret', '');

        // Webhook Signature Verification
        if ($webhook_secret && $sig_header) {
            $parts = explode(',', $sig_header);
            $timestamp = '';
            $signature_v1 = '';
            foreach ($parts as $part) {
                $pair = explode('=', $part, 2);
                if (count($pair) === 2) {
                    if (trim($pair[0]) === 't') $timestamp = trim($pair[1]);
                    if (trim($pair[0]) === 'v1') $signature_v1 = trim($pair[1]);
                }
            }

            if (!$timestamp || !$signature_v1) {
                photo_purchase_log('error', 'Stripe Webhook: タイムスタンプまたは署名(v1)が不足しています。', array('sig' => $sig_header));
                http_response_code(400);
                exit;
            }

            // Verify timestamp age (allow 5 minutes drift)
            if (abs(time() - intval($timestamp)) > 300) {
                photo_purchase_log('error', 'Stripe Webhook: タイムスタンプの期限切れです。', array('t' => $timestamp, 'now' => time()));
                http_response_code(400);
                exit;
            }

            $signed_payload = $timestamp . '.' . $payload;
            $expected_sig = hash_hmac('sha256', $signed_payload, $webhook_secret);

            if (!hash_equals($expected_sig, $signature_v1)) {
                photo_purchase_log('error', 'Stripe Webhook: 署名検証に失敗しました。', array('expected' => $expected_sig, 'v1' => $signature_v1));
                http_response_code(401);
                exit;
            }
        } elseif ($webhook_secret) {
            photo_purchase_log('error', 'Stripe Webhook: 署名ヘッダーが見つかりません。');
            http_response_code(400);
            exit;
        }

        $event = json_decode($payload, true);
        if (!$event || !isset($event['type'])) {
            photo_purchase_log('error', 'Stripe Webhook: ペイロードの解析に失敗しました。');
            http_response_code(400);
            exit;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'photo_orders';

        switch ($event['type']) {
            case 'checkout.session.completed':
                $session = $event['data']['object'];
                $order_token = $session['client_reference_id'] ?? '';
                $subscription_id = $session['subscription'] ?? '';
                $customer_id = $session['customer'] ?? '';
                $transaction_id = $session['payment_intent'] ?? '';

                if ($order_token) {
                    $update_data = array(
                        'status' => 'processing',
                    );
                    if ($subscription_id) {
                        $update_data['stripe_subscription_id'] = $subscription_id;
                        $update_data['status'] = 'active';
                    }
                    if ($customer_id) {
                        $update_data['stripe_customer_id'] = $customer_id;
                    }
                    if ($transaction_id) {
                        $update_data['transaction_id'] = $transaction_id;
                    }

                    // Only update and send notification if status is still 'pending_payment'
                    $rows_updated = $wpdb->update(
                        $table_name, 
                        $update_data, 
                        array('order_token' => $order_token, 'status' => 'pending_payment')
                    );
                    
                    if ($rows_updated > 0) {
                        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE order_token = %s", $order_token));
                        if ($order) {
                            $order_data = array(
                                'items' => json_decode($order->order_items, true),
                                'buyer' => array(
                                    'name' => $order->buyer_name,
                                    'email' => $order->buyer_email,
                                ),
                                'shipping' => json_decode($order->shipping_info, true),
                                'method' => $order->payment_method,
                                'coupon_info' => $order->coupon_info,
                            );

                            photo_purchase_send_buyer_notification($order_token, $order_data, $order->total_amount);
                            photo_purchase_send_admin_notification($order_token, $order_data, $order->total_amount);
                        }
                    }
                }
                break;

            case 'invoice.paid':
                $invoice = $event['data']['object'];
                $subscription_id = $invoice['subscription'] ?? '';
                if ($subscription_id) {
                    $wpdb->update($table_name, array('status' => 'active'), array('stripe_subscription_id' => $subscription_id));
                }
                break;

            case 'customer.subscription.deleted':
                $subscription = $event['data']['object'];
                $subscription_id = $subscription['id'] ?? '';
                if ($subscription_id) {
                    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE stripe_subscription_id = %s", $subscription_id));
                    if ($order && $order->status !== 'cancelled') {
                        $wpdb->update($table_name, array('status' => 'cancelled'), array('id' => $order->id));
                        photo_purchase_send_admin_sub_cancel_notification($order);
                    }
                }
                break;
        }

        http_response_code(200);
        exit;
    }
}

/**
 * Notify Admin about Subscription Cancellation
 */
function photo_purchase_send_admin_sub_cancel_notification($order) {
    $admin_email = get_option('photo_pp_admin_notification_email', get_option('admin_email'));
    $shop_name = get_option('photo_pp_seller_name', get_bloginfo('name'));
    $subject = '【サブスク解約】' . $shop_name . '：定期購入が解約されました';
    
    $message = "以下の定期購入（サブスクリプション）がStripe側で解約されました。\n\n";
    $message .= "注文番号: " . $order->order_token . "\n";
    $message .= "購入者: " . $order->buyer_name . " 様\n";
    $message .= "メール: " . $order->buyer_email . "\n";
    $message .= "Stripe サブスクID: " . $order->stripe_subscription_id . "\n\n";
    $message .= "ステータスを「キャンセル」に更新しました。\n";
    
    $from_email = get_option('photo_pp_seller_email', get_option('admin_email'));
    $headers = array('Content-Type: text/plain; charset=UTF-8', "From: $shop_name <$from_email>");
    
    $footer = function_exists('photo_purchase_get_email_footer') ? photo_purchase_get_email_footer() : '';
    $message .= "\n---\n" . $footer;
    
    wp_mail($admin_email, $subject, $message, $headers);
}
