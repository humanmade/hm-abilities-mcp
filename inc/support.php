<?php
/**
 * Shared helpers for HM Abilities.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Support;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Ability category slug shared by every HM ability.
 *
 * @var string
 */
const CATEGORY = 'hm-abilities';

/**
 * Largest page size the list abilities will honor, to bound query cost.
 *
 * @var int
 */
const MAX_PER_PAGE = 100;

/**
 * Post types the content abilities may read or create.
 *
 * Defaults to pages and posts. Sites add their own custom post types with the
 * `hm_abilities_post_types` filter.
 *
 * @return string[]
 */
function post_types(): array {
	/**
	 * Filters the post types the content abilities may read or create.
	 *
	 * @param string[] $post_types Post type slugs. Default [ 'page', 'post' ].
	 */
	$post_types = apply_filters( 'hm_abilities_post_types', [ 'page', 'post' ] );

	return is_array( $post_types ) ? array_values( array_filter( array_map( 'strval', $post_types ) ) ) : [ 'page', 'post' ];
}

/**
 * Post statuses the content abilities may assign when creating or updating.
 *
 * @return string[]
 */
function statuses(): array {
	return [ 'draft', 'pending', 'publish', 'private' ];
}

/**
 * Post statuses the list abilities may filter by. Adds the `any` meta-status
 * (all public statuses, excluding trash and auto-draft) used as the default.
 *
 * @return string[]
 */
function list_statuses(): array {
	return array_merge( [ 'any' ], statuses() );
}

/**
 * Statuses that need `edit_others_posts` to see beyond your own.
 *
 * Excludes `private`, which the `readable` perm already gates on the narrower
 * `read_private_posts`.
 *
 * @var string[]
 */
const UNPUBLISHED_STATUSES = [ 'draft', 'pending', 'future' ];

/**
 * Resolve a `status` input into `post_status` and `perm` query arguments
 * scoped to what the caller may read.
 *
 * `any` is expanded into concrete statuses: `WP_Query` reads a literal `any`
 * as a NOT IN over the hidden statuses and never applies `perm`.
 *
 * @param mixed $status Raw `status` input.
 * @return array{post_status: string[], perm: string}
 */
function status_query_args( $status ): array {
	$requested = ( is_string( $status ) && in_array( $status, list_statuses(), true ) ) ? $status : 'any';

	if ( $requested !== 'any' ) {
		return [
			'post_status' => [ $requested ],
			'perm'        => in_array( $requested, UNPUBLISHED_STATUSES, true ) ? 'editable' : 'readable',
		];
	}

	$statuses = array_values( get_post_stati( [ 'exclude_from_search' => false ] ) );

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		$statuses = array_values( array_diff( $statuses, UNPUBLISHED_STATUSES ) );
	}

	return [
		'post_status' => $statuses,
		'perm'        => 'readable',
	];
}

/**
 * Shape a post for ability output.
 *
 * @param WP_Post $post         Post object.
 * @param bool    $with_content Include raw block markup.
 * @return array<string, mixed>
 */
function format_post( WP_Post $post, bool $with_content = false ): array {
	$data = [
		'id'     => $post->ID,
		'title'  => get_the_title( $post ),
		'slug'   => $post->post_name,
		'status' => $post->post_status,
		'type'   => $post->post_type,
		'link'   => (string) get_permalink( $post ),
	];

	if ( $with_content ) {
		$data['content'] = $post->post_content;
	}

	return $data;
}

/**
 * Meta key that records the original (remote) URL a sideloaded attachment came
 * from, so a re-run of the media sync can dedupe instead of re-uploading.
 *
 * @var string
 */
const SOURCE_URL_META = '_hm_abilities_source_url';

/**
 * Shape an attachment for media ability output.
 *
 * `url` is the new attachment URL on this site (what a sync client rewrites
 * content to); `original_url` is the remote URL it was imported from.
 *
 * @param int    $id           Attachment ID.
 * @param string $original_url Source URL the file was sideloaded from, if any.
 * @return array<string, mixed>
 */
