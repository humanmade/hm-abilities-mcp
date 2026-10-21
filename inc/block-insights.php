<?php
/**
 * Block insight abilities: usage stats, per-page block listing, and missing
 * block detection across a multisite network.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Block_Insights;

use HM\Abilities\Support;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Default largest number of posts scanned per site when tallying block usage,
 * to bound query and memory cost. `hm_abilities_block_usage_scan_limit`
 * changes it. Results note when a site is truncated.
 *
 * @var int
 */
const SCAN_LIMIT = 500;

/**
 * Posts read per query while scanning a site, so peak memory stays near one
 * batch's content whatever the scan limit.
 *
 * @var int
 */
const SCAN_BATCH = 100;

/**
 * Sites tallied per `block-usage-stats` call at network scope: default, then
 * the hard ceiling.
 *
 * Each site here costs a `switch_to_blog`, a query for up to the scan limit's
 * posts, and a block parse of every one of them, so the ceiling is deliberately
 * low. At the default SCAN_LIMIT, a page of 25 sites is up to 12,500 post parses.
 * Callers cover a larger network by following `next_page` across calls.
 *
 * @var int
 */
const STATS_SITES_DEFAULT = 10;
const STATS_SITES_MAX     = 25;

/**
 * Sites checked per `find-missing-block` call: default, then the hard ceiling.
 *
 * Roughly SCAN_LIMIT times cheaper per site than the tally above, since only
 * the one target page per site is parsed, so this page size can be far larger.
 * The default sits at the ceiling deliberately: a network of 100 live sites or
 * fewer is then answered completely by one call, and only larger networks —
 * the ones an unbounded scan would have timed out on — need paging.
 *
 * @var int
 */
