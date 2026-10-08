<?php
/**
 * Reads saved access levels and answers permission questions.
 *
 * @package MCPContent
 */

declare(strict_types=1);

namespace Procoders\McpContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings-backed access checks. WordPress capabilities are checked separately.
 */
final class Access {

	public const OPTION = 'procoders_mcp_content_settings';

	/**
	 * Ordered levels. A higher rank includes every lower one.
	 */
	private const RANK = array(
		'read'    => 1,
		'draft'   => 2,
		'publish' => 3,
	);

	/**
	 * @return array{post_types: array<string, array<string, mixed>>, allow_media_upload: bool}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$post_types = array();

		if ( isset( $stored['post_types'] ) && is_array( $stored['post_types'] ) ) {
			$post_types = $stored['post_types'];
		}

		return array(
			'post_types'         => $post_types,
			'allow_media_upload' => ! empty( $stored['allow_media_upload'] ),
		);
	}

	public static function level( string $post_type ): string {
		$row = self::row( $post_type );

		if ( ! isset( $row['level'] ) || ! is_string( $row['level'] ) ) {
			return '';
		}

		return isset( self::RANK[ $row['level'] ] ) ? $row['level'] : '';
	}

	public static function allows( string $post_type, string $level ): bool {
		$current = self::level( $post_type );

		if ( '' === $current || ! isset( self::RANK[ $level ] ) ) {
			return false;
		}

		return self::RANK[ $current ] >= self::RANK[ $level ];
	}

	public static function allows_trash( string $post_type ): bool {
		if ( ! self::allows( $post_type, 'draft' ) ) {
			return false;
		}

		$row = self::row( $post_type );

		return ! empty( $row['allow_trash'] );
	}

	public static function allows_create_terms( string $post_type ): bool {
		if ( ! self::allows( $post_type, 'draft' ) ) {
			return false;
		}

		$row = self::row( $post_type );

		return ! empty( $row['allow_create_terms'] );
	}

	public static function allows_media_upload(): bool {
		return self::settings()['allow_media_upload'];
	}

	/**
	 * @return array<int, string>
	 */
	public static function disabled_blocks( string $post_type ): array {
		$row = self::row( $post_type );

		if ( ! isset( $row['disabled_blocks'] ) || ! is_array( $row['disabled_blocks'] ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$row['disabled_blocks'],
				static function ( $name ): bool {
					return is_string( $name ) && '' !== $name;
				}
			)
		);
	}

	public static function block_allowed( string $post_type, string $block_name ): bool {
		if ( ! self::allows( $post_type, 'read' ) ) {
			return false;
		}

		if ( ! Catalog::block_matches_post_type( $block_name, $post_type ) ) {
			return false;
		}

		return ! in_array( $block_name, self::disabled_blocks( $post_type ), true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( string $post_type ): array {
		$settings = self::settings();
		$row      = $settings['post_types'][ $post_type ] ?? null;

		return is_array( $row ) ? $row : array();
	}
}
