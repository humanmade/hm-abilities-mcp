<?php
/**
 * Settings abilities: read and update WordPress settings.
 *
 * Covers the General and Reading screens plus permalinks. The General/Reading
 * surface mirrors core's REST-registered settings (the same keys `/wp/v2/settings`
 * exposes): the option<->REST-name map and types are pulled from
 * `get_registered_settings()` at run time, and writes go through
 * `update_option()`, which applies each setting's registered sanitizer.
 * Permalinks are not REST-registered, so they are handled explicitly.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Settings;

use HM\Abilities\Support;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Permalink settings, which core does not register for REST. Keyed by the raw
 * option name (also the exposed key) => JSON type.
 *
 * @var array<string, string>
 */
const PERMALINK_KEYS = [
	'permalink_structure' => 'string',
	'category_base'       => 'string',
	'tag_base'            => 'string',
];

/**
 * Reading settings core does not register for REST, keyed by exposed key (also
 * the raw option name) => JSON type.
 *
 * `blog_public` backs the Reading screen's "Search engine visibility" checkbox:
 * 1 lets search engines index the site, 0 discourages them.
 *
 * @var array<string, string>
 */
const READING_KEYS = [
	'blog_public' => 'integer',
];

/**
 * The General/Reading settings this ability manages, as the single source of
 * truth for that surface: exposed key (the name `/wp/v2/settings` uses) =>
 * { option, type }. `settings_map()` prefers core's live REST registration and
 * falls back to this. `url`/`siteurl`/`home` are excluded on purpose: editing
 * them can lock everyone out of the site.
 *
 * @var array<string, array{option: string, type: string}>
 */
