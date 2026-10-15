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
use WP_Query;

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
			'description'         => __( 'List pages, posts, and custom post types, filtered by type, status, search, author, or taxonomy terms. Returns the total match count alongside the current page.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					Support\with_fields(
						[
							'post_type'   => [
								'type'        => [ 'string', 'array' ],
								'items'       => [
									'type' => 'string',
									'enum' => Support\post_types(),
								],
								'default'     => 'page',
								'description' => __( 'One post type, or an array of them to search several in a single call.', 'hm-abilities-mcp' ),
							],
							'status'      => [
								'type'    => 'string',
								'enum'    => Support\list_statuses(),
								'default' => 'any',
							],
							'search'      => [ 'type' => 'string' ],
							'author'      => [
								'type'        => [ 'integer', 'string' ],
								'description' => __( 'Limit to one author, given as a user ID or as a user slug, login, or email. Email requires the list_users capability. Use list-users to turn a name like "Jane Smith" into a slug, or read author.slug off any returned item.', 'hm-abilities-mcp' ),
							],
							'terms'       => [
								'type'                 => 'object',
								'additionalProperties' => [
									'type'  => [ 'string', 'integer', 'array' ],
									'items' => [ 'type' => [ 'string', 'integer' ] ],
								],
								'description'          => __( 'Limit to items carrying these terms, as a map of taxonomy slug to one term value or a list of them. Values in one taxonomy match any of them; separate taxonomies must all match. Use list-terms to discover taxonomies and their terms.', 'hm-abilities-mcp' ),
							],
							'terms_field' => [
								'type'        => 'string',
								'enum'        => [ 'slug', 'name', 'id' ],
								'default'     => 'slug',
								'description' => __( 'Which term value the terms map holds.', 'hm-abilities-mcp' ),
							],
							'per_page'    => [
								'type'    => 'integer',
								'default' => 20,
								'minimum' => 1,
								'maximum' => Support\MAX_PER_PAGE,
							],
							'page'        => [
								'type'    => 'integer',
								'default' => 1,
							],
						]
					)
				)
			),
			'output_schema'       => Support\list_schema( Support\post_schema() ),
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
					Support\with_fields(
						[
							'post_type' => [
								'type'        => [ 'string', 'array' ],
								'items'       => [
									'type' => 'string',
									'enum' => Support\post_types(),
								],
								'default'     => 'post',
								'description' => __( 'One post type, or an array of them to cover several in a single call.', 'hm-abilities-mcp' ),
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
				)
			),
			'output_schema'       => Support\list_schema( Support\post_schema() ),
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
					Support\with_fields(
						[
							'id' => [
								'type'        => 'integer',
								'description' => __( 'Post ID.', 'hm-abilities-mcp' ),
							],
						]
					)
				),
				[ 'id' ]
			),
			'output_schema'       => Support\post_schema( true ),
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
			'output_schema'       => Support\post_schema(),
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
			'output_schema'       => Support\post_schema(),
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
		static function () use ( $input ) {
			$input = is_array( $input ) ? $input : [];

			$types = resolve_post_types( $input, 'page' );

			if ( is_wp_error( $types ) ) {
				return $types;
			}

			$author = resolve_author( $input );

			if ( is_wp_error( $author ) ) {
				return $author;
			}

			$tax_query = resolve_tax_query( $input );

			if ( is_wp_error( $tax_query ) ) {
				return $tax_query;
			}

			$args = array_merge(
				[
					'post_type'      => $types,
					'posts_per_page' => isset( $input['per_page'] ) ? min( Support\MAX_PER_PAGE, max( 1, (int) $input['per_page'] ) ) : 20,
					'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
				],
				Support\status_query_args( $input['status'] ?? null )
			);

			if ( ! empty( $input['search'] ) ) {
				$args['s'] = (string) $input['search'];
			}

			if ( $author > 0 ) {
				$args['author'] = $author;
			}

			if ( $tax_query ) {
				$args['tax_query'] = $tax_query;
			}

			return run_post_query( $args, Support\requested_fields( $input ) );
		}
	);
}

