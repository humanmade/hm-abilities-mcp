<?php
/**
 * Taxonomy abilities: discover taxonomies and search their terms.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Terms;

use HM\Abilities\Support;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Orderings the term listing accepts.
 *
 * @var string[]
 */
const ORDERBY = [ 'name', 'count' ];

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the taxonomy abilities.
 *
 * @return void
 */
function register(): void {
	wp_register_ability(
		'hm-abilities/list-terms',
		[
			'label'               => __( 'List terms', 'hm-abilities-mcp' ),
			'description'         => __( 'List the available taxonomies, and search or page the terms in one of them. Call without a taxonomy to see what is available, then again with one to find terms. The term slugs it returns are the values the terms filter on list-content accepts.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'taxonomy'   => [
							'type'        => 'string',
							'description' => __( 'Taxonomy to list terms from. Omit to return only the available taxonomies.', 'hm-abilities-mcp' ),
						],
						'search'     => [
							'type'        => 'string',
							'description' => __( 'Match terms whose name contains this text. Use it to find the real term behind a loose description, for instance "miso" to reach "white miso paste".', 'hm-abilities-mcp' ),
						],
						'hide_empty' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Skip terms no content is assigned to.', 'hm-abilities-mcp' ),
						],
						'orderby'    => [
							'type'        => 'string',
							'enum'        => ORDERBY,
							'default'     => 'name',
							'description' => __( 'Order alphabetically by name, or by how much content uses each term with count.', 'hm-abilities-mcp' ),
						],
						'per_page'   => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => Support\MAX_PER_PAGE,
						],
						'page'       => [
							'type'    => 'integer',
							'default' => 1,
						],
					]
				)
			),
			'output_schema'       => Support\list_schema(
				Support\term_schema(),
				[
					'taxonomies' => Support\output_list(
						taxonomy_schema(),
						__( 'Every taxonomy available to these abilities, returned on every call.', 'hm-abilities-mcp' )
					),
					'taxonomy'   => Support\prop( [ 'string', 'null' ], __( 'The taxonomy the items came from, or null when none was named.', 'hm-abilities-mcp' ) ),
				],
				[ 'taxonomies', 'taxonomy' ]
			),
			'execute_callback'    => __NAMESPACE__ . '\\list_terms',
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
 * Permission: read terms on the targeted subsite.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_read( $input = null ) {
	return Support\can_on_site( $input, 'edit_posts' );
}

/**
 * List taxonomies, and the terms in one of them.
 *
 * The available taxonomies come back on every call. Without a `taxonomy` the
 * term fields are empty rather than absent, keeping one shape.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function list_terms( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ) {
			$input = is_array( $input ) ? $input : [];

			$available  = Support\taxonomies();
			$taxonomies = array_values( array_filter( array_map( __NAMESPACE__ . '\\format_taxonomy', $available ) ) );

			$requested = isset( $input['taxonomy'] ) ? trim( (string) $input['taxonomy'] ) : '';

			if ( $requested === '' ) {
				return empty_response( $taxonomies );
			}

			if ( ! in_array( $requested, $available, true ) ) {
				return Support\error(
					'hm_abilities_invalid_taxonomy',
					sprintf(
						/* translators: 1: requested taxonomy slug, 2: comma-separated available taxonomy slugs. */
						__( 'Unavailable taxonomy: %1$s. Available taxonomies: %2$s.', 'hm-abilities-mcp' ),
						$requested,
						$available ? implode( ', ', $available ) : __( 'none', 'hm-abilities-mcp' )
					),
					400
				);
			}

			$per_page = isset( $input['per_page'] ) ? min( Support\MAX_PER_PAGE, max( 1, (int) $input['per_page'] ) ) : 20;
			$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
			$orderby  = ( isset( $input['orderby'] ) && in_array( $input['orderby'], ORDERBY, true ) ) ? (string) $input['orderby'] : 'name';

			$args = [
				'taxonomy'   => $requested,
				'hide_empty' => ! empty( $input['hide_empty'] ),
				'orderby'    => $orderby,
				'order'      => $orderby === 'count' ? 'DESC' : 'ASC',
			];

			if ( isset( $input['search'] ) && trim( (string) $input['search'] ) !== '' ) {
				$args['search'] = trim( (string) $input['search'] );
			}

			$total = get_terms( array_merge( $args, [ 'fields' => 'count' ] ) );
			$terms = get_terms(
				array_merge(
					$args,
					[
						'number' => $per_page,
						'offset' => ( $page - 1 ) * $per_page,
					]
				)
			);

			if ( is_wp_error( $total ) ) {
				return $total;
			}

			if ( is_wp_error( $terms ) ) {
				return $terms;
			}

			$total = (int) $total;
			$pages = (int) ceil( $total / $per_page );
			$items = array_map(
				static fn ( WP_Term $term ): array => Support\format_term( $term ),
				array_values( array_filter( (array) $terms, static fn ( $term ): bool => $term instanceof WP_Term ) )
			);

			return [
				'taxonomies'  => $taxonomies,
				'taxonomy'    => $requested,
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
 * The discovery-only response, returned when no taxonomy was named.
 *
 * @param array<int, array<string, mixed>> $taxonomies Formatted taxonomies.
 * @return array<string, mixed>
 */
function empty_response( array $taxonomies ): array {
	return [
		'taxonomies'  => $taxonomies,
		'taxonomy'    => null,
		'items'       => [],
		'count'       => 0,
		'total'       => 0,
		'page'        => 1,
		'total_pages' => 0,
		'has_more'    => false,
		'next_page'   => null,
	];
}

/**
 * Shape a taxonomy for ability output.
 *
 * `total_terms` counts every term, including unused ones.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return array<string, mixed>|null Null when the taxonomy is not registered.
 */
function format_taxonomy( string $taxonomy ): ?array {
	$object = get_taxonomy( $taxonomy );

	if ( ! $object ) {
		return null;
	}

	$count = wp_count_terms(
		[
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		]
	);

	return [
		'name'         => $object->name,
		'label'        => $object->label,
		'hierarchical' => (bool) $object->hierarchical,
		'post_types'   => array_values( array_intersect( (array) $object->object_type, Support\post_types() ) ),
		'total_terms'  => is_wp_error( $count ) ? 0 : (int) $count,
	];
}

/**
 * Output schema for a taxonomy, describing `format_taxonomy()`.
 *
 * @return array<string, mixed>
 */
function taxonomy_schema(): array {
	return Support\output_object(
		[
			'name'         => Support\prop( 'string', __( 'Taxonomy slug, which is the value the taxonomy input takes.', 'hm-abilities-mcp' ) ),
			'label'        => Support\prop( 'string', __( 'Human-readable taxonomy label.', 'hm-abilities-mcp' ) ),
			'hierarchical' => Support\prop( 'boolean', __( 'True when terms in the taxonomy nest under a parent.', 'hm-abilities-mcp' ) ),
			'post_types'   => Support\output_list(
				[ 'type' => 'string' ],
				__( 'Exposed post types the taxonomy applies to.', 'hm-abilities-mcp' )
			),
			'total_terms'  => Support\prop( 'integer', __( 'How many terms the taxonomy holds, counting unused ones.', 'hm-abilities-mcp' ) ),
		],
		[ 'name', 'label', 'hierarchical', 'post_types', 'total_terms' ]
	);
}