const FALLBACK_MAP = [
	'title'          => [ 'option' => 'blogname', 'type' => 'string' ],
	'description'    => [ 'option' => 'blogdescription', 'type' => 'string' ],
	'email'          => [ 'option' => 'admin_email', 'type' => 'string' ],
	'timezone'       => [ 'option' => 'timezone_string', 'type' => 'string' ],
	'date_format'    => [ 'option' => 'date_format', 'type' => 'string' ],
	'time_format'    => [ 'option' => 'time_format', 'type' => 'string' ],
	'start_of_week'  => [ 'option' => 'start_of_week', 'type' => 'integer' ],
	'language'       => [ 'option' => 'WPLANG', 'type' => 'string' ],
	'show_on_front'  => [ 'option' => 'show_on_front', 'type' => 'string' ],
	'page_on_front'  => [ 'option' => 'page_on_front', 'type' => 'integer' ],
	'page_for_posts' => [ 'option' => 'page_for_posts', 'type' => 'integer' ],
	'posts_per_page' => [ 'option' => 'posts_per_page', 'type' => 'integer' ],
];

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the settings abilities.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'hm-abilities/get-settings',
		[
			'label'               => __( 'Get settings', 'hm-abilities-mcp' ),
			'description'         => __( 'Read the WordPress General, Reading (including search engine visibility), and permalink settings.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema( Support\with_site_id( [] ) ),
			'output_schema'       => snapshot_schema(),
			'execute_callback'    => __NAMESPACE__ . '\\get_settings',
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
		'hm-abilities/update-settings',
		[
			'label'               => __( 'Update settings', 'hm-abilities-mcp' ),
			'description'         => __( 'Update WordPress General, Reading (including search engine visibility), and permalink settings. Send any subset of the supported keys.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema( Support\with_site_id( update_properties() ) ),
			'output_schema'       => snapshot_schema(),
			'execute_callback'    => __NAMESPACE__ . '\\update_settings',
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
 * Build the meta block shared by the settings abilities.
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
 * Input-schema properties for update-settings. Static (registration runs before
 * core registers its settings on rest_api_init); types mirror core's.
 *
 * @return array<string, array<string, mixed>>
 */
function update_properties(): array {
	return [
		'title'               => [
			'type'        => 'string',
			'description' => __( 'Site title (blogname).', 'hm-abilities-mcp' ),
		],
		'description'         => [
			'type'        => 'string',
			'description' => __( 'Tagline (blogdescription).', 'hm-abilities-mcp' ),
		],
		'email'               => [
			'type'        => 'string',
			'format'      => 'email',
			'description' => __( 'Administration email address (admin_email).', 'hm-abilities-mcp' ),
		],
		'timezone'            => [
			'type'        => 'string',
			'description' => __( 'Timezone string, e.g. "Australia/Sydney" (timezone_string).', 'hm-abilities-mcp' ),
		],
		'date_format'         => [ 'type' => 'string' ],
		'time_format'         => [ 'type' => 'string' ],
		'start_of_week'       => [
			'type'        => 'integer',
			'minimum'     => 0,
			'maximum'     => 6,
			'description' => __( 'Day the week starts on (0 = Sunday).', 'hm-abilities-mcp' ),
		],
		'language'            => [
			'type'        => 'string',
			'description' => __( 'Site locale code, e.g. "en_US" (WPLANG).', 'hm-abilities-mcp' ),
		],
		'show_on_front'       => [
			'type' => 'string',
			'enum' => [ 'posts', 'page' ],
		],
		'page_on_front'       => [
			'type'        => 'integer',
			'description' => __( 'ID of the static front page (requires show_on_front = page).', 'hm-abilities-mcp' ),
		],
		'page_for_posts'      => [
			'type'        => 'integer',
			'description' => __( 'ID of the page that lists the latest posts.', 'hm-abilities-mcp' ),
		],
		'posts_per_page'      => [
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Blog pages show at most this many posts.', 'hm-abilities-mcp' ),
		],
		'blog_public'         => [
			'type'        => 'integer',
			'enum'        => [ 0, 1 ],
			'description' => __( 'Search engine visibility: 1 lets search engines index the site, 0 discourages them.', 'hm-abilities-mcp' ),
		],
		'permalink_structure' => [
			'type'        => 'string',
			'description' => __( 'Permalink structure, e.g. "/%postname%/". Empty string sets plain permalinks.', 'hm-abilities-mcp' ),
		],
		'category_base'       => [
			'type'        => 'string',
			'description' => __( 'Category base prefix. Empty for the default.', 'hm-abilities-mcp' ),
		],
		'tag_base'            => [
			'type'        => 'string',
			'description' => __( 'Tag base prefix. Empty for the default.', 'hm-abilities-mcp' ),
		],
	];
}

/**
 * Permission: read settings on the targeted subsite.
 *
 * Matches core's `/wp/v2/settings`, which requires `manage_options` to read as
 * well as to write.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_read( $input = null ) {
	return Support\can_on_site( $input, 'manage_options' );
}

/**
 * Permission: write settings on the targeted subsite.
 *
 * Same capability as reading, but additionally refuses a site that is not
 * accepting changes.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_write( $input = null ) {
	return Support\can_write_on_site( $input, 'manage_options' );
}

/**
 * Map of exposed key => { option, type, permalink } for every supported
 * setting: General/Reading pulled from core's REST registration (filtered to
 * the FALLBACK_MAP keys, filled from FALLBACK_MAP), then permalinks appended.
 *
 * @return array<string, array{option: string, type: string, permalink: bool}>
 */
function settings_map(): array {
	$map     = [];
	$allowed = array_keys( FALLBACK_MAP );

	$registered = function_exists( 'get_registered_settings' ) ? get_registered_settings() : [];

	foreach ( $registered as $option => $args ) {
		if ( empty( $args['show_in_rest'] ) ) {
			continue;
		}

		$rest = ( is_array( $args['show_in_rest'] ) && ! empty( $args['show_in_rest']['name'] ) )
			? (string) $args['show_in_rest']['name']
			: (string) $option;

		if ( ! in_array( $rest, $allowed, true ) ) {
			continue;
		}

		$map[ $rest ] = [
			'option'    => (string) $option,
			'type'      => (string) ( $args['type'] ?? 'string' ),
			'permalink' => false,
		];
	}

	// Fill any key core has not registered yet from the static map.
	foreach ( $allowed as $rest ) {
		if ( ! isset( $map[ $rest ] ) ) {
			$map[ $rest ] = FALLBACK_MAP[ $rest ] + [ 'permalink' => false ];
		}
	}

	foreach ( READING_KEYS as $key => $type ) {
		$map[ $key ] = [
			'option'    => $key,
			'type'      => $type,
			'permalink' => false,
		];
	}

	foreach ( PERMALINK_KEYS as $key => $type ) {
		$map[ $key ] = [
			'option'    => $key,
			'type'      => $type,
			'permalink' => true,
		];
	}

	return $map;
}

/**
 * Read the current value of every supported setting (call inside the target
 * site context).
 *
 * @return array<string, mixed>
 */
function snapshot(): array {
	$out = [];

	foreach ( settings_map() as $key => $spec ) {
		$out[ $key ] = cast_value( get_option( $spec['option'] ), $spec['type'] );
	}

	return $out;
}

/**
 * Output schema for `snapshot()`, which both settings abilities return.
 *
 * Keys and types come from `settings_map()`, so a key added there carries
 * through. Descriptions are borrowed from the write schema.
 *
 * @return array<string, mixed>
 */
function snapshot_schema(): array {
	$writable   = update_properties();
	$properties = [];

	foreach ( settings_map() as $key => $spec ) {
		$properties[ $key ] = Support\prop( schema_type( $spec['type'] ), $writable[ $key ]['description'] ?? '' );
	}

	return Support\output_object( $properties, array_keys( $properties ) );
}

/**
 * The JSON type `cast_value()` produces for a settings-map type.
 *
 * @param string $type Type recorded in the settings map.
 * @return string
 */
function schema_type( string $type ): string {
	return match ( $type ) {
		'integer' => 'integer',
		'boolean' => 'boolean',
		default => 'string',
	};
}

/**
 * Return every supported setting for the targeted subsite.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function get_settings( $input = null ) {
	return Support\on_site(
		$input,
		static function (): array {
			return snapshot();
		}
	);
}

/**
 * Update a subset of the supported settings on the targeted subsite.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function update_settings( $input = null ) {
	$input = is_array( $input ) ? $input : [];

	// Permalink settings can only be changed on the site serving the request.
	// Applying them flushes rewrite rules against the request-global
	// $wp_rewrite, which switch_to_blog() does not rebuild for another subsite;
	// flushing there would regenerate that site's rules from this site's
	// permastructs and drop its own CPT/taxonomy routes.
	if ( array_intersect_key( PERMALINK_KEYS, $input ) !== [] ) {
		$target = Support\resolve_target_site( $input );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( $target !== get_current_blog_id() ) {
			return Support\error(
				'hm_abilities_permalink_cross_site',
				__( 'Permalink settings can only be changed on the site serving the request; omit site_id or target the current site.', 'hm-abilities-mcp' ),
				400
			);
		}
	}

	return Support\on_site(
		$input,
		static function () use ( $input ) {
			return apply_settings( $input );
		}
	);
}

/**
 * Make core's settings-error collector available.
 *
 * `sanitize_option()` reports a refused value through `add_settings_error()`,
 * which lives in wp-admin and is absent from an ability request, so without
 * this the refusal is dropped and the write looks like it succeeded.
 *
 * @return void
 */
function load_settings_error_collector(): void {
	if ( ! function_exists( 'add_settings_error' ) ) {
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}
}

/**
 * The settings errors recorded so far in this request.
 *
 * Read from the global rather than through `get_settings_errors()`, which
 * re-runs sanitization for a setting when asked to and would write again.
 *
 * @return array<int, array<string, mixed>>
 */
function settings_errors_log(): array {
	return isset( $GLOBALS['wp_settings_errors'] ) && is_array( $GLOBALS['wp_settings_errors'] )
		? $GLOBALS['wp_settings_errors']
		: [];
}

/**
 * Validate and apply the requested settings in the (already switched) context.
 *
 * Unknown keys and invalid reading values are rejected before anything is
 * written. A value core's sanitizer refuses mid-write is caught through
 * `add_settings_error()` and returned as an error, leaving any setting applied
 * earlier in the same call in place.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function apply_settings( array $input ) {
	// `site_id` steered the switch already; it is not a setting.
	unset( $input['site_id'] );

	$map     = settings_map();
	$changes = [];

	foreach ( $input as $key => $value ) {
		if ( ! isset( $map[ $key ] ) ) {
			return Support\error(
				'hm_abilities_unknown_setting',
				/* translators: %s: setting key. */
				sprintf( __( 'Unknown or unsupported setting: %s.', 'hm-abilities-mcp' ), (string) $key ),
				400
			);
		}

		$changes[ $key ] = $value;
	}

	if ( $changes === [] ) {
		return Support\error( 'hm_abilities_no_settings', __( 'Provide at least one setting to update.', 'hm-abilities-mcp' ), 400 );
	}

	$reading = validate_reading( $changes );

	if ( is_wp_error( $reading ) ) {
		return $reading;
	}

	$flush_permalinks = false;
	$rejected         = [];

	load_settings_error_collector();

	foreach ( $changes as $key => $value ) {
		$spec      = $map[ $key ];
		$requested = cast_value( $value, $spec['type'] );

		// Count first: the global accumulates across the loop, so only the
		// entries added by this write belong to this setting.
		$before = count( settings_errors_log() );

		if ( $spec['permalink'] ) {
			set_permalink_option( $key, (string) $requested );
			$flush_permalinks = true;
		} else {
			update_option( $spec['option'], $requested );
		}

		foreach ( array_slice( settings_errors_log(), $before ) as $entry ) {
			if ( ( $entry['setting'] ?? '' ) === $spec['option'] ) {
				$rejected[] = $key;
				break;
			}
		}
	}

	if ( $flush_permalinks ) {
		// Soft flush: rewrite the rules option for this (switched) site without
		// touching the .htaccess file.
		flush_rewrite_rules( false );
	}

	if ( $rejected !== [] ) {
		return Support\error(
			'hm_abilities_setting_rejected',
			sprintf(
				/* translators: %s: comma-separated list of rejected setting keys. */
				__( 'WordPress would not store the value given for these settings, so they were left unchanged: %s. Nothing was written for them, and any setting applied earlier in this request still stands.', 'hm-abilities-mcp' ),
				implode( ', ', $rejected )
			),
			400
		);
	}

	return snapshot();
}

