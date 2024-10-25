<?php
/*
Plugin Name: Footnotes Made Easy
Plugin URI: https://github.com/wpcorner/footnotes-made-easy/
Description: Allows post authors to easily add and manage footnotes in posts.
Version: 3.0.4
Author: Patrick Lumumba
Author URI: https://wpcorner.co/author/patrick-l/
Text Domain: footnotes-made-easy
*/

if (!defined('ABSPATH')) {
    exit;
}

function fme_enqueue_styles() {
    wp_enqueue_style('gbad-styles', plugin_dir_url(__FILE__) . 'css/gbad.css', array(), filemtime(plugin_dir_path(__FILE__) . 'css/gbad.css'));
}
add_action('admin_enqueue_scripts', 'fme_enqueue_styles');

$swas_wp_footnotes = new swas_wp_footnotes();

class swas_wp_footnotes {
    private $current_options;
    private $default_options;
    private $footnotes_content = '';
    private $styles;

    const OPTIONS_VERSION = '5';

    function __construct() {
        $this->styles = array(
            'decimal' => '1,2...10',
            'decimal-leading-zero' => '01, 02...10',
            'lower-alpha' => 'a,b...j',
            'upper-alpha' => 'A,B...J',
            'lower-roman' => 'i,ii...x',
            'upper-roman' => 'I,II...X',
            'symbol' => 'Symbol'
        );

        $this->default_options = array(
            'superscript' => true,
            'pre_backlink' => ' [',
            'backlink' => '&#8617;',
            'post_backlink' => ']',
            'pre_identifier' => '',
            'inner_pre_identifier' => '',
            'list_style_type' => 'decimal',
            'list_style_symbol' => '&dagger;',
            'inner_post_identifier' => '',
            'post_identifier' => '',
            'pre_footnotes' => '',
            'post_footnotes' => '',
            'no_display_home' => false,
            'no_display_preview' => false,
            'no_display_archive' => false,
            'no_display_date' => false,
            'no_display_category' => false,
            'no_display_search' => false,
            'no_display_feed' => false,
            'combine_identical_notes' => true,
            'priority' => 11,
            'footnotes_open' => ' ((',
            'footnotes_close' => '))',
            'pretty_tooltips' => false,
            'version' => self::OPTIONS_VERSION
        );

        $this->current_options = get_option('swas_footnote_options');
        if (!$this->current_options) {
            $this->current_options = $this->default_options;
            update_option('swas_footnote_options', $this->current_options);
        } else {
            if (!isset($this->current_options['version']) || $this->current_options['version'] !== self::OPTIONS_VERSION) {
                foreach ($this->default_options as $key => $value) {
                    if (!isset($this->current_options[$key])) {
                        $this->current_options[$key] = $value;
                    }
                }
                $this->current_options['version'] = self::OPTIONS_VERSION;
                update_option('swas_footnote_options', $this->current_options);
            }
        }

        $footnotes_options = array();
        $post_array = $_POST;

        if (!empty($post_array['save_options']) && !empty($post_array['save_footnotes_made_easy_options'])) {
            $footnotes_options['superscript'] = isset($post_array['superscript']);
            $footnotes_options['pre_backlink'] = sanitize_text_field($post_array['pre_backlink']);
            $footnotes_options['backlink'] = sanitize_text_field($post_array['backlink']);
            $footnotes_options['post_backlink'] = sanitize_text_field($post_array['post_backlink']);
            $footnotes_options['pre_identifier'] = sanitize_text_field($post_array['pre_identifier']);
            $footnotes_options['inner_pre_identifier'] = sanitize_text_field($post_array['inner_pre_identifier']);
            $footnotes_options['list_style_type'] = sanitize_text_field($post_array['list_style_type']);
            $footnotes_options['inner_post_identifier'] = sanitize_text_field($post_array['inner_post_identifier']);
            $footnotes_options['post_identifier'] = sanitize_text_field($post_array['post_identifier']);
            $footnotes_options['list_style_symbol'] = sanitize_text_field($post_array['list_style_symbol']);
            $footnotes_options['pre_footnotes'] = $post_array['pre_footnotes'];
            $footnotes_options['post_footnotes'] = $post_array['post_footnotes'];
            $footnotes_options['no_display_home'] = isset($post_array['no_display_home']);
            $footnotes_options['no_display_preview'] = isset($post_array['no_display_preview']);
            $footnotes_options['no_display_archive'] = isset($post_array['no_display_archive']);
            $footnotes_options['no_display_date'] = isset($post_array['no_display_date']);
            $footnotes_options['no_display_category'] = isset($post_array['no_display_category']);
            $footnotes_options['no_display_search'] = isset($post_array['no_display_search']);
            $footnotes_options['no_display_feed'] = isset($post_array['no_display_feed']);
            $footnotes_options['combine_identical_notes'] = isset($post_array['combine_identical_notes']);
            $footnotes_options['priority'] = sanitize_text_field($post_array['priority']);
            $footnotes_options['footnotes_open'] = sanitize_text_field($post_array['footnotes_open']);
            $footnotes_options['footnotes_close'] = sanitize_text_field($post_array['footnotes_close']);
            $footnotes_options['pretty_tooltips'] = isset($post_array['pretty_tooltips']);

            update_option('swas_footnote_options', $footnotes_options);
            $this->current_options = $footnotes_options;
        }

        // Hook into both regular content and Elementor widgets
        add_filter('the_content', array($this, 'process_content'), $this->current_options['priority']);
        add_filter('widget_text', array($this, 'process_content'), $this->current_options['priority']);
        add_action('elementor/widget/render_content', array($this, 'process_content'), $this->current_options['priority']);
        add_action('admin_menu', array($this, 'add_options_page'));
        add_action('wp_head', array($this, 'insert_styles'));
        
        remove_shortcode('footnotes');
        add_shortcode('footnotes', array($this, 'shortcode_handler'));
        
        if ($this->current_options['pretty_tooltips']) {
            add_action('wp_enqueue_scripts', array($this, 'tooltip_scripts'));
        }

        add_filter('plugin_action_links', array($this, 'add_settings_link'), 10, 2);
        add_filter('plugin_row_meta', array($this, 'plugin_meta'), 10, 2);
    }

