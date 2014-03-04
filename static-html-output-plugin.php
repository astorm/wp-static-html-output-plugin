<?php
// Bail if WP-CLI is not present
if ( !defined( 'WP_CLI' ) ) return;

/*
Plugin Name: WP Static HTML Output CLI
Version: 1.0
Description: A CLI interface for the WP Super Cache plugin
Author: Alan Storm
Author URI: http://alanstorm.com
Plugin URI: X
License: MIT
*/

function static_html_output_cli_init() {
	if ( !class_exists( 'StaticHtmlOutput' ) )
	{
		return;
    }
    
	if ( defined('WP_CLI') && WP_CLI ) {
		include dirname(__FILE__) . '/cli.php';
	}
}
add_action( 'plugins_loaded', 'static_html_output_cli_init' );

