<?php
/**
 * Turns structured block trees into post content and back.
 *
 * @package MCPContent
 */

declare(strict_types=1);

namespace Procoders\McpContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block markup, post fields, terms, and media.
 */
final class Content {

	/**
	 * @param array<int, mixed> $blocks    Block tree from the ability input.
	 * @param string            $post_type Post type the blocks will be saved on.
	 */
	public static function blocks_to_html( array $blocks, string $post_type ): string {
		$parsed = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				throw new Content_Exception( 'Each block must be an object.' );
			}

			$parsed[] = self::to_parsed_block( $block, $post_type, null );
		}

		return serialize_blocks( $parsed );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function html_to_blocks( string $html, string $post_type ): array {
		$parsed = parse_blocks( $html );
		$blocks = array();

		foreach ( $parsed as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$blocks[] = self::from_parsed_block( $block, $post_type );
		}

		return $blocks;
	}

	/**
	 * @param array<string, mixed> $fields Field name to value.
	 */
	public static function update_post_fields( int $post_id, string $post_type, array $fields ): void {
		if ( array() === $fields ) {
			return;
		}

		if ( ! function_exists( 'update_field' ) ) {
			throw new Content_Exception( 'ACF is not active, so post fields cannot be saved.' );
		}

		$allowed = array();

		foreach ( Catalog::post_field_schema( $post_type ) as $field ) {
			$allowed[ $field['name'] ] = $field;
		}

		foreach ( $fields as $name => $value ) {
			$name = (string) $name;

			if ( ! isset( $allowed[ $name ] ) ) {
				throw new Content_Exception( sprintf( 'Field "%s" is not on this post type.', $name ) );
			}

			$stored = self::store_value( $allowed[ $name ], $value );
			update_field( $name, $stored, $post_id );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function read_post_fields( int $post_id, string $post_type ): array {
		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}

		$values = array();

		foreach ( Catalog::post_field_schema( $post_type ) as $field ) {
			$raw = get_field( $field['name'], $post_id, false );

			if ( null === $raw || false === $raw || '' === $raw ) {
				continue;
			}

			$values[ $field['name'] ] = $raw;
		}

		return $values;
	}

	/**
	 * @param array<string, mixed> $terms Taxonomy => list of IDs or slugs.
	 */
	public static function assign_terms( int $post_id, string $post_type, array $terms, bool $create_missing ): void {
		foreach ( $terms as $taxonomy => $raw_terms ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			$tax      = get_taxonomy( $taxonomy );

			if ( ! $tax || ! in_array( $post_type, (array) $tax->object_type, true ) ) {
				throw new Content_Exception( sprintf( 'Taxonomy "%s" is not registered for this post type.', $taxonomy ) );
			}

			if ( ! current_user_can( $tax->cap->assign_terms ) ) {
				throw new Content_Exception( sprintf( 'You cannot assign %s terms.', $taxonomy ) );
			}

			if ( ! is_array( $raw_terms ) ) {
				throw new Content_Exception( sprintf( 'Terms for "%s" must be a list.', $taxonomy ) );
			}

			$ids = array();

			foreach ( $raw_terms as $term ) {
				$ids[] = self::resolve_term( $taxonomy, $term, $create_missing, $tax );
			}

			$result = wp_set_object_terms( $post_id, $ids, $taxonomy, false );

			if ( is_wp_error( $result ) ) {
				throw new Content_Exception( $result->get_error_message() );
			}
		}
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function read_terms( int $post_id ): array {
		$taxonomies = get_object_taxonomies( get_post_type( $post_id ), 'names' );
		$assigned   = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy );

			if ( is_wp_error( $terms ) || array() === $terms ) {
				continue;
			}

			$assigned[ $taxonomy ] = array();

			foreach ( $terms as $term ) {
				$assigned[ $taxonomy ][] = array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		return $assigned;
	}

	/**
	 * @return array{id: int, url: string, mime: string}
	 */
	public static function upload_from_url( string $url, string $title, string $alt ): array {
		if ( ! Access::allows_media_upload() ) {
			throw new Content_Exception( 'Media upload from URL is turned off.' );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			throw new Content_Exception( 'You cannot upload files.' );
		}

		$url = esc_url_raw( $url );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			throw new Content_Exception( 'That URL cannot be downloaded.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			throw new Content_Exception( $tmp->get_error_message() );
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( basename( $path ) );

		if ( '' === $name ) {
			$name = 'upload';
		}

		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		$id   = media_handle_sideload( $file, 0, $title );

		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- download_url temp file.
			throw new Content_Exception( $id->get_error_message() );
		}

		$mime = (string) get_post_mime_type( $id );
		$ok   = str_starts_with( $mime, 'image/' ) || str_starts_with( $mime, 'video/' ) || 'application/pdf' === $mime;

		if ( ! $ok ) {
			wp_delete_attachment( $id, true );
			throw new Content_Exception( 'Only images, videos, and PDFs can be uploaded.' );
		}

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return array(
			'id'   => (int) $id,
			'url'  => (string) wp_get_attachment_url( $id ),
			'mime' => $mime,
		);
	}

	/**
	 * @param array<string, mixed> $node   One block from the ability input.
	 * @return array<string, mixed>
	 */
	private static function to_parsed_block( array $node, string $post_type, ?string $parent ): array {
		$name = isset( $node['name'] ) ? sanitize_text_field( (string) $node['name'] ) : '';

		if ( '' === $name ) {
			throw new Content_Exception( 'A block is missing its name.' );
		}

		if ( ! Access::block_allowed( $post_type, $name ) ) {
			throw new Content_Exception( sprintf( 'Block "%s" is not enabled for this post type.', $name ) );
		}

		$parents = Catalog::parents_of( $name );

		if ( array() !== $parents && ( null === $parent || ! in_array( $parent, $parents, true ) ) ) {
			throw new Content_Exception( sprintf( 'Block "%s" can only be placed inside %s.', $name, implode( ', ', $parents ) ) );
		}

		$inner_nodes = array();

		if ( isset( $node['inner_blocks'] ) && is_array( $node['inner_blocks'] ) ) {
			$inner_nodes = $node['inner_blocks'];
		} elseif ( 'core/list' === $name && isset( $node['items'] ) && is_array( $node['items'] ) ) {
			foreach ( $node['items'] as $item ) {
				$inner_nodes[] = array(
					'name' => 'core/list-item',
					'text' => (string) $item,
				);
			}
		}

		$inner = array();

		foreach ( $inner_nodes as $child ) {
			if ( ! is_array( $child ) ) {
				throw new Content_Exception( sprintf( 'Inner blocks of "%s" must be objects.', $name ) );
			}

			$inner[] = self::to_parsed_block( $child, $post_type, $name );
		}

		if ( str_starts_with( $name, 'core/' ) ) {
			return self::core_block( $name, $node, $inner );
		}

		$schema = Catalog::block_field_schema( $name );
		$fields = isset( $node['fields'] ) && is_array( $node['fields'] ) ? $node['fields'] : array();
		$data   = self::flatten_fields( $schema, $fields );
		$attrs  = array(
			'name' => $name,
			'data' => $data,
			'mode' => 'preview',
		);

		if ( ! empty( $node['anchor'] ) ) {
			$attrs['anchor'] = sanitize_title( (string) $node['anchor'] );
		}

		$inner_content = array();

		if ( array() !== $inner ) {
			$inner_content[] = "\n";

			foreach ( $inner as $unused ) {
				unset( $unused );
				$inner_content[] = null;
				$inner_content[] = "\n";
			}
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => $inner_content,
		);
	}

	/**
	 * @param array<string, mixed>              $node  Input node.
	 * @param array<int, array<string, mixed>>  $inner Parsed children.
	 * @return array<string, mixed>
	 */
	private static function core_block( string $name, array $node, array $inner ): array {
		$attrs         = array();
		$html          = '';
		$inner_content = array();

		if ( isset( $node['html'] ) && is_string( $node['html'] ) && '' !== $node['html'] ) {
			$html = wp_kses_post( $node['html'] );
		}

		$text = isset( $node['text'] ) ? (string) $node['text'] : '';

		switch ( $name ) {
			case 'core/paragraph':
				if ( '' === $html ) {
					$html = '<p>' . esc_html( $text ) . '</p>';
				}
				break;

			case 'core/heading':
				$level           = isset( $node['level'] ) ? (int) $node['level'] : 2;
				$level           = min( 6, max( 2, $level ) );
				$attrs['level']  = $level;
				if ( '' === $html ) {
					$html = sprintf( '<h%d class="wp-block-heading">%s</h%d>', $level, esc_html( $text ), $level );
				}
				break;

			case 'core/list':
				$ordered          = ! empty( $node['ordered'] );
				$tag              = $ordered ? 'ol' : 'ul';
				$attrs['ordered'] = $ordered;
				$inner_content    = array( '<' . $tag . ' class="wp-block-list">' . "\n" );
				foreach ( $inner as $unused ) {
					unset( $unused );
					$inner_content[] = null;
					$inner_content[] = "\n";
				}
				$inner_content[] = '</' . $tag . '>';
				$html            = '';
				break;

			case 'core/list-item':
				if ( '' === $html ) {
					$html = '<li>' . esc_html( $text ) . '</li>';
				}
				break;

			case 'core/quote':
				$citation = isset( $node['citation'] ) ? esc_html( (string) $node['citation'] ) : '';
				if ( '' === $html ) {
					$html = '<blockquote class="wp-block-quote"><p>' . esc_html( $text ) . '</p>';
					if ( '' !== $citation ) {
						$html .= '<cite>' . $citation . '</cite>';
					}
					$html .= '</blockquote>';
				}
				break;

			case 'core/image':
				$attachment_id = isset( $node['id'] ) ? absint( $node['id'] ) : 0;
				if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
					throw new Content_Exception( 'core/image needs an existing attachment id.' );
				}
				$attrs['id']       = $attachment_id;
				$attrs['sizeSlug'] = 'large';
				$image             = wp_get_attachment_image( $attachment_id, 'large', false, array( 'class' => 'wp-image-' . $attachment_id ) );
				$html              = '<figure class="wp-block-image size-large">' . $image . '</figure>';
				break;

			case 'core/separator':
				$html = '<hr class="wp-block-separator has-alpha-channel-opacity"/>';
				break;

			case 'core/buttons':
				$inner_content = array( '<div class="wp-block-buttons">' . "\n" );
				foreach ( $inner as $unused ) {
					unset( $unused );
					$inner_content[] = null;
					$inner_content[] = "\n";
				}
				$inner_content[] = '</div>';
				break;

			case 'core/button':
				$url = isset( $node['url'] ) ? esc_url( (string) $node['url'] ) : '';
				if ( '' === $html ) {
					$html = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . esc_html( $text ) . '</a></div>';
				}
				break;

			case 'core/html':
				if ( '' === $html ) {
					$html = wp_kses_post( $text );
				}
				break;

			default:
				throw new Content_Exception( sprintf( 'Core block "%s" is not supported.', $name ) );
		}

		if ( '' !== $html && array() === $inner_content ) {
			$inner_content = array( $html );
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => $inner_content,
		);
	}

	/**
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	private static function from_parsed_block( array $block, string $post_type ): array {
		$name = (string) $block['blockName'];
		$item = array(
			'name' => $name,
		);

		if ( str_starts_with( $name, 'core/' ) ) {
			$item['html'] = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

			if ( isset( $block['attrs']['level'] ) ) {
				$item['level'] = (int) $block['attrs']['level'];
			}

			if ( isset( $block['attrs']['id'] ) ) {
				$item['id'] = (int) $block['attrs']['id'];
			}
		} else {
			$data   = array();
			$attrs  = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			if ( isset( $attrs['data'] ) && is_array( $attrs['data'] ) ) {
				$data = $attrs['data'];
			}

			$item['fields'] = self::unflatten_fields( Catalog::block_field_schema( $name ), $data );
		}

		$children = array();

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $child ) {
				if ( is_array( $child ) && ! empty( $child['blockName'] ) ) {
					$children[] = self::from_parsed_block( $child, $post_type );
				}
			}
		}

		if ( array() !== $children ) {
			$item['inner_blocks'] = $children;
		}

		return $item;
	}

	/**
	 * @param array<int, array<string, mixed>> $schema Field schema.
	 * @param array<string, mixed>             $values Submitted values.
	 * @return array<string, mixed>
	 */
	private static function flatten_fields( array $schema, array $values ): array {
		$data    = array();
		$by_name = array();

		foreach ( $schema as $field ) {
			$by_name[ $field['name'] ] = $field;
		}

		foreach ( $values as $name => $value ) {
			$name = (string) $name;

			if ( ! isset( $by_name[ $name ] ) ) {
				throw new Content_Exception( sprintf( 'Unknown field "%s".', $name ) );
			}

			self::write_field( $data, $by_name[ $name ], $value, '' );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data   ACF block data, passed by reference.
	 * @param array<string, mixed> $field  Schema field.
	 * @param mixed                $value  Submitted value.
	 */
	private static function write_field( array &$data, array $field, mixed $value, string $prefix ): void {
		$name = $prefix . $field['name'];
		$type = (string) $field['type'];
		$key  = (string) $field['key'];

		if ( 'repeater' === $type ) {
			$rows         = is_array( $value ) ? array_values( $value ) : array();
			$data[ $name ] = count( $rows );
			$data[ '_' . $name ] = $key;

			foreach ( $rows as $index => $row ) {
				if ( ! is_array( $row ) ) {
					throw new Content_Exception( sprintf( 'Repeater "%s" rows must be objects.', $field['name'] ) );
				}

				foreach ( $field['sub_fields'] ?? array() as $sub ) {
					if ( ! is_array( $sub ) || ! array_key_exists( $sub['name'], $row ) ) {
						continue;
					}

					self::write_field( $data, $sub, $row[ $sub['name'] ], $name . '_' . $index . '_' );
				}
			}

			return;
		}

		if ( 'group' === $type ) {
			$data[ '_' . $name ] = $key;
			$row                 = is_array( $value ) ? $value : array();

			foreach ( $field['sub_fields'] ?? array() as $sub ) {
				if ( ! is_array( $sub ) || ! array_key_exists( $sub['name'], $row ) ) {
					continue;
				}

				self::write_field( $data, $sub, $row[ $sub['name'] ], $name . '_' );
			}

			return;
		}

		if ( 'flexible_content' === $type ) {
			$rows            = is_array( $value ) ? array_values( $value ) : array();
			$data[ $name ]   = count( $rows );
			$data[ '_' . $name ] = $key;

			foreach ( $rows as $index => $row ) {
				if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) ) {
					throw new Content_Exception( sprintf( 'Flexible content "%s" needs acf_fc_layout on each row.', $field['name'] ) );
				}

				$layout_name = sanitize_key( (string) $row['acf_fc_layout'] );
				$data[ $name . '_' . $index . '_acf_fc_layout' ] = $layout_name;
				$subs = array();

				foreach ( $field['layouts'] ?? array() as $layout ) {
					if ( is_array( $layout ) && ( $layout['name'] ?? '' ) === $layout_name ) {
						$subs = $layout['sub_fields'] ?? array();
					}
				}

				foreach ( $subs as $sub ) {
					if ( ! is_array( $sub ) || ! array_key_exists( $sub['name'], $row ) ) {
						continue;
					}

					self::write_field( $data, $sub, $row[ $sub['name'] ], $name . '_' . $index . '_' );
				}
			}

			return;
		}

		$data[ $name ]       = self::store_value( $field, $value );
		$data[ '_' . $name ] = $key;
	}

	/**
	 * @param array<int, array<string, mixed>> $schema Field schema.
	 * @param array<string, mixed>             $data   Raw ACF block data.
	 * @return array<string, mixed>
	 */
	private static function unflatten_fields( array $schema, array $data ): array {
		$values = array();

		foreach ( $schema as $field ) {
			if ( ! self::field_present( $data, $field, '' ) ) {
				continue;
			}

			$values[ $field['name'] ] = self::read_field( $data, $field, '' );
		}

		return $values;
	}

	/**
	 * @param array<string, mixed> $data   Raw data.
	 * @param array<string, mixed> $field  Schema field.
	 */
	private static function field_present( array $data, array $field, string $prefix ): bool {
		$name = $prefix . $field['name'];

		return array_key_exists( $name, $data ) || array_key_exists( '_' . $name, $data );
	}

	/**
	 * @param array<string, mixed> $data  Raw data.
	 * @param array<string, mixed> $field Schema field.
	 */
	private static function read_field( array $data, array $field, string $prefix ): mixed {
		$name = $prefix . $field['name'];
		$type = (string) $field['type'];

		if ( 'repeater' === $type ) {
			$count = isset( $data[ $name ] ) ? (int) $data[ $name ] : 0;
			$rows  = array();

			for ( $index = 0; $index < $count; $index++ ) {
				$row = array();

				foreach ( $field['sub_fields'] ?? array() as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}

					$row[ $sub['name'] ] = self::read_field( $data, $sub, $name . '_' . $index . '_' );
				}

				$rows[] = $row;
			}

			return $rows;
		}

		if ( 'group' === $type ) {
			$row = array();

			foreach ( $field['sub_fields'] ?? array() as $sub ) {
				if ( ! is_array( $sub ) || ! self::field_present( $data, $sub, $name . '_' ) ) {
					continue;
				}

				$row[ $sub['name'] ] = self::read_field( $data, $sub, $name . '_' );
			}

			return $row;
		}

		return $data[ $name ] ?? null;
	}

	/**
	 * @param array<string, mixed> $field Schema field.
	 */
	private static function store_value( array $field, mixed $value ): mixed {
		$type = (string) $field['type'];

		if ( null === $value || '' === $value ) {
			return '';
		}

		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'wysiwyg':
				return wp_kses_post( (string) $value );

			case 'number':
			case 'range':
				if ( ! is_numeric( $value ) ) {
					throw new Content_Exception( sprintf( 'Field "%s" must be a number.', $field['name'] ) );
				}
				return 0 + $value;

			case 'true_false':
				return $value ? 1 : 0;

			case 'email':
				return sanitize_email( (string) $value );

			case 'url':
			case 'oembed':
				return esc_url_raw( (string) $value );

			case 'image':
			case 'file':
				return self::attachment_id( $value, (string) $field['name'] );

			case 'gallery':
			case 'relationship':
				return self::id_list( $value, (string) $field['name'] );

			case 'link':
				if ( ! is_array( $value ) ) {
					throw new Content_Exception( sprintf( 'Field "%s" must be a link object.', $field['name'] ) );
				}
				return array(
					'url'    => esc_url_raw( (string) ( $value['url'] ?? '' ) ),
					'title'  => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
					'target' => sanitize_text_field( (string) ( $value['target'] ?? '' ) ),
				);

			case 'checkbox':
			case 'select':
				if ( is_array( $value ) ) {
					return array_map(
						static function ( $item ): string {
							return sanitize_text_field( (string) $item );
						},
						$value
					);
				}
				return sanitize_text_field( (string) $value );

			default:
				if ( is_array( $value ) ) {
					throw new Content_Exception( sprintf( 'Field "%s" (%s) does not accept a list.', $field['name'], $type ) );
				}
				return sanitize_text_field( (string) $value );
		}
	}

	private static function attachment_id( mixed $value, string $name ): int {
		if ( is_array( $value ) && isset( $value['id'] ) ) {
			$value = $value['id'];
		}

		$id = absint( $value );

		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			throw new Content_Exception( sprintf( 'Field "%s" needs an attachment ID.', $name ) );
		}

		return $id;
	}

	/**
	 * @return array<int, int>
	 */
	private static function id_list( mixed $value, string $name ): array {
		if ( ! is_array( $value ) ) {
			throw new Content_Exception( sprintf( 'Field "%s" must be a list of IDs.', $name ) );
		}

		$ids = array();

		foreach ( $value as $item ) {
			$ids[] = absint( is_array( $item ) ? ( $item['id'] ?? 0 ) : $item );
		}

		return $ids;
	}

	/**
	 * @param mixed      $term Term ID or slug.
	 * @param \WP_Taxonomy $tax  Taxonomy object.
	 */
	private static function resolve_term( string $taxonomy, mixed $term, bool $create_missing, \WP_Taxonomy $tax ): int {
		if ( is_int( $term ) || ( is_string( $term ) && ctype_digit( $term ) ) ) {
			$found = get_term( (int) $term, $taxonomy );

			if ( $found instanceof \WP_Term ) {
				return (int) $found->term_id;
			}

			throw new Content_Exception( sprintf( 'Term %s was not found in %s.', (string) $term, $taxonomy ) );
		}

		$slug = sanitize_title( (string) $term );

		if ( '' === $slug ) {
			throw new Content_Exception( sprintf( 'Empty term in %s.', $taxonomy ) );
		}

		$found = get_term_by( 'slug', $slug, $taxonomy );

		if ( $found instanceof \WP_Term ) {
			return (int) $found->term_id;
		}

		if ( ! $create_missing ) {
			throw new Content_Exception( sprintf( 'Term "%s" does not exist in %s. Creating terms is turned off.', $slug, $taxonomy ) );
		}

		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			throw new Content_Exception( sprintf( 'You cannot create %s terms.', $taxonomy ) );
		}

		$created = wp_insert_term( sanitize_text_field( (string) $term ), $taxonomy, array( 'slug' => $slug ) );

		if ( is_wp_error( $created ) ) {
			throw new Content_Exception( $created->get_error_message() );
		}

		return (int) $created['term_id'];
	}
}
