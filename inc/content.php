<?php
/**
 * Content abilities: list, read, create, update posts and pages.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Content;

use HM\Abilities\Support;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the content abilities.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$read  = [
		'readonly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];
	$write = [
		'readonly'    => false,
		'destructive' => true,
		'idempotent'  => true,
	];

	wp_register_ability(
		'hm-abilities/list-content',
		[
			'label'               => __( 'List content', 'hm-abilities-mcp' ),
			'description'         => __( 'List pages, posts, and custom post types with optional filters.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'post_type' => [
							'type'    => 'string',
							'enum'    => Support\post_types(),
							'default' => 'page',
						],
						'status'    => [
							'type'    => 'string',
							'enum'    => Support\list_statuses(),
							'default' => 'any',
						],
						'search'    => [ 'type' => 'string' ],
						'per_page'  => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => Support\MAX_PER_PAGE,
						],
						'page'      => [
							'type'    => 'integer',
							'default' => 1,
						],
					]
				)
			),
			'execute_callback'    => __NAMESPACE__ . '\\list_content',
			'permission_callback' => __NAMESPACE__ . '\\can_read',
			'meta'                => meta( $read ),
		]
	);

	wp_register_ability(
		'hm-abilities/list-recent-articles',
		[
			'label'               => __( 'List recent articles', 'hm-abilities-mcp' ),
			'description'         => __( 'List recently published articles within a published-date window, newest first. Defaults to articles published today.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'post_type' => [
							'type'    => 'string',
							'enum'    => Support\post_types(),
							'default' => 'post',
						],
						'after'     => [
							'type'        => 'string',
							'description' => __( 'Start of the published-date window (any strtotime format, e.g. "2026-07-01" or "-7 days"). Defaults to the start of today in the site timezone.', 'hm-abilities-mcp' ),
						],
						'before'    => [
							'type'        => 'string',
							'description' => __( 'End of the published-date window. Defaults to the end of today in the site timezone.', 'hm-abilities-mcp' ),
						],
						'per_page'  => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => Support\MAX_PER_PAGE,
						],
						'page'      => [
							'type'    => 'integer',
							'default' => 1,
						],
					]
				)
			),
			'execute_callback'    => __NAMESPACE__ . '\\list_recent_articles',
			'permission_callback' => __NAMESPACE__ . '\\can_read',
			'meta'                => meta( $read ),
		]
	);

	wp_register_ability(
		'hm-abilities/get-content',
		[
			'label'               => __( 'Get content', 'hm-abilities-mcp' ),
			'description'         => __( 'Return a single post/page including its raw block markup.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'id' => [
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'hm-abilities-mcp' ),
						],
					]
				),
				[ 'id' ]
			),
			'execute_callback'    => __NAMESPACE__ . '\\get_content',
			'permission_callback' => __NAMESPACE__ . '\\can_read',
			'meta'                => meta( $read ),
		]
	);

	wp_register_ability(
		'hm-abilities/create-content',
		[
			'label'               => __( 'Create content', 'hm-abilities-mcp' ),
			'description'         => __( 'Create a page, post, or custom post type with block markup.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'post_type' => [
							'type' => 'string',
							'enum' => Support\post_types(),
						],
						'title'     => [ 'type' => 'string' ],
						'status'    => [
							'type'    => 'string',
							'enum'    => Support\statuses(),
							'default' => 'draft',
						],
						'slug'      => [ 'type' => 'string' ],
						'content'   => [
							'type'        => 'string',
							'description' => __( 'Block markup.', 'hm-abilities-mcp' ),
						],
					]
				),
				[ 'post_type', 'title' ]
			),
			'execute_callback'    => __NAMESPACE__ . '\\create_content',
			'permission_callback' => __NAMESPACE__ . '\\can_write',
			'meta'                => meta(
				[
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				]
			),
		]
	);

	wp_register_ability(
		'hm-abilities/update-content',
		[
			'label'               => __( 'Update content', 'hm-abilities-mcp' ),
			'description'         => __( 'Update an existing post/page. Content fully replaces the existing block markup.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'id'      => [ 'type' => 'integer' ],
						'title'   => [ 'type' => 'string' ],
						'status'  => [
							'type' => 'string',
							'enum' => Support\statuses(),
						],
						'slug'    => [ 'type' => 'string' ],
						'content' => [
							'type'        => 'string',
							'description' => __( 'Replacement block markup.', 'hm-abilities-mcp' ),
						],
					]
				),
				[ 'id' ]
			),
			'execute_callback'    => __NAMESPACE__ . '\\update_content',
			'permission_callback' => __NAMESPACE__ . '\\can_write',
			'meta'                => meta( $write ),
		]
	);
}

/**
 * Build the meta block shared by content abilities.
 *
 * @param array<string, bool> $annotations Annotation flags.
 * @return array<string, mixed>
 */
