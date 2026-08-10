<?php
/**
 * Block helper abilities: validate markup, list registered block types.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Blocks;

use HM\Abilities\Support;
use WP_Block_Type_Registry;

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
 * Register the block helper abilities.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$read = [
		'readonly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	wp_register_ability(
		'hm-abilities/validate-blocks',
		[
			'label'               => __( 'Validate blocks', 'hm-abilities-mcp' ),
			'description'         => __( 'Parse block markup and report any block types that are not registered.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				[
					'content' => [
						'type'        => 'string',
						'description' => __( 'Block markup to validate.', 'hm-abilities-mcp' ),
					],
				],
				[ 'content' ]
			),
			'execute_callback'    => __NAMESPACE__ . '\\validate_blocks',
			'permission_callback' => __NAMESPACE__ . '\\can_read',
			'meta'                => meta( $read ),
		]
	);

	wp_register_ability(
		'hm-abilities/list-block-types',
		[
			'label'               => __( 'List block types', 'hm-abilities-mcp' ),
			'description'         => __( 'List registered block types, optionally filtered by namespace (e.g. "core", "hm-abilities").', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				[
					'namespace' => [
						'type'        => 'string',
						'description' => __( 'Block namespace prefix filter.', 'hm-abilities-mcp' ),
					],
				]
			),
			'execute_callback'    => __NAMESPACE__ . '\\list_block_types',
			'permission_callback' => __NAMESPACE__ . '\\can_read',
			'meta'                => meta( $read ),
		]
	);
}

/**
 * Build the meta block shared by block abilities.
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
 * Permission: read (edit_posts).
 *
 * These abilities operate on the per-request block-type registry, which is not
 * site-specific, so they take no `site_id` and check the capability on the
 * current site.
 *
 * @return bool|\WP_Error
 */
function can_read() {
	return Support\cap_check( 'edit_posts' );
}

/**
 * Validate block markup.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function validate_blocks( $input = null ) {
	$input = is_array( $input ) ? $input : [];

	if ( ! isset( $input['content'] ) || ! is_string( $input['content'] ) ) {
		return Support\error( 'hm_abilities_missing_content', __( 'Block markup content is required.', 'hm-abilities-mcp' ), 400 );
	}

	$registry = WP_Block_Type_Registry::get_instance();
	$invalid  = [];
	$count    = 0;

	$walk = static function ( array $blocks, callable $walk_fn ) use ( &$invalid, &$count, $registry ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			if ( $name === null ) {
				continue; // Freeform/whitespace chunk between blocks.
			}

			++$count;

			if ( ! $registry->is_registered( $name ) && ! in_array( $name, $invalid, true ) ) {
				$invalid[] = $name;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$walk_fn( $block['innerBlocks'], $walk_fn );
			}
		}
	};

	$walk( parse_blocks( $input['content'] ), $walk );

	return [
		'valid'       => $invalid === [],
		'block_count' => $count,
		'invalid'     => $invalid,
	];
}

/**
 * List registered block types.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>
 */
function list_block_types( $input = null ): array {
	$input  = is_array( $input ) ? $input : [];
	$filter = isset( $input['namespace'] ) ? (string) $input['namespace'] : '';

	$types = [];

	foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		if ( $filter !== '' && strpos( (string) $name, $filter . '/' ) !== 0 ) {
			continue;
		}

		$types[] = [
			'name'     => (string) $name,
			'title'    => (string) $type->title,
			'category' => (string) $type->category,
		];
	}

	return [
		'block_types' => $types,
		'count'       => count( $types ),
	];
}
