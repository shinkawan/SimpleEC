<?php
/**
 * Event Pages Logic for Simple EC
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Meta Box for Event Selection
 */
function photo_purchase_add_event_page_meta_box()
{
    add_meta_box(
        'photo_event_selection',
        __('表示するイベントの選択', 'photo-purchase'),
        'photo_purchase_event_selection_callback',
        'photo_event_page',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'photo_purchase_add_event_page_meta_box');

/**
 * Meta Box Callback
 */
function photo_purchase_event_selection_callback($post)
{
    wp_nonce_field('photo_event_page_save_meta', 'photo_event_page_meta_nonce');

    $selected_event = get_post_meta($post->ID, '_photo_event_slug', true);
    $events = get_terms(array(
        'taxonomy' => 'photo_event',
        'hide_empty' => false,
    ));

    echo '<select name="photo_event_slug" style="width:100%;">';
    echo '<option value="">' . __('イベントを選択してください', 'photo-purchase') . '</option>';
    if (!empty($events) && !is_wp_error($events)) {
        foreach ($events as $event) {
            echo '<option value="' . esc_attr($event->slug) . '" ' . selected($selected_event, $event->slug, false) . '>' . esc_html($event->name) . '</option>';
        }
    }
    echo '</select>';
    echo '<p class="description">' . __('このページに表示する写真のグループ（イベント）を選択してください。', 'photo-purchase') . '</p>';
}

/**
 * Save Meta Box Data
 */
/**
 * Save Meta Box Data
 */
function photo_purchase_save_event_page_meta($post_id)
{
    // Minimum check: Don't check nonce for a moment to see if it's the issue
    if (get_post_type($post_id) !== 'photo_event_page') {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (isset($_REQUEST['photo_event_slug'])) {
        $slug = sanitize_text_field($_REQUEST['photo_event_slug']);
        update_post_meta($post_id, '_photo_event_slug', $slug);
    }
}
add_action('save_post', 'photo_purchase_save_event_page_meta', 99);

/**
 * Automatically Append Gallery to Event Page Content
 */
function photo_purchase_append_gallery_to_event_page($content)
{
    if (is_singular('photo_event_page')) {
        // If password protected, WP standard handles the form. 
        // We only append if password is NOT required.
        if (post_password_required()) {
            return $content;
        }

        $event_slug = get_post_meta(get_the_ID(), '_photo_event_slug', true);
        if ($event_slug) {
            $content .= do_shortcode('[photo_gallery event="' . esc_attr($event_slug) . '"]');
        }
    }
    return $content;
}
add_filter('the_content', 'photo_purchase_append_gallery_to_event_page');
