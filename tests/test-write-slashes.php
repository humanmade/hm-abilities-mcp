<?php
/**
 * Covers backslashes surviving every write ability. Core's insert, update and
 * meta functions unslash their input, so a value passed through unslashed
 * loses each backslash: the `\u003c` escapes serialize_block_attributes()
 * writes into block comments become `u003c`. Helpers come from
 * tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

wp_set_current_user( 1 );

$content = '<!-- wp:heading {"content":"\u003cstrong\u003eCan this be frozen?\u003c/strong\u003e"} --><h2 class="wp-block-heading"><strong>Can this be frozen?</strong></h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Saved to C:\Recipes\new</p><!-- /wp:paragraph -->';
$title   = 'Back\slash title';

echo "== create-content ==\n";

$created = run( 'hm-abilities/create-content', [ 'post_type' => 'post', 'title' => $title, 'content' => $content ] );
$id      = (int) field( $created, 'id' );
check( 'create-content succeeds', $id > 0, $created );
check( 'the stored content keeps its backslashes', get_post( $id )->post_content === $content, get_post( $id )->post_content );
check( 'the stored title keeps its backslash', get_post( $id )->post_title === $title, get_post( $id )->post_title );

echo "\n== update-content ==\n";

$existing = wp_insert_post( wp_slash( [ 'post_type' => 'post', 'post_title' => 'Existing', 'post_status' => 'draft', 'post_content' => $content ] ) );
check( 'baseline: the fixture holds its backslashes', get_post( $existing )->post_content === $content, get_post( $existing )->post_content );

$read = run( 'hm-abilities/get-content', [ 'id' => $existing ] );
run( 'hm-abilities/update-content', [ 'id' => $existing, 'content' => field( $read, 'content' ) ] );
check( 'sending back what get-content returned leaves the content unchanged', get_post( $existing )->post_content === $content, get_post( $existing )->post_content );

run( 'hm-abilities/update-content', [ 'id' => $existing, 'title' => $title ] );
check( 'an updated title keeps its backslash', get_post( $existing )->post_title === $title, get_post( $existing )->post_title );

echo "\n== update-navigation ==\n";

$menu = wp_insert_post( [
	'post_type'    => 'wp_navigation',
	'post_title'   => 'Primary',
	'post_status'  => 'publish',
	'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->',
] );

// serialize_block_attributes() writes & and " as \u0026 and \u0022.
$label = 'Tips & "Tricks"';
run( 'hm-abilities/update-navigation', [ 'id' => $menu, 'items' => [ [ 'label' => $label, 'url' => '/tips' ] ] ] );
$items = field( run( 'hm-abilities/get-navigation', [ 'id' => $menu ] ), 'items' );
check( 'a label with & and quotes reads back unchanged', ( $items[0]['label'] ?? null ) === $label, $items );

echo "\n== upload-media ==\n";

$image = imagecreatetruecolor( 3, 3 );
ob_start();
imagepng( $image );
$png = base64_encode( (string) ob_get_clean() );

$alt      = 'Alt with a \ backslash';
$uploaded = run( 'hm-abilities/upload-media', [ 'data' => $png, 'title' => $title, 'alt' => $alt ] );
$media    = (int) field( $uploaded, 'id' );
check( 'upload-media succeeds', $media > 0, $uploaded );
check( 'the attachment title keeps its backslash', get_post( $media )->post_title === $title, get_post( $media )->post_title );
check( 'the alt text keeps its backslash', get_post_meta( $media, '_wp_attachment_image_alt', true ) === $alt, get_post_meta( $media, '_wp_attachment_image_alt', true ) );

// With no title or alt in the input, core takes them and the caption from the
// image's IPTC and XMP metadata.
require_once ABSPATH . 'wp-admin/includes/file.php';

$meta_title   = 'IPTC\title';
$meta_caption = 'Shot in C:\Studio';
$meta_alt     = 'XMP\alt';
$iptc         = static fn ( int $dataset, string $value ): string => chr( 0x1C ) . chr( 2 ) . chr( $dataset ) . pack( 'n', strlen( $value ) ) . $value;

$jpeg_path = wp_tempnam( 'slashes.jpg' );
imagejpeg( $image, $jpeg_path );
$jpeg = iptcembed( $iptc( 105, $meta_title ) . $iptc( 120, $meta_caption ), $jpeg_path )
	. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/">'
	. '<Iptc4xmpCore:AltTextAccessibility><rdf:Alt><rdf:li xml:lang="x-default">' . $meta_alt . '</rdf:li></rdf:Alt></Iptc4xmpCore:AltTextAccessibility>'
	. '</rdf:Description></rdf:RDF></x:xmpmeta>';
wp_delete_file( $jpeg_path );

$from_meta = (int) field( run( 'hm-abilities/upload-media', [ 'data' => base64_encode( $jpeg ) ] ), 'id' );
check( 'a title read from the image keeps its backslash', get_post( $from_meta )->post_title === $meta_title, get_post( $from_meta )->post_title );
check( 'a caption read from the image keeps its backslash', get_post( $from_meta )->post_content === $meta_caption, get_post( $from_meta )->post_content );
check( 'alt text read from the image keeps its backslash', get_post_meta( $from_meta, '_wp_attachment_image_alt', true ) === $meta_alt, get_post_meta( $from_meta, '_wp_attachment_image_alt', true ) );

// wp_http_validate_url() accepts a literal backslash, and a repeat import is
// matched against the stored source URL. A public IP literal passes the source
// screen without DNS; the fetch itself is faked.
$source     = 'http://1.1.1.1/img/a\b.png';
$fake_fetch = static function ( $pre, $args ) use ( $png ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Stands in for WP_Http streaming the body to the temp file.
	file_put_contents( $args['filename'], base64_decode( $png ) );

	return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => $args['filename'] ];
};
add_filter( 'pre_http_request', $fake_fetch, 10, 2 );

$first  = run( 'hm-abilities/upload-media', [ 'source_url' => $source ] );
$second = run( 'hm-abilities/upload-media', [ 'source_url' => $source ] );

remove_filter( 'pre_http_request', $fake_fetch, 10 );

check( 'a URL import succeeds', (int) field( $first, 'id' ) > 0, $first );
check( 'a repeat import of a URL with a backslash returns the first', field( $second, 'id' ) === field( $first, 'id' ) && field( $second, 'already_existed' ) === true, $second );

summary();