/**
 * Run a bounded post query and shape it into the standard list envelope.
 *
 * `$args` overrides the defaults below. `perm` defaults to `readable`, which
 * scopes private posts to the caller; left unset, `WP_Query` never reaches its
 * `read_private_posts` check.
 *
 * @param array<string, mixed> $args   Query arguments.
 * @param string[]             $fields Extra fields for `Support\format_post()`.
 * @return array<string, mixed>
 */
function run_post_query( array $args, array $fields ): array {
	$query = new WP_Query(
		array_merge(
			[
				'suppress_filters' => true,
				'perm'             => 'readable',
			],
			$args
		)
	);

	update_post_author_caches( $query->posts );

	$items = array_map(
		static fn ( WP_Post $post ): array => Support\format_post( $post, false, $fields ),
		$query->posts
	);

	return Support\list_response( $query, $items );
}

/**
 * Resolve the `post_type` input into a list of exposed post types.
 *
 * Accepts a single slug or an array of them. An unexposed slug is an error
 * rather than a silent fallback.
 *
 * @param array<string, mixed> $input    Ability input.
 * @param string               $fallback Post type used when none is given.
 * @return string[]|\WP_Error
 */
function resolve_post_types( array $input, string $fallback ) {
	if ( ! isset( $input['post_type'] ) ) {
		return [ $fallback ];
	}

	$requested = is_array( $input['post_type'] ) ? $input['post_type'] : [ $input['post_type'] ];
	$requested = array_values( array_unique( array_filter( array_map( 'strval', $requested ) ) ) );

	if ( $requested === [] ) {
		return [ $fallback ];
	}

	$allowed = Support\post_types();
	$unknown = array_values( array_diff( $requested, $allowed ) );

	if ( $unknown ) {
		return Support\error(
			'hm_abilities_invalid_post_type',
			sprintf(
				/* translators: 1: comma-separated unsupported post types, 2: comma-separated available post types. */
				__( 'Unsupported post_type: %1$s. Available post types: %2$s.', 'hm-abilities-mcp' ),
				implode( ', ', $unknown ),
				implode( ', ', $allowed )
			),
			400
		);
	}

	return $requested;
}

/**
 * Resolve the `author` input into a user ID.
 *
 * Accepts a user ID, slug or login, and an email only from a caller holding
 * `list_users`, so the filter cannot probe for addresses. A value matching no
 * user is an error rather than an empty result.
 *
 * @param array<string, mixed> $input Ability input.
 * @return int|\WP_Error User ID, or 0 when no author was given.
 */
function resolve_author( array $input ) {
	if ( ! isset( $input['author'] ) ) {
		return 0;
	}

	$author = is_string( $input['author'] ) ? trim( $input['author'] ) : $input['author'];

	if ( $author === '' ) {
		return 0;
	}

	$user = false;

	if ( is_int( $author ) || ( is_string( $author ) && ctype_digit( $author ) ) ) {
		$user = get_userdata( (int) $author );
	} else {
		$fields = current_user_can( 'list_users' ) ? [ 'slug', 'login', 'email' ] : [ 'slug', 'login' ];

		foreach ( $fields as $field ) {
			$user = get_user_by( $field, (string) $author );

			if ( $user ) {
				break;
			}
		}
	}

	if ( ! $user ) {
		return Support\error(
			'hm_abilities_author_not_found',
			__( 'No user matches that author. Pass a user ID, or the slug, login, or email of an existing user. The author.slug on any listed item is accepted.', 'hm-abilities-mcp' ),
			404
		);
	}

	return (int) $user->ID;
}

/**
 * Build the `tax_query` for the `terms` input.
 *
 * Terms within one taxonomy are OR-ed, separate taxonomies AND-ed. A taxonomy
 * outside `Support\taxonomies()` is an error rather than an ignored clause.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<int|string, mixed>|\WP_Error Empty when no terms were given.
 */
