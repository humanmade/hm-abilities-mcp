<?php
/**
 * Navigation abilities: list menus, read items, replace items.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Navigation;

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
 * Register the navigation abilities.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'hm-abilities/list-navigation',
		[
			'label'               => __( 'List navigation menus', 'hm-abilities-mcp' ),
			'description'         => __( 'List all navigation menus (wp_navigation posts).', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema( Support\with_site_id( [] ) ),
			'execute_callback'    => __NAMESPACE__ . '\\list_menus',
			'permission_callback' => __NAMESPACE__ . '\\can_read',
			'meta'                => meta(
				[
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				]
			),
		]
	);

	wp_register_ability(
		'hm-abilities/get-navigation',
		[
			'label'               => __( 'Get navigation menu', 'hm-abilities-mcp' ),
			'description'         => __( 'Return a navigation menu and its items by ID.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'id' => [
							'type'        => 'integer',
							'description' => __( 'wp_navigation post ID.', 'hm-abilities-mcp' ),
						],
					]
				),
				[ 'id' ]
			),
			'execute_callback'    => __NAMESPACE__ . '\\get_menu',
			'permission_callback' => __NAMESPACE__ . '\\can_read',
			'meta'                => meta(
				[
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				]
			),
		]
	);

	wp_register_ability(
		'hm-abilities/update-navigation',
		[
			'label'               => __( 'Update navigation menu', 'hm-abilities-mcp' ),
			'description'         => __( 'Replace all items of a navigation menu by ID. Items: [{label, url, kind?, children?[]}].', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'id'    => [ 'type' => 'integer' ],
						'items' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'label'    => [ 'type' => 'string' ],
									'url'      => [ 'type' => 'string' ],
									'kind'     => [
										'type'    => 'string',
										'default' => 'custom',
									],
									'children' => [ 'type' => 'array' ],
								],
								'required'   => [ 'label' ],
							],
						],
					]
				),
				[ 'id', 'items' ]
			),
			'execute_callback'    => __NAMESPACE__ . '\\update_menu',
			'permission_callback' => __NAMESPACE__ . '\\can_write',
			'meta'                => meta(
				[
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				]
			),
		]
	);
}

/**
 * Build the meta block.
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
 * Permission: read navigation on the targeted subsite.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_read( $input = null ) {
	return Support\can_on_site( $input, 'edit_theme_options' );
}

/**
 * Permission: write navigation on the targeted subsite.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_write( $input = null ) {
	return Support\can_on_site( $input, 'manage_options' );
}

/**
 * List navigation menus on the targeted subsite.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function list_menus( $input = null ) {
	return Support\on_site(
		$input,
		static function (): array {
			$menus = array_map(
				static fn ( WP_Post $post ): array => [
					'id'    => $post->ID,
					'title' => get_the_title( $post ),
					'slug'  => $post->post_name,
				],
				get_posts(
					[
						'post_type'      => 'wp_navigation',
						'post_status'    => 'publish',
						'posts_per_page' => Support\MAX_PER_PAGE,
					]
				)
			);

			return [
				'menus' => $menus,
				'count' => count( $menus ),
			];
		}
	);
}

/**
 * Get a navigation menu's items.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function get_menu( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ) {
			$id = Support\validate_id( $input );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post || $post->post_type !== 'wp_navigation' ) {
				return Support\error( 'hm_abilities_not_found', __( 'No navigation menu found for that ID.', 'hm-abilities-mcp' ), 404 );
			}

			return [
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'items' => Support\markup_to_nav_items( $post->post_content ),
			];
		}
	);
}

/**
 * Replace a navigation menu's items.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function update_menu( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ) {
			$id = Support\validate_id( $input );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post || $post->post_type !== 'wp_navigation' ) {
				return Support\error( 'hm_abilities_not_found', __( 'No navigation menu found for that ID.', 'hm-abilities-mcp' ), 404 );
			}

			if ( ! is_array( $input ) || ! isset( $input['items'] ) ) {
				return Support\error( 'hm_abilities_missing_items', __( 'An items array is required.', 'hm-abilities-mcp' ), 400 );
			}

			if ( ! is_array( $input['items'] ) ) {
				return Support\error( 'hm_abilities_invalid_items', __( 'The items field must be an array.', 'hm-abilities-mcp' ), 400 );
			}

			$items = $input['items'];

			$result = wp_update_post(
				[
					'ID'           => $id,
					'post_content' => Support\nav_items_to_markup( $items ),
				],
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return [
				'id'         => $id,
				'item_count' => count( $items ),
			];
		}
	);
}
