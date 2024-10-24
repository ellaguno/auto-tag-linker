<?php
/**
 * Uninstall script for Auto Tag Linker
 *
 * @package Auto_Tag_Linker
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('auto_tag_linker_settings');

// Get all post types
$post_types = get_post_types(array('public' => true));

// Process deletion in batches
foreach ($post_types as $post_type) {
    $batch_size = 100; // Process posts in smaller batches
    $offset = 0;
    
    do {
        $posts = get_posts(array(
            'post_type'      => $post_type,
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'no_found_rows'  => true, // Optimización adicional
            'orderby'        => 'ID', // Ordenamiento más eficiente
            'order'          => 'ASC'
        ));

        if (!empty($posts)) {
            foreach ($posts as $post_id) {
                delete_post_meta($post_id, '_disable_auto_linking');
            }
        }

        $offset += $batch_size;
    } while (!empty($posts));
}

// Clear any cached data
wp_cache_flush();
