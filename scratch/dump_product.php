<?php
require_once('wp-load.php');
$post_id = 6361; // Tシャツ from the user's screenshot
$variations = get_post_meta($post_id, '_photo_variation_skus', true);
echo "--- VARIATIONS ---\n";
print_r($variations);
echo "\n--- CUSTOM OPTIONS ---\n";
$options = get_post_meta($post_id, '_photo_custom_options', true);
print_r($options);
