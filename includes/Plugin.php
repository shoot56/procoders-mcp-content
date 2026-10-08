<?php
/**
 * Plugin bootstrap.
 *
 * @package MCPContent
 */

declare(strict_types=1);

namespace Procoders\McpContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires admin settings and ability registration.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', array( Settings::class, 'register_page' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice_missing_api' ) );
		add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );
	}

	/**
	 * Tell admins when this WordPress build cannot register abilities.
	 */
	public function maybe_notice_missing_api(): void {
		if ( ! current_user_can( 'activate_plugins' ) || function_exists( 'wp_register_ability' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Procoders MCP Content requires WordPress 6.9 or newer with the Abilities API.', 'procoders-mcp-content' );
		echo '</p></div>';
	}
}