function resolve_tax_query( array $input ) {
	$terms = ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) ? $input['terms'] : [];

	if ( $terms === [] ) {
		return [];
	}

	$allowed = Support\taxonomies();
	$field   = ( isset( $input['terms_field'] ) && in_array( $input['terms_field'], [ 'slug', 'name', 'id' ], true ) ) ? (string) $input['terms_field'] : 'slug';

	$tax_query = [ 'relation' => 'AND' ];

	foreach ( $terms as $taxonomy => $values ) {
		$taxonomy = (string) $taxonomy;

		if ( ! in_array( $taxonomy, $allowed, true ) ) {
			return Support\error(
				'hm_abilities_invalid_taxonomy',
				sprintf(
					/* translators: 1: requested taxonomy slug, 2: comma-separated available taxonomy slugs. */
					__( 'Unavailable taxonomy: %1$s. Available taxonomies: %2$s.', 'hm-abilities-mcp' ),
					$taxonomy,
					$allowed ? implode( ', ', $allowed ) : __( 'none', 'hm-abilities-mcp' )
				),
				400
			);
		}

		$values = array_values( array_filter( array_map( 'strval', is_array( $values ) ? $values : [ $values ] ), 'strlen' ) );

		if ( $values === [] ) {
			continue;
		}

		$tax_query[] = [
			'taxonomy' => $taxonomy,
			'field'    => $field === 'id' ? 'term_id' : $field,
			'terms'    => $field === 'id' ? array_map( 'intval', $values ) : $values,
			'operator' => 'IN',
		];
	}

	return count( $tax_query ) > 1 ? $tax_query : [];
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
		static function () use ( $input ) {
			$input = is_array( $input ) ? $input : [];

			$types = resolve_post_types( $input, 'post' );

			if ( is_wp_error( $types ) ) {
				return $types;
			}

			$args = [
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => isset( $input['per_page'] ) ? min( Support\MAX_PER_PAGE, max( 1, (int) $input['per_page'] ) ) : 20,
				'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'date_query'     => date_range_query( $input ),
			];

			return run_post_query( $args, Support\requested_fields( $input ) );
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

			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				return Support\error( 'hm_abilities_forbidden', __( 'You are not allowed to read that post.', 'hm-abilities-mcp' ), 403 );
			}

			return Support\format_post( $post, true, Support\requested_fields( is_array( $input ) ? $input : [] ) );
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

			// A type registered with custom caps maps `create_posts` elsewhere.
			$type_object = get_post_type_object( $post_type );

			if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
				return Support\error( 'hm_abilities_forbidden', __( 'You are not allowed to create content of that post type.', 'hm-abilities-mcp' ), 403 );
			}

			if ( ! isset( $input['title'] ) || ! is_string( $input['title'] ) || $input['title'] === '' ) {
				return Support\error( 'hm_abilities_missing_title', __( 'A title is required.', 'hm-abilities-mcp' ), 400 );
			}

			$status = isset( $input['status'] ) ? (string) $input['status'] : 'draft';

			if ( ! in_array( $status, Support\statuses(), true ) ) {
				return Support\error( 'hm_abilities_invalid_status', __( 'Unsupported status.', 'hm-abilities-mcp' ), 400 );
			}

			$denied = Support\require_status_cap( $status, $post_type );

			if ( $denied ) {
				return $denied;
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
				return Support\error( 'hm_abilities_create_failed', __( 'The post could not be loaded after creating.', 'hm-abilities-mcp' ), 500 );
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

			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return Support\error( 'hm_abilities_forbidden', __( 'You are not allowed to edit that post.', 'hm-abilities-mcp' ), 403 );
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

				$denied = Support\require_status_cap( $status, $post->post_type );

				if ( $denied ) {
					return $denied;
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
