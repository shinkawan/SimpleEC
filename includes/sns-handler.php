<?php
if (!defined('ABSPATH')) exit;

/**
 * SNS Login Handler for Google and LINE
 */

/**
 * Get SNS Config
 */
function photo_purchase_get_sns_config() {
    return array(
        'google' => array(
            'client_id'     => get_option('photo_pp_google_client_id'),
            'client_secret' => get_option('photo_pp_google_client_secret'),
            'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'     => 'https://oauth2.googleapis.com/token',
            'user_url'      => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'scope'         => 'openid email profile',
        ),
        'line' => array(
            'client_id'     => get_option('photo_pp_line_client_id'),
            'client_secret' => get_option('photo_pp_line_client_secret'),
            'auth_url'      => 'https://access.line.me/oauth2/v2.1/authorize',
            'token_url'     => 'https://api.line.me/oauth2/v2.1/token',
            'user_url'      => 'https://api.line.me/v2/profile',
            'scope'         => 'openid profile email', // Note: email needs permission in Developers console
        ),
    );
}

/**
 * Get Redirect URI for SNS Login
 */
function photo_purchase_get_sns_redirect_uri($sns) {
    return home_url('/?pp_sns_callback=' . $sns);
}

/**
 * Generate SNS Auth URL
 * @param string $sns SNS service (google|line)
 * @param string $return_url URL to redirect back to after login
 */
function photo_purchase_get_sns_auth_url($sns, $return_url = '') {
    if (get_option('photo_pp_enable_sns_login', '1') !== '1') return false;
    $config = photo_purchase_get_sns_config();
    if (!isset($config[$sns])) return false;

    $c = $config[$sns];
    if (empty($c['client_id'])) return false;

    // Generate state and store in transient for security
    // We store the SNS type AND the return URL
    $state = wp_generate_password(24, false);
    $state_data = array(
        'sns' => $sns,
        'return_url' => $return_url
    );
    set_transient('photo_pp_sns_state_' . $state, $state_data, 10 * MINUTE_IN_SECONDS);

    $params = array(
        'response_type' => 'code',
        'client_id'     => $c['client_id'],
        'redirect_uri'  => photo_purchase_get_sns_redirect_uri($sns),
        'scope'         => $c['scope'],
        'state'         => $state,
    );

    if ($sns === 'google') {
        $params['access_type'] = 'offline';
        $params['prompt'] = 'select_account';
    }

    return $c['auth_url'] . '?' . http_build_query($params);
}

/**
 * Handle SNS Callback
 */
function photo_purchase_handle_sns_callback() {
    if (!isset($_GET['pp_sns_callback']) || !isset($_GET['code']) || !isset($_GET['state'])) return;

    if (get_option('photo_pp_enable_sns_login', '1') !== '1') {
        wp_die(__('SNSログイン機能は現在無効化されています。', 'photo-purchase'));
    }

    $sns = sanitize_text_field($_GET['pp_sns_callback']);
    $code = sanitize_text_field($_GET['code']);
    $state = sanitize_text_field($_GET['state']);

    // Verify state
    $state_data = get_transient('photo_pp_sns_state_' . $state);
    if (!$state_data || !is_array($state_data) || $state_data['sns'] !== $sns) {
        wp_die('Invalid state. Please try again.');
    }
    delete_transient('photo_pp_sns_state_' . $state);
    
    $return_url = !empty($state_data['return_url']) ? $state_data['return_url'] : '';

    $config = photo_purchase_get_sns_config();
    if (!isset($config[$sns])) return;

    $c = $config[$sns];

    // Exchange Code for Token
    $response = wp_remote_post($c['token_url'], array(
        'body' => array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'redirect_uri'  => photo_purchase_get_sns_redirect_uri($sns),
        ),
    ));

    if (is_wp_error($response)) wp_die('Token exchange failed.');

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $access_token = $body['access_token'] ?? '';

    if (!$access_token) wp_die('Access token not found.');

    // Get User Info
    $user_response = wp_remote_get($c['user_url'], array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        ),
    ));

    if (is_wp_error($user_response)) wp_die('User info fetch failed.');

    $user_data = json_decode(wp_remote_retrieve_body($user_response), true);
    
    // Normalize user data
    $email = '';
    $sns_id = '';
    $display_name = '';

    if ($sns === 'google') {
        $email = $user_data['email'] ?? '';
        $sns_id = $user_data['sub'] ?? '';
        $display_name = $user_data['name'] ?? '';
    } elseif ($sns === 'line') {
        // LINE needs ID Token for email or specific permission
        $sns_id = $user_data['userId'] ?? '';
        $display_name = $user_data['displayName'] ?? '';
        
        // Try to get email from ID Token if available
        if (isset($body['id_token'])) {
            $parts = explode('.', $body['id_token']);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                $email = $payload['email'] ?? '';
            }
        }
    }

    if (!$email) {
        wp_die('Email not returned from SNS. Please check your account settings.');
    }

    // Authenticate or Register User
    $user = get_user_by('email', $email);

    if (!$user) {
        // Create new user if register enabled or just let it happen for Simple EC
        $random_password = wp_generate_password(12, false);
        $user_id = wp_create_user($email, $random_password, $email);
        
        if (is_wp_error($user_id)) {
            wp_die('User creation failed: ' . $user_id->get_error_message());
        }
        
        $user = get_user_by('id', $user_id);
        wp_update_user(array(
            'ID' => $user_id,
            'display_name' => $display_name
        ));
    }

    // Mark user with SNS ID for future reference
    update_user_meta($user->ID, 'sns_id_' . $sns, $sns_id);

    // Login user
    wp_set_current_user($user->ID, $user->user_login);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    // Redirect to Return URL OR Dashboard
    if (!empty($return_url)) {
        wp_safe_redirect($return_url);
    } else {
        wp_safe_redirect(photo_purchase_get_dashboard_url());
    }
    exit;
}
add_action('init', 'photo_purchase_handle_sns_callback');

