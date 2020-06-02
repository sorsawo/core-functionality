<?php
/**
 * Plugin Name: Core Functionality
 * Plugin URI: https://github.com/sorsawo/core-functionality/
 * Description: Allow you to change WordPress core functionality easily.
 * Author: Sorsawo.Com
 * Author URI: https://sorsawo.com
 * Version: 1.0
 * Text Domain: core-functionality
 * Domain Path: languages
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

class Core_Functionality {
    
    private static $_instance = NULL;
    
    /**
     * Initialize all variables, filters and actions
     */
    public function __construct() {
        remove_action( 'wp_head', 'rsd_link' );
        remove_action( 'wp_head', 'wlwmanifest_link' );
        remove_action( 'wp_head', 'wp_generator' );
        remove_action( 'wp_head', 'wp_shortlink_wp_head' );
        remove_action( 'wp_head', 'feed_links', 2 );
        remove_action( 'wp_head', 'feed_links_extra', 3 );
        
        add_filter( 'xmlrpc_enabled',                  '__return_false' );
        add_filter( 'pings_open',                      '__return_false', 9999 );
        add_filter( 'pre_update_option_enable_xmlrpc', '__return_false' );
        add_filter( 'pre_option_enable_xmlrpc',        '__return_zero' );
        
        add_action( 'init',               array( $this, 'remove_query_strings' ) );
        add_action( 'init',               array( $this, 'disable_emojis' ) );
        add_action( 'init',               array( $this, 'remove_wpseo_notifications' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'move_jquery_scripts' ) );
        add_action( 'pre_get_posts',      array( $this, 'exclude_noindex_from_search' ) );
        add_action( 'xmlrpc_call',        array( $this, 'kill_xmlrpc' ) );
        add_action( 'pre_ping',           array( $this, 'disable_self_pingbacks' ) );
        
        add_filter( 'http_request_args',         array( $this, 'dont_update_core_plugin' ), 5, 2 );
        add_filter( 'wpforms_field_new_default', array( $this, 'wpforms_default_large_field_size' ) );
        add_filter( 'wp_headers',                array( $this, 'filter_headers' ), 10, 1 );
        add_filter( 'rewrite_rules_array',       array( $this, 'filter_rewrites' ) );
        add_filter( 'bloginfo_url',              array( $this, 'kill_pingback_url' ), 11, 2 );
    }
    
    /**
     * retrieve singleton class instance
     * @return instance reference to plugin
     */
    public static function instance() {
        if ( NULL === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function disable_emojis() {
        remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles',     'print_emoji_styles' );
        remove_action( 'admin_print_styles',  'print_emoji_styles' );  
        
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );    
        remove_filter( 'wp_mail',          'wp_staticize_emoji_for_email' );
        
        add_filter( 'tiny_mce_plugins',  array( $this, 'disable_emojis_tinymce' ) );
        add_filter( 'wp_resource_hints', array( $this, 'disable_emojis_dns_prefetch' ), 10, 2 );
        add_filter( 'emoji_svg_url',     '__return_false');
    }
    
    public function disable_emojis_tinymce( $plugins ) {
        if ( is_array( $plugins ) ) {
            return array_diff( $plugins, array( 'wpemoji' ) );
        } else {
            return array();
        }
    }
    
    public function disable_emojis_dns_prefetch( $urls, $relation_type ) {
        if ( 'dns-prefetch' === $relation_type ) {
            $emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2.2.1/svg/' );
            $urls = array_diff( $urls, array( $emoji_svg_url ) );
        }
        
        return $urls;
    }
    
    public function remove_query_strings() {
        if ( !is_admin() ) {
            add_filter( 'script_loader_src', array( $this, 'remove_query_strings_split' ), 15 );
            add_filter( 'style_loader_src',  array( $this, 'remove_query_strings_split' ), 15 );
        }
    }

    public function remove_query_strings_split( $src ) {
        $output = preg_split( "/(&ver|\?ver)/", $src );
        return $output[0];
    }

    public function move_jquery_scripts() {
        if ( !is_admin() ) {
            wp_deregister_script( 'jquery' );
            wp_register_script( 'jquery', includes_url( '/js/jquery/jquery.js' ), false, NULL, true );
            wp_enqueue_script( 'jquery' );
        }
    }
    
    public function remove_wpseo_notifications() {
        if ( ! class_exists( 'Yoast_Notification_Center' ) ) {
            return;
        }
        
        remove_action( 'admin_notices', array( Yoast_Notification_Center::get(), 'display_notifications' ) );
        remove_action( 'all_admin_notices', array( Yoast_Notification_Center::get(), 'display_notifications' ) );
    }
    
    public function exclude_noindex_from_search( $query ) {
        if ( $query->is_main_query() && $query->is_search() && ! is_admin() ) {
            $meta_query = empty( $query->query_vars['meta_query'] ) ? array() : $query->query_vars['meta_query'];
            $meta_query[] = array(
                'key' => '_yoast_wpseo_meta-robots-noindex',
                'compare' => 'NOT EXISTS',
            );
            
            $query->set( 'meta_query', $meta_query );
        }
    }
    
    public function dont_update_core_plugin( $r, $url ) {
        if ( 0 !== strpos( $url, 'https://api.wordpress.org/plugins/update-check/1.1/' ) ) {
            return $r; // Not a plugin update request. Bail immediately.
        }
        
        $plugins = json_decode( $r['body']['plugins'], true );
        unset( $plugins['plugins'][plugin_basename( __FILE__ )] );
        $r['body']['plugins'] = json_encode( $plugins );
        
        return $r;
    }
    
    public function wpforms_default_large_field_size() {
        if ( empty( $field['size'] ) ) {
            $field['size'] = 'large';
        }
        
        return $field;
    }
    
    public function filter_headers( $headers ) {
        if ( isset( $headers['X-Pingback'] ) ) {
            unset( $headers['X-Pingback'] );
        }
        
        return $headers;
    }
    
    public function filter_rewrites( $rules ) {
        foreach ( $rules as $rule => $rewrite ) {
            if ( preg_match( '/trackback\/\?\$$/i', $rule ) ) {
                unset( $rules[$rule] );
            }
        }
        
        return $rules;
    }
    
    public function kill_pingback_url( $output, $show ) {
        if ( $show === 'pingback_url' ) {
            $output = '';
        }
        
        return $output;
    }
    
    public function kill_xmlrpc( $action ) {
        if ( 'pingback.ping' === $action ) {
            wp_die( 'Pingbacks are not supported', 'Not Allowed!', array( 'response' => 403 ) );
        }
    }
    
    public function disable_self_pingbacks( &$links ) {
        $home = get_option( 'home' );
        
        foreach ( $links as $l => $link ) {
            if ( strpos( $link, $home ) === 0 ) {
                unset( $links[$l] );
            }
        }
    }

}

Core_Functionality::instance();
