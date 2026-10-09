<?php
/**
 * WordPress abilities exposed to the MCP Adapter.
 *
 * @package MCPContent
 */

declare(strict_types=1);

namespace Procoders\McpContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers procoders-mcp-content/* abilities.
 */
final class Abilities {

	private const PREFIX = 'procoders-mcp-content';

	private const STATUSES = array( 'draft', 'pending', 'publish', 'private', 'future', 'trash' );

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || wp_has_ability_category( 'procoders-mcp-content' ) ) {
			return;
		}

		wp_register_ability_category(
			'procoders-mcp-content',
			array(
				'label'       => __( 'Procoders MCP Content', 'procoders-mcp-content' ),
				'description' => __( 'Read and write site content through the MCP Adapter.', 'procoders-mcp-content' ),
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::add( 'list-post-types', __( 'List the post types enabled for MCP, with their access level.', 'procoders-mcp-content' ), true, array( self::class, 'list_post_types' ), self::can_discover( ... ) );
		self::add( 'list-blocks', __( 'List blocks that can be used on a post type.', 'procoders-mcp-content' ), true, array( self::class, 'list_blocks' ), self::can_read_type_input( ... ) );
		self::add( 'get-block-schema', __( 'Return the ACF field schema for one block.', 'procoders-mcp-content' ), true, array( self::class, 'get_block_schema' ), self::can_read_block_input( ... ) );
		self::add( 'get-post-fields', __( 'Return ACF fields stored on a post type, outside blocks.', 'procoders-mcp-content' ), true, array( self::class, 'get_post_fields' ), self::can_read_type_input( ... ) );
		self::add( 'list-posts', __( 'List posts of an enabled post type.', 'procoders-mcp-content' ), true, array( self::class, 'list_posts' ), self::can_read_type_input( ... ) );
		self::add( 'get-post', __( 'Get one post, including structured blocks and fields.', 'procoders-mcp-content' ), true, array( self::class, 'get_post' ), self::can_read_post_input( ... ) );
		self::add( 'create-post', __( 'Create a post. Content is a list of blocks with field values. Author is a user ID, login, or email. Date is the original local datetime. A term may be an object with name and parent.', 'procoders-mcp-content' ), false, array( self::class, 'create_post' ), self::can_create_input( ... ) );
		self::add( 'update-post', __( 'Update a post. Only provided fields, blocks, terms, author, and date change. A term may be an object with name and parent.', 'procoders-mcp-content' ), false, array( self::class, 'update_post' ), self::can_update_input( ... ) );
		self::add( 'upload-media', __( 'Download a file from a URL into the media library.', 'procoders-mcp-content' ), false, array( self::class, 'upload_media' ), self::can_upload( ... ) );
	}

	/**
	 * @param array<string, mixed>|null $input Unused.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_post_types( ?array $input = null ): array {
		unset( $input );
		$types = array();

		foreach ( Catalog::manageable_post_types() as $name => $object ) {
			$level = Access::level( $name );

			if ( '' === $level || ! current_user_can( $object->cap->edit_posts ) ) {
				continue;
			}

			$types[] = array(
				'name'               => $name,
				'label'              => $object->labels->singular_name,
				'level'              => $level,
				'allow_trash'        => Access::allows_trash( $name ),
				'allow_create_terms' => Access::allows_create_terms( $name ),
			);
		}

		return $types;
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_blocks( array $input ): array {
		$post_type = self::post_type_from( $input );
		$blocks    = array();

		foreach ( Catalog::blocks_for_post_type( $post_type ) as $block ) {
			if ( ! Access::block_allowed( $post_type, $block['name'] ) ) {
				continue;
			}

			$blocks[] = $block;
		}

		return $blocks;
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_block_schema( array $input ): array {
		$post_type = self::post_type_from( $input );
		$name      = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';

		if ( '' === $name || ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			throw new Content_Exception( 'Block is not registered.' );
		}

		if ( ! Access::block_allowed( $post_type, $name ) ) {
			throw new Content_Exception( 'Block is not enabled for this post type.' );
		}

		return array(
			'name'   => $name,
			'fields' => Catalog::block_field_schema( $name ),
		);
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_post_fields( array $input ): array {
		$post_type = self::post_type_from( $input );

		return array(
			'post_type' => $post_type,
			'fields'    => Catalog::post_field_schema( $post_type ),
		);
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_posts( array $input ): array {
		$post_type = self::post_type_from( $input );
		$per_page  = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page  = max( 1, min( 100, $per_page ) );
		$status    = isset( $input['status'] ) && is_scalar( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';
		$search    = isset( $input['search'] ) && is_scalar( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';

		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => self::status_query( $post_type, $status ),
				'posts_per_page'         => $per_page,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				's'                      => $search,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$posts = array();

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
				$posts[] = self::summary( $post );
			}
		}

		return $posts;
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_post( array $input ): array {
		$post = self::post_from_input( $input );

		return self::details( $post );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function create_post( array $input ): array {
		$post_type = self::post_type_from( $input );
		$title     = self::text_value( $input['title'] ?? null, 'Title' );

		if ( '' === $title ) {
			throw new Content_Exception( 'Title is required.' );
		}

		$post_id = wp_insert_post(
			array_merge(
				array(
					'post_type'    => $post_type,
					'post_title'   => $title,
					'post_status'  => self::resolve_status( $post_type, $input['status'] ?? 'draft', true ),
					'post_excerpt' => isset( $input['excerpt'] ) ? self::text_value( $input['excerpt'], 'Excerpt', true ) : '',
					'post_name'    => isset( $input['slug'] ) ? sanitize_title( self::text_value( $input['slug'], 'Slug' ) ) : '',
					'post_content' => self::content_from_input( $input, $post_type ),
				),
				self::authorship_from_input( $input, $post_type )
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new Content_Exception( $post_id->get_error_message() );
		}

		self::apply_extras( (int) $post_id, $post_type, $input );

		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post ) {
			throw new Content_Exception( 'The post was created but could not be loaded.' );
		}

		return self::summary( $post );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function update_post( array $input ): array {
		$post = self::post_from_input( $input );

		if ( ! self::status_is_writable( $post->post_status, $post->post_type ) ) {
			throw new Content_Exception( 'This post cannot be edited at the current access level.' );
		}

		$post_data = array( 'ID' => $post->ID );

		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = self::text_value( $input['title'], 'Title' );
		}

		if ( isset( $input['excerpt'] ) ) {
			$post_data['post_excerpt'] = self::text_value( $input['excerpt'], 'Excerpt', true );
		}

		if ( isset( $input['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( self::text_value( $input['slug'], 'Slug' ) );
		}

		if ( isset( $input['status'] ) ) {
			$post_data['post_status'] = self::resolve_status( $post->post_type, $input['status'], false );

			if ( 'trash' === $post_data['post_status'] && ! current_user_can( 'delete_post', $post->ID ) ) {
				throw new Content_Exception( 'You cannot trash this post.' );
			}
		}

		if ( array_key_exists( 'content', $input ) ) {
			$post_data['post_content'] = self::content_from_input( $input, $post->post_type );
		}

		$post_data = array_merge( $post_data, self::authorship_from_input( $input, $post->post_type ) );

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				throw new Content_Exception( $result->get_error_message() );
			}
		}

		self::apply_extras( $post->ID, $post->post_type, $input );

		$updated = get_post( $post->ID );

		if ( ! $updated instanceof \WP_Post ) {
			throw new Content_Exception( 'The post could not be loaded after update.' );
		}

		return self::summary( $updated );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array{id: int, url: string, mime: string}
	 */
	public static function upload_media( array $input ): array {
		if ( ! isset( $input['url'] ) || ! is_scalar( $input['url'] ) ) {
			throw new Content_Exception( 'A file URL is required.' );
		}

		$url   = (string) $input['url'];
		$title = isset( $input['title'] ) ? self::text_value( $input['title'], 'Title' ) : '';
		$alt   = isset( $input['alt'] ) ? self::text_value( $input['alt'], 'Alt' ) : '';

		if ( '' === $url ) {
			throw new Content_Exception( 'A file URL is required.' );
		}

		return Content::upload_from_url( $url, $title, $alt );
	}

	/**
	 * @param array<string, mixed>|null $input Unused for discovery.
	 */
	private static function can_discover( ?array $input = null ): bool {
		unset( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		foreach ( Catalog::manageable_post_types() as $name => $object ) {
			if ( Access::allows( $name, 'read' ) && current_user_can( $object->cap->edit_posts ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function can_read_type_input( mixed $input ): bool {
		if ( ! is_array( $input ) ) {
			return false;
		}

		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';

		return self::user_can_read_type( $post_type );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function can_read_block_input( mixed $input ): bool {
		if ( ! is_array( $input ) ) {
			return false;
		}

		$post_type = isset( $input['post_type'] ) && is_scalar( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$name      = isset( $input['name'] ) && is_scalar( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';

		return self::user_can_read_type( $post_type ) && Access::block_allowed( $post_type, $name );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function can_read_post_input( mixed $input ): bool {
		if ( ! is_array( $input ) ) {
			return false;
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return self::user_can_read_type( $post->post_type ) && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function can_create_input( mixed $input ): bool {
		if ( ! is_array( $input ) ) {
			return false;
		}

		$post_type = isset( $input['post_type'] ) && is_scalar( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$object    = get_post_type_object( $post_type );

		if ( ! $object || ! Access::allows( $post_type, 'draft' ) || ! current_user_can( $object->cap->create_posts ) ) {
			return false;
		}

		$status = isset( $input['status'] ) && is_scalar( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';

		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return Access::allows( $post_type, 'publish' ) && current_user_can( $object->cap->publish_posts );
		}

		return 'trash' !== $status;
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function can_update_input( mixed $input ): bool {
		if ( ! is_array( $input ) ) {
			return false;
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		if ( ! Access::allows( $post->post_type, 'draft' ) ) {
			return false;
		}

		if ( ! self::status_is_writable( $post->post_status, $post->post_type ) ) {
			return false;
		}

		if ( ! isset( $input['status'] ) ) {
			return true;
		}

		$status = is_scalar( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';

		if ( 'trash' === $status ) {
			return Access::allows_trash( $post->post_type ) && current_user_can( 'delete_post', $post->ID );
		}

		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			$object = get_post_type_object( $post->post_type );

			return $object && Access::allows( $post->post_type, 'publish' ) && current_user_can( $object->cap->publish_posts );
		}

		return in_array( $status, array( 'draft', 'pending' ), true );
	}

	/**
	 * @param array<string, mixed>|null $input Unused.
	 */
	private static function can_upload( ?array $input = null ): bool {
		unset( $input );
		return is_user_logged_in() && current_user_can( 'upload_files' ) && Access::allows_media_upload();
	}

	private static function user_can_read_type( string $post_type ): bool {
		$object = get_post_type_object( $post_type );

		if ( ! $object ) {
			return false;
		}

		return Access::allows( $post_type, 'read' ) && current_user_can( $object->cap->edit_posts );
	}

	/**
	 * @param callable $callback  Execute callback.
	 * @param callable $permission Permission callback.
	 */
	private static function add( string $name, string $description, bool $readonly, callable $callback, callable $permission ): void {
		wp_register_ability(
			self::PREFIX . '/' . $name,
			array(
				'label'               => $name,
				'description'         => $description,
				'category'            => 'procoders-mcp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => $callback,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations' => array(
						'readonly'    => $readonly,
						'destructive' => ! $readonly && 'upload-media' !== $name,
						'idempotent'  => $readonly || 'update-post' === $name,
					),
					'public'      => true,
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function post_type_from( array $input ): string {
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';

		if ( ! self::user_can_read_type( $post_type ) && ! ( Access::allows( $post_type, 'draft' ) && self::user_can_edit_type( $post_type ) ) ) {
			throw new Content_Exception( 'That post type is not available.' );
		}

		return $post_type;
	}

	private static function user_can_edit_type( string $post_type ): bool {
		$object = get_post_type_object( $post_type );

		return $object && current_user_can( $object->cap->edit_posts );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function post_from_input( array $input ): \WP_Post {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array_keys( Catalog::manageable_post_types() ), true ) ) {
			throw new Content_Exception( 'Post not found.' );
		}

		if ( ! Access::allows( $post->post_type, 'read' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new Content_Exception( 'You cannot read this post.' );
		}

		return $post;
	}

	/**
	 * @param mixed $status Requested status.
	 */
	private static function resolve_status( string $post_type, mixed $status, bool $is_create ): string {
		if ( null === $status || '' === $status ) {
			$status = 'draft';
		}

		if ( ! is_scalar( $status ) ) {
			throw new Content_Exception( 'Unsupported post status.' );
		}

		$status = sanitize_key( (string) $status );

		if ( '' === $status ) {
			$status = 'draft';
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new Content_Exception( 'Unsupported post status.' );
		}

		if ( 'trash' === $status ) {
			if ( $is_create || ! Access::allows_trash( $post_type ) ) {
				throw new Content_Exception( 'Moving this post type to the trash is turned off.' );
			}

			return $status;
		}

		if ( ! Access::allows( $post_type, 'draft' ) ) {
			throw new Content_Exception( 'Writing is turned off for this post type.' );
		}

		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			$object = get_post_type_object( $post_type );

			if ( ! Access::allows( $post_type, 'publish' ) ) {
				throw new Content_Exception( 'Publishing is turned off for this post type.' );
			}

			if ( ! $object || ! current_user_can( $object->cap->publish_posts ) ) {
				throw new Content_Exception( 'You cannot publish this post type.' );
			}
		}

		return $status;
	}

	/**
	 * Draft access can change drafts only. Live statuses need the publish level.
	 */
	private static function status_is_writable( string $status, string $post_type ): bool {
		if ( in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return Access::allows( $post_type, 'draft' );
		}

		if ( 'trash' === $status ) {
			return Access::allows_trash( $post_type );
		}

		return Access::allows( $post_type, 'publish' );
	}

	/**
	 * Author and original publication date for create and update.
	 *
	 * Author accepts a user ID, login, or email. Date is local site time,
	 * `Y-m-d H:i:s`. `date_gmt` overrides the computed GMT value.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, int|string>
	 */
	private static function authorship_from_input( array $input, string $post_type ): array {
		$args   = array();
		$author = self::author_id_from_input( $input, $post_type );

		if ( null !== $author ) {
			$args['post_author'] = $author;
		}

		if ( isset( $input['date'] ) ) {
			$args['post_date'] = self::mysql_datetime( $input['date'], 'Date' );
		}

		if ( isset( $input['date_gmt'] ) ) {
			$args['post_date_gmt'] = self::mysql_datetime( $input['date_gmt'], 'GMT date' );
		} elseif ( isset( $args['post_date'] ) ) {
			$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
		}

		return $args;
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function author_id_from_input( array $input, string $post_type ): ?int {
		if ( ! array_key_exists( 'author', $input ) || null === $input['author'] || '' === $input['author'] ) {
			return null;
		}

		$author = $input['author'];
		$user   = false;

		if ( is_int( $author ) || ( is_string( $author ) && ctype_digit( $author ) ) ) {
			$user = get_userdata( (int) $author );
		} elseif ( is_string( $author ) ) {
			$user = get_user_by( 'login', $author );

			if ( ! $user && is_email( $author ) ) {
				$user = get_user_by( 'email', $author );
			}
		}

		if ( ! $user instanceof \WP_User ) {
			throw new Content_Exception( 'Author was not found. Pass a user ID, login, or email.' );
		}

		$object = get_post_type_object( $post_type );
		$cap    = $object ? $object->cap->edit_others_posts : 'edit_others_posts';

		if ( (int) $user->ID !== get_current_user_id() && ! current_user_can( $cap ) ) {
			throw new Content_Exception( 'You cannot assign posts to another author.' );
		}

		return (int) $user->ID;
	}

	private static function mysql_datetime( mixed $value, string $label ): string {
		if ( ! is_scalar( $value ) ) {
			throw new Content_Exception( sprintf( '%s must be text.', $label ) );
		}

		$raw = str_replace( 'T', ' ', trim( (string) $value ) );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			$raw .= ' 00:00:00';
		}

		$raw  = substr( $raw, 0, 19 );
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw );

		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $raw ) {
			throw new Content_Exception( sprintf( '%s must be a date like 2024-01-15 12:00:00.', $label ) );
		}

		return $date->format( 'Y-m-d H:i:s' );
	}

	private static function text_value( mixed $value, string $label, bool $textarea = false ): string {
		if ( ! is_scalar( $value ) ) {
			throw new Content_Exception( sprintf( '%s must be text.', $label ) );
		}

		$string = (string) $value;

		return $textarea ? sanitize_textarea_field( $string ) : sanitize_text_field( $string );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function content_from_input( array $input, string $post_type ): string {
		if ( ! array_key_exists( 'content', $input ) || null === $input['content'] ) {
			return '';
		}

		if ( ! is_array( $input['content'] ) ) {
			throw new Content_Exception( 'Content must be a list of blocks.' );
		}

		return Content::blocks_to_html( $input['content'], $post_type );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 */
	private static function apply_extras( int $post_id, string $post_type, array $input ): void {
		if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
			Content::update_post_fields( $post_id, $post_type, $input['fields'] );
		}

		if ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) {
			Content::assign_terms( $post_id, $post_type, $input['terms'], Access::allows_create_terms( $post_type ) );
		}

		if ( array_key_exists( 'featured_image_id', $input ) ) {
			$image_id = absint( $input['featured_image_id'] );

			if ( 0 === $image_id ) {
				delete_post_thumbnail( $post_id );
			} else {
				Content::assert_usable_media( $image_id, 'image' );
				set_post_thumbnail( $post_id, $image_id );
			}
		}
	}

	/**
	 * @return array<int, string>|string
	 */
	private static function status_query( string $post_type, string $status ) {
		if ( 'trash' === $status ) {
			if ( ! Access::allows_trash( $post_type ) ) {
				throw new Content_Exception( 'Trash is not enabled for this post type.' );
			}

			return 'trash';
		}

		if ( 'any' === $status ) {
			return array( 'publish', 'draft', 'pending', 'private', 'future' );
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new Content_Exception( 'Unsupported post status.' );
		}

		return $status;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function summary( \WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'title'    => get_the_title( $post ),
			'status'   => $post->post_status,
			'slug'     => $post->post_name,
			'date'     => get_the_date( DATE_ATOM, $post ),
			'link'     => get_permalink( $post ) ?: '',
			'edit_url' => get_edit_post_link( $post->ID, 'raw' ) ?: '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function details( \WP_Post $post ): array {
		$summary             = self::summary( $post );
		$summary['excerpt']  = $post->post_excerpt;
		$summary['content']  = Content::html_to_blocks( $post->post_content, $post->post_type );
		$summary['fields']   = Content::read_post_fields( $post->ID, $post->post_type );
		$summary['terms']    = Content::read_terms( $post->ID );
		$thumbnail           = (int) get_post_thumbnail_id( $post );

		if ( $thumbnail > 0 ) {
			$summary['featured_image_id'] = $thumbnail;
		}

		return $summary;
	}
}