function format_attachment( int $id, string $original_url = '' ): array {
	$data = [
		'id'    => $id,
		'url'   => (string) wp_get_attachment_url( $id ),
		'title' => get_the_title( $id ),
		'mime'  => (string) get_post_mime_type( $id ),
		'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
	];

	if ( $original_url !== '' ) {
		$data['original_url'] = $original_url;
	}

	return $data;
}

/**
 * Build an object input schema with the top-level default the validator needs.
 *
 * @param array<string, mixed> $properties Schema properties.
 * @param string[]             $required   Required property names.
 * @return array<string, mixed>
 */
function object_schema( array $properties, array $required = [] ): array {
	$schema = [
		'type'       => 'object',
		'default'    => (object) [],
		'properties' => $properties,
	];

	if ( $required ) {
		$schema['required'] = $required;
	}

	return $schema;
}

/**
 * Build a WP_Error with an HTTP status attached.
 *
 * @param string               $code    Machine code.
 * @param string               $message Human message.
 * @param int                  $status  HTTP status.
 * @param array<string, mixed> $data    Extra error data merged alongside the status.
 * @return WP_Error
 */
function error( string $code, string $message, int $status, array $data = [] ): WP_Error {
	return new WP_Error( $code, $message, [ 'status' => $status ] + $data );
}

/**
 * Validate a required positive integer ID from ability input.
 *
 * @param mixed  $input Ability input.
 * @param string $key   Field name.
 * @return int|WP_Error
 */
function validate_id( $input, string $key = 'id' ) {
	$input = is_array( $input ) ? $input : [];

	if ( ! isset( $input[ $key ] ) ) {
		return error(
			'hm_abilities_missing_' . $key,
			/* translators: %s: input field name. */
			sprintf( __( 'A %s is required.', 'hm-abilities-mcp' ), $key ),
			400
		);
	}

	if ( ! is_int( $input[ $key ] ) || $input[ $key ] <= 0 ) {
		return error(
			'hm_abilities_invalid_' . $key,
			/* translators: %s: input field name. */
			sprintf( __( 'The %s must be a positive integer.', 'hm-abilities-mcp' ), $key ),
			400
		);
	}

	return $input[ $key ];
}

/**
 * Permission helper: require a capability.
 *
 * @param string $cap Capability.
 * @return bool|WP_Error
 */
function cap_check( string $cap ) {
	if ( ! current_user_can( $cap ) ) {
		return error( 'hm_abilities_forbidden', __( 'You are not allowed to perform this action.', 'hm-abilities-mcp' ), 403 );
	}

	return true;
}

/**
 * Permission helper: require the capability to read across the network.
 *
 * On multisite the block-insight abilities can switch into any subsite, so
 * require a network capability. On a single site there is nothing to cross
 * into, so fall back to the same `edit_posts` gate the other read abilities use.
 *
 * @return bool|WP_Error
 */
function cap_check_network() {
	if ( is_multisite() ) {
		return cap_check( 'manage_sites' );
	}

	return cap_check( 'edit_posts' );
}

/**
 * Recursively flatten parsed blocks into a single list, dropping the
 * null-name freeform/whitespace chunks that sit between top-level blocks.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return array<int, array<string, mixed>>
 */
function flatten_blocks( array $blocks ): array {
	$out = [];

	foreach ( $blocks as $block ) {
		if ( ( $block['blockName'] ?? null ) !== null ) {
			$out[] = $block;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$out = array_merge( $out, flatten_blocks( $block['innerBlocks'] ) );
		}
	}

	return $out;
}

/**
 * Tally block-type usage from block markup, inner blocks included.
 *
 * @param string $content Block markup.
 * @return array<string, int> Block name => count, sorted by count descending.
 */
function tally_blocks( string $content ): array {
	$tally = [];

	foreach ( flatten_blocks( parse_blocks( $content ) ) as $block ) {
		$name           = (string) $block['blockName'];
		$tally[ $name ] = ( $tally[ $name ] ?? 0 ) + 1;
	}

	arsort( $tally );

	return $tally;
}

/**
 * Turn a block tally into the list-of-objects shape the abilities return.
 *
 * @param array<string, int> $tally Block name => count.
 * @return array<int, array{name: string, count: int}>
 */
