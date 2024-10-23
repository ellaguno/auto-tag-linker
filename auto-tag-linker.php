<?php
/*
Plugin Name: Auto Tag Linker
Description: Automatically links words in posts to their corresponding tag archives
Version: 1.43
Author: Eduardo Llaguno
*/

if (!defined('ABSPATH')) {
    exit;
}

class AutoTagLinker {
    private $options_group = 'auto_tag_linker_options';
    private $option_name = 'auto_tag_linker_settings';

    public function __construct() {
        add_filter('the_content', array($this, 'process_content'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post', array($this, 'save_meta_box_data'));
        add_action('wp_head', array($this, 'add_custom_css'));
    }

    public function get_option($key, $default = '') {
        $options = get_option($this->option_name, array());
        return isset($options[$key]) ? $options[$key] : $default;
    }

public function process_content($content) {
        global $post;
        
        if (!isset($post) || get_post_meta($post->ID, '_disable_auto_linking', true)) {
            return $content;
        }

        if (is_feed() || is_archive()) {
            return $content;
        }

        // Dividir el contenido preservando los tags HTML completos
        $parts = preg_split('/(<[^>]*>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        // Array para llevar registro de palabras ya procesadas
        $processed_words = array();
        
        foreach ($parts as $i => $part) {
            // Si esta parte comienza con < es un tag HTML, lo saltamos
            if (strpos($part, '<') === 0) {
                continue;
            }
            
            // Primero procesamos palabras personalizadas
            if ($this->get_option('enable_custom_words', true)) {
                $custom_words = $this->parse_custom_words($this->get_option('custom_words', ''));
                list($parts[$i], $words_processed) = $this->process_custom_words($part, $custom_words);
                // Guardamos las palabras que ya fueron procesadas
                $processed_words = array_merge($processed_words, $words_processed);
            }

            // Luego procesamos tags, pero evitando las palabras ya procesadas
            if ($this->get_option('enable_tags', true)) {
                $tags = get_tags(array('hide_empty' => false));
                if (!empty($tags)) {
                    $parts[$i] = $this->process_tags($parts[$i], $tags, $processed_words);
                }
            }
        }

        return implode('', $parts);
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

                $target = $new_window ? ' target="_blank"' : '';
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
            // Saltamos si la palabra ya fue procesada como palabra personalizada
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
                $target = $new_window ? ' target="_blank"' : '';
                return sprintf('<a href="%s"%s class="auto-tag-link">%s</a>', 
                    esc_url(get_tag_link($tag->term_id)),
                    $target,
                    esc_html($matches[1])
                );
            }, $text, $max_links);
        }

        return $text;
    }

    public function add_admin_menu() {
        add_options_page(
            'Auto Tag Linker Settings',
            'Auto Tag Linker',
            'manage_options',
            'auto-tag-linker',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting(
            $this->options_group,
            $this->option_name,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_options')
            )
        );
    }

    public function sanitize_options($input) {
        $sanitized = array();
        
        // Sanitizar max_links_per_tag
        $sanitized['max_links_per_tag'] = isset($input['max_links_per_tag']) ? 
            absint($input['max_links_per_tag']) : 1;

        // Sanitizar open_new_window
        $sanitized['open_new_window'] = isset($input['open_new_window']);

        // Sanitizar enable_tags
        $sanitized['enable_tags'] = isset($input['enable_tags']);

        // Sanitizar enable_custom_words
        $sanitized['enable_custom_words'] = isset($input['enable_custom_words']);

        // Sanitizar enabled_post_types
        $sanitized['enabled_post_types'] = isset($input['enabled_post_types']) && is_array($input['enabled_post_types']) ? 
            array_map('sanitize_text_field', $input['enabled_post_types']) : array('post');

        // Sanitizar custom_words
        $sanitized['custom_words'] = isset($input['custom_words']) ? 
            sanitize_textarea_field($input['custom_words']) : '';

        // Sanitizar blacklist
        $sanitized['blacklist'] = isset($input['blacklist']) ? 
            sanitize_textarea_field($input['blacklist']) : '';

        // Sanitizar custom_css
        $sanitized['custom_css'] = isset($input['custom_css']) ? 
            sanitize_textarea_field($input['custom_css']) : '';

        return $sanitized;
    }

    public function add_meta_box() {
        $screens = get_post_types(array('public' => true), 'names');
        foreach ($screens as $screen) {
            add_meta_box(
                'atl_meta_box',
                'Auto Tag Linker',
                array($this, 'render_meta_box'),
                $screen,
                'side'
            );
        }
    }

    public function render_meta_box($post) {
        $value = get_post_meta($post->ID, '_disable_auto_linking', true);
        wp_nonce_field('atl_meta_box', 'atl_meta_box_nonce');
        ?>
        <label>
            <input type="checkbox" name="disable_auto_linking" value="1" <?php checked($value, '1'); ?>>
            Desactivar auto-linking para este post
        </label>
        <?php
    }

