<?php
/**
 * Template for Photo Product Archives and Taxonomy
 */

get_header();
?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">
        <div class="container" style="max-width: 1200px; margin: 40px auto; padding: 0 20px;">
            <?php
            // Output the gallery shortcode
            // If it's a taxonomy archive, the shortcode will detect the current term via GET or queried object
            echo photo_purchase_gallery_shortcode(array());
            ?>
        </div>
    </main>
</div>

<?php
get_footer();
