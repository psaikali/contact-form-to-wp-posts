<?php
/*
 * Plugin Name: Contact Form to WP posts
 * Version: 0.2
 * Plugin URI: http://mosaika.fr
 * Description: This simple plugin lets you save Contact Form 7 submissions into WordPress custom posts.
 * Author: Pierre Saikali
 * Author URI: http://www.mosaika.fr
 * Requires at least: 4.0
 * Tested up to: 4.9.8
 *
 * Text Domain: cf7_to_wp
 * Domain Path: /lang/
 *
 * @package WordPress
 * @author Pierre Saikali
 * @since 0.1
 */

__('Contact Form to WP posts', 'cf7_to_wp');
__('This simple plugin lets you save Contact Form 7 submissions into WordPress custom posts.', 'cf7_to_wp');

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Load plugin files
 */
require_once( 'includes/class-cf7_to_wp.php' );

/**
 * Returns the main instance of CF7_To_WP to prevent the need to use globals.
 *
 * @since  0.1
 * @return object CF7_To_WP
 */
function cf7_to_wp () {
	$instance = CF7_To_WP::instance( __FILE__, '0.2' );
	return $instance;
}

add_action( 'plugins_loaded', array( cf7_to_wp(), 'init' ) );
