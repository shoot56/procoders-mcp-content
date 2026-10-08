<?php
/**
 * Cleanup plugin options on uninstall.
 *
 * @package MCPContent
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'procoders_mcp_content_settings' );
