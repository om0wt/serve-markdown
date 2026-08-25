<?php
/**
 * Plugin Name: Serve Markdown
 * Plugin URI:  https://github.com/ajaykj/serve-markdown
 * Description: Serve Markdown versions of your content for AI agents and crawlers. Features content negotiation, .md URLs, auto-discovery, crawler logging, and full admin controls.
 * Version:     1.0.4
 * Author:      Azey
 * Author URI:  https://profiles.wordpress.org/akumarjain/
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: serve-md
 */

if ( ! defined( 'WPINC' ) ) {
	exit;
}

define( 'SERVE_MD_VERSION', '1.0.4' );
define( 'SERVE_MD_FILE', __FILE__ );
define( 'SERVE_MD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SERVE_MD_URL', plugin_dir_url( __FILE__ ) );

require_once SERVE_MD_DIR . 'includes/class-serve-md-logger.php';
require_once SERVE_MD_DIR . 'includes/class-serve-md-admin.php';
require_once SERVE_MD_DIR . 'includes/class-serve-md-metabox.php';
require_once SERVE_MD_DIR . 'includes/class-serve-md-core.php';

register_activation_hook( __FILE__, [ 'Serve_MD_Core', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Serve_MD_Core', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'Serve_MD_Core', 'instance' ] );
