<?php
/**
 * Block insight abilities: usage stats, per-page block listing, and missing
 * block detection across a multisite network.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\BlockInsights;

use WP_Post;
use HM\Abilities\Support;
use const HM\Abilities\Support\CATEGORY;

defined( 'ABSPATH' ) || exit;

/**
 * Largest number of posts scanned per site when tallying block usage, to bound
 * query and memory cost. Results note when a site is truncated.
 *
 * @var int
 */
const SCAN_LIMIT = 500;

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the block insight abilities.
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
		'hm-abilities/block-usage-stats',
		[
			'label'               => __( 'Block usage stats', 'hm-abilities-mcp' ),
			'description'         => __( 'Count how often each block type is used, for one site or across the whole multisite network.', 'hm-abilities-mcp' ),
			'category'            => CATEGORY,
			'input_schema'        => Support\object_schema(
				[
					'scope'     => [
						'type'        => 'string',
						'enum'        => [ 'site', 'network' ],
						'default'     => 'site',
						'description' => __( '"site" tallies one subsite; "network" aggregates every subsite with a per-site breakdown.', 'hm-abilities-mcp' ),
					],
					'site_id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Blog ID to tally when scope is "site". Defaults to the current site.', 'hm-abilities-mcp' ),
					],
					'post_type' => [
						'type'        => 'string',
						'enum'        => Support\post_types(),
						'description' => __( 'Restrict the tally to one post type. Defaults to all supported types.', 'hm-abilities-mcp' ),
					],
					'status'    => [
						'type'    => 'string',
						'enum'    => Support\list_statuses(),
						'default' => 'publish',
					],
				]
			),
			'execute_callback'    => __NAMESPACE__ . '\\block_usage_stats',
			'permission_callback' => 'HM\\Abilities\\Support\\cap_check_network',
			'meta'                => meta( $read ),
		]
	);

	wp_register_ability(
		'hm-abilities/get-page-blocks',
		[
			'label'               => __( 'Get page blocks', 'hm-abilities-mcp' ),
			'description'         => __( 'List the blocks on a single page. Give a front-end URL (the subsite is resolved from it) or a page ID plus optional site ID.', 'hm-abilities-mcp' ),
			'category'            => CATEGORY,
			'input_schema'        => Support\object_schema(
				[
					'url'             => [
						'type'        => 'string',
						'description' => __( 'Front-end URL of the page. The network subsite is resolved from the URL.', 'hm-abilities-mcp' ),
					],
					'page_id'         => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Page/post ID. Used when no URL is given.', 'hm-abilities-mcp' ),
					],
					'site_id'         => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Blog ID the page ID belongs to. Defaults to the current site. Ignored when a URL is given.', 'hm-abilities-mcp' ),
					],
					'include_outline' => [
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Include a nested outline preserving the block hierarchy.', 'hm-abilities-mcp' ),
					],
				]
			),
			'execute_callback'    => __NAMESPACE__ . '\\get_page_blocks',
			'permission_callback' => 'HM\\Abilities\\Support\\cap_check_network',
			'meta'                => meta( $read ),
		]
	);

	wp_register_ability(
		'hm-abilities/find-missing-block',
		[
			'label'               => __( 'Find missing block', 'hm-abilities-mcp' ),
			'description'         => __( 'Across the network, report which subsites are missing a given block type on their front page (or a given page path). Example block_name: "core/details".', 'hm-abilities-mcp' ),
			'category'            => CATEGORY,
			'input_schema'        => Support\object_schema(
				[
					'block_name' => [
						'type'        => 'string',
						'description' => __( 'Block type to look for, e.g. "core/details" or "core/heading".', 'hm-abilities-mcp' ),
					],
					'path'       => [
						'type'        => 'string',
						'description' => __( 'Page path to check on each subsite (e.g. "about"). Defaults to the front page.', 'hm-abilities-mcp' ),
					],
				],
				[ 'block_name' ]
			),
			'execute_callback'    => __NAMESPACE__ . '\\find_missing_block',
			'permission_callback' => 'HM\\Abilities\\Support\\cap_check_network',
			'meta'                => meta( $read ),
		]
	);
}

