<?php
/**
 * Base64 `data` input for the upload-media ability.
 *
 * Kept apart from the URL path because it never touches the network: the size
 * is capped before decoding, and dedupe is by a hash of the bytes.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Media_Data;

use HM\Abilities\Media;
use HM\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Largest decoded file accepted as base64 `data`, before the site upload limit.
 *
 * A request through the MCP adapter measured peak memory at about 5.6 times the
 * decoded size, so 5 MB adds about 28 MB over the site's baseline.
 *
 * @var int
 */
const DEFAULT_MAX_BYTES = 5242880; // 5 MB.

/**
 * Meta key holding the SHA-256 of a base64 upload's bytes, for dedupe.
 *
 * @var string
 */
const CONTENT_HASH_META = '_hm_abilities_content_sha256';

/**
 * Decode base64 `data` and import it, reusing a prior import of the same bytes.
 *
 * @param array<string, mixed> $input Ability input, with a non-empty `data`.
 * @return array<string, mixed>|\WP_Error
 */
function sideload( array $input ) {
	$bytes = decode( $input['data'] );

	if ( is_wp_error( $bytes ) ) {
		return $bytes;
	}

	$post_id = Media\attach_target( $input );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$hash     = hash( 'sha256', $bytes );
	$existing = Media\existing_attachment_id( CONTENT_HASH_META, $hash );

	if ( $existing > 0 ) {
		return Support\format_attachment( $existing ) + [ 'already_existed' => true ];
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$temp = write_to_temp( $bytes );

	// Image sub-sizes are generated next; release the decoded copy first.
	unset( $bytes );

	if ( is_wp_error( $temp ) ) {
		return $temp;
	}

	$name = Media\filename_for( '', isset( $input['filename'] ) ? (string) $input['filename'] : '', $temp );
	$id   = Media\import_file( $temp, $name, $post_id, $input );

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	update_post_meta( $id, CONTENT_HASH_META, $hash );

	return Support\format_attachment( $id ) + [ 'already_existed' => false ];
}

/**
 * Returns the largest decoded file, in bytes, accepted as base64 `data`.
 *
 * Never above the site upload limit.
 *
 * @return int
 */
function max_bytes(): int {
	/**
	 * Filters the largest decoded file, in bytes, accepted as base64 `data`.
	 *
	 * Peak memory grows by about 5.6 times this through the MCP adapter, so
	 * raise `memory_limit` alongside it. Callers over it are told to pass
	 * `source_url` instead.
	 *
	 * @param int $max_bytes Maximum decoded bytes. Default 5 MB.
	 */
	$max_bytes    = (int) apply_filters( 'hm_abilities_media_max_data_bytes', DEFAULT_MAX_BYTES );
	$upload_limit = (int) wp_max_upload_size();

	return $upload_limit > 0 ? min( $max_bytes, $upload_limit ) : $max_bytes;
}

/**
 * Decodes base64 `data`, refusing it before decoding when it is over the limit.
 *
 * Accepts a `data:<mime>;base64,` prefix. The declared MIME type is ignored:
 * core detects the real type from the bytes.
 *
 * @param string $data Base64 string or data: URI.
 * @return string|\WP_Error Decoded bytes.
 */
function decode( string $data ) {
	$invalid = Support\error( 'hm_abilities_invalid_data', __( 'The data is not valid base64.', 'hm-abilities-mcp' ), 400 );

	if ( str_starts_with( $data, 'data:' ) ) {
		$comma = strpos( $data, ',' );

		if ( $comma === false || ! str_ends_with( substr( $data, 0, $comma ), ';base64' ) ) {
			return $invalid;
		}

		$data = substr( $data, $comma + 1 );
	}

	// Four base64 characters encode three bytes, so this is an upper bound.
	$decoded_size = intdiv( strlen( $data ) * 3, 4 );
	$max_bytes    = max_bytes();

	if ( $decoded_size > $max_bytes ) {
		return Support\error(
			'hm_abilities_data_too_large',
			sprintf(
				/* translators: 1: Size of the decoded data, e.g. "12 MB". 2: Largest size accepted as data. */
				__( 'The data decodes to about %1$s, over the %2$s limit for base64 uploads. Host the file and pass its URL as source_url instead.', 'hm-abilities-mcp' ),
				size_format( $decoded_size ),
				size_format( $max_bytes )
			),
			413
		);
	}

	$bytes = base64_decode( $data, true );

	return ( $bytes === false || $bytes === '' ) ? $invalid : $bytes;
}

/**
 * Writes bytes to a new temp file.
 *
 * @param string $bytes File contents.
 * @return string|\WP_Error Temp file path.
 */
function write_to_temp( string $bytes ) {
	// wp_tempnam() returns a path even when it could not create the file, so a
	// failed write is what catches an unwritable temp directory.
	$temp = wp_tempnam( 'hm-abilities-data' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A wp_tempnam() file in the temp dir; WP_Filesystem may pick a transport that needs credentials a headless request cannot give.
	if ( file_put_contents( $temp, $bytes ) !== strlen( $bytes ) ) {
		wp_delete_file( $temp );

		return Support\error( 'hm_abilities_temp_file_failed', __( 'Could not write the temporary file.', 'hm-abilities-mcp' ), 500 );
	}

	return $temp;
}
