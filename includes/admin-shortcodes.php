<?php
if (!defined('ABSPATH')) exit;

/**
 * Render the Shortcode Helper Admin Page
 */
function photo_purchase_shortcode_helper_page() {
    // Fetch Categories
    $categories = get_terms(array(
        'taxonomy' => 'photo_event',
        'hide_empty' => false,
    ));

    // Fetch Recent Products
    $products = get_posts(array(
        'post_type' => 'photo_product',
        'posts_per_page' => 50,
        'post_status' => 'publish',
    ));
    ?>
    <div class="wrap">
        <h1 style="margin-bottom: 20px;"><?php _e('Simple EC ショートコード・チートシート', 'photo-purchase'); ?></h1>
        
        <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #4f46e5; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <p style="margin: 0; font-size: 15px; color: #334155;">
                <?php _e('各ページに埋め込み可能なショートコードの一覧です。コピーボタンで取得し、ページや投稿に貼り付けてください。', 'photo-purchase'); ?>
            </p>
        </div>

        <div id="dashboard-widgets" class="metabox-holder">
            <div id="postbox-container-1" class="postbox-container" style="width: 100%;">
                
                <!-- Section: General -->
                <div class="postbox" style="margin-bottom: 25px;">
                    <div class="postbox-header">
                        <h2 class="hndle"><span><span class="dashicons dashicons-admin-generic"></span> <?php _e('基本ショートコード', 'photo-purchase'); ?></span></h2>
                    </div>
                    <div class="inside" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; padding: 20px;">
                        <div class="shortcode-item">
                            <label style="font-weight: 700; display: block; margin-bottom: 8px;"><?php _e('全商品ギャラリー', 'photo-purchase'); ?></label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <code style="flex: 1; padding: 8px; background: #f0f0f1; border-radius: 4px;">[ec_gallery]</code>
                                <button class="button copy-shortcode-btn" data-shortcode="[ec_gallery]"><?php _e('コピー', 'photo-purchase'); ?></button>
                            </div>
                        </div>
                        <div class="shortcode-item">
                            <label style="font-weight: 700; display: block; margin-bottom: 8px;"><?php _e('カートボタン', 'photo-purchase'); ?></label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <code style="flex: 1; padding: 8px; background: #f0f0f1; border-radius: 4px;">[ec_cart_indicator]</code>
                                <button class="button copy-shortcode-btn" data-shortcode="[ec_cart_indicator]"><?php _e('コピー', 'photo-purchase'); ?></button>
                            </div>
                        </div>
                        <div class="shortcode-item">
                            <label style="font-weight: 700; display: block; margin-bottom: 8px;"><?php _e('マイページ', 'photo-purchase'); ?></label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <code style="flex: 1; padding: 8px; background: #f0f0f1; border-radius: 4px;">[ec_member_dashboard]</code>
                                <button class="button copy-shortcode-btn" data-shortcode="[ec_member_dashboard]"><?php _e('コピー', 'photo-purchase'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <!-- Section: Categories -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2 class="hndle"><span><span class="dashicons dashicons-category"></span> <?php _e('カテゴリー別埋め込み', 'photo-purchase'); ?></span></h2>
                        </div>
                        <div class="inside" style="padding: 0;">
                            <table class="wp-list-table widefat fixed striped" style="border: none;">
                                <thead>
                                    <tr>
                                        <th><?php _e('カテゴリー名', 'photo-purchase'); ?></th>
                                        <th><?php _e('ショートコード', 'photo-purchase'); ?></th>
                                        <th style="width: 100px;"><?php _e('操作', 'photo-purchase'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($categories) && !is_wp_error($categories)): foreach ($categories as $cat): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($cat->name); ?></strong></td>
                                        <td><code>[ec_gallery category="<?php echo $cat->slug; ?>" hide_filters="true"]</code></td>
                                        <td><button class="button button-small copy-shortcode-btn" data-shortcode='[ec_gallery category="<?php echo $cat->slug; ?>" hide_filters="true"]'><?php _e('コピー', 'photo-purchase'); ?></button></td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr><td colspan="3"><?php _e('カテゴリーがありません', 'photo-purchase'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Section: Products -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2 class="hndle"><span><span class="dashicons dashicons-cart"></span> <?php _e('商品ピンポイント埋め込み', 'photo-purchase'); ?></span></h2>
                        </div>
                        <div class="inside" style="padding: 0;">
                            <div style="max-height: 400px; overflow-y: auto;">
                                <table class="wp-list-table widefat fixed striped" style="border: none;">
                                    <thead>
                                        <tr>
                                            <th><?php _e('商品名', 'photo-purchase'); ?></th>
                                            <th><?php _e('ショートコード', 'photo-purchase'); ?></th>
                                            <th style="width: 100px;"><?php _e('操作', 'photo-purchase'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($products)): foreach ($products as $p): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($p->post_title); ?></strong> <small>(ID: <?php echo $p->ID; ?>)</small></td>
                                            <td><code>[ec_gallery ids="<?php echo $p->ID; ?>" hide_filters="true"]</code></td>
                                            <td><button class="button button-small copy-shortcode-btn" data-shortcode='[ec_gallery ids="<?php echo $p->ID; ?>" hide_filters="true"]'><?php _e('コピー', 'photo-purchase'); ?></button></td>
                                        </tr>
                                        <?php endforeach; else: ?>
                                        <tr><td colspan="3"><?php _e('商品がありません', 'photo-purchase'); ?></td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section: Options -->
                <div class="postbox" style="margin-top: 25px;">
                    <div class="postbox-header">
                        <h2 class="hndle"><span><span class="dashicons dashicons-lightbulb"></span> <?php _e('便利なオプション設定', 'photo-purchase'); ?></span></h2>
                    </div>
                    <div class="inside" style="padding: 20px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <h4 style="margin-top: 0; color: #1e293b;"><code>hide_filters="true"</code></h4>
                                <p style="font-size: 13px; line-height: 1.6;"><?php _e('検索バーやカテゴリーチップを非表示にします。ランディングページなどで「決まった商品だけ」をきれいに見せたい時に最適です。', 'photo-purchase'); ?></p>
                                <code style="display: block; padding: 8px; background: white; border: 1px solid #cbd5e1; border-radius: 4px; margin-bottom: 10px;">[ec_gallery category="featured" hide_filters="true"]</code>
                                <button class="button button-small copy-shortcode-btn" data-shortcode='[ec_gallery category="featured" hide_filters="true"]'><?php _e('この例をコピー', 'photo-purchase'); ?></button>
                            </div>
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <h4 style="margin-top: 0; color: #1e293b;"><code>ids="10,20,30"</code></h4>
                                <p style="font-size: 13px; line-height: 1.6;"><?php _e('複数のIDをカンマで区切ることで、特定の商品を**指定した順番通り**に並べて表示できます。売れ筋トップ3の紹介などに便利です。', 'photo-purchase'); ?></p>
                                <p style="font-size: 12px; color: #64748b; margin: 0;">※並び順を固定にするため <code>orderby="post__in"</code> が自動適用されます。</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <script>
        document.querySelectorAll('.copy-shortcode-btn').forEach(button => {
            button.addEventListener('click', function() {
                const shortcode = this.getAttribute('data-shortcode');
                const btn = this;
                
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(shortcode).then(() => {
                        const originalText = btn.innerHTML;
                        btn.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> <?php _e('コピー完了', 'photo-purchase'); ?>';
                        btn.style.backgroundColor = '#dcfce7';
                        btn.style.borderColor = '#22c55e';
                        btn.style.color = '#166534';
                        
                        setTimeout(() => {
                            btn.innerHTML = originalText;
                            btn.style.backgroundColor = '';
                            btn.style.borderColor = '';
                            btn.style.color = '';
                        }, 2000);
                    });
                }
            });
        });
        </script>
        <style>
        .postbox-header { border-bottom: 1px solid #ccd0d4; background: #f6f7f7; }
        .postbox .hndle { cursor: default !important; }
        .postbox .hndle span { display: flex; align-items: center; gap: 8px; font-weight: 600; }
        .copy-shortcode-btn { transition: all 0.2s ease; min-width: 90px; }
        code { font-family: 'Consolas', 'Monaco', monospace; font-size: 13px; color: #c2410c; background: #fff7ed; padding: 2px 4px; border-radius: 3px; }
        </style>
    </div>
    <?php
}

/**
 * Alias for compatibility
 */
function photo_purchase_shortcodes_page() {
    photo_purchase_shortcode_helper_page();
}
