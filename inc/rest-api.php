<?php
/**
 * REST API abilities: dispatch any internal REST request.
 *
 * Split by HTTP method so each ability's annotations hold for every call it
 * can make. Core treats POST as an edit method (`POST /wp/v2/posts/123`
 * overwrites the post), so every method but GET and OPTIONS is destructive.
 * The `method` enum is the guard: the Abilities API validates input before it
 * checks permissions. The write ability is opt-in.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Rest_Api;

use HM\Abilities\Support;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Read ability key.
 *
 * @var string
 */
const READ_ABILITY = 'hm-abilities/read-rest-api';

/**
 * Write ability key.
 *
 * @var string
 */
const WRITE_ABILITY = 'hm-abilities/write-rest-api';

/**
 * Methods the read ability accepts.
 *
 * @var string[]
 */
const READ_METHODS = [ 'GET', 'OPTIONS' ];

/**
 * Methods the write ability accepts.
 *
 * @var string[]
 */
const WRITE_METHODS = [ 'POST', 'PUT', 'PATCH', 'DELETE' ];

/**
 * Methods whose params are sent as the request body rather than the query.
 *
 * @var string[]
 */
const BODY_METHODS = [ 'POST', 'PUT', 'PATCH' ];

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Registers the read ability, and the write ability when the site opts in.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		READ_ABILITY,
		[
			'label'               => __( 'Read REST API', 'hm-abilities-mcp' ),
			'description'         => __( 'Send a GET or OPTIONS request to any WordPress REST API route on this site. Use it for data the other abilities do not cover. GET /wp/v2 lists the core routes, and OPTIONS on a route describes its methods and arguments.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => input_schema( READ_METHODS ),
			'output_schema'       => output_schema(),
			'execute_callback'    => __NAMESPACE__ . '\\dispatch',
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

	/**
	 * Filters whether to register `hm-abilities/write-rest-api`.
	 *
	 * @param bool $enabled Whether to register it. Default false.
	 */
	if ( ! apply_filters( 'hm_abilities_enable_rest_write', false ) ) {
		return;
	}

	wp_register_ability(
		WRITE_ABILITY,
		[
			'label'               => __( 'Write REST API', 'hm-abilities-mcp' ),
			'description'         => __( 'Send a POST, PUT, PATCH or DELETE request to any WordPress REST API route on this site. Use it only for changes the other abilities cannot make. Check the route with OPTIONS through hm-abilities/read-rest-api first.', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => input_schema( WRITE_METHODS ),
			'output_schema'       => output_schema(),
			'execute_callback'    => __NAMESPACE__ . '\\dispatch',
			'permission_callback' => __NAMESPACE__ . '\\can_write',
			'meta'                => Support\meta(
				[
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				]
			),
		]
	);
}

/**
 * Input schema limited to the given methods.
 *
 * A route carrying a query string matches no registered route, so the pattern
 * refuses one and points the caller at `params`.
 *
 * @param string[] $methods HTTP methods the ability accepts.
 * @return array<string, mixed>
 */
function input_schema( array $methods ): array {
	return Support\object_schema(
		[
			'method' => Support\prop( 'string', __( 'HTTP method.', 'hm-abilities-mcp' ), [ 'enum' => $methods ] ),
			'route'  => Support\prop(
				'string',
				__( 'REST route path, such as /wp/v2/posts or /wp/v2/posts/123. Put query arguments in params, not in the route.', 'hm-abilities-mcp' ),
				[ 'pattern' => '^/[^?#]*$' ]
			),
			'params' => Support\prop(
				'object',
				__( 'Request arguments. Sent as the query string for GET, OPTIONS and DELETE, and as the body for POST, PUT and PATCH.', 'hm-abilities-mcp' ),
				[ 'additionalProperties' => true ]
			),
		],
		[ 'method', 'route' ]
	);
}

/**
 * Output schema shared by the REST API abilities.
 *
 * @return array<string, mixed>
 */
function output_schema(): array {
	return Support\output_object(
		[
			'status'  => Support\prop( 'integer', __( 'HTTP status the route answered with. On a 4xx status, data holds the error.', 'hm-abilities-mcp' ) ),
			'headers' => Support\prop( 'object', __( 'Response headers, such as X-WP-Total and X-WP-TotalPages on a collection.', 'hm-abilities-mcp' ) ),
			'data'    => Support\prop(
				[ 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' ],
				__( 'Response body.', 'hm-abilities-mcp' )
			),
		],
		[ 'status', 'headers', 'data' ]
	);
}

/**
 * Permission: any logged-in user. The route's own permission callback decides
 * what they may read.
 *
 * @return bool|\WP_Error
 */
function can_read() {
	if ( ! is_user_logged_in() ) {
		return Support\error( 'hm_abilities_not_logged_in', __( 'You must be logged in to call the REST API.', 'hm-abilities-mcp' ), 401 );
	}

	return true;
}

/**
 * Permission: `manage_options` on the site serving the request, which must be
 * live.
 *
 * Ignores any `site_id` in the input, because the request is dispatched on
 * this site and a capability elsewhere would be the wrong one to check.
 *
 * @return bool|\WP_Error
 */
function can_write() {
	return Support\can_write_on_site( [], 'manage_options' );
}

/**
 * Dispatches the request internally and returns the route's response.
 *
 * Applies what `serve_request()` does over HTTP and `rest_do_request()` skips,
 * as core's `rest_preload_api_request()` does. Without `rest_post_dispatch`,
 * `_fields` is ignored and core's connector key masking on `/wp/v2/settings`
 * never runs.
 *
 * @param mixed $input Ability input.
 * @return array{status: int, headers: object, data: mixed}
 */
function dispatch( $input = null ): array {
	$method = $input['method'];
	$params = $input['params'] ?? [];

	// Over HTTP, rest_api_loaded() strips the trailing slash before matching.
	$route = untrailingslashit( $input['route'] );

	if ( $route === '' ) {
		$route = '/';
	}

	$request = new WP_REST_Request( $method, $route );

	if ( in_array( $method, BODY_METHODS, true ) ) {
		// Routes read either form params or JSON (the abilities run route reads JSON only), so send both.
		$request->set_body_params( $params );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
	} else {
		$request->set_query_params( $params );
	}

	$server = rest_get_server();

	/** This filter is documented in wp-includes/rest-api/class-wp-rest-server.php */
	$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $request ) ), $server, $request );
	$embed    = $request->has_param( '_embed' ) ? rest_parse_embed_param( $request['_embed'] ) : false;

	return [
		'status'  => $response->get_status(),
		// An object, so an empty header list encodes as {} and matches the schema.
		'headers' => (object) $response->get_headers(),
		'data'    => $server->response_to_data( $response, $embed ),
	];
}
