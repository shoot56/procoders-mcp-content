<?php
/**
 * Settings screen for post-type access.
 *
 * @package MCPContent
 */

declare(strict_types=1);

namespace Procoders\McpContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options page. Nothing is exposed until an administrator picks a level.
 */
final class Settings {

	public static function register_page(): void {
		add_options_page(
			__( 'Procoders MCP Content', 'procoders-mcp-content' ),
			__( 'Procoders MCP Content', 'procoders-mcp-content' ),
			'manage_options',
			'procoders-mcp-content',
			array( self::class, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'procoders-mcp-content' ) );
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			self::handle_save();
		}

		$settings = Access::settings();
		$types    = Catalog::manageable_post_types();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Procoders MCP Content', 'procoders-mcp-content' ) . '</h1>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag after redirect.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'procoders-mcp-content' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Choose which post types an MCP client can see. Levels stack: Draft includes reading, Publish includes drafts. WordPress still checks the connected user’s own capabilities.', 'procoders-mcp-content' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'procoders_mcp_content_settings', 'procoders_mcp_content_nonce' );

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Media upload', 'procoders-mcp-content' ) . '</th><td>';
		echo '<label><input type="checkbox" name="procoders_mcp_content[allow_media_upload]" value="1" ' . checked( $settings['allow_media_upload'], true, false ) . ' /> ';
		echo esc_html__( 'Allow uploading files from a URL into the media library.', 'procoders-mcp-content' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Leave this off until a migration needs new files. Existing attachment IDs can be used without it.', 'procoders-mcp-content' ) . '</p>';
		echo '</td></tr></tbody></table>';

		echo '<table class="widefat striped" style="margin-top:1em">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post type', 'procoders-mcp-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Access', 'procoders-mcp-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Trash', 'procoders-mcp-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Create terms', 'procoders-mcp-content' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $types as $name => $object ) {
			$row      = $settings['post_types'][ $name ] ?? array();
			$level    = isset( $row['level'] ) && is_string( $row['level'] ) ? $row['level'] : '';
			$disabled = isset( $row['disabled_blocks'] ) && is_array( $row['disabled_blocks'] ) ? $row['disabled_blocks'] : array();
			$blocks   = Catalog::blocks_for_post_type( $name );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $object->labels->name ) . '</strong><br /><code>' . esc_html( $name ) . '</code></td>';
			echo '<td><select name="procoders_mcp_content[post_types][' . esc_attr( $name ) . '][level]">';

			foreach ( self::level_choices() as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( $level, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}

			echo '</select></td>';
			echo '<td><input type="checkbox" name="procoders_mcp_content[post_types][' . esc_attr( $name ) . '][allow_trash]" value="1" ' . checked( ! empty( $row['allow_trash'] ), true, false ) . ' /></td>';
			echo '<td><input type="checkbox" name="procoders_mcp_content[post_types][' . esc_attr( $name ) . '][allow_create_terms]" value="1" ' . checked( ! empty( $row['allow_create_terms'] ), true, false ) . ' /></td>';
			echo '</tr>';

			echo '<tr><td colspan="4">';
			echo '<details><summary>' . esc_html( sprintf( /* translators: %d: block count */ __( 'Blocks (%d)', 'procoders-mcp-content' ), count( $blocks ) ) ) . '</summary>';
			echo '<p class="description">' . esc_html__( 'Checked blocks can be written into this post type. Nested blocks can only be placed inside their parent.', 'procoders-mcp-content' ) . '</p>';
			echo '<div style="columns:2;margin:0.5em 0 1em">';

			foreach ( $blocks as $block ) {
				$checked = ! in_array( $block['name'], $disabled, true );
				$input   = 'procoders_mcp_content[post_types][' . $name . '][blocks][]';

				echo '<label style="display:block;margin:0 0 0.25em">';
				echo '<input type="checkbox" name="' . esc_attr( $input ) . '" value="' . esc_attr( $block['name'] ) . '" ' . checked( $checked, true, false ) . ' /> ';
				echo esc_html( $block['title'] ) . ' <code>' . esc_html( $block['name'] ) . '</code>';

				if ( $block['nested_only'] ) {
					echo ' <em>' . esc_html__( '(nested)', 'procoders-mcp-content' ) . '</em>';
				}

				echo '</label>';
			}

			echo '</div></details></td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Save access', 'procoders-mcp-content' ) );
		echo '</form></div>';
	}

	/**
	 * Persist a settings payload. Used by the admin form.
	 *
	 * @param array<string, mixed> $input Raw form values.
	 */
	public static function save( array $input ): void {
		$clean = array(
			'allow_media_upload' => ! empty( $input['allow_media_upload'] ),
			'post_types'         => array(),
		);

		$rows = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array();

		foreach ( Catalog::manageable_post_types() as $name => $object ) {
			unset( $object );
			$row = isset( $rows[ $name ] ) && is_array( $rows[ $name ] ) ? $rows[ $name ] : array();
			$level = isset( $row['level'] ) ? sanitize_key( (string) $row['level'] ) : '';

			if ( ! in_array( $level, array( 'read', 'draft', 'publish' ), true ) ) {
				continue;
			}

			$available = array_column( Catalog::blocks_for_post_type( $name ), 'name' );
			$checked   = array();

			if ( isset( $row['blocks'] ) && is_array( $row['blocks'] ) ) {
				foreach ( $row['blocks'] as $block_name ) {
					$block_name = sanitize_text_field( (string) $block_name );

					if ( in_array( $block_name, $available, true ) ) {
						$checked[] = $block_name;
					}
				}
			}

			$clean['post_types'][ $name ] = array(
				'level'              => $level,
				'allow_trash'        => ! empty( $row['allow_trash'] ),
				'allow_create_terms' => ! empty( $row['allow_create_terms'] ),
				'disabled_blocks'    => array_values( array_diff( $available, $checked ) ),
			);
		}

		update_option( Access::OPTION, $clean, false );
	}

	private static function handle_save(): void {
		check_admin_referer( 'procoders_mcp_content_settings', 'procoders_mcp_content_nonce' );

		$raw = isset( $_POST['procoders_mcp_content'] ) ? wp_unslash( $_POST['procoders_mcp_content'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save().

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		self::save( $raw );

		wp_safe_redirect( admin_url( 'options-general.php?page=procoders-mcp-content&updated=1' ) );
		exit;
	}

	/**
	 * @return array<string, string>
	 */
	private static function level_choices(): array {
		return array(
			''        => __( 'Off', 'procoders-mcp-content' ),
			'read'    => __( 'Read', 'procoders-mcp-content' ),
			'draft'   => __( 'Draft', 'procoders-mcp-content' ),
			'publish' => __( 'Publish', 'procoders-mcp-content' ),
		);
	}
}
