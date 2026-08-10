<?php
/**
 * HM Abilities category.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Category;

use HM\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Wire up the category registration.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the shared `hm-abilities` ability category.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		Support\CATEGORY,
		[
			'label'       => __( 'HM Abilities', 'hm-abilities-mcp' ),
			'description' => __( 'Abilities exposed to MCP by the HM Abilities MCP plugin.', 'hm-abilities-mcp' ),
		]
	);
}