function tally_to_list( array $tally ): array {
	$list = [];

	foreach ( $tally as $name => $count ) {
		$list[] = [
			'name'  => (string) $name,
			'count' => (int) $count,
		];
	}

	return $list;
}

/**
 * Build a nested outline of block names, preserving the block hierarchy.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return array<int, array<string, mixed>>
 */
function block_outline( array $blocks ): array {
	$out = [];

	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;

		if ( $name === null ) {
			continue;
		}

		$node = [ 'name' => (string) $name ];

		if ( ! empty( $block['innerBlocks'] ) ) {
			$children = block_outline( $block['innerBlocks'] );

			if ( $children ) {
				$node['inner'] = $children;
			}
		}

		$out[] = $node;
	}

	return $out;
}

/**
 * Query args restricting a site lookup to live sites.
 *
 * `WP_Site_Query` leaves these flags unset by default, which returns archived,
 * spam, and deleted sites alongside live ones. The abilities only ever act on
 * live sites, so every site lookup passes these.
 *
 * @return array<string, int>
 */
function live_site_args(): array {
	return [
		'archived' => 0,
		'spam'     => 0,
		'deleted'  => 0,
	];
}

/**
 * Whether a blog ID belongs to a live site. Always true off multisite.
 *
 * @param int $blog_id Blog ID.
 * @return bool
 */
function is_live_site( int $blog_id ): bool {
	if ( ! is_multisite() ) {
		return true;
	}

	$site = get_site( $blog_id );

	if ( ! $site ) {
		return false;
	}

	return ! $site->archived && ! $site->spam && ! $site->deleted;
}

/**
 * The status flags set on a site, as a list of `archived`/`spam`/`deleted`.
 *
 * Empty for a live site, and for a site that does not exist.
 *
 * @param int $blog_id Blog ID.
 * @return string[]
 */
function site_status_flags( int $blog_id ): array {
	$site = is_multisite() ? get_site( $blog_id ) : null;

	if ( ! $site ) {
		return [];
	}

	return array_values(
		array_filter(
			[ 'archived', 'spam', 'deleted' ],
			static function ( $flag ) use ( $site ) {
				return (bool) $site->$flag;
			}
		)
	);
}

/**
 * Require that a site is in a state that accepts changes.
 *
 * Core stops serving an archived, spam, or deleted site on the front end
 * (`ms_site_check()`) but places no such guard on programmatic access, so
 * `switch_to_blog()` would happily write to one. An agent acting on its own
 * should not be the thing that edits a site the network has taken out of
 * service, so the write abilities call this and refuse.
 *
 * Reads are deliberately unaffected: inspecting an archived site before it is
 * removed is legitimate, and blocking it would break auditing.
 *
 * Always passes off multisite, where no site can hold these flags.
 *
 * @param int $blog_id Blog ID, already validated as existing.
 * @return WP_Error|null Error when the site is not writable, null when it is.
 */
function require_live_site( int $blog_id ): ?WP_Error {
	if ( is_live_site( $blog_id ) ) {
		return null;
	}

	/**
	 * Filters whether the abilities may write to a site that is not live.
	 *
	 * Networks that intentionally keep editing archived subsites can return
	 * true to restore the unguarded behaviour.
	 *
	 * @param bool $allow   Whether to allow the write. Default false.
	 * @param int  $blog_id Blog ID being written to.
	 */
	if ( apply_filters( 'hm_abilities_allow_non_live_site_writes', false, $blog_id ) ) {
		return null;
	}

	$flags = site_status_flags( $blog_id );

	return error(
		'hm_abilities_site_not_live',
		sprintf(
			/* translators: %s: comma-separated site status flags, e.g. "archived". */
			__( 'That site is marked %s and does not accept changes. Reading from it is still allowed.', 'hm-abilities-mcp' ),
			implode( ', ', $flags )
		),
		409,
		[ 'site_status' => $flags ]
	);
}

/**
 * Total number of live sites in the network. Always 1 off multisite.
 *
 * @return int
 */
function count_sites(): int {
	if ( ! is_multisite() ) {
		return 1;
	}

	return (int) get_sites( live_site_args() + [ 'count' => true ] );
}

