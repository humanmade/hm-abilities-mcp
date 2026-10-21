<?php
/**
 * Covers a title read from the post abilities going back to update-content
 * unchanged. get_the_title() texturizes quotes, dashes and ellipses into
 * entities and prefixes a private post with "Private: ", so a caller that
 * edits a copy of what it read would save those into the title. Helpers come
 * from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

wp_set_current_user( 1 );

// Every character wptexturize() rewrites: an apostrophe, double quotes, a
// double hyphen and three dots.
$title = 'Sam\'s "big" launch -- part 2...';

echo "== get-content ==\n";

$private = wp_insert_post( wp_slash( [ 'post_type' => 'post', 'post_title' => $title, 'post_status' => 'private' ] ) );
check( 'baseline: the fixture stores the title as given', get_post( $private )->post_title === $title, get_post( $private )->post_title );

$read = run( 'hm-abilities/get-content', [ 'id' => $private ] );
check( 'get-content returns the stored title of a private post', field( $read, 'title' ) === $title, field( $read, 'title' ) );

run( 'hm-abilities/update-content', [ 'id' => $private, 'title' => field( $read, 'title' ) ] );
check( 'sending that title back leaves it unchanged', get_post( $private )->post_title === $title, get_post( $private )->post_title );

echo "\n== list abilities ==\n";

$published = wp_insert_post( wp_slash( [ 'post_type' => 'post', 'post_title' => $title, 'post_status' => 'publish' ] ) );

$listed = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'publish', 'search' => 'launch' ] );
check( 'list-content returns the stored title', leaked( $listed, $title ), titles( $listed ) );

$recent = run( 'hm-abilities/list-recent-articles', [] );
check( 'list-recent-articles returns the stored title', leaked( $recent, $title ), titles( $recent ) );

echo "\n== write replies ==\n";

$created = run( 'hm-abilities/create-content', [ 'post_type' => 'post', 'title' => $title, 'status' => 'private' ] );
check( 'create-content replies with the title it stored', field( $created, 'title' ) === $title, $created );

$updated = run( 'hm-abilities/update-content', [ 'id' => $published, 'status' => 'publish' ] );
check( 'update-content replies with the stored title', field( $updated, 'title' ) === $title, $updated );

summary();