/**
 * Build the meta block shared by these abilities.
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
 * Tally block usage for a single site or the whole network.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function block_usage_stats( $input = null ) {
	$input = is_array( $input ) ? $input : [];

	$scope  = ( ( $input['scope'] ?? 'site' ) === 'network' ) ? 'network' : 'site';
	$status = ( isset( $input['status'] ) && in_array( $input['status'], Support\list_statuses(), true ) ) ? (string) $input['status'] : 'publish';

	$post_types = Support\post_types();
	if ( isset( $input['post_type'] ) ) {
		if ( ! in_array( $input['post_type'], $post_types, true ) ) {
			return Support\error( 'hm_abilities_invalid_post_type', __( 'Unsupported post_type.', 'hm-abilities-mcp' ), 400 );
		}
		$post_types = [ (string) $input['post_type'] ];
	}

	if ( $scope === 'network' ) {
		$blog_ids = Support\each_site();
	} else {
		$blog_ids = [ isset( $input['site_id'] ) ? (int) $input['site_id'] : get_current_blog_id() ];
	}

	$global    = [];
	$by_site   = [];
	$posts_all = 0;

	foreach ( $blog_ids as $blog_id ) {
		$site = Support\with_site(
			$blog_id,
			static function () use ( $post_types, $status ): array {
				return scan_site( $post_types, $status );
			}
		);

		$posts_all += $site['post_count'];

		foreach ( $site['tally'] as $name => $count ) {
			$global[ $name ] = ( $global[ $name ] ?? 0 ) + $count;
		}

		if ( $scope === 'network' ) {
			$by_site[] = [
				'site_id'     => $site['summary']['site_id'],
				'name'        => $site['summary']['name'],
				'url'         => $site['summary']['url'],
				'post_count'  => $site['post_count'],
				'block_count' => array_sum( $site['tally'] ),
				'truncated'   => $site['truncated'],
				'blocks'      => Support\tally_to_list( $site['tally'] ),
			];
		}
	}

	arsort( $global );

	$result = [
		'scope'                 => $scope,
		'status'                => $status,
		'post_types'            => array_values( $post_types ),
		'post_count'            => $posts_all,
		'block_count'           => array_sum( $global ),
		'distinct_block_types'  => count( $global ),
		'blocks'                => Support\tally_to_list( $global ),
	];

	if ( $scope === 'network' ) {
		$result['sites'] = $by_site;
	}

	return $result;
}

/**
 * Scan the current blog's content and tally block usage.
 *
 * @param string[] $post_types Post types to scan.
 * @param string   $status     Post status filter ("any" or a concrete status).
 * @return array{tally: array<string,int>, post_count: int, truncated: bool, summary: array<string,mixed>}
 */
function scan_site( array $post_types, string $status ): array {
	$posts = get_posts(
		[
			'post_type'        => $post_types,
			'post_status'      => $status,
			'posts_per_page'   => SCAN_LIMIT + 1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => false,
			'no_found_rows'    => true,
		]
	);

	$truncated = count( $posts ) > SCAN_LIMIT;
	if ( $truncated ) {
		$posts = array_slice( $posts, 0, SCAN_LIMIT );
	}

	$tally = [];

	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post || $post->post_content === '' ) {
			continue;
		}

		foreach ( Support\tally_blocks( $post->post_content ) as $name => $count ) {
			$tally[ $name ] = ( $tally[ $name ] ?? 0 ) + $count;
		}
	}

	arsort( $tally );

	return [
		'tally'      => $tally,
		'post_count' => count( $posts ),
		'truncated'  => $truncated,
		'summary'    => Support\current_site_summary(),
	];
}