/**
 * One bounded page of live blog IDs, ordered by ID so paging is stable across
 * calls.
 *
 * A single-element list of the current blog when this is not a multisite
 * install, since no other blog exists to page through.
 *
 * @param int $per_page Sites per page.
 * @param int $page     1-based page number.
 * @return int[]
 */
function site_page( int $per_page, int $page ): array {
	if ( ! is_multisite() ) {
		return $page === 1 ? [ get_current_blog_id() ] : [];
	}

	$sites = get_sites(
		live_site_args() + [
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
 * Validate a single blog ID against the install.
 *
 * On multisite an unknown blog ID is a 404 and a non-positive ID a 400. On
 * single site any ID other than the current blog is rejected, since no other
 * blog exists.
 *
 * @param int $site_id Blog ID to check.
 * @return int|WP_Error The blog ID, or an error.
 */
function validate_blog_id( int $site_id ) {
	if ( $site_id <= 0 ) {
		return error( 'hm_abilities_invalid_site_id', __( 'The site_id must be a positive integer.', 'hm-abilities-mcp' ), 400 );
	}

	if ( ! is_multisite() ) {
		if ( $site_id !== get_current_blog_id() ) {
			return error( 'hm_abilities_not_multisite', __( 'This is not a multisite install; omit site_id or pass the current site.', 'hm-abilities-mcp' ), 400 );
		}

		return $site_id;
	}

	if ( ! get_site( $site_id ) ) {
		return error( 'hm_abilities_site_not_found', __( 'No network site matches that site_id.', 'hm-abilities-mcp' ), 404 );
	}

	return $site_id;
}

/**
 * Standard `site_ids`/`per_page`/`page` input-schema properties for an ability
 * that works across several subsites in one call.
 *
 * Merge into an ability's properties. A caller either names the sites it wants
 * with `site_ids`, or pages through the network with `per_page` and `page`.
 *
 * @param array<string, mixed> $properties        Existing schema properties.
 * @param int                  $default_per_page  Default sites per page.
 * @param int                  $max               Largest page size this ability allows.
 * @return array<string, mixed> Properties with the batch fields added.
 */
function with_site_batch( array $properties, int $default_per_page, int $max ): array {
	$properties['site_ids'] = [
		'type'        => 'array',
		'items'       => [
			'type'    => 'integer',
			'minimum' => 1,
		],
		'maxItems'    => $max,
		/* translators: %d: largest number of sites allowed in one call. */
		'description' => sprintf( __( 'Blog IDs to act on, at most %d per call. Omit to page through the whole network with per_page and page.', 'hm-abilities-mcp' ), $max ),
	];

	$properties['per_page'] = [
		'type'        => 'integer',
		'default'     => $default_per_page,
		'minimum'     => 1,
		'maximum'     => $max,
		/* translators: %d: largest number of sites allowed in one call. */
		'description' => sprintf( __( 'Sites to process per call when paging the network. Capped at %d so one call stays within a single request.', 'hm-abilities-mcp' ), $max ),
	];

	$properties['page'] = [
		'type'        => 'integer',
		'default'     => 1,
		'minimum'     => 1,
		'description' => __( 'Which page of network sites to process. Follow the next_page value in the response to cover the rest.', 'hm-abilities-mcp' ),
	];

	return $properties;
}

/**
 * Resolve the bounded set of blogs an ability call should touch.
 *
 * Returns an explicitly named set when the caller passes `site_ids`, otherwise
 * one page of the network. Either way the count is capped at `$max`, so a call
 * costs the same whether the network has ten sites or ten thousand. The
 * returned `sites` block tells the caller whether more pages remain, so the
 * client drives the loop across calls instead of PHP looping inside one request.
 *
 * Only live sites are considered: archived, spam, and deleted sites are skipped
 * when paging, and named explicitly they are an error.
 *
 * @param mixed $input            Ability input (read for `site_ids`, `per_page`, `page`).
 * @param int   $default_per_page Default sites per page.
 * @param int   $max              Largest page size this ability allows.
 * @return array{blog_ids: int[], sites: array<string, mixed>}|WP_Error
 */
function resolve_site_batch( $input, int $default_per_page, int $max ) {
	$input = is_array( $input ) ? $input : [];

	if ( isset( $input['site_ids'] ) ) {
		return resolve_explicit_sites( $input['site_ids'], $max );
	}

	$per_page = isset( $input['per_page'] ) ? min( $max, max( 1, (int) $input['per_page'] ) ) : $default_per_page;
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

	$blog_ids = site_page( $per_page, $page );
	$total    = count_sites();
	$has_more = ( $page * $per_page ) < $total;

	return [
		'blog_ids' => $blog_ids,
		'sites'    => [
			'selection'   => 'network_page',
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'processed'   => count( $blog_ids ),
			'has_more'    => $has_more,
			'next_page'   => $has_more ? $page + 1 : null,
			'total_pages' => (int) ceil( $total / $per_page ),
		],
	];
}

/**
 * Validate a caller-supplied `site_ids` list into a bounded blog ID list.
 *
 * Rejects an over-long list rather than silently truncating it, so a caller
 * never believes it covered sites the call skipped.
 *
 * @param mixed $site_ids Raw `site_ids` input.
 * @param int   $max      Largest number of sites allowed in one call.
 * @return array{blog_ids: int[], sites: array<string, mixed>}|WP_Error
 */
function resolve_explicit_sites( $site_ids, int $max ) {
	if ( ! is_array( $site_ids ) || $site_ids === [] ) {
		return error( 'hm_abilities_invalid_site_ids', __( 'The site_ids must be a non-empty array of blog IDs.', 'hm-abilities-mcp' ), 400 );
	}

	if ( count( $site_ids ) > $max ) {
		return error(
			'hm_abilities_too_many_sites',
			sprintf(
				/* translators: 1: number of sites requested, 2: largest number allowed. */
				__( 'Too many sites in one call: %1$d requested, %2$d is the maximum. Split the list across several calls.', 'hm-abilities-mcp' ),
				count( $site_ids ),
				$max
			),
			400
		);
	}

	$blog_ids = [];

	foreach ( $site_ids as $site_id ) {
		$valid = validate_blog_id( (int) $site_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! is_live_site( $valid ) ) {
			return error(
				'hm_abilities_site_not_live',
				sprintf(
					/* translators: %d: blog ID. */
					__( 'Site %d is archived, spam, or deleted. These abilities only act on live sites.', 'hm-abilities-mcp' ),
					$valid
				),
				400
			);
		}

		if ( ! in_array( $valid, $blog_ids, true ) ) {
			$blog_ids[] = $valid;
		}
	}

	return [
		'blog_ids' => $blog_ids,
		'sites'    => [
			'selection' => 'explicit',
			'total'     => count( $blog_ids ),
			'processed' => count( $blog_ids ),
			'has_more'  => false,
			'next_page' => null,
		],
	];
}

/**
 * Run a callback in the context of a given blog, restoring context afterwards.
 *
 * Switching is a no-op on single site or when the target is the current blog.
 *
 * @param int      $blog_id  Target blog ID.
 * @param callable $callback Callback run in the switched context.
 * @return mixed Whatever the callback returns.
 */
function with_site( int $blog_id, callable $callback ) {
	$switched = false;

	if ( is_multisite() && $blog_id > 0 && $blog_id !== get_current_blog_id() ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		return $callback();
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Standard `site_id` input-schema property for a multisite-targeting ability.
 *
 * Merge into an ability's properties so a caller can act on a specific subsite.
 * Omitting it means the current site. Harmless on single-site installs, where
 * only the current blog is a valid value.
 *
 * @param array<string, mixed> $properties Existing schema properties.
 * @return array<string, mixed> Properties with `site_id` added.
 */
function with_site_id( array $properties ): array {
	$properties['site_id'] = [
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'Blog ID to act on within the multisite network. Defaults to the current site.', 'hm-abilities-mcp' ),
	];

	return $properties;
}

/**
 * Resolve and validate the blog a request targets from its `site_id` input.
 *
 * Returns the current blog when no `site_id` is given. On multisite an unknown
 * blog ID is a 404 and a non-positive ID a 400. On single site any `site_id`
 * other than the current blog is rejected, since no other blog exists.
 *
 * @param mixed $input Ability input.
 * @return int|WP_Error Target blog ID, or an error.
 */
function resolve_target_site( $input ) {
	$input = is_array( $input ) ? $input : [];

	if ( ! isset( $input['site_id'] ) ) {
		return get_current_blog_id();
	}

	return validate_blog_id( (int) $input['site_id'] );
}

/**
 * Run an execute callback in the subsite resolved from `site_id`.
 *
 * Resolves and validates the target blog, switches into it for the callback,
 * and restores context afterwards. Returns the resolve error unchanged when the
 * `site_id` is invalid, so an execute callback can `return on_site( ... )`.
 *
 * @param mixed    $input    Ability input (read for `site_id`).
 * @param callable $callback Callback run in the target context.
 * @return mixed Callback result, or a WP_Error from resolution.
 */
function on_site( $input, callable $callback ) {
	$blog_id = resolve_target_site( $input );

	if ( is_wp_error( $blog_id ) ) {
		return $blog_id;
	}

	return with_site( $blog_id, $callback );
}

/**
 * Permission helper: require a capability on the subsite the request targets.
 *
 * Resolves the target blog from `site_id`, then checks the capability inside
 * that blog's context. `switch_to_blog()` re-evaluates the current user's caps
 * for the target site (since WP 4.9), so this reflects that user's rights on
 * the subsite actually acted on — a subsite editor cannot act elsewhere, while
 * super admins still pass everywhere.
 *
 * @param mixed  $input Ability input.
 * @param string $cap   Required capability.
 * @return bool|WP_Error
 */
function can_on_site( $input, string $cap ) {
	return check_cap_on_site( $input, $cap, false );
}

/**
 * Permission helper: require a capability on the subsite the request targets,
 * and require that subsite to accept changes.
 *
 * The write counterpart to `can_on_site()`. Use it for every ability that
 * mutates subsite state; see `require_live_site()` for why reads are exempt.
 *
 * @param mixed  $input Ability input.
 * @param string $cap   Required capability.
 * @return bool|WP_Error
 */
function can_write_on_site( $input, string $cap ) {
	return check_cap_on_site( $input, $cap, true );
}

/**
 * Shared body of `can_on_site()` and `can_write_on_site()`.
 *
 * @param mixed  $input        Ability input.
 * @param string $cap          Required capability.
 * @param bool   $require_live Whether the target site must accept changes.
 * @return bool|WP_Error
 */
function check_cap_on_site( $input, string $cap, bool $require_live ) {
	$blog_id = resolve_target_site( $input );

	if ( is_wp_error( $blog_id ) ) {
		return $blog_id;
	}

	if ( $require_live ) {
		$not_live = require_live_site( $blog_id );

		if ( $not_live ) {
			return $not_live;
		}
	}

	return with_site(
		$blog_id,
		static function () use ( $cap ) {
			return cap_check( $cap );
		}
	);
}

/**
 * Summarize the current blog (call inside the target context).
 *
 * @return array{site_id: int, name: string, url: string}
 */
function current_site_summary(): array {
	return [
		'site_id' => get_current_blog_id(),
		'name'    => get_bloginfo( 'name' ),
		'url'     => home_url( '/' ),
	];
}

/**
 * The static front page ID for the current blog, or 0 when the front page is
 * the posts index (no single page backs it).
 *
 * @return int
 */
function static_front_page_id(): int {
	if ( get_option( 'show_on_front' ) === 'page' ) {
		return (int) get_option( 'page_on_front' );
	}

	return 0;
}

/**
 * Resolve a front-end URL to the live network blog that serves it.
 *
 * Matches the URL host and the longest site path prefix, so a subdirectory
 * subsite (e.g. `/breeze/`) wins over the root site (`/`). Returns the current
 * blog ID unchanged on a single-site install.
 *
 * Delegates the match to core's `get_site_by_path()`, which queries only the
 * candidate path segments rather than loading the network, and handles the
 * `www` variants and subdirectory layouts a hand-rolled loop would miss.
 *
 * @param string $url Front-end URL.
 * @return int|WP_Error Blog ID, or an error if the URL is unparseable, unmatched, or not live.
 */
function resolve_site_from_url( string $url ) {
	$url = trim( $url );

	if ( $url === '' ) {
		return error( 'hm_abilities_invalid_url', __( 'A URL is required.', 'hm-abilities-mcp' ), 400 );
	}

	if ( ! is_multisite() ) {
		return get_current_blog_id();
	}

	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return error( 'hm_abilities_invalid_url', __( 'Could not parse that URL.', 'hm-abilities-mcp' ), 400 );
	}

	$host = strtolower( (string) $parts['host'] );
	$path = trailingslashit( isset( $parts['path'] ) ? (string) $parts['path'] : '/' );

	$site = get_site_by_path( $host, $path );

	if ( ! $site ) {
		return error( 'hm_abilities_site_not_found', __( 'No network site matches that URL.', 'hm-abilities-mcp' ), 404 );
	}

	$blog_id = (int) $site->blog_id;

	if ( ! is_live_site( $blog_id ) ) {
		return error(
			'hm_abilities_site_not_live',
			sprintf(
				/* translators: %d: blog ID. */
				__( 'Site %d is archived, spam, or deleted. These abilities only act on live sites.', 'hm-abilities-mcp' ),
				$blog_id
			),
			400
		);
	}

	return $blog_id;
}

/**
 * Serialize an ordered item list into navigation block markup.
 *
 * Each item: [ 'label' => string, 'url' => string, 'kind' => string,
 * 'children' => item[] ]. Items with children become navigation-submenu
 * blocks (one nesting level); others become navigation-link blocks.
 *
 * @param array<int, array<string, mixed>> $items Navigation items.
 * @return string
 */
function nav_items_to_markup( array $items ): string {
	return serialize_blocks( nav_items_to_blocks( $items ) );
}

/**
 * Build parsed-block structures for a navigation item list (recursive).
 *
 * Returns the block array shape WordPress core expects, so serialization runs
 * through serialize_block() — which escapes attribute values safely. Building
 * the comment markup by hand would let a label containing "-->" or "--" break
 * the block delimiter.
 *
 * @param array<int, array<string, mixed>> $items Navigation items.
 * @return array<int, array<string, mixed>>
 */
function nav_items_to_blocks( array $items ): array {
	$blocks = [];

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || empty( $item['label'] ) ) {
			continue;
		}

		$attrs = [
			'label' => (string) $item['label'],
			'url'   => isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '',
			'kind'  => isset( $item['kind'] ) ? (string) $item['kind'] : 'custom',
		];

		$children     = isset( $item['children'] ) && is_array( $item['children'] ) ? $item['children'] : [];
		$inner_blocks = $children ? nav_items_to_blocks( $children ) : [];

		$blocks[] = [
			'blockName'    => $inner_blocks ? 'core/navigation-submenu' : 'core/navigation-link',
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inner_blocks ), null ),
		];
	}

	return $blocks;
}

/**
 * Parse navigation block markup back into the item structure.
 *
 * @param string $content Navigation block markup.
 * @return array<int, array<string, mixed>>
 */
function markup_to_nav_items( string $content ): array {
	return blocks_to_items( parse_blocks( $content ) );
}

/**
 * Map parsed navigation blocks to the item structure (recursive).
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return array<int, array<string, mixed>>
 */
function blocks_to_items( array $blocks ): array {
	$items = [];

	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;

		if ( ! in_array( $name, [ 'core/navigation-link', 'core/navigation-submenu' ], true ) ) {
			continue;
		}

		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$item  = [
			'label' => (string) ( $attrs['label'] ?? '' ),
			'url'   => (string) ( $attrs['url'] ?? '' ),
			'kind'  => (string) ( $attrs['kind'] ?? 'custom' ),
		];

		if ( $name === 'core/navigation-submenu' && ! empty( $block['innerBlocks'] ) ) {
			$item['children'] = blocks_to_items( $block['innerBlocks'] );
		}

		$items[] = $item;
	}

	return $items;
}