/**
 * Get Dashboard URL (My Page)
 */
function photo_purchase_get_dashboard_url() {
    // 1. すでにIDが設定されている場合はそれを優先
    $page_id = get_option('photo_my_page_id');
    if ($page_id && get_post($page_id)) {
        return get_permalink($page_id);
    }

    // 2. ショートコード [ec_member_dashboard] を含むページをキーワード検索
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        's'              => '[ec_member_dashboard]',
        'posts_per_page' => 1,
    ));
    
    if (!empty($pages)) {
        // 見つかった場合はIDを保存して次回から高速化
        update_option('photo_my_page_id', $pages[0]->ID);
        return get_permalink($pages[0]->ID);
    }
    
    return home_url('/dashboard/'); // フォールバック
}

/**
 * Get Gallery Page URL
 */
function photo_purchase_get_gallery_url() {
    static $gallery_url = null;
    if ($gallery_url !== null) return $gallery_url;

    // 1. 手動設定がある場合はそれを優先
    $page_id = get_option('photo_gallery_page_id');
    if ($page_id && get_post($page_id)) {
        $gallery_url = get_permalink($page_id);
        return $gallery_url;
    }

    // 2. ショートコード [ec_gallery] を含むページを高速検索
    global $wpdb;
    $post_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_content LIKE %s AND post_status = 'publish' AND post_type = 'page' LIMIT 1",
            '%[ec_gallery]%'
        )
    );
    
    if ($post_id) {
        $gallery_url = get_permalink($post_id);
        update_option('photo_gallery_page_id', $post_id); // 自動保存
    } else {
        $gallery_url = home_url('/');
    }
    
    return $gallery_url;
}

/**
 * Handle Login Redirect (Default WP Login)
 */
add_filter('login_redirect', 'photo_purchase_login_redirect', 10, 3);
function photo_purchase_login_redirect($redirect_to, $request, $user) {
    if (isset($user->roles) && is_array($user->roles)) {
        if (in_array('administrator', $user->roles)) {
            return $redirect_to; // Admins go where they wanted
        }
    }
    
    // If request has a valid redirect_to, use it (unless it's wp-admin)
    if (!empty($request) && strpos($request, 'wp-admin') === false) {
        return $request;
    }
    
    // Otherwise go to My Page
    return photo_purchase_get_dashboard_url();
}

/**
 * Handle Logout Redirect
 */
add_filter('logout_redirect', 'photo_purchase_logout_redirect', 10, 3);
function photo_purchase_logout_redirect($redirect_to, $requested_redirect_to, $user) {
    if (!empty($requested_redirect_to)) {
        return $requested_redirect_to;
    }
    return photo_purchase_get_dashboard_url();
}

/**
 * Block Dashboard Access for Members
 */
add_action('admin_init', 'photo_purchase_restrict_admin_access');
function photo_purchase_restrict_admin_access() {
    global $pagenow;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    
    // Allow admin-post.php for processing frontend forms (profile/checkout)
    if (isset($pagenow) && $pagenow === 'admin-post.php') return;

    if (!current_user_can('manage_options')) {
        wp_safe_redirect(photo_purchase_get_dashboard_url());
        exit;
    }
}

/**
 * Render SNS Login Buttons
 */
