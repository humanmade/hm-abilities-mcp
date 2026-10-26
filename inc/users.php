<?php
/**
 * User abilities: find the author behind a name.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Users;

use HM\Abilities\Support;
use WP_User;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Columns a caller without `list_users` may search.
 *
 * Mirrors the set core's users endpoint falls back to, which withholds
 * `user_email` so a low-privileged caller cannot probe for addresses.
 *
 * @var string[]
 */
const PUBLIC_SEARCH_COLUMNS = [ 'ID', 'user_login', 'user_nicename', 'display_name' ];

/**
 * Columns a caller holding `list_users` may search.
 *
 * @var string[]
 */
const PRIVILEGED_SEARCH_COLUMNS = [ 'ID', 'user_login', 'user_nicename', 'user_email', 'display_name' ];

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the user abilities.
 *
 * @return void
 */
function register(): void {
	wp_register_ability(
		'hm-abilities/list-users',
		[
			'label'               => __( 'List users', 'hm-abilities-mcp' ),
			'description'         => __( 'Find users by name, so an author can be named in conversation and then filtered on. Search matches display name, username, and slug. The slug it returns is the value the author filter on list-content accepts.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'search'        => [
							'type'        => 'string',
							'description' => __( 'Match users whose display name, username, or slug contains this text. Partial names work, so "jane" reaches "Jane Smith".', 'hm-abilities-mcp' ),
						],
						'roles'         => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'Limit to these roles. Requires the list_users capability.', 'hm-abilities-mcp' ),
						],
						'has_published' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Limit to users who have published content in one of the exposed post types. Use it to list actual authors rather than every account.', 'hm-abilities-mcp' ),
						],
						'per_page'      => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => Support\MAX_PER_PAGE,
						],
						'page'          => [
							'type'    => 'integer',
							'default' => 1,
						],
					]
				)
			),
			'output_schema'       => Support\list_schema( user_schema() ),
			'execute_callback'    => __NAMESPACE__ . '\\list_users',
			'permission_callback' => __NAMESPACE__ . '\\can_read',
			'meta'                => Support\meta(
				[
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				]
			),
		]
	);
}

/**
 * Permission: read users on the targeted subsite.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_read( $input = null ) {
	return Support\can_on_site( $input, 'edit_posts' );
}

/**
 * Find users on the targeted subsite.
 *
 * Search is wrapped in wildcards: `WP_User_Query` treats a bare term as an
 * exact match.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function list_users( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ) {
			$input = is_array( $input ) ? $input : [];

			$can_list = current_user_can( 'list_users' );

			$per_page = isset( $input['per_page'] ) ? min( Support\MAX_PER_PAGE, max( 1, (int) $input['per_page'] ) ) : 20;
			$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

			$args = [
				'number'  => $per_page,
				'paged'   => $page,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => 'all',
			];

			$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

			if ( $search !== '' ) {
				$args['search']         = '*' . $search . '*';
				$args['search_columns'] = $can_list ? PRIVILEGED_SEARCH_COLUMNS : PUBLIC_SEARCH_COLUMNS;
			}

			$roles = ( isset( $input['roles'] ) && is_array( $input['roles'] ) ) ? array_values( array_filter( array_map( 'strval', $input['roles'] ) ) ) : [];

			if ( $roles ) {
				if ( ! $can_list ) {
					return Support\error(
						'hm_abilities_cannot_filter_by_role',
						__( 'You are not allowed to filter users by role.', 'hm-abilities-mcp' ),
						403
					);
				}

				$args['role__in'] = $roles;
			}

			if ( ! empty( $input['has_published'] ) ) {
				$args['has_published_posts'] = Support\post_types();
			}

			$query = new WP_User_Query( $args );
			$total = (int) $query->get_total();
			$pages = (int) ceil( $total / $per_page );

			$items = array_map(
				static fn ( WP_User $user ): array => format_user( $user, $can_list ),
				array_values( array_filter( (array) $query->get_results(), static fn ( $user ): bool => $user instanceof WP_User ) )
			);

			return [
				'items'       => $items,
				'count'       => count( $items ),
				'total'       => $total,
				'page'        => $page,
				'total_pages' => $pages,
				'has_more'    => $page < $pages,
				'next_page'   => $page < $pages ? $page + 1 : null,
			];
		}
	);
}

/**
 * Shape a user for ability output.
 *
 * `slug` is what the `author` filter on the list abilities accepts. Roles are
 * included only for a caller holding `list_users`, matching the capability core
 * requires to filter on them.
 *
 * @param WP_User $user     User object.
 * @param bool    $can_list Whether the caller holds `list_users`.
 * @return array<string, mixed>
 */
function format_user( WP_User $user, bool $can_list ): array {
	$data = [
		'id'   => (int) $user->ID,
		'name' => $user->display_name,
		'slug' => $user->user_nicename,
	];

	if ( $can_list ) {
		$data['roles'] = array_values( $user->roles );
	}

	return $data;
}

/**
 * Output schema for a user, describing `format_user()`.
 *
 * `roles` is optional because it is returned only to a caller holding
 * `list_users`.
 *
 * @return array<string, mixed>
 */
function user_schema(): array {
	return Support\output_object(
		[
			'id'    => Support\prop( 'integer', __( 'User ID.', 'hm-abilities-mcp' ) ),
			'name'  => Support\prop( 'string', __( 'User display name.', 'hm-abilities-mcp' ) ),
			'slug'  => Support\prop( 'string', __( 'User slug, which is the value the author filter on list-content accepts.', 'hm-abilities-mcp' ) ),
			'roles' => Support\output_list(
				[ 'type' => 'string' ],
				__( 'Roles the user holds on the targeted site. Returned only to a caller holding the list_users capability.', 'hm-abilities-mcp' )
			),
		],
		[ 'id', 'name', 'slug' ]
	);
}