    function process_content($content) {
        // Process the content and replace footnote markers
        $processed_content = $this->process($content);
        return $processed_content;
    }

    function shortcode_handler() {
        // Return the footnotes content and clear it
        $footnotes = $this->footnotes_content;
        $this->footnotes_content = '';
        return $footnotes;
    }

    function process($content) {
        global $post;
        
        if (!$post) {
            return $content;
        }

        $start_number = (1 === preg_match('|<!\-\-startnum=(\d+)\-\->|', $content, $start_number_array)) ? $start_number_array[1] : 1;

        // Extract all footnotes
        if (!preg_match_all('/(' . preg_quote($this->current_options['footnotes_open'], '/') . ')(.*)(' .
            preg_quote($this->current_options['footnotes_close'], '/') . ')/Us', $content, $identifiers, PREG_SET_ORDER)) {
            return $content;
        }

        $display = true;
        if ($this->current_options['no_display_home'] && is_home()) $display = false;
        if ($this->current_options['no_display_archive'] && is_archive()) $display = false;
        if ($this->current_options['no_display_date'] && is_date()) $display = false;
        if ($this->current_options['no_display_category'] && is_category()) $display = false;
        if ($this->current_options['no_display_search'] && is_search()) $display = false;
        if ($this->current_options['no_display_feed'] && is_feed()) $display = false;
        if ($this->current_options['no_display_preview'] && is_preview()) $display = false;

        if (!$display) {
            foreach ($identifiers as $value) {
                $content = str_replace($value[0], '', $content);
            }
            return $content;
        }

        $footnotes = array();
        $style = get_post_meta($post->ID, 'footnote_style', true);
        $style = (isset($this->styles[$style])) ? $style : $this->current_options['list_style_type'];

        foreach ($identifiers as $i => $value) {
            if ('ref:' === substr($value[2], 0, 4)) {
                $ref = (int)substr($value[2], 4);
                $identifiers[$i]['text'] = $identifiers[$ref-1][2];
            } else {
                $identifiers[$i]['text'] = $value[2];
            }

            if ($this->current_options['combine_identical_notes']) {
                foreach ($footnotes as $j => $note) {
                    if ($note['text'] === $identifiers[$i]['text']) {
                        $identifiers[$i]['use_footnote'] = $j;
                        $footnotes[$j]['identifiers'][] = $i;
                        continue 2;
                    }
                }
            }

            $identifiers[$i]['use_footnote'] = count($footnotes);
            $footnotes[] = array(
                'text' => $identifiers[$i]['text'],
                'symbol' => isset($identifiers[$i]['symbol']) ? $identifiers[$i]['symbol'] : '',
                'identifiers' => array($i)
            );
        }

        $use_full_link = is_feed() && !is_preview();

        // Replace footnote markers with links
        foreach ($identifiers as $key => $value) {
            $id_id = sprintf('identifier_%d_%d', $key, $post->ID);
            $id_num = ($style === 'decimal') ? $value['use_footnote'] + $start_number :
                $this->convert_num($value['use_footnote'] + $start_number, $style, count($footnotes));
            $id_href = ($use_full_link ? get_permalink($post->ID) : '') . '#footnote_' . $value['use_footnote'] . '_' . $post->ID;
            $id_title = str_replace('"', '"', htmlentities(html_entity_decode(wp_strip_all_tags($value['text']), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'));
            
            $id_replace = sprintf(
                '%s<a href="%s" id="%s" class="footnote-link footnote-identifier-link" title="%s">%s%s%s</a>%s',
                $this->current_options['pre_identifier'],
                $id_href,
                $id_id,
                $id_title,
                $this->current_options['inner_pre_identifier'],
                $id_num,
                $this->current_options['inner_post_identifier'],
                $this->current_options['post_identifier']
            );
            
            if ($this->current_options['superscript']) {
                $id_replace = '<sup>' . $id_replace . '</sup>';
            }
            
            $content = str_replace($value[0], $id_replace, $content);
        }

        if ($display) {
            $footnotes_markup = $this->current_options['pre_footnotes'];
            $start = ($start_number !== 1) ? sprintf('start="%d" ', $start_number) : '';
            $footnotes_markup .= '<ol ' . $start . 'class="footnotes">';
            
            foreach ($footnotes as $key => $value) {
                $footnotes_markup .= sprintf(
                    '<li id="footnote_%d_%d" class="footnote"%s>',
                    $key,
                    $post->ID,
                    $style === 'symbol' ? ' style="list-style-type:none;"' :
                        ($style !== $this->current_options['list_style_type'] ? ' style="list-style-type:' . $style . ';"' : '')
                );
                
                if ($style === 'symbol') {
                    $footnotes_markup .= sprintf(
                        '<span class="symbol">%s</span> ',
                        $this->convert_num($key + $start_number, $style, count($footnotes))
                    );
                }
                
                $footnotes_markup .= $value['text'];
                
                if (!is_feed()) {
                    $footnotes_markup .= '<span class="footnote-back-link-wrapper">';
                    foreach ($value['identifiers'] as $identifier) {
                        $footnotes_markup .= sprintf(
                            '%s<a href="%s#identifier_%d_%d" class="footnote-link footnote-back-link">%s</a>%s',
                            $this->current_options['pre_backlink'],
                            $use_full_link ? get_permalink($post->ID) : '',
                            $identifier,
                            $post->ID,
                            $this->current_options['backlink'],
                            $this->current_options['post_backlink']
                        );
                    }
                    $footnotes_markup .= '</span>';
                }
                
                $footnotes_markup .= '</li>';
            }
            
            $footnotes_markup .= '</ol>' . $this->current_options['post_footnotes'];
            $this->footnotes_content = $footnotes_markup;
        }

        return $content;
    }

    // Rest of the class methods remain unchanged...
    function add_settings_link($links, $file) {
        static $this_plugin;
        if (!$this_plugin) {
            $this_plugin = plugin_basename(__FILE__);
        }
        if (strpos($file, 'footnotes-made-easy.php') !== false) {
            array_unshift($links, '<a href="options-general.php?page=footnotes-options-page">' . __('Settings', 'footnotes-made-easy') . '</a>');
        }
        return $links;
    }

    function plugin_meta($links, $file) {
        if (strpos($file, 'footnotes-made-easy.php') !== false) {
            $links[] = '<a href="https://github.com/wpcorner/footnotes-made-easy/">' . __('Github', 'footnotes-made-easy') . '</a>';
            $links[] = '<a href="https://wordpress.org/support/plugin/footnotes-made-easy">' . __('Support', 'footnotes-made-easy') . '</a>';
            $links[] = '<a href="https://wpcorner.co/support/footnotes-made-easy/">' . __('Documentation', 'footnotes-made-easy') . '</a>';
        }
        return $links;
    }

    function footnotes_options_page() {
        $this->current_options = get_option('swas_footnote_options');
        $new_setting = array();
        foreach ($this->current_options as $key => $setting) {
            $new_setting[$key] = htmlentities($setting);
        }
        $this->current_options = $new_setting;
        unset($new_setting);
        include(dirname(__FILE__) . '/options.php');
    }

    function footnotes_help() {
        global $footnotes_hook;
        $screen = get_current_screen();
        if ($screen->id !== $footnotes_hook) {
            return;
        }
        $screen->add_help_tab(array(
            'id' => 'footnotes-help-tab',
            'title' => __('Help', 'footnotes-made-easy'),
            'content' => $this->add_help_content()
        ));
        $screen->set_help_sidebar($this->add_sidebar_content());
    }

    function add_help_content() {
        return '<p>' . __('This screen allows you to specify the default options for the Footnotes Made Easy plugin.', 'footnotes-made-easy') . '</p>' .
               '<p>' . __('The identifier is what appears when a footnote is inserted into your page contents. The back-link appear after each footnote, linking back to the identifier.', 'footnotes-made-easy') . '</p>' .
               '<p>' . __('Remember to click the Save Changes button at the bottom of the screen for new settings to take effect.', 'footnotes-made-easy') . '</p>';
    }

    function add_sidebar_content() {
        return '<p><strong>' . __('For more information:', 'footnotes-made-easy') . '</strong></p>' .
               '<p><a href="https://wordpress.org/plugins/footnotes-made-easy/">' . __('Instructions', 'footnotes-made-easy') . '</a></p>' .
               '<p><a href="https://wordpress.org/support/plugin/footnotes-made-easy">' . __('Support Forum', 'footnotes-made-easy') . '</a></p>';
    }

    function add_options_page() {
        global $footnotes_hook;
        $footnotes_hook = add_options_page(
            __('Footnotes Made Easy', 'footnotes-made-easy'),
            __('Footnotes', 'footnotes-made-easy'),
            'manage_options',
            'footnotes-options-page',
            array($this, 'footnotes_options_page')
        );
        add_action('load-' . $footnotes_hook, array($this, 'footnotes_help'));
    }

    function insert_styles() {
        echo '<style type="text/css">';
        if ('symbol' !== $this->current_options['list_style_type']) {
            printf('ol.footnotes>li{list-style-type:%s;}', $this->current_options['list_style_type']);
        }
        echo "ol.footnotes{color:#666666;}\nol.footnotes li{font-size:80%;}\n";
        echo '</style>';
    }

    function convert_num($num, $style, $total) {
        switch ($style) {
            case 'decimal-leading-zero':
                $width = max(2, strlen($total));
                return sprintf("%0{$width}d", $num);
            case 'lower-roman':
                return $this->roman($num, 'lower');
            case 'upper-roman':
                return $this->roman($num);
            case 'lower-alpha':
                return $this->alpha($num, 'lower');
            case 'upper-alpha':
                return $this->alpha($num);
            case 'symbol':
                return str_repeat($this->current_options['list_style_symbol'], $num);
            default:
                return $num;
        }
    }

    function roman($num, $case = 'upper') {
        $num = (int)$num;
        $conversion = array(
            'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400,
            'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40,
            'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1
        );
        $roman = '';
        foreach ($conversion as $r => $d) {
            $roman .= str_repeat($r, (int)($num / $d));
            $num %= $d;
        }
        return ($case === 'lower') ? strtolower($roman) : $roman;
    }

    function alpha($num, $case = 'upper') {
        $j = 1;
        for ($i = 'A'; $i <= 'ZZ'; $i++) {
            if ($j === $num) {
                return ($case === 'lower') ? strtolower($i) : $i;
            }
            $j++;
        }
        return '';
    }

    function tooltip_scripts() {
        wp_enqueue_script(
            'wp-footnotes-tooltips',
            plugins_url('js/tooltips.min.js', __FILE__),
            array('jquery', 'jquery-ui-widget', 'jquery-ui-tooltip', 'jquery-ui-core', 'jquery-ui-position')
        );
        wp_enqueue_style('wp-footnotes-tt-style', plugins_url('css/tooltips.min.css', __FILE__));
    }
}