function photo_purchase_render_sns_login_buttons() {
    if (get_option('photo_pp_enable_sns_login', '1') !== '1') {
        return '';
    }

    $config = photo_purchase_get_sns_config();
    $enabled_sns = array();

    foreach ($config as $sns => $c) {
        if (!empty($c['client_id'])) {
            $enabled_sns[] = $sns;
        }
    }

    if (empty($enabled_sns)) {
        if (current_user_can('manage_options')) {
            return '<div class="photo-sns-login-container admin-preview" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 12px; background: #f8fafc;">
                        <p style="color: #64748b; font-weight: 600; margin-bottom: 10px;">' . __('【管理者プレビュー】SNS連携が未設定です', 'photo-purchase') . '</p>
                        <p style="font-size: 13px; color: #94a3b8; margin-bottom: 15px;">' . __('管理画面の「SNS連携」タブからGoogleやLINEのクライアントIDを設定すると、ここにログインボタンが表示されます。', 'photo-purchase') . '</p>
                        <a href="' . admin_url('edit.php?post_type=photo_product&page=photo-purchase-settings&tab=sns') . '" class="button button-secondary">' . __('設定画面へ移動', 'photo-purchase') . '</a>
                    </div>';
        }
        return '';
    }

    ob_start();
    $current_url = set_url_scheme('http://' . wp_unslash($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']));
    ?>
    <div class="photo-sns-login-container">
        <p class="photo-sns-login-title"><?php _e('SNSアカウントでログイン', 'photo-purchase'); ?></p>
        <div class="photo-sns-buttons">
            <?php if (in_array('google', $enabled_sns)): ?>
                <a href="<?php echo esc_url(photo_purchase_get_sns_auth_url('google', $current_url)); ?>" class="photo-sns-button photo-sns-google">
                    <span class="photo-sns-icon">
                        <svg viewBox="0 0 48 48" width="20" height="20">
                            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"></path>
                            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"></path>
                            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"></path>
                            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"></path>
                            <path fill="none" d="M0 0h48v48H0z"></path>
                        </svg>
                    </span>
                    <span class="photo-sns-text">Googleでログイン</span>
                </a>
            <?php endif; ?>

            <?php if (in_array('line', $enabled_sns)): ?>
                <a href="<?php echo esc_url(photo_purchase_get_sns_auth_url('line', $current_url)); ?>" class="photo-sns-button photo-sns-line">
                    <span class="photo-sns-icon">
                        <svg viewBox="0 0 32 32" width="20" height="20" fill="currentColor">
                           <path d="M27.273,13.636C27.273,7.561,21.905,2.66,15.298,2.66c-6.61,0-11.977,4.901-11.977,10.976 c0,5.43,4.178,9.972,9.814,10.825c0.383,0.082,0.903,0.252,1.033,0.579c0.12,0.3,0.078,0.769,0.038,1.071 c-0.038,0.301-0.174,1.171-0.211,1.547c-0.038,0.375-0.174,1.649,0.75,2.25c0.923,0.601,2.449,0.395,3.435-0.19 c0.987-0.584,5.341-3.235,7.284-5.542C25.464,22.176,27.273,18.17,27.273,13.636z M10.457,17.436h-2.17 c-0.264,0-0.478-0.211-0.478-0.473V10.31c0-0.262,0.214-0.473,0.478-0.473h2.17c0.264,0,0.478,0.211,0.478,0.473v6.653 C10.935,17.225,10.722,17.436,10.457,17.436z M15.655,17.436H13.43c-0.264,0-0.478-0.211-0.478-0.473V10.31 c0-0.262,0.214-0.473,0.478-0.473h0.126c0.264,0,0.478,0.211,0.478,0.473v5.629h1.621c0.264,0,0.478,0.211,0.478,0.473v0.551 C16.133,17.225,15.919,17.436,15.655,17.436z M18.42,17.436h-0.126c-0.264,0-0.478-0.211-0.478-0.473V10.31 c0-0.262,0.214-0.473,0.478-0.473h0.126c0.264,0,0.478,0.211,0.478,0.473v6.653C18.898,17.225,18.684,17.436,18.42,17.436z M24.34,17.436h-2.434c-0.264,0-0.478-0.211-0.478-0.473V10.31c0-0.262,0.214-0.473,0.478-0.473h0.126 c0.264,0,0.478,0.211,0.478,0.473v5.156h1.83c0.264,0,0.478,0.211,0.478,0.473v0.551C24.818,17.225,24.604,17.436,24.34,17.436z"></path>
                        </svg>
                    </span>
                    <span class="photo-sns-text">LINEでログイン</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
