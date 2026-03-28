<?php
if (!defined('ABSPATH')) exit;

/**
 * Authentication System for Simple EC (OTP / Passwordless)
 */

/**
 * Send OTP via AJAX
 */
function photo_purchase_send_login_otp_ajax() {
    check_ajax_referer('photo_purchase_nonce', 'nonce');

    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    if (empty($email) || !is_email($email)) {
        wp_send_json_error(array('message' => __('正しいメールアドレスを入力してください。', 'photo-purchase')));
    }

    $user = get_user_by('email', $email);
    if (!$user) {
        wp_send_json_error(array('message' => __('このメールアドレスは登録されていません。ゲストとして購入手続きを進めてください。', 'photo-purchase')));
    }

    // Generate 6 digit OTP
    $otp = sprintf('%06d', mt_rand(0, 999999));
    
    // Store in transient for 10 minutes
    $transient_key = 'photo_pp_otp_' . md5($email);
    set_transient($transient_key, $otp, 10 * MINUTE_IN_SECONDS);

    // Send Email
    $subject = sprintf(__('【%s】ログイン用認証コード', 'photo-purchase'), get_bloginfo('name'));
    
    $message = sprintf(__('以下の6桁の認証コードを入力してログインを完了してください。', 'photo-purchase')) . "\n\n";
    $message .= sprintf(__('認証コード: %s', 'photo-purchase'), $otp) . "\n\n";
    $message .= sprintf(__('このコードは10分間有効です。', 'photo-purchase')) . "\n";
    
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    
    $sent = wp_mail($email, $subject, $message, $headers);
    
    if ($sent) {
        wp_send_json_success(array('message' => __('認証コードをメールで送信しました。', 'photo-purchase')));
    } else {
        wp_send_json_error(array('message' => __('メールの送信に失敗しました。時間をおいて再度お試しください。', 'photo-purchase')));
    }
}
add_action('wp_ajax_photo_send_otp', 'photo_purchase_send_login_otp_ajax');
add_action('wp_ajax_nopriv_photo_send_otp', 'photo_purchase_send_login_otp_ajax');

/**
 * Verify OTP via AJAX
 */
function photo_purchase_verify_login_otp_ajax() {
    check_ajax_referer('photo_purchase_nonce', 'nonce');

    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
    
    if (empty($email) || empty($code)) {
        wp_send_json_error(array('message' => __('メールアドレスまたは認証コードが正しくありません。', 'photo-purchase')));
    }

    $transient_key = 'photo_pp_otp_' . md5($email);
    $stored_otp = get_transient($transient_key);

    if (!$stored_otp) {
        wp_send_json_error(array('message' => __('認証コードの有効期限が切れているか、見つかりません。もう一度送信してください。', 'photo-purchase')));
    }

    if ($stored_otp !== $code) {
        wp_send_json_error(array('message' => __('認証コードが間違っています。', 'photo-purchase')));
    }

    $user = get_user_by('email', $email);
    if (!$user) {
        wp_send_json_error(array('message' => __('ユーザーが見つかりません。', 'photo-purchase')));
    }

    // Login user
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID);
    delete_transient($transient_key);

    wp_send_json_success(array('message' => __('ログインに成功しました！', 'photo-purchase')));
}
add_action('wp_ajax_photo_verify_otp', 'photo_purchase_verify_login_otp_ajax');
add_action('wp_ajax_nopriv_photo_verify_otp', 'photo_purchase_verify_login_otp_ajax');
