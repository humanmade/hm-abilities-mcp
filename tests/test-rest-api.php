<?php
/**
 * Covers the REST API abilities: each holds to its own methods, the route's
 * permission callback still applies, and the write ability is opt-in. Helpers
 * come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

use HM\Abilities\Rest_Api;

/**
 * Counts posts with a title, in any status. A draft has no slug, so a slug
 * lookup cannot tell whether one was created.
 *
 * @param string $title Post title.
 * @return int
 */
function posts_titled( string $title ): int {
	return count( get_posts( [ 'title' => $title, 'post_status' => 'any', 'fields' => 'ids' ] ) );
}

$editor = wp_insert_user( [
	'user_login'   => 'eli_' . wp_rand( 1000, 9999 ),
	'user_pass'    => wp_generate_password(),
	'display_name' => 'Eli Editor',
	'role'         => 'editor',
] );

$subscriber = wp_insert_user( [
	'user_login'   => 'sam_' . wp_rand( 1000, 9999 ),
	'user_pass'    => wp_generate_password(),
	'display_name' => 'Sam Subscriber',
	'role'         => 'subscriber',
] );

$post = wp_insert_post( [
	'post_type'   => 'post',
	'post_title'  => 'REST fixture',
	'post_status' => 'publish',
	'post_author' => 1,
] );

echo "== read-rest-api: GET and OPTIONS only ==\n";
wp_set_current_user( 1 );

$read = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => "/wp/v2/posts/$post" ] );
check( 'an administrator reads a post', field( $read, 'status' ) === 200 && ( field( $read, 'data' )['id'] ?? null ) === $post, $read );

$list = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => '/wp/v2/posts', 'params' => [ 'per_page' => 1 ] ] );
check( 'params reach a GET as query arguments', count( (array) field( $list, 'data' ) ) === 1, $list );
check( 'collection headers come back', isset( field( $list, 'headers' )->{'X-WP-Total'} ), $list );

$options = run( Rest_Api\READ_ABILITY, [ 'method' => 'OPTIONS', 'route' => '/wp/v2/posts' ] );
check( 'OPTIONS describes the route', field( $options, 'status' ) === 200 && isset( field( $options, 'data' )['endpoints'] ), $options );

$post_via_read = run( Rest_Api\READ_ABILITY, [ 'method' => 'POST', 'route' => '/wp/v2/posts', 'params' => [ 'title' => 'Should not exist' ] ] );
check( 'a POST is refused by the input schema', code( $post_via_read ) === 'ability_invalid_input', $post_via_read );
check( 'and creates nothing', posts_titled( 'Should not exist' ) === 0 );

$delete_via_read = run( Rest_Api\READ_ABILITY, [ 'method' => 'DELETE', 'route' => "/wp/v2/posts/$post" ] );
check( 'a DELETE is refused by the input schema', code( $delete_via_read ) === 'ability_invalid_input', $delete_via_read );
check( 'and the post survives', get_post_status( $post ) === 'publish' );

$override = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => "/wp/v2/posts/$post", 'params' => [ '_method' => 'DELETE', 'force' => true ] ] );
check( 'a _method param does not turn a GET into a DELETE', get_post_status( $post ) === 'publish', $override );

$query_route = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => '/wp/v2/posts?per_page=1' ] );
check( 'a query string in the route is refused', code( $query_route ) === 'ability_invalid_input', $query_route );

echo "\n== read-rest-api: matches what the route does over HTTP ==\n";

$trailing = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => "/wp/v2/posts/$post/" ] );
check( 'a trailing slash still matches the route', field( $trailing, 'status' ) === 200, $trailing );

$index = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => '/' ] );
check( 'the index at / is still reachable', field( $index, 'status' ) === 200 && isset( field( $index, 'data' )['namespaces'] ), field( $index, 'status' ) );

// The index, unlike the posts controller, leaves _fields to rest_post_dispatch.
$fields = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => '/', 'params' => [ '_fields' => 'name' ] ] );
check( '_fields narrows a route that does not apply it itself', array_keys( (array) field( $fields, 'data' ) ) === [ 'name' ], array_keys( (array) field( $fields, 'data' ) ) );

$embedded = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => "/wp/v2/posts/$post", 'params' => [ '_embed' => 'author' ] ] );
check( '_embed embeds the linked author', isset( field( $embedded, 'data' )['_embedded']['author'] ), $embedded );

$tag_response = static function ( WP_REST_Response $response ): WP_REST_Response {
	$response->header( 'X-HM-Post-Dispatch', 'ran' );
	return $response;
};
add_filter( 'rest_post_dispatch', $tag_response );
$filtered = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => "/wp/v2/posts/$post" ] );
remove_filter( 'rest_post_dispatch', $tag_response );
check( 'every rest_post_dispatch filter runs, as core key masking needs', ( field( $filtered, 'headers' )->{'X-HM-Post-Dispatch'} ?? null ) === 'ran', $filtered );