    public function save_meta_box_data($post_id) {
        if (!isset($_POST['atl_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['atl_meta_box_nonce'], 'atl_meta_box')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $value = isset($_POST['disable_auto_linking']) ? '1' : '';
        update_post_meta($post_id, '_disable_auto_linking', $value);
    }

    public function add_custom_css() {
        $custom_css = $this->get_option('custom_css', '.auto-tag-link { text-decoration: none !important; color: inherit; } .auto-tag-link:hover { text-decoration: none !important; color: inherit; }');
        if (!empty($custom_css)) {
            echo "<style type='text/css'>\n" . esc_html($custom_css) . "\n</style>\n";
        }
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php settings_fields($this->options_group); ?>
                <table class="form-table" role="presentation">
                    <!-- Sección de Configuración General -->
                    <tr>
                        <th colspan="2">
                            <h2 class="title">Configuración General</h2>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="max_links_per_tag">Maximum links per word/tag</label>
                        </th>
                        <td>
                            <input type="number" id="max_links_per_tag" 
                                   name="<?php echo esc_attr($this->option_name); ?>[max_links_per_tag]" 
                                   value="<?php echo esc_attr($this->get_option('max_links_per_tag', 1)); ?>" 
                                   min="1" max="10">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Open links in new window</th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($this->option_name); ?>[open_new_window]" 
                                       value="1" 
                                       <?php checked($this->get_option('open_new_window', false)); ?>>
                            </label>
                        </td>
                    </tr>

                    <!-- Post Types -->
                    <tr>
                        <th scope="row">Enable for Post Types</th>
                        <td>
                            <?php
                            $enabled_types = $this->get_option('enabled_post_types', array('post'));
                            $post_types = get_post_types(array('public' => true), 'objects');
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
                        </td>
                    </tr>

                    <!-- Sección de Fuentes de Enlaces -->
                    <tr>
                        <th colspan="2">
                            <h2 class="title">Fuentes de Enlaces</h2>
                        </th>
                    </tr>

                    <tr>
                        <th scope="row">Habilitar Enlaces</th>
                        <td>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($this->option_name); ?>[enable_tags]" 
                                       value="1" 
                                       <?php checked($this->get_option('enable_tags', true)); ?>>
                                Usar etiquetas internas de WordPress
                            </label>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($this->option_name); ?>[enable_custom_words]" 
                                       value="1" 
                                       <?php checked($this->get_option('enable_custom_words', true)); ?>>
                                Usar lista personalizada de palabras
                            </label>
                        </td>
                    </tr>

                    <!-- Lista de Palabras Personalizadas -->
                    <tr>
                        <th scope="row">
                            <label for="custom_words">Lista de Palabras</label>
                        </th>
                        <td>
                        <textarea id="custom_words" 
                                      name="<?php echo esc_attr($this->option_name); ?>[custom_words]" 
                                      rows="10" 
                                      class="large-text code"
                                      placeholder="palabra1|https://ejemplo.com/url1&#10;palabra2|&#10;palabra3|https://ejemplo.com/url3"><?php 
                                echo esc_textarea($this->get_option('custom_words', '')); 
                            ?></textarea>
                            <p class="description">
                                Formato: una palabra por línea.<br>
                                Para URLs personalizados: palabra|URL<br>
                                Sin URL: la palabra se vinculará a la búsqueda interna<br>
                                Ejemplo:<br>
                                WordPress|https://wordpress.org<br>
                                PHP
                            </p>
                        </td>
                    </tr>

                    <!-- Blacklist -->
                    <tr>
                        <th scope="row">
                            <label for="blacklist">Blacklist</label>
                        </th>
                        <td>
                            <textarea id="blacklist" 
                                      name="<?php echo esc_attr($this->option_name); ?>[blacklist]" 
                                      rows="5" 
                                      class="large-text code"
                                      placeholder="Una palabra por línea"><?php 
                                echo esc_textarea($this->get_option('blacklist', '')); 
                            ?></textarea>
                            <p class="description">Palabras que no se convertirán en enlaces. Una por línea.</p>
                        </td>
                    </tr>

                    <!-- Custom CSS -->
                    <tr>
                        <th scope="row">
                            <label for="custom_css">Custom CSS</label>
                        </th>
                        <td>
                            <textarea id="custom_css" 
                                      name="<?php echo esc_attr($this->option_name); ?>[custom_css]" 
                                      rows="5" 
                                      class="large-text code"><?php 
                                echo esc_textarea($this->get_option('custom_css', '.auto-tag-link { text-decoration: none !important; color: inherit; } .auto-tag-link:hover { text-decoration: none !important; color: inherit; }')); 
                            ?></textarea>
                            <p class="description">Personaliza el estilo de los enlaces automáticos.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

// Inicializar el plugin
new AutoTagLinker();
