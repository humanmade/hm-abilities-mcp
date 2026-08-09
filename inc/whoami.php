<?php
/**
 * "Who am I" ability.
 *
 * Registers the `hm-abilities/whoami` ability, which returns the profile of the
 * current user. A read-only proof of concept for the WordPress Abilities API.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Whoami;

use HM\Abilities\Support;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability key.
 *
 * @var string
 */
const ABILITY = 'hm-abilities/whoami';

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the `hm-abilities/whoami` ability.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		ABILITY,
		[
			'label'               => __( 'Who am I', 'hm-abilities-mcp' ),
			'description'         => __( 'Returns the profile of the currently authenticated user, including their roles on the targeted subsite.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema( Support\with_site_id( [] ) ),
			'execute_callback'    => __NAMESPACE__ . '\\execute',
			'permission_callback' => __NAMESPACE__ . '\\check_permission',
			'output_schema'       => [
				'type'                 => 'object',
				'properties'           => [
					'id'           => [
						'type'        => 'integer',
						'description' => __( 'User ID.', 'hm-abilities-mcp' ),
					],
					'username'     => [
						'type'        => 'string',
						'description' => __( 'User login name.', 'hm-abilities-mcp' ),
					],
					'display_name' => [
						'type'        => 'string',
						'description' => __( 'Public display name.', 'hm-abilities-mcp' ),
					],
					'email'        => [
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'User email address.', 'hm-abilities-mcp' ),
					],
					'roles'        => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => __( 'Roles assigned to the user on the targeted site.', 'hm-abilities-mcp' ),
					],
					'avatar_url'   => [
						'type'        => 'string',
						'format'      => 'uri',
						'description' => __( 'URL of the user avatar.', 'hm-abilities-mcp' ),
					],
					'site'         => [
						'type'        => 'object',
						'description' => __( 'The site (blog) this profile and its roles apply to. On multisite this identifies which subsite the caller is acting on.', 'hm-abilities-mcp' ),
						'properties'  => [
							'id'   => [
								'type'        => 'integer',
								'description' => __( 'Blog ID within the network.', 'hm-abilities-mcp' ),
							],
							'name' => [
								'type'        => 'string',
								'description' => __( 'Site name.', 'hm-abilities-mcp' ),
							],
							'url'  => [
								'type'        => 'string',
								'format'      => 'uri',
								'description' => __( 'Site home URL.', 'hm-abilities-mcp' ),
							],
						],
					],
				],
				'required'             => [ 'id', 'username', 'display_name', 'email', 'roles', 'site' ],
				'additionalProperties' => false,
			],
			'meta'                => [
				'show_in_rest' => true,
				'mcp'          => [
					'public' => true,
				],
				'annotations'  => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		]
	);
}

/**
 * Permission check: the caller must be logged in, and — since `execute()` can
 * switch into any subsite named by `site_id` — must belong to that subsite
 * (super admins pass everywhere). Without this, any logged-in user could probe
 * arbitrary subsites and read their name, URL, and the caller's roles there.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|WP_Error True when authorized, error otherwise.
 */
function check_permission( $input = null ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'hm_abilities_whoami_not_logged_in',
			__( 'You must be logged in to view your profile.', 'hm-abilities-mcp' ),
			[ 'status' => 401 ]
		);
	}

	$blog_id = Support\resolve_target_site( $input );

	if ( is_wp_error( $blog_id ) ) {
		return $blog_id;
	}

	if ( ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id(), $blog_id ) ) {
		return new WP_Error(
			'hm_abilities_whoami_forbidden',
			__( 'You are not a member of that site.', 'hm-abilities-mcp' ),
			[ 'status' => 403 ]
		);
	}

	return true;
}

/**
 * Build the current user's profile for the targeted subsite.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return array<string, mixed>|WP_Error Profile data, or an error if no user.
 */
function execute( $input = null ) {
	return Support\on_site(
		$input,
		static function () {
			$user = wp_get_current_user();

			if ( ! $user || $user->ID === 0 ) {
				return new WP_Error(
					'hm_abilities_whoami_no_user',
					__( 'No authenticated user found.', 'hm-abilities-mcp' ),
					[ 'status' => 401 ]
				);
			}

			return [
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'roles'        => array_values( $user->roles ),
				'avatar_url'   => (string) get_avatar_url( $user->ID ),
				'site'         => [
					'id'   => get_current_blog_id(),
					'name' => get_bloginfo( 'name' ),
					'url'  => home_url(),
				],
			];
		}
	);
}