const MISSING_SITES_DEFAULT = 100;
const MISSING_SITES_MAX     = 100;

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
			'description'         => __( 'Count how often each block type is used, for one site or across a page of multisite network subsites. At network scope one call covers a bounded page of sites; follow next_page in the response to cover the rest.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_batch(
					[
						'scope'     => [
							'type'        => 'string',
							'enum'        => [ 'site', 'network' ],
							'default'     => 'site',
							'description' => __( '"site" tallies one subsite; "network" aggregates a page of subsites with a per-site breakdown.', 'hm-abilities-mcp' ),
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
					],
					STATS_SITES_DEFAULT,
					STATS_SITES_MAX
				)
			),
			'output_schema'       => Support\output_object(
				[
					'scope'                => Support\prop(
						'string',
						__( 'The scope the tally covered.', 'hm-abilities-mcp' ),
						[ 'enum' => [ 'site', 'network' ] ]
					),
					'status'               => Support\prop( 'string', __( 'Post status the scan was filtered to.', 'hm-abilities-mcp' ) ),
					'post_types'           => Support\output_list(
						[ 'type' => 'string' ],
						__( 'Post types the scan covered.', 'hm-abilities-mcp' )
					),
					'post_count'           => Support\prop( 'integer', __( 'Posts scanned across every site in this response.', 'hm-abilities-mcp' ) ),
					'truncated'            => Support\prop( 'boolean', __( 'True when any site scanned holds more matching posts than the scan limit, so its counts cover only that many, lowest post IDs first.', 'hm-abilities-mcp' ) ),
					'block_count'          => Support\prop( 'integer', __( 'Block instances counted across every site in this response.', 'hm-abilities-mcp' ) ),
					'distinct_block_types' => Support\prop( 'integer', __( 'How many different block types were seen.', 'hm-abilities-mcp' ) ),
					'blocks'               => Support\block_tally_schema(),
					'sites'                => Support\output_list(
						site_tally_row_schema(),
						__( 'Per-site breakdown. Returned only at network scope.', 'hm-abilities-mcp' )
					),
					'pagination'           => Support\site_batch_schema(),
					'note'                 => partial_note_schema(),
				],
				[ 'scope', 'status', 'post_types', 'post_count', 'truncated', 'block_count', 'distinct_block_types', 'blocks' ]
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
			'category'            => Support\CATEGORY,
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
			'output_schema'       => Support\output_object(
				[
					'site'                 => Support\site_summary_schema(),
					'post'                 => Support\output_object(
						[
							'id'     => Support\prop( 'integer', __( 'Post ID.', 'hm-abilities-mcp' ) ),
							'title'  => Support\prop( 'string', __( 'Rendered post title.', 'hm-abilities-mcp' ) ),
							'status' => Support\prop( 'string', __( 'Post status.', 'hm-abilities-mcp' ) ),
							'type'   => Support\prop( 'string', __( 'Post type slug.', 'hm-abilities-mcp' ) ),
							'link'   => Support\prop( 'string', __( 'Permalink to the post.', 'hm-abilities-mcp' ), [ 'format' => 'uri' ] ),
						],
						[ 'id', 'title', 'status', 'type', 'link' ],
						__( 'The page the blocks were read from.', 'hm-abilities-mcp' )
					),
					'block_count'          => Support\prop( 'integer', __( 'Block instances on the page, counting nested ones.', 'hm-abilities-mcp' ) ),
					'distinct_block_types' => Support\prop( 'integer', __( 'How many different block types the page uses.', 'hm-abilities-mcp' ) ),
					'blocks'               => Support\block_tally_schema(),
					'outline'              => Support\output_list(
						Support\output_object(
							[
								'name'  => Support\prop( 'string', __( 'Block type name.', 'hm-abilities-mcp' ) ),
								'inner' => Support\output_list(
									[ 'type' => 'object' ],
									__( 'Nested blocks, each the same shape as this node. Present only where the block has inner blocks.', 'hm-abilities-mcp' )
								),
							],
							[ 'name' ]
						),
						__( 'The block hierarchy in document order. Returned unless include_outline was false.', 'hm-abilities-mcp' )
					),
				],
				[ 'site', 'post', 'block_count', 'distinct_block_types', 'blocks' ]
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
			'description'         => __( 'Report which network subsites are missing a given block type on their front page (or a given page path). Checks a bounded page of subsites per call; follow next_page in the response to cover the rest. Example block_name: "core/details".', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_batch(
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
					MISSING_SITES_DEFAULT,
					MISSING_SITES_MAX
				),
				[ 'block_name' ]
			),
			'output_schema'       => Support\output_object(
				[
					'block_name'    => Support\prop( 'string', __( 'The block type that was looked for.', 'hm-abilities-mcp' ) ),
					'target'        => Support\prop( 'string', __( 'The page path checked on each subsite, or "front-page" when none was given.', 'hm-abilities-mcp' ) ),
					'checked_count' => Support\prop( 'integer', __( 'Subsites covered by this response.', 'hm-abilities-mcp' ) ),
					'present_count' => Support\prop( 'integer', __( 'Subsites whose target page carries the block.', 'hm-abilities-mcp' ) ),
					'missing_count' => Support\prop( 'integer', __( 'Subsites whose target page does not carry the block.', 'hm-abilities-mcp' ) ),
					'present'       => Support\output_list(
						site_row_schema(),
						__( 'The subsites carrying the block.', 'hm-abilities-mcp' )
					),
					'missing'       => Support\output_list(
						site_row_schema(),
						__( 'The subsites not carrying it, including those with no target page.', 'hm-abilities-mcp' )
					),
					'pagination'    => Support\site_batch_schema(),
					'note'          => partial_note_schema(),
				],
				[ 'block_name', 'target', 'checked_count', 'present_count', 'missing_count', 'present', 'missing', 'pagination' ]
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

	$sites = null;

	if ( $scope === 'network' ) {
		$batch = Support\resolve_site_batch( $input, STATS_SITES_DEFAULT, STATS_SITES_MAX );

		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		$blog_ids = $batch['blog_ids'];
		$sites    = $batch['sites'];
	} else {
		$blog_ids = [ isset( $input['site_id'] ) ? (int) $input['site_id'] : get_current_blog_id() ];
	}

	/**
	 * Filters the largest number of posts scanned per site.
	 *
	 * Raise it for a site whose content outgrows the default, so its counts
	 * are complete rather than reported as truncated. Each post scanned is a
	 * block parse, and at network scope the limit applies to every site in
	 * the page, so raise it for site scope alone unless the network is small.
	 *
	 * @param int    $limit Posts scanned per site, or -1 for no limit. Default SCAN_LIMIT.
	 * @param string $scope "site" or "network".
	 */
	$limit = apply_filters( 'hm_abilities_block_usage_scan_limit', SCAN_LIMIT, $scope );

	// -1 means no limit, as in WP_Query. So does PHP_INT_MAX and above, where the
	// extra row that detects truncation would overflow; compared before the cast wraps it.
	if ( is_numeric( $limit ) && $limit >= PHP_INT_MAX ) {
		$limit = null;
	} else {
		$limit = (int) $limit === -1 ? null : max( 1, (int) $limit );
	}

	$global    = [];
	$by_site   = [];
	$posts_all = 0;
	$truncated = false;

	foreach ( $blog_ids as $blog_id ) {
		$site = Support\with_site(
			$blog_id,
			static function () use ( $post_types, $status, $limit ) {
				return scan_site( $post_types, $status, $limit );
			}
		);

		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$posts_all += $site['post_count'];
		$truncated  = $truncated || $site['truncated'];

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
		'truncated'             => $truncated,
		'block_count'           => array_sum( $global ),
		'distinct_block_types'  => count( $global ),
		'blocks'                => Support\tally_to_list( $global ),
	];

	if ( $scope === 'network' ) {
		$result['sites']      = $by_site;
		$result['pagination'] = $sites;

		if ( ! empty( $sites['has_more'] ) ) {
			$result['note'] = partial_note( $sites );
		}
	}

	return $result;
}

/**
 * Scan the current blog's content and tally block usage.
 *
 * Unpublished statuses are scoped to what the caller may read.
 *
 * Takes the IDs through WP_Query, which applies that scoping, then reads only
 * their content, SCAN_BATCH posts at a time. An ID query returns before the
 * `posts_results` and `the_posts` filters, so a plugin hiding posts there does
 * not narrow the scan.
 *
 * @param string[] $post_types Post types to scan.
 * @param string   $status     Post status filter ("any" or a concrete status).
 * @param int|null $limit      Largest number of posts to scan, or null for all.
 * @return array{tally: array<string,int>, post_count: int, truncated: bool, summary: array<string,mixed>}|\WP_Error
 */
function scan_site( array $post_types, string $status, ?int $limit ) {
	global $wpdb;

	$scope = array_merge(
		[
			'post_type'        => $post_types,
			'suppress_filters' => false,
			'no_found_rows'    => true,
		],
		Support\status_query_args( $status )
	);

	$ids = get_posts(
		$scope + [
			'posts_per_page' => $limit === null ? -1 : $limit + 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		]
	);

	$truncated = $limit !== null && count( $ids ) > $limit;
	if ( $truncated ) {
		$ids = array_slice( $ids, 0, $limit );
	}

	$tally = [];

	foreach ( array_chunk( $ids, SCAN_BATCH ) as $batch ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query primes every post it loads into the object cache, which a tally never reads again.
		$contents = $wpdb->get_col( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $batch ), '%d' ) ) . ')', $batch ) );

		// A failed read returns no rows, which would tally the batch as empty posts.
		if ( $wpdb->last_error !== '' ) {
			return Support\error( 'hm_abilities_block_scan_failed', __( 'Could not read post content for the block scan.', 'hm-abilities-mcp' ), 500 );
		}

		foreach ( $contents as $content ) {
			if ( $content === '' ) {
				continue;
			}

			foreach ( Support\tally_blocks( (string) $content ) as $name => $count ) {
				$tally[ $name ] = ( $tally[ $name ] ?? 0 ) + $count;
			}
		}
	}

	arsort( $tally );

	return [
		'tally'      => $tally,
		'post_count' => count( $ids ),
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
	$post = Support\resolve_post( $post_id, 'read_post', 'page' );

	if ( is_wp_error( $post ) ) {
		return $post;
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

	$batch = Support\resolve_site_batch( $input, MISSING_SITES_DEFAULT, MISSING_SITES_MAX );

	if ( is_wp_error( $batch ) ) {
		return $batch;
	}

	$present = [];
	$missing = [];

	foreach ( $batch['blog_ids'] as $blog_id ) {
		$row = Support\with_site(
			(int) $blog_id,
			static function () use ( $block_name, $path ): array {
				$summary = Support\current_site_summary();

				if ( $path !== '' ) {
					// `get_page_by_path()` carries no status clause, so a page
					// the caller may not read is treated as absent.
					$page    = get_page_by_path( $path, OBJECT, Support\post_types() );
					$post_id = ( $page instanceof WP_Post && ! is_wp_error( Support\resolve_post( $page->ID, 'read_post', 'page' ) ) ) ? $page->ID : 0;
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

	$result = [
		'block_name'    => $block_name,
		'target'        => $path !== '' ? $path : 'front-page',
		'checked_count' => count( $present ) + count( $missing ),
		'present_count' => count( $present ),
		'missing_count' => count( $missing ),
		'present'       => $present,
		'missing'       => $missing,
		'pagination'    => $batch['sites'],
	];

	if ( ! empty( $batch['sites']['has_more'] ) ) {
		$result['note'] = partial_note( $batch['sites'] );
	}

	return $result;
}

/**
 * Output schema for one subsite row in the find-missing-block result.
 *
 * Extends the site summary with what was found on that site's target page.
 * `note` appears only where no target page resolved, which also reports
 * `page_id` as null.
 *
 * @return array<string, mixed>
 */
function site_row_schema(): array {
	return Support\extend_object(
		Support\site_summary_schema(),
		[
			'page_id' => Support\prop( [ 'integer', 'null' ], __( 'ID of the page checked, or null when none resolved.', 'hm-abilities-mcp' ) ),
			'present' => Support\prop( 'boolean', __( 'True when the block appears on that page.', 'hm-abilities-mcp' ) ),
			'count'   => Support\prop( 'integer', __( 'How many times the block appears on that page.', 'hm-abilities-mcp' ) ),
			'note'    => Support\prop( 'string', __( 'Why no page was checked. Returned only when page_id is null.', 'hm-abilities-mcp' ) ),
		],
		[ 'page_id', 'present', 'count' ]
	);
}

/**
 * Output schema for one subsite row in the block-usage-stats result.
 *
 * Extends the site summary with what the scan counted on that site.
 *
 * @return array<string, mixed>
 */
function site_tally_row_schema(): array {
	return Support\extend_object(
		Support\site_summary_schema(),
		[
			'post_count'  => Support\prop( 'integer', __( 'Posts scanned on this site.', 'hm-abilities-mcp' ) ),
			'block_count' => Support\prop( 'integer', __( 'Block instances counted on this site.', 'hm-abilities-mcp' ) ),
			'truncated'   => Support\prop( 'boolean', __( 'True when the site holds more matching posts than the scan limit, so the counts below cover only that many.', 'hm-abilities-mcp' ) ),
			'blocks'      => Support\block_tally_schema(),
		],
		[ 'post_count', 'block_count', 'truncated', 'blocks' ]
	);
}

/**
 * Output schema for the `note` a partial response carries, describing
 * `partial_note()`.
 *
 * @return array<string, mixed>
 */
function partial_note_schema(): array {
	return Support\prop( 'string', __( 'Warning that the counts cover one page of the network. Returned only when sites remain.', 'hm-abilities-mcp' ) );
}

/**
 * Warning line for a response that covers only part of the network.
 *
 * Counts in a paged response describe the sites in that page alone, so say so
 * explicitly — a caller that reads them as network totals would under-report.
 *
 * @param array<string, mixed> $sites Pagination block from resolve_site_batch().
 * @return string
 */
function partial_note( array $sites ): string {
	return sprintf(
		/* translators: 1: sites covered by this response, 2: total sites in the network, 3: next page number. */
		__( 'Counts cover only the %1$d sites in this page, not the whole network of %2$d. Call again with page %3$d to continue.', 'hm-abilities-mcp' ),
		(int) $sites['processed'],
		(int) $sites['total'],
		(int) $sites['next_page']
	);
}