echo "\n== read-rest-api: the route's own permissions apply ==\n";
wp_set_current_user( $subscriber );

$settings = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => '/wp/v2/settings' ] );
check( 'a subscriber is refused the settings route', in_array( field( $settings, 'status' ), [ 401, 403 ], true ), $settings );
check( 'with the route error in data', ( field( $settings, 'data' )['code'] ?? null ) === 'rest_forbidden', $settings );

wp_set_current_user( 0 );

$anonymous = run( Rest_Api\READ_ABILITY, [ 'method' => 'GET', 'route' => '/wp/v2/posts' ] );
check( 'a logged-out caller is refused', code( $anonymous ) === 'ability_invalid_permissions', $anonymous );

echo "\n== write-rest-api: opt-in ==\n";
wp_set_current_user( 1 );

check( 'it is not registered by default', ! wp_has_ability( Rest_Api\WRITE_ABILITY ) );

// Abilities may only register while wp_abilities_api_init runs, which has
// already fired, so re-run this module's registration inside that action name.
add_filter( 'hm_abilities_enable_rest_write', '__return_true' );
wp_unregister_ability( Rest_Api\READ_ABILITY );
$GLOBALS['wp_current_filter'][] = 'wp_abilities_api_init';
Rest_Api\register();
array_pop( $GLOBALS['wp_current_filter'] );

check( 'it registers once the filter opts in', wp_has_ability( Rest_Api\WRITE_ABILITY ) );

$annotations = wp_get_ability( Rest_Api\WRITE_ABILITY )->get_meta()['annotations'] ?? [];
check( 'it is annotated destructive and not read-only', $annotations['destructive'] === true && $annotations['readonly'] === false, $annotations );

$created = run( Rest_Api\WRITE_ABILITY, [ 'method' => 'POST', 'route' => '/wp/v2/posts', 'params' => [ 'title' => 'Made over REST', 'status' => 'draft' ] ] );
$new_id  = (int) ( field( $created, 'data' )['id'] ?? 0 );
check( 'an administrator creates a post', field( $created, 'status' ) === 201 && get_post_status( $new_id ) === 'draft', $created );

$deleted = run( Rest_Api\WRITE_ABILITY, [ 'method' => 'DELETE', 'route' => "/wp/v2/posts/$new_id", 'params' => [ 'force' => true ] ] );
check( 'and deletes it', field( $deleted, 'status' ) === 200 && get_post( $new_id ) === null, $deleted );

$get_via_write = run( Rest_Api\WRITE_ABILITY, [ 'method' => 'GET', 'route' => '/wp/v2/posts' ] );
check( 'a GET is refused by its input schema', code( $get_via_write ) === 'ability_invalid_input', $get_via_write );

$nested = run( Rest_Api\WRITE_ABILITY, [
	'method' => 'POST',
	'route'  => '/wp-abilities/v1/abilities/hm-abilities/create-content/run',
	'params' => [ 'input' => [ 'post_type' => 'post', 'title' => 'Made by a nested run' ] ],
] );
check( 'a POST to an ability run route carries its input', field( $nested, 'status' ) === 200 && posts_titled( 'Made by a nested run' ) === 1, $nested );

// An editor can create posts through the route itself, so only the ability's
// own manage_options gate stops this.
wp_set_current_user( $editor );

$direct = new WP_REST_Request( 'POST', '/wp/v2/posts' );
$direct->set_body_params( [ 'title' => 'Editor direct' ] );
$direct = rest_do_request( $direct );
check( 'precondition: the posts route itself lets an editor create', $direct->get_status() === 201, $direct->get_data() );
wp_delete_post( (int) ( $direct->get_data()['id'] ?? 0 ), true );

$editor_write = run( Rest_Api\WRITE_ABILITY, [ 'method' => 'POST', 'route' => '/wp/v2/posts', 'params' => [ 'title' => 'Editor post' ] ] );
check( 'an editor is refused without manage_options', code( $editor_write ) === 'ability_invalid_permissions', $editor_write );
check( 'and creates nothing', posts_titled( 'Editor post' ) === 0 );

wp_set_current_user( $subscriber );

$subscriber_write = run( Rest_Api\WRITE_ABILITY, [ 'method' => 'POST', 'route' => '/wp/v2/posts', 'params' => [ 'title' => 'Subscriber post' ] ] );
check( 'a subscriber is refused without manage_options', code( $subscriber_write ) === 'ability_invalid_permissions', $subscriber_write );

summary();