/**
 * List the blocks on a single page, resolving the subsite when needed.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function get_page_blocks( $input = null ) {
	$input = is_array( $input ) ? $input : [];

	$has_url  = isset( $input['url'] ) && is_string( $input['url'] ) && trim( $input['url'] ) !== '';
	$has_page = isset( $input['page_id'] );

	if ( ! $has_url && ! $has_page ) {
		return Support\error( 'hm_abilities_missing_target', __( 'Provide either a url or a page_id.', 'hm-abilities-mcp' ), 400 );
	}

	if ( $has_url ) {
		$blog_id = Support\resolve_site_from_url( (string) $input['url'] );

		if ( is_wp_error( $blog_id ) ) {
			return $blog_id;
		}

		$url     = (string) $input['url'];
		$include = ! isset( $input['include_outline'] ) || (bool) $input['include_outline'];

		return Support\with_site(
			(int) $blog_id,
			static function () use ( $url, $include ) {
				$post_id = url_to_postid( $url );

				if ( ! $post_id ) {
					$post_id = Support\static_front_page_id();
				}

				if ( ! $post_id ) {
					return Support\error( 'hm_abilities_not_found', __( 'No page resolved from that URL on the matched site.', 'hm-abilities-mcp' ), 404 );
				}

				return format_page_blocks( (int) $post_id, $include );
			}
		);
	}

	$page_id = (int) $input['page_id'];

	if ( $page_id <= 0 ) {
		return Support\error( 'hm_abilities_invalid_page_id', __( 'The page_id must be a positive integer.', 'hm-abilities-mcp' ), 400 );
	}

	$blog_id = isset( $input['site_id'] ) ? (int) $input['site_id'] : get_current_blog_id();
	$include = ! isset( $input['include_outline'] ) || (bool) $input['include_outline'];

	return Support\with_site(
		$blog_id,
		static function () use ( $page_id, $include ) {
			return format_page_blocks( $page_id, $include );
		}
	);
}

/**
 * Shape the block listing for one post in the current blog context.
 *
 * @param int  $post_id Post ID.
 * @param bool $include_outline Include the nested outline.
 * @return array<string, mixed>|\WP_Error
 */
function format_page_blocks( int $post_id, bool $include_outline ) {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return Support\error( 'hm_abilities_not_found', __( 'No page found for that ID on the target site.', 'hm-abilities-mcp' ), 404 );
	}

	$tally = Support\tally_blocks( $post->post_content );

	$result = [
		'site'                 => Support\current_site_summary(),
		'post'                 => [
			'id'     => $post->ID,
			'title'  => get_the_title( $post ),
			'status' => $post->post_status,
			'type'   => $post->post_type,
			'link'   => (string) get_permalink( $post ),
		],
		'block_count'          => array_sum( $tally ),
		'distinct_block_types' => count( $tally ),
		'blocks'               => Support\tally_to_list( $tally ),
	];

	if ( $include_outline ) {
		$result['outline'] = Support\block_outline( parse_blocks( $post->post_content ) );
	}

	return $result;
}

/**
 * Report which network subsites are missing a given block on the target page.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function find_missing_block( $input = null ) {
	$input = is_array( $input ) ? $input : [];

	if ( ! isset( $input['block_name'] ) || ! is_string( $input['block_name'] ) || trim( $input['block_name'] ) === '' ) {
		return Support\error( 'hm_abilities_missing_block_name', __( 'A block_name is required.', 'hm-abilities-mcp' ), 400 );
	}

	$block_name = trim( (string) $input['block_name'] );
	$path       = ( isset( $input['path'] ) && is_string( $input['path'] ) ) ? trim( (string) $input['path'] ) : '';

	$present = [];
	$missing = [];

	foreach ( Support\each_site() as $blog_id ) {
		$row = Support\with_site(
			(int) $blog_id,
			static function () use ( $block_name, $path ): array {
				$summary = Support\current_site_summary();

				if ( $path !== '' ) {
					$page    = get_page_by_path( $path, OBJECT, Support\post_types() );
					$post_id = $page instanceof WP_Post ? $page->ID : 0;
				} else {
					$post_id = Support\static_front_page_id();
				}

				if ( ! $post_id ) {
					return $summary + [
						'page_id' => null,
						'present' => false,
						'count'   => 0,
						'note'    => $path !== ''
							? __( 'No page found at that path.', 'hm-abilities-mcp' )
							: __( 'No static front page is set (front page is the posts index).', 'hm-abilities-mcp' ),
					];
				}

				$post  = get_post( $post_id );
				$tally = ( $post instanceof WP_Post ) ? Support\tally_blocks( $post->post_content ) : [];

				return $summary + [
					'page_id' => (int) $post_id,
					'present' => isset( $tally[ $block_name ] ),
					'count'   => (int) ( $tally[ $block_name ] ?? 0 ),
				];
			}
		);

		if ( ! empty( $row['present'] ) ) {
			$present[] = $row;
		} else {
			$missing[] = $row;
		}
	}

	return [
		'block_name'    => $block_name,
		'target'        => $path !== '' ? $path : 'front-page',
		'checked_count' => count( $present ) + count( $missing ),
		'present_count' => count( $present ),
		'missing_count' => count( $missing ),
		'present'       => $present,
		'missing'       => $missing,
	];
}