function meta( array $annotations ): array {
	return [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => $annotations,
	];
}

/**
 * Permission: read content on the targeted subsite.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_read( $input = null ) {
	return Support\can_on_site( $input, 'edit_posts' );
}

/**
 * Permission: write content on the targeted subsite.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_write( $input = null ) {
	return Support\can_write_on_site( $input, 'manage_options' );
}

/**
 * List content on the targeted subsite.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function list_content( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ): array {
			$input = is_array( $input ) ? $input : [];

			$type = ( isset( $input['post_type'] ) && in_array( $input['post_type'], Support\post_types(), true ) ) ? $input['post_type'] : 'page';

			$status = ( isset( $input['status'] ) && in_array( $input['status'], Support\list_statuses(), true ) ) ? $input['status'] : 'any';

			$query = [
				'post_type'      => $type,
				'post_status'    => $status,
				'posts_per_page' => isset( $input['per_page'] ) ? min( Support\MAX_PER_PAGE, max( 1, (int) $input['per_page'] ) ) : 20,
				'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			];

			if ( ! empty( $input['search'] ) ) {
				$query['s'] = (string) $input['search'];
			}

			$items = array_map(
				static fn ( WP_Post $post ): array => Support\format_post( $post ),
				get_posts( $query )
			);

			return [
				'items' => $items,
				'count' => count( $items ),
			];
		}
	);
}

/**
 * List recently published articles within a date window on the targeted subsite.
 *
 * Restricted to published posts and ordered newest-first. When neither `after`
 * nor `before` is supplied the window is the current day in the site timezone.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function list_recent_articles( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ): array {
			$input = is_array( $input ) ? $input : [];

			$type = ( isset( $input['post_type'] ) && in_array( $input['post_type'], Support\post_types(), true ) ) ? $input['post_type'] : 'post';

			$query = [
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => isset( $input['per_page'] ) ? min( Support\MAX_PER_PAGE, max( 1, (int) $input['per_page'] ) ) : 20,
				'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'date_query'     => date_range_query( $input ),
			];

			$items = array_map(
				static fn ( WP_Post $post ): array => article_summary( $post ),
				get_posts( $query )
			);

			return [
				'items' => $items,
				'count' => count( $items ),
			];
		}
	);
}

/**
 * Build the `WP_Query` date_query for the recent-articles window.
 *
 * Matches on `post_date` (site-local), inclusive of both bounds. With no bounds
 * given it defaults to the current day in the site timezone, so "today" tracks
 * the site's clock rather than UTC.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<int, array<string, mixed>>
 */
function date_range_query( array $input ): array {
	$after  = ( isset( $input['after'] ) && is_string( $input['after'] ) ) ? trim( $input['after'] ) : '';
	$before = ( isset( $input['before'] ) && is_string( $input['before'] ) ) ? trim( $input['before'] ) : '';

	if ( $after === '' && $before === '' ) {
		$today = current_time( 'Y-m-d' );

		return [
			[
				'after'     => $today . ' 00:00:00',
				'before'    => $today . ' 23:59:59',
				'inclusive' => true,
			],
		];
	}

	$clause = [ 'inclusive' => true ];

	if ( $after !== '' ) {
		$clause['after'] = $after;
	}

	if ( $before !== '' ) {
		$clause['before'] = $before;
	}

	return [ $clause ];
}

