<?php
/*
Plugin Name: Auto Tag Linker
Plugin URI: https://sesolibre.com
Description: Automatically links words in posts to their corresponding tag archives
Version: 1.43
Author: Eduardo Llaguno
Author URI: https://sesolibre.com
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: auto-tag-linker
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

class AutoTagLinker {
    private $options_group = 'auto_tag_linker_options';
    private $option_name = 'auto_tag_linker_settings';

    public function __construct() {
        // Initialize the plugin
        add_action('init', array($this, 'init'));
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('add_meta_boxes', array($this, 'add_meta_box'));
            add_action('save_post', array($this, 'save_meta_box_data'));
        }

        // Front-end hooks
        if (!is_admin()) {
            add_filter('the_content', array($this, 'process_content'));
            add_action('wp_head', array($this, 'add_custom_css'));
        }
    }

    public function init() {
        load_plugin_textdomain(
            'auto-tag-linker',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    public function add_admin_menu() {
        add_options_page(
            'Auto Tag Linker Settings', // Page title
            'Auto Tag Linker',          // Menu title
            'manage_options',           // Capability
            'auto-tag-linker',         // Menu slug
            array($this, 'render_settings_page') // Callback function
        );
    }

    public function register_settings() {
        register_setting($this->options_group, $this->option_name);

        // Add default options if they don't exist
        if (false === get_option($this->option_name)) {
            $defaults = array(
                'max_links_per_tag' => 1,
                'open_new_window' => false,
                'enable_tags' => true,
                'enable_custom_words' => true,
                'enabled_post_types' => array('post'),
                'custom_words' => '',
                'blacklist' => '',
                'custom_css' => '.auto-tag-link { text-decoration: none !important; color: inherit; } .auto-tag-link:hover { text-decoration: underline !important; }'
            );
            update_option($this->option_name, $defaults);
        }
    }

    public function get_option($key, $default = '') {
        $options = get_option($this->option_name);
        return isset($options[$key]) ? $options[$key] : $default;
    }

    public function process_content($content) {
        // Check if we're in the main query and it's a single post
        if (!is_main_query() || !is_singular()) {
            return $content;
        }

        global $post;
        
        // Check if auto-linking is disabled for this post
        if (get_post_meta($post->ID, '_disable_auto_linking', true)) {
            return $content;
        }

        // Get enabled post types
        $enabled_types = $this->get_option('enabled_post_types', array('post'));
        if (!in_array($post->post_type, $enabled_types)) {
            return $content;
        }

        // Process the content
        return $this->process_text($content);
    }

    private function process_text($content) {
        // Crear un array para almacenar temporalmente los enlaces existentes
        $existing_links = array();
        
        // Guardar los enlaces existentes con un placeholder temporal
        $content = preg_replace_callback('/<a\b[^>]*>(.*?)<\/a>/i', function($matches) use (&$existing_links) {
            $placeholder = '%%EXISTING_LINK_' . count($existing_links) . '%%';
            $existing_links[] = $matches[0];
            return $placeholder;
        }, $content);

        // Ahora procesar el contenido normal
        $parts = preg_split('/(<[^>]*>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        if (!is_array($parts)) {
            // Restaurar los enlaces existentes antes de retornar
            return $this->restore_existing_links($content, $existing_links);
        }

        $processed_words = array();
        
        foreach ($parts as $i => $part) {
            // Skip HTML tags
            if (strpos($part, '<') === 0) {
                continue;
            }
            
            // Process custom words first if enabled
            if ($this->get_option('enable_custom_words', true)) {
                $custom_words = $this->parse_custom_words($this->get_option('custom_words', ''));
                list($parts[$i], $words_processed) = $this->process_custom_words($part, $custom_words);
                $processed_words = array_merge($processed_words, $words_processed);
            }

            // Then process tags if enabled
            if ($this->get_option('enable_tags', true)) {
                $tags = get_tags(array('hide_empty' => false));
                if (!empty($tags)) {
                    $parts[$i] = $this->process_tags($parts[$i], $tags, $processed_words);
                }
            }
        }

        $processed_content = implode('', $parts);
        
        // Restaurar los enlaces existentes
        return $this->restore_existing_links($processed_content, $existing_links);
    }

    private function restore_existing_links($content, $existing_links) {
        foreach ($existing_links as $i => $link) {
            $content = str_replace('%%EXISTING_LINK_' . $i . '%%', $link, $content);
        }
        return $content;
    }
    
    private function parse_custom_words($custom_words_text) {
        $words = array();
        $lines = explode("\n", $custom_words_text);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = array_map('trim', explode('|', $line));
            if (!empty($parts[0])) {
                $words[] = array(
                    'word' => $parts[0],
                    'url' => isset($parts[1]) && !empty($parts[1]) ? $parts[1] : ''
                );
            }
        }
        
        return $words;
    }
    
    private function process_custom_words($text, $custom_words) {
        $blacklist = array_map('trim', explode("\n", $this->get_option('blacklist', '')));
        $max_links = absint($this->get_option('max_links_per_tag', 1));
        $new_window = $this->get_option('open_new_window', false);
        $processed_words = array();

        foreach ($custom_words as $word_data) {
            if (in_array(strtolower($word_data['word']), array_map('strtolower', $blacklist))) {
                continue;
            }

            $count = 0;
            $word = preg_quote($word_data['word'], '/');
            $pattern = '/\b(' . $word . ')\b/u';
            
            $text = preg_replace_callback($pattern, function($matches) use ($word_data, $new_window, &$count, $max_links) {
                if ($count >= $max_links) {
                    return $matches[0];
                }
                $count++;
                
                $url = !empty($word_data['url']) ? 
                    $word_data['url'] : 
                    home_url('/?s=' . urlencode($matches[1]));

                $target = $new_window ? ' target="_blank" rel="noopener noreferrer"' : '';
                return sprintf('<a href="%s"%s class="auto-tag-link">%s</a>', 
                    esc_url($url),
                    $target,
                    esc_html($matches[1])
                );
            }, $text, $max_links);

            if ($count > 0) {
                $processed_words[] = strtolower($word_data['word']);
            }
        }

        return array($text, $processed_words);
    }

    private function process_tags($text, $tags, $processed_words) {
        $blacklist = array_map('trim', explode("\n", $this->get_option('blacklist', '')));
        $max_links = absint($this->get_option('max_links_per_tag', 1));
        $new_window = $this->get_option('open_new_window', false);

        foreach ($tags as $tag) {
            if (in_array(strtolower($tag->name), $processed_words)) {
                continue;
            }

            if (in_array(strtolower($tag->name), array_map('strtolower', $blacklist))) {
                continue;
            }

            $count = 0;
            $tag_name = preg_quote($tag->name, '/');
            $pattern = '/\b(' . $tag_name . ')\b/u';
            
            $text = preg_replace_callback($pattern, function($matches) use ($tag, $new_window, &$count, $max_links) {
                if ($count >= $max_links) {
                    return $matches[0];
                }
                $count++;
                $target = $new_window ? ' target="_blank" rel="noopener noreferrer"' : '';
                return sprintf('<a href="%s"%s class="auto-tag-link">%s</a>', 
                    esc_url(get_tag_link($tag->term_id)),
                    $target,
                    esc_html($matches[1])
                );
            }, $text, $max_links);
        }

        return $text;
    }

    public function add_custom_css() {
        $custom_css = $this->get_option('custom_css', '');
        if (!empty($custom_css)) {
            // Default styles if none set
            if (trim($custom_css) === '') {
                $custom_css = '.auto-tag-link { text-decoration: none !important; color: inherit; } 
                              .auto-tag-link:hover { text-decoration: underline !important; }';
            }
            
            // Primero limpiamos el CSS y luego lo escapamos
            $clean_css = wp_strip_all_tags($custom_css);
            ?>
            <!-- Auto Tag Linker Custom CSS -->
            <style type="text/css">
                <?php echo esc_html($clean_css); ?>
            </style>
            <?php
        }
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'auto-tag-linker'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields($this->options_group);
                $options = get_option($this->option_name);
                ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th colspan="2">
                            <h2 class="title"><?php esc_html_e('General Settings', 'auto-tag-linker'); ?></h2>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="max_links_per_tag"><?php esc_html_e('Maximum links per word/tag', 'auto-tag-linker'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="max_links_per_tag" 
                                   name="<?php echo esc_attr($this->option_name); ?>[max_links_per_tag]" 
                                   value="<?php echo esc_attr($this->get_option('max_links_per_tag', 1)); ?>" 
                                   min="1" max="10">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Open links in new window', 'auto-tag-linker'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($this->option_name); ?>[open_new_window]" 
                                       value="1" 
                                       <?php checked($this->get_option('open_new_window', false)); ?>>
                                <?php esc_html_e('Open in new window', 'auto-tag-linker'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Link Sources', 'auto-tag-linker'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($this->option_name); ?>[enable_tags]" 
                                       value="1" 
                                       <?php checked($this->get_option('enable_tags', true)); ?>>
                                <?php esc_html_e('Enable tag linking', 'auto-tag-linker'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($this->option_name); ?>[enable_custom_words]" 
                                       value="1" 
                                       <?php checked($this->get_option('enable_custom_words', true)); ?>>
                                <?php esc_html_e('Enable custom word linking', 'auto-tag-linker'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Post Types', 'auto-tag-linker'); ?></th>
                        <td>
                            <?php
                            $post_types = get_post_types(array('public' => true), 'objects');
                            $enabled_types = $this->get_option('enabled_post_types', array('post'));
                            foreach ($post_types as $post_type) :
                            ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" 
                                           name="<?php echo esc_attr($this->option_name); ?>[enabled_post_types][]" 
                                           value="<?php echo esc_attr($post_type->name); ?>"
                                           <?php checked(in_array($post_type->name, $enabled_types)); ?>>
                                    <?php echo esc_html($post_type->label); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php esc_html_e('Select which post types should have auto-linking enabled.', 'auto-tag-linker'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="custom_words"><?php esc_html_e('Custom Words', 'auto-tag-linker'); ?></label>
                        </th>
                        <td>
                            <textarea id="custom_words" 
                                     name="<?php echo esc_attr($this->option_name); ?>[custom_words]" 
                                     rows="10" 
                                     class="large-text code"><?php 
                                echo esc_textarea($this->get_option('custom_words', '')); 
                            ?></textarea>
                            <p class="description">
                                <?php 
                                esc_html_e('One word per line. Format: word|URL (URL is optional)', 'auto-tag-linker');
                                echo '<br>';
                                esc_html_e('Example:', 'auto-tag-linker');
                                echo '<br>';
                                echo 'WordPress|https://wordpress.org<br>PHP';
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="blacklist"><?php esc_html_e('Blacklist', 'auto-tag-linker'); ?></label>
                        </th>
                        <td>
                            <textarea id="blacklist" 
                                     name="<?php echo esc_attr($this->option_name); ?>[blacklist]" 
                                     rows="5" 
                                     class="large-text code"><?php 
                                echo esc_textarea($this->get_option('blacklist', '')); 
                            ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Words to exclude from linking. One per line.', 'auto-tag-linker'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="custom_css"><?php esc_html_e('Custom CSS', 'auto-tag-linker'); ?></label>
                        </th>
                        <td>
                            <textarea id="custom_css" 
                                     name="<?php echo esc_attr($this->option_name); ?>[custom_css]" 
                                     rows="5" 
                                     class="large-text code"><?php 
                                echo esc_textarea($this->get_option('custom_css', '.auto-tag-link { text-decoration: none !important; color: inherit; } .auto-tag-link:hover { text-decoration: underline !important; }')); 
                            ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Custom CSS for styling the auto-generated links.', 'auto-tag-linker'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('Save Changes', 'auto-tag-linker')); ?>
            </form>
        </div>
        <?php
    }

    public function render_meta_box($post) {
        wp_nonce_field('atl_meta_box', 'atl_meta_box_nonce');
        $value = get_post_meta($post->ID, '_disable_auto_linking', true);
        ?>
        <label>
            <input type="checkbox" name="disable_auto_linking" value="1" <?php checked($value, '1'); ?>>
            <?php esc_html_e('Disable auto-linking for this post', 'auto-tag-linker'); ?>
        </label>
        <?php
    }

    public function add_meta_box() {
        $screens = get_post_types(array('public' => true), 'names');
        foreach ($screens as $screen) {
            add_meta_box(
                'atl_meta_box',
                __('Auto Tag Linker', 'auto-tag-linker'),
                array($this, 'render_meta_box'),
                $screen,
                'side'
            );
        }
    }


    public function save_meta_box_data($post_id) {
        // Verify nonce is set
        if (!isset($_POST['atl_meta_box_nonce'])) {
            return;
        }

        // Clean and validate nonce
        $nonce = sanitize_text_field(wp_unslash($_POST['atl_meta_box_nonce']));
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'atl_meta_box')) {
            return;
        }

        // Check if autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Sanitize and save the meta value
        $disable_auto_linking = isset($_POST['disable_auto_linking']) ? 
            sanitize_text_field(wp_unslash($_POST['disable_auto_linking'])) : '';
        
        update_post_meta($post_id, '_disable_auto_linking', $disable_auto_linking);
    }
}

// Initialize the plugin
function initialize_auto_tag_linker() {
    new AutoTagLinker();
}
add_action('plugins_loaded', 'initialize_auto_tag_linker');

// Register activation hook
register_activation_hook(__FILE__, 'atl_activate');
function atl_activate() {
    // Add default options on activation
    if (false === get_option('auto_tag_linker_settings')) {
        $defaults = array(
            'max_links_per_tag' => 1,
            'open_new_window' => false,
            'enable_tags' => true,
            'enable_custom_words' => true,
            'enabled_post_types' => array('post'),
            'custom_words' => '',
            'blacklist' => '',
            'custom_css' => '.auto-tag-link { text-decoration: none !important; color: inherit; } .auto-tag-link:hover { text-decoration: underline !important; }'
        );
        add_option('auto_tag_linker_settings', $defaults);
    }
    flush_rewrite_rules();
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'atl_deactivate');
function atl_deactivate() {
    flush_rewrite_rules();
}
