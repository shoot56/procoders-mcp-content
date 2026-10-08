<?php
/**
 * Discovers post types, blocks, and field schemas from the current site.
 *
 * @package MCPContent
 */

declare(strict_types=1);

namespace Procoders\McpContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only catalog of what the active theme and plugins registered.
 */
final class Catalog {

	/**
	 * Core blocks the agent may place when a post type is enabled.
	 */
	public const CORE_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/image',
		'core/separator',
		'core/buttons',
		'core/button',
		'core/html',
	);

	/**
	 * Post types that are never offered on the settings screen.
	 */
	private const EXCLUDED_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
		'wp_font_family',
		'wp_font_face',
		'acf-field',
		'acf-field-group',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
	);

	/**
	 * @return array<string, \WP_Post_Type>
	 */
	public static function manageable_post_types(): array {
		$objects = get_post_types(
			array(
				'show_ui' => true,
			),
			'objects'
		);
		$types   = array();

		foreach ( $objects as $name => $object ) {
			if ( in_array( $name, self::EXCLUDED_TYPES, true ) ) {
				continue;
			}

			$types[ $name ] = $object;
		}

		uasort(
			$types,
			static function ( \WP_Post_Type $a, \WP_Post_Type $b ): int {
				return strcasecmp( $a->labels->name, $b->labels->name );
			}
		);

		return $types;
	}

	/**
	 * Blocks that may appear in this post type, before the settings allowlist.
	 *
	 * @return array<int, array{name: string, title: string, description: string, nested_only: bool, parents: array<int, string>}>
	 */
	public static function blocks_for_post_type( string $post_type ): array {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return array();
		}

		$blocks = array();

		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $block ) {
			if ( ! self::block_matches_post_type( $block->name, $post_type ) ) {
				continue;
			}

			$parents = is_array( $block->parent ) ? array_values( $block->parent ) : array();

			$blocks[] = array(
				'name'        => $block->name,
				'title'       => $block->title ? wp_strip_all_tags( (string) $block->title ) : $block->name,
				'description' => $block->description ? wp_strip_all_tags( (string) $block->description ) : '',
				'nested_only' => array() !== $parents,
				'parents'     => $parents,
			);
		}

		usort(
			$blocks,
			static function ( array $a, array $b ): int {
				$a_core = str_starts_with( $a['name'], 'core/' );
				$b_core = str_starts_with( $b['name'], 'core/' );

				if ( $a_core !== $b_core ) {
					return $a_core ? -1 : 1;
				}

				return strcasecmp( $a['title'], $b['title'] );
			}
		);

		return $blocks;
	}

	public static function block_matches_post_type( string $block_name, string $post_type ): bool {
		if ( str_starts_with( $block_name, 'core/' ) ) {
			return in_array( $block_name, self::CORE_BLOCKS, true );
		}

		$registry = \WP_Block_Type_Registry::get_instance();

		if ( ! $registry->is_registered( $block_name ) ) {
			return false;
		}

		if ( ! function_exists( 'acf_get_block_type' ) ) {
			return true;
		}

		$acf_block = acf_get_block_type( $block_name );

		if ( ! is_array( $acf_block ) ) {
			return true;
		}

		$post_types = $acf_block['post_types'] ?? array();

		if ( ! is_array( $post_types ) || array() === $post_types ) {
			return true;
		}

		return in_array( $post_type, $post_types, true );
	}

	/**
	 * @return array<int, string>
	 */
	public static function parents_of( string $block_name ): array {
		$registry = \WP_Block_Type_Registry::get_instance();

		if ( ! $registry->is_registered( $block_name ) ) {
			return array();
		}

		$block = $registry->get_registered( $block_name );

		return ( $block && is_array( $block->parent ) ) ? array_values( $block->parent ) : array();
	}

	/**
	 * Simplified ACF field tree for one block.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function block_field_schema( string $block_name ): array {
		if ( ! function_exists( 'acf_get_block_fields' ) ) {
			return array();
		}

		$fields = acf_get_block_fields(
			array(
				'name' => $block_name,
			)
		);

		if ( ! is_array( $fields ) ) {
			return array();
		}

		return self::simplify_fields( $fields );
	}

	/**
	 * ACF field groups located on the post type itself, not on a block.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function post_field_schema( string $post_type ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$groups = acf_get_field_groups(
			array(
				'post_type' => $post_type,
			)
		);
		$schema = array();

		foreach ( $groups as $group ) {
			if ( self::group_is_block_only( $group ) ) {
				continue;
			}

			$fields = acf_get_fields( $group['key'] );

			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( self::simplify_fields( $fields ) as $field ) {
				$schema[] = $field;
			}
		}

		return $schema;
	}

	/**
	 * @param array<string, mixed> $group Field group.
	 */
	private static function group_is_block_only( array $group ): bool {
		if ( empty( $group['location'] ) || ! is_array( $group['location'] ) ) {
			return false;
		}

		foreach ( $group['location'] as $rules ) {
			if ( ! is_array( $rules ) ) {
				continue;
			}

			$has_block = false;

			foreach ( $rules as $rule ) {
				if ( is_array( $rule ) && isset( $rule['param'] ) && 'block' === $rule['param'] ) {
					$has_block = true;
				}
			}

			if ( ! $has_block ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $fields ACF fields.
	 * @return array<int, array<string, mixed>>
	 */
	private static function simplify_fields( array $fields ): array {
		$simple = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) || empty( $field['type'] ) ) {
				continue;
			}

			if ( in_array( $field['type'], array( 'tab', 'message', 'accordion' ), true ) ) {
				continue;
			}

			$item = array(
				'key'      => (string) ( $field['key'] ?? '' ),
				'name'     => (string) $field['name'],
				'label'    => isset( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : (string) $field['name'],
				'type'     => (string) $field['type'],
				'required' => ! empty( $field['required'] ),
			);

			if ( ! empty( $field['instructions'] ) ) {
				$item['instructions'] = wp_strip_all_tags( (string) $field['instructions'] );
			}

			if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
				$item['choices'] = $field['choices'];
			}

			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$item['sub_fields'] = self::simplify_fields( $field['sub_fields'] );
			}

			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				$layouts = array();

				foreach ( $field['layouts'] as $layout ) {
					if ( ! is_array( $layout ) || empty( $layout['name'] ) ) {
						continue;
					}

					$layouts[] = array(
						'name'       => (string) $layout['name'],
						'label'      => isset( $layout['label'] ) ? wp_strip_all_tags( (string) $layout['label'] ) : (string) $layout['name'],
						'sub_fields' => self::simplify_fields( is_array( $layout['sub_fields'] ?? null ) ? $layout['sub_fields'] : array() ),
					);
				}

				$item['layouts'] = $layouts;
			}

			$simple[] = $item;
		}

		return $simple;
	}
}
