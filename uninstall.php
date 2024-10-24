<?php
// Si WordPress no llamó este archivo, abortamos
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// Eliminar todas las opciones del plugin
delete_option('auto_tag_linker_settings');

// Obtener todos los post types
$post_types = get_post_types(array('public' => true), 'names');

// Eliminar los meta datos de todos los posts
global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
        '_disable_auto_linking'
    )
);

// Limpiar el caché de transients si existiera
delete_transient('atl_custom_words_cache');

// Si hay tablas personalizadas (en este caso no hay), se eliminarían así:
// $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mi_tabla_personalizada");

// Opcionalmente, limpiar las opciones de usuario si las hubiera
/*
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        'atl_%'
    )
);
*/
