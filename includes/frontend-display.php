<?php
/**
 * Frontend Display for Simple EC (Event Navigation Version)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode: [photo_gallery]
 * Supports 'event' attribute OR dynamic 'event_slug' URL parameter.
 */
function photo_purchase_gallery_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'event' => '', // Default event slug via attribute
    ), $atts);

    // Search keyword
    $search_query = isset($_GET['ec_search']) ? sanitize_text_field($_GET['ec_search']) : '';
    // Category slug (ec_cat or event_slug)
    $event_slug = isset($_GET['ec_cat']) ? sanitize_text_field($_GET['ec_cat']) : (isset($_GET['event_slug']) ? sanitize_text_field($_GET['event_slug']) : $atts['event']);
    // Sort
    $sort = isset($_GET['ec_sort']) ? sanitize_text_field($_GET['ec_sort']) : 'newness';

    $args = array(
        'post_type' => 'photo_product',
        'posts_per_page' => -1,
    );

    // Sorting Logic
    switch ($sort) {
        case 'price_asc':
            $args['meta_key'] = '_photo_price_l';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'ASC';
            break;
        case 'price_desc':
            $args['meta_key'] = '_photo_price_l';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'DESC';
            break;
        case 'name_asc':
            $args['orderby'] = 'title';
            $args['order'] = 'ASC';
            break;
        case 'newness':
        default:
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            break;
    }

    if (!empty($search_query)) {
        $args['s'] = $search_query;
    }

    if (!empty($event_slug)) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'photo_event',
                'field' => 'slug',
                'terms' => $event_slug,
            ),
        );
    }

    $query = new WP_Query($args);

    ob_start();

    if ($query->have_posts()) {
        ?>
        <div class="ec-gallery-controls-panel" style="background: #fff; padding: 30px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); margin-bottom: 40px; border: 1px solid #eee;">
            <!-- Search & Actions Row -->
            <div style="display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap;">
                <form method="get" class="ec-search-form" style="flex: 1; min-width: 250px; position:relative;">
                    <?php
                    if (!empty($event_slug)) {
                        echo '<input type="hidden" name="ec_cat" value="' . esc_attr($event_slug) . '">';
                    }
                    ?>
                    <input type="text" name="ec_search" value="<?php echo esc_attr($search_query); ?>" 
                        placeholder="<?php _e('キーワードで検索...', 'photo-purchase'); ?>"
                        style="width: 100%; padding: 14px 50px 14px 25px; border-radius: 40px; border: 1px solid #efefef; background: #fbfbfb; font-size: 16px; outline: none; transition: all 0.3s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    <button type="submit" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: var(--pp-primary); color: #fff; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; display:flex; align-items:center; justify-content:center; transition: opacity 0.3s;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </button>
                    <style>
                        .ec-search-form input:focus { border-color: var(--pp-primary); background: #fff; box-shadow: 0 0 0 4px rgba(var(--pp-primary-rgb), 0.1); }
                        .ec-search-form button:hover { opacity: 0.9; }
                    </style>
                </form>

                <div class="fav-filter-wrap" style="display:flex; gap:10px;">
                    <!-- Sort Selector -->
                    <div class="ec-sort-selector-wrap">
                        <select id="ec-sort-selector" style="padding: 12px 20px; border-radius: 40px; border: 1px solid #efefef; background: #fff; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s; outline:none; appearance:none; padding-right:40px; background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20width%3D%2220%22%20height%3D%2220%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23777%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 15px center;">
                            <option value="newness" <?php selected($sort, 'newness'); ?>><?php _e('新着順', 'photo-purchase'); ?></option>
                            <option value="price_asc" <?php selected($sort, 'price_asc'); ?>><?php _e('価格の安い順', 'photo-purchase'); ?></option>
                            <option value="price_desc" <?php selected($sort, 'price_desc'); ?>><?php _e('価格の高い順', 'photo-purchase'); ?></option>
                            <option value="name_asc" <?php selected($sort, 'name_asc'); ?>><?php _e('名前順 (昇順)', 'photo-purchase'); ?></option>
                        </select>
                        <script>
                        jQuery(document).ready(function($) {
                            $('#ec-sort-selector').on('change', function() {
                                var val = $(this).val();
                                var url = new URL(window.location.href);
                                url.searchParams.set('ec_sort', val);
                                window.location.href = url.toString();
                            });
                        });
                        </script>
                    </div>

                    <button class="fav-filter-btn" id="toggle-fav-filter" style="padding: 12px 25px; border-radius: 40px; border: 1px solid #efefef; background: #fff; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s; display: flex; align-items: center; gap: 8px;">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" style="color:#ff6b6b;"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                        <?php _e('お気に入り', 'photo-purchase'); ?>
                    </button>
                    <style>.fav-filter-btn:hover, #ec-sort-selector:hover { border-color: var(--pp-primary); background: #fbfbfb; }</style>
                </div>
            </div>

            <!-- Category Chips Row -->
            <div class="ec-category-chips-row" style="border-top: 1px solid #f5f5f5; padding-top: 20px;">
                <div class="ec-category-chips" style="display: flex; gap: 12px; overflow-x: auto; padding-bottom: 5px; -webkit-overflow-scrolling: touch; scrollbar-width: none;">
                    <style>
                        .ec-category-chips::-webkit-scrollbar { display: none; }
                        .ec-chip { 
                            white-space: nowrap; padding: 10px 22px; border-radius: 30px; text-decoration: none; 
                            font-size: 14px; font-weight: 500; transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
                            background: #f7f7f7; color: #777; border: 1px solid transparent;
                        }
                        .ec-chip:hover { transform: translateY(-3px); background: #ffffff; border-color: #eee; box-shadow: 0 4px 15px rgba(0,0,0,0.08); color: #333; }
                        .ec-chip.active { background: var(--pp-primary); color: #fff !important; box-shadow: 0 8px 20px rgba(var(--pp-primary-rgb), 0.3); }
                    </style>
                    <?php
                    $terms = get_terms(array('taxonomy' => 'photo_event', 'hide_empty' => true));
                    
                    // "All" Chip
                    $all_class = empty($event_slug) ? 'active' : '';
                    $all_url = remove_query_arg('ec_cat');
                    ?>
                    <a href="<?php echo esc_url($all_url); ?>" class="ec-chip <?php echo $all_class; ?>">
                        <?php _e('すべて', 'photo-purchase'); ?>
                    </a>

                    <?php foreach ($terms as $t): 
                        $is_active = ($event_slug === $t->slug);
                        $chip_url = add_query_arg('ec_cat', $t->slug);
                        ?>
                        <a href="<?php echo esc_url($chip_url); ?>" class="ec-chip <?php echo $is_active ? 'active' : ''; ?>">
                            <?php echo esc_html($t->name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="ec-gallery-status" style="margin-bottom: 25px; display:flex; align-items:center; gap:10px;">
            <div style="flex:1; height:1px; background:#efefef;"></div>
            <div class="event-title">
                <?php
                if (!empty($search_query)) {
                    echo '<span style="color:#888; font-size:0.9em;">SEARCH RESULTS FOR:</span>';
                    echo '<h2 style="margin:5px 0 0; font-size:1.6rem; font-weight:800;">「' . esc_html($search_query) . '」</h2>';
                } elseif (!empty($event_slug)) {
                    $term = get_term_by('slug', $event_slug, 'photo_event');
                    if ($term) {
                        echo '<span style="color:#888; font-size:0.9em;">CATEGORY:</span>';
                        echo '<h2 style="margin:5px 0 0; font-size:1.6rem; font-weight:800;">' . esc_html($term->name) . '</h2>';
                    }
                } else {
                    echo '<h2 style="margin:0; font-size:1.6rem; font-weight:800;">' . __('すべての商品', 'photo-purchase') . '</h2>';
                }
                ?>
            </div>
            <div style="flex:1; height:1px; background:#efefef;"></div>
        </div>

        <?php
        $cols = get_option('photo_pp_gallery_columns', '3');
        $grid_style = "grid-template-columns: repeat({$cols}, 1fr);";
        ?>
        <div class="photo-purchase-gallery" style="<?php echo esc_attr($grid_style); ?>">
            <?php
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                // Fetch prices
                $enable_digital = get_option('photo_pp_enable_digital_sales', '1');
                $p_digital = ($enable_digital === '1') ? get_post_meta($post_id, '_photo_price_digital', true) : 0;
                if ($enable_digital === '1' && !$p_digital)
                    $p_digital = get_post_meta($post_id, '_photo_price', true); // fallback only if enabled
                $p_l = get_post_meta($post_id, '_photo_price_l', true);
                $p_2l = get_post_meta($post_id, '_photo_price_2l', true);

                // Subscription Info (v3.0.0)
                $is_sub = get_post_meta($post_id, '_photo_is_subscription', true) === '1';
                $p_sub = $is_sub ? get_post_meta($post_id, '_photo_price_subscription', true) : 0;
                $sub_interval = get_post_meta($post_id, '_photo_sub_interval', true) ?: 'month';
                $sub_interval_count = get_post_meta($post_id, '_photo_sub_interval_count', true) ?: 1;
                
                $is_sold_out = get_post_meta($post_id, '_photo_is_sold_out', true) === '1';
                $manage_stock = get_post_meta($post_id, '_photo_manage_stock', true) === '1';
                $stock_qty = intval(get_post_meta($post_id, '_photo_stock_qty', true));
                if ($manage_stock && $stock_qty <= 0) {
                    $is_sold_out = true;
                }

                $thumbnail = get_the_post_thumbnail_url($post_id, 'large');
                $full_image = get_the_post_thumbnail_url($post_id, 'full');
                $gallery_ids = get_post_meta($post_id, '_ec_gallery_ids', true);
                $gallery_urls = [];
                if ($gallery_ids) {
                    $ids = explode(',', $gallery_ids);
                    foreach ($ids as $id) {
                        $url = wp_get_attachment_image_url($id, 'large');
                        if ($url) $gallery_urls[] = $url;
                    }
                }
                // Add full image as the first gallery item
                array_unshift($gallery_urls, $full_image);
                $gallery_data = htmlspecialchars(json_encode($gallery_urls), ENT_QUOTES, 'UTF-8');
                $description = apply_filters('the_content', get_post_field('post_content', $post_id));
                ?>
                <div class="photo-item <?php echo $is_sold_out ? 'is-sold-out' : ''; ?>" data-id="<?php echo $post_id; ?>" 
                     data-description="<?php echo esc_attr($description); ?>"
                     data-gallery='<?php echo $gallery_data; ?>'
                     data-sold-out="<?php echo $is_sold_out ? '1' : '0'; ?>"
                     data-manage-stock="<?php echo $manage_stock ? '1' : '0'; ?>"
                     data-stock-qty="<?php echo $stock_qty; ?>">
                    <button class="fav-btn" data-id="<?php echo $post_id; ?>" title="<?php _e('お気に入りに追加', 'photo-purchase'); ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                    </button>
                    <?php if ($thumbnail): ?>
                        <div class="photo-thumb-wrap" style="position:relative; cursor:zoom-in;">
                            <img src="<?php echo esc_url($thumbnail); ?>" alt="<?php the_title_attribute(); ?>"
                                class="demo-photo lightbox-trigger" data-full="<?php echo esc_url($full_image); ?>">
                            <?php if ($is_sold_out): ?>
                                <div class="sold-out-badge" style="position: absolute; top: 10px; left: 10px; background: rgba(0,0,0,0.7); color: #fff; padding: 5px 12px; border-radius: 4px; font-weight: bold; font-size: 14px; z-index: 10; pointer-events: none;">売り切れ</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <h3><?php the_title(); ?></h3>

                    <div class="format-selection" style="margin-bottom: 10px;">
                        <select class="photo-format" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #ddd;">
                            <?php if ($p_digital > 0): ?>
                                <option value="digital" data-price="<?php echo $p_digital; ?>">
                                    <?php echo sprintf(__('ダウンロード - %d円', 'photo-purchase'), $p_digital); ?>
                                </option>
                            <?php endif; ?>
                            <?php if ($p_l > 0): ?>
                                <option value="l_size" data-price="<?php echo $p_l; ?>">
                                    <?php echo sprintf(__('配送品 - %s円', 'photo-purchase'), number_format($p_l)); ?>
                                </option>
                            <?php endif; ?>
                            <?php if ($is_sub && $p_sub > 0): 
                                $interval_labels = ['day' => '日', 'week' => '週', 'month' => 'ヶ月', 'year' => '年'];
                                $label = ($sub_interval_count > 1) ? $sub_interval_count . $interval_labels[$sub_interval] : $interval_labels[$sub_interval];
                                $sub_req = get_post_meta($post_id, '_photo_sub_requires_shipping', true) === '1' ? '1' : '0';
                                ?>
                                <option value="subscription" data-price="<?php echo $p_sub; ?>" data-is-sub="1" data-sub-requires-shipping="<?php echo $sub_req; ?>">
                                    <?php echo sprintf(__('サブスクリプション (%s) - %s円', 'photo-purchase'), $label, number_format($p_sub)); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="photo-price-anim-wrap" style="margin-bottom: 15px; font-size: 1.5rem; font-weight: 800; color: var(--pp-primary);">
                        <span class="price-symbol">¥</span><span class="photo-price-val">0</span>
                    </div>

                    <?php
                    $custom_options = get_post_meta($post_id, '_photo_custom_options', true);
                    if (is_array($custom_options) && !empty($custom_options)):
                        // Group options by their 'group' field
                        $grouped_options = array();
                        foreach ($custom_options as $opt) {
                            $g = !empty($opt['group']) ? $opt['group'] : 'オプション';
                            if (!isset($grouped_options[$g])) {
                                $grouped_options[$g] = array('items' => array(), 'required' => false);
                            }
                            $grouped_options[$g]['items'][] = $opt;
                            if (!empty($opt['required']) && $opt['required'] === '1') {
                                $grouped_options[$g]['required'] = true;
                            }
                        }

                        echo '<div class="custom-options-wrap" style="margin-top: 15px; border-top: 1px dashed #eee; padding-top: 15px;">';
                        foreach ($grouped_options as $group_name => $group_data):
                            $is_required = $group_data['required'];
                            ?>
                            <div class="attribute-group-wrap" data-required="<?php echo $is_required ? '1' : '0'; ?>" data-group-name="<?php echo esc_attr($group_name); ?>" style="margin-bottom: 12px;">
                                <div style="font-weight: bold; margin-bottom: 5px; font-size: 0.9em; color: #555;">
                                    ▼ <?php echo esc_html($group_name); ?>
                                    <?php if ($is_required): ?>
                                        <span style="background: #e74c3c; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 3px; margin-left: 5px; vertical-align: middle;">必須</span>
                                    <?php endif; ?>
                                </div>
                                <div class="custom-options-group" style="display: flex; flex-wrap: wrap; gap: 10px;">
                                    <?php foreach ($group_data['items'] as $opt): 
                                        $type = $opt['type'] ?? 'radio';
                                        $price = intval($opt['price'] ?? 0);
                                        $price_label = ($price > 0) ? " (+" . number_format($price) . "円)" : "";
                                        $input_name = 'custom_opt_' . md5($group_name);
                                    ?>
                                        <label style="cursor: pointer; background: #f9f9f9; padding: 5px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9em; display: flex; align-items: center; gap: 5px;">
                                            <input type="<?php echo esc_attr($type); ?>" 
                                                   name="<?php echo esc_attr($input_name); ?>" 
                                                   value="<?php echo esc_attr($price); ?>" 
                                                   data-name="<?php echo esc_attr($opt['name']); ?>" 
                                                   data-group="<?php echo esc_attr($group_name); ?>" 
                                                   class="custom-opt-check"
                                                   <?php if($type === 'radio' && count($group_data['items']) === 1) echo 'checked'; ?>>
                                            <?php echo esc_html($opt['name'] . $price_label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach;
                        echo '</div>';
                    endif;
                    ?>
                    <div class="quantity-select"
                        style="margin-bottom: 15px; display: flex; align-items: center; justify-content: center; gap: 10px;">
                        <label style="font-size: 0.9rem;"><?php _e('数量:', 'photo-purchase'); ?></label>
                        <input type="number" class="photo-qty" value="1" min="1"
                            style="width: 70px; padding: 8px; border-radius: 8px; border: 1px solid #ddd;">
                    </div>

                    <button class="add-to-cart-btn" data-id="<?php echo $post_id; ?>" <?php echo $is_sold_out ? 'disabled' : ''; ?>>
                        <?php echo $is_sold_out ? __('売り切れ', 'photo-purchase') : __('カートに入れる', 'photo-purchase'); ?>
                    </button>
                </div>
                <?php
            }
            ?>
        </div>
        <!-- Quickview Modal -->
        <div id="photo-lightbox" class="photo-lightbox ec-quickview-modal">
            <div class="photo-lightbox-content ec-quickview-container">
                <span class="photo-lightbox-close">&times;</span>
                <div class="ec-quickview-layout">
                    <div class="ec-quickview-left">
                        <div class="ec-quickview-main-image">
                            <img id="lightbox-img" src="" alt="Main Image">
                        </div>
                        <div id="ec-quickview-gallery" class="ec-quickview-gallery">
                            <!-- Sub images will be injected here -->
                        </div>
                    </div>
                    <div class="ec-quickview-right">
                        <h2 id="ec-quickview-title"></h2>
                        <div id="ec-quickview-description" class="ec-quickview-description prose"></div>
                        
                        <div class="ec-quickview-meta">
                            <div class="ec-quickview-format-wrap">
                                <select id="ec-quickview-format" class="photo-format"></select>
                            </div>
                            <div class="ec-quickview-qty-wrap" style="margin-top: 15px;">
                                <label><?php _e('数量:', 'photo-purchase'); ?></label>
                                <input type="number" id="ec-quickview-qty" class="photo-qty" value="1" min="1">
                            </div>
                        </div>

                        <div class="ec-quickview-price-wrap" style="margin: 20px 0;">
                            <div class="photo-price-anim-wrap" style="font-size: 2rem; font-weight: 800; color: var(--pp-primary);">
                                <span class="price-symbol">¥</span><span class="photo-price-val">0</span>
                            </div>
                        </div>

                        <div id="ec-quickview-options-container">
                            <!-- Options will be cloned from .photo-item by JS -->
                        </div>

                        <div class="ec-quickview-actions" style="margin-top: 25px;">
                            <button id="ec-quickview-add-to-cart" class="add-to-cart-btn">
                                <?php _e('カートに入れる', 'photo-purchase'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    } else {
        echo '<div style="padding:80px 20px; text-align:center; background:#f9f9f9; border-radius:15px; border: 1px dashed #ddd; margin-top:20px;">';
        echo '<div style="font-size: 50px; margin-bottom: 20px;">🔍</div>';
        if (!empty($search_query)) {
            echo '<h3 style="margin:0 0 10px;">' . sprintf(__('「%s」に一致する商品は見つかりませんでした。', 'photo-purchase'), esc_html($search_query)) . '</h3>';
            echo '<p style="color:#666;">別のキーワードで試すか、カテゴリーを選択してください。</p>';
        } else {
            echo '<h3 style="margin:0 0 10px;">' . __('このカテゴリーの商品はまだありません。', 'photo-purchase') . '</h3>';
        }
        echo '<a href="' . esc_url(remove_query_arg(array('ec_search', 'ec_cat'))) . '" class="button" style="margin-top:20px; display:inline-block; padding:10px 25px; background:#666; color:#fff; text-decoration:none; border-radius:25px;">すべて表示に戻す</a>';
        echo '</div>';
    }

    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('ec_gallery', 'photo_purchase_gallery_shortcode');