/**
 * Shape an article for the recent-articles list, adding the published date.
 *
 * @param WP_Post $post Post object.
 * @return array<string, mixed>
 */
function article_summary( WP_Post $post ): array {
	$data         = Support\format_post( $post );
	$data['date'] = (string) get_post_time( 'c', false, $post );

	return $data;
}

/**
 * Get one content item with block markup.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function get_content( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ) {
			$id = Support\validate_id( $input );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				return Support\error( 'hm_abilities_not_found', __( 'No post found for that ID.', 'hm-abilities-mcp' ), 404 );
			}

			if ( ! in_array( $post->post_type, Support\post_types(), true ) ) {
				return Support\error( 'hm_abilities_forbidden', __( 'That post type is not available through this ability.', 'hm-abilities-mcp' ), 403 );
			}

			return Support\format_post( $post, true );
		}
	);
}

/**
 * Create content.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function create_content( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ) {
			$input = is_array( $input ) ? $input : [];

			$post_type = $input['post_type'] ?? '';

			if ( ! in_array( $post_type, Support\post_types(), true ) ) {
				return Support\error( 'hm_abilities_invalid_post_type', __( 'Unsupported post_type.', 'hm-abilities-mcp' ), 400 );
			}

			if ( ! isset( $input['title'] ) || ! is_string( $input['title'] ) || $input['title'] === '' ) {
				return Support\error( 'hm_abilities_missing_title', __( 'A title is required.', 'hm-abilities-mcp' ), 400 );
			}

			$status = isset( $input['status'] ) ? (string) $input['status'] : 'draft';

			if ( ! in_array( $status, Support\statuses(), true ) ) {
				return Support\error( 'hm_abilities_invalid_status', __( 'Unsupported status.', 'hm-abilities-mcp' ), 400 );
			}

			$id = wp_insert_post(
				[
					'post_type'    => $post_type,
					'post_title'   => $input['title'],
					'post_status'  => $status,
					'post_author'  => get_current_user_id(),
					'post_name'    => isset( $input['slug'] ) ? (string) $input['slug'] : '',
					'post_content' => isset( $input['content'] ) ? (string) $input['content'] : '',
				],
				true
			);

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				return [ 'id' => $id ];
			}

			return Support\format_post( $post );
		}
	);
}

/**
 * Update content.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function update_content( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ) {
			$id = Support\validate_id( $input );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				return Support\error( 'hm_abilities_not_found', __( 'No post found for that ID.', 'hm-abilities-mcp' ), 404 );
			}

			if ( ! in_array( $post->post_type, Support\post_types(), true ) ) {
				return Support\error( 'hm_abilities_forbidden', __( 'That post type is not available through this ability.', 'hm-abilities-mcp' ), 403 );
			}

			$update = [ 'ID' => $id ];

			if ( isset( $input['title'] ) ) {
				$update['post_title'] = (string) $input['title'];
			}
			if ( isset( $input['status'] ) ) {
				$status = (string) $input['status'];

				if ( ! in_array( $status, Support\statuses(), true ) ) {
					return Support\error( 'hm_abilities_invalid_status', __( 'Unsupported status.', 'hm-abilities-mcp' ), 400 );
				}

				$update['post_status'] = $status;
			}
			if ( isset( $input['slug'] ) ) {
				$update['post_name'] = (string) $input['slug'];
			}
			if ( isset( $input['content'] ) ) {
				$update['post_content'] = (string) $input['content'];
			}

			$result = wp_update_post( $update, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				return Support\error( 'hm_abilities_update_failed', __( 'The post could not be loaded after updating.', 'hm-abilities-mcp' ), 500 );
			}

			return Support\format_post( $post );
		}
	);
}
