<?php
/**
 * Network site discovery: a paginated list of the subsites the network-wide
 * abilities can target.
 *
 * The network abilities each process a bounded page of sites per call, so a
 * caller needs a way to see what is out there and to name specific sites. This
 * ability is that way in.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Sites;

use HM\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Sites returned per call: default, then the hard ceiling.
 *
 * Each site costs a `switch_to_blog` and two option reads to resolve its name
 * and URL, so this page size can be comfortably larger than the scanning
 * abilities allow.
 *
 * @var int
 */
const SITES_DEFAULT = 50;
const SITES_MAX     = Support\MAX_PER_PAGE;

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the site discovery ability.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'hm-abilities/list-network-sites',
		[
			'label'               => __( 'List network sites', 'hm-abilities-mcp' ),
			'description'         => __( 'List the live subsites in the multisite network, a page at a time. Archived, spam, and deleted sites are excluded. Use the returned site_id values to target the network abilities at specific sites, and follow next_page to walk the whole network. Returns just the current site on a single-site install.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				[
					'per_page' => [
						'type'        => 'integer',
						'default'     => SITES_DEFAULT,
						'minimum'     => 1,
						'maximum'     => SITES_MAX,
						'description' => __( 'Sites to return per call.', 'hm-abilities-mcp' ),
					],
					'page'     => [
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
						'description' => __( 'Which page of sites to return.', 'hm-abilities-mcp' ),
					],
					'search'   => [
						'type'        => 'string',
						'description' => __( 'Filter sites by domain or path.', 'hm-abilities-mcp' ),
					],
				]
			),
			'execute_callback'    => __NAMESPACE__ . '\\list_network_sites',
			'permission_callback' => 'HM\\Abilities\\Support\\cap_check_network',
			'meta'                => [
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
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
 * Return one page of network sites.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>
 */
function list_network_sites( $input = null ): array {
	$input = is_array( $input ) ? $input : [];

	$per_page = isset( $input['per_page'] ) ? min( SITES_MAX, max( 1, (int) $input['per_page'] ) ) : SITES_DEFAULT;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

	$blog_ids = $search !== '' ? search_site_ids( $search, $per_page, $page ) : Support\site_page( $per_page, $page );
	$total    = $search !== '' ? count_matching_sites( $search ) : Support\count_sites();

	$items = [];

	foreach ( $blog_ids as $blog_id ) {
		$items[] = Support\with_site(
			(int) $blog_id,
			static function (): array {
				return Support\current_site_summary();
			}
		);
	}

	$has_more = ( $page * $per_page ) < $total;

	return [
		'items'      => $items,
		'count'      => count( $items ),
		'pagination' => [
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
			'has_more'    => $has_more,
			'next_page'   => $has_more ? $page + 1 : null,
		],
	];
}

/**
 * One bounded page of blog IDs matching a domain/path search.
 *
 * @param string $search   Search term.
 * @param int    $per_page Sites per page.
 * @param int    $page     1-based page number.
 * @return int[]
 */
function search_site_ids( string $search, int $per_page, int $page ): array {
	if ( ! is_multisite() ) {
		return Support\site_page( $per_page, $page );
	}

	$sites = get_sites(
		Support\live_site_args() + [
			'search'  => $search,
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'fields'  => 'ids',
			'orderby' => 'id',
			'order'   => 'ASC',
		]
	);

	return array_map( 'intval', $sites );
}

/**
 * Total live sites matching a domain/path search.
 *
 * @param string $search Search term.
 * @return int
 */
function count_matching_sites( string $search ): int {
	if ( ! is_multisite() ) {
		return 1;
	}

	return (int) get_sites(
		Support\live_site_args() + [
			'search' => $search,
			'count'  => true,
		]
	);
}
