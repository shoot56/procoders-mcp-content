<?php
/**
 * Plugin Name: Procoders MCP Content
 * Description: Exposes post types, blocks, and fields to the WordPress MCP Adapter, with per-type access levels.
 * Version: 0.1.1
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Dmitry Shutko
 * Author URI: https://procoders.tech
 * Text Domain: procoders-mcp-content
 * License: GPL-2.0-or-later
 * 
 * GitHub Plugin URI: shoot56/procoders-mcp-content
 * Primary Branch: main
 *
 * @package MCPContent
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PROCODERS_MCP_CONTENT_VERSION', '0.1.0' );
define( 'PROCODERS_MCP_CONTENT_FILE', __FILE__ );
define( 'PROCODERS_MCP_CONTENT_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Procoders\McpContent\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = PROCODERS_MCP_CONTENT_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Procoders\McpContent\Plugin::instance()->init();
	}
);