/**
 * Cross-field validation for the reading settings. Runs before any write so no
 * partial mutation occurs.
 *
 * @param array<string, mixed> $changes Requested changes.
 * @return true|\WP_Error
 */
function validate_reading( array $changes ) {
	foreach ( [ 'page_on_front', 'page_for_posts' ] as $key ) {
		if ( ! isset( $changes[ $key ] ) ) {
			continue;
		}

		$page_id = (int) $changes[ $key ];

		// 0 is the WordPress sentinel for "no page selected" and clears the setting.
		if ( $page_id === 0 ) {
			continue;
		}

		$page = get_post( $page_id );

		// Must be a real, published page — a post/CPT/revision/draft here would
		// silently break the front or posts page for visitors.
		if ( ! $page instanceof WP_Post || $page->post_type !== 'page' || $page->post_status !== 'publish' ) {
			return Support\error(
				'hm_abilities_invalid_page',
				sprintf(
					/* translators: %s: reading-settings option key. */
					__( '%s must reference an existing published page.', 'hm-abilities-mcp' ),
					$key
				),
				400
			);
		}
	}

	// A static front page requires a valid page_on_front. Compute the resulting
	// state (incoming values fall back to current options) and reject the
	// combination that would blank the front page.
	$resulting_show          = $changes['show_on_front'] ?? get_option( 'show_on_front' );
	$resulting_page_on_front = isset( $changes['page_on_front'] )
		? (int) $changes['page_on_front']
		: (int) get_option( 'page_on_front' );

	if ( $resulting_show === 'page' && $resulting_page_on_front <= 0 ) {
		return Support\error(
			'hm_abilities_invalid_show_on_front',
			__( 'Setting a static front page (show_on_front=page) requires a valid page_on_front.', 'hm-abilities-mcp' ),
			400
		);
	}

	return true;
}

/**
 * Apply a single permalink setting through the rewrite API.
 *
 * Uses the global `$wp_rewrite`, which is request-scoped; while switched to
 * another subsite it writes that site's option and is discarded when the
 * request ends. The caller flushes the rules once after all changes.
 *
 * @param string $key   One of the PERMALINK_KEYS.
 * @param string $value Raw value.
 * @return void
 */
function set_permalink_option( string $key, string $value ): void {
	global $wp_rewrite;

	if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
		// Fall back to a plain option write if the rewrite API is unavailable.
		update_option( $key, $value );

		return;
	}

	match ( $key ) {
		'permalink_structure' => $wp_rewrite->set_permalink_structure( $value ),
		'category_base'       => $wp_rewrite->set_category_base( $value ),
		'tag_base'            => $wp_rewrite->set_tag_base( $value ),
		default               => null,
	};
}

/**
 * Cast a setting value to its declared type. Used both for reading (shaping the
 * JSON output) and writing (normalizing before `update_option()`, which still
 * runs the registered sanitizer on top).
 *
 * @param mixed  $value Value to cast.
 * @param string $type  JSON type.
 * @return mixed
 */
function cast_value( $value, string $type ) {
	return match ( $type ) {
		'integer' => (int) $value,
		'boolean' => (bool) $value,
		default   => (string) $value,
	};
}
