<?php
/**
 * Media abilities: sideload a remote file into the media library.
 *
 * Built for content sync: when content is copied to this site, the media it
 * references is sideloaded here and the markup rewritten to the new URLs. The
 * upload ability records the original URL so a re-run dedupes instead of
 * importing the same file twice.
 *
 * Dedupe is by URL identity, not content: the same URL is never re-fetched
 * (so a file replaced in place at the source stays stale here until the URL
 * changes), and the same file served from two URLs imports twice. The lookup
 * is also not transactional, so two concurrent calls for a not-yet-imported
 * URL can both create an attachment; a sync should run sequentially per URL.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Media;

use HM\Abilities\Support;
use const HM\Abilities\Support\CATEGORY;

defined( 'ABSPATH' ) || exit;

/**
 * Hard ceiling on a single download, used when no upload limit is configured.
 *
 * @var int
 */
const DEFAULT_MAX_BYTES = 67108864; // 64 MB.

/**
 * Wire up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
}

/**
 * Register the media abilities.
 *
 * @return void
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'hm-abilities/upload-media',
		[
			'label'               => __( 'Upload media', 'hm-abilities-mcp' ),
			'description'         => __( 'Sideload a file from a URL into the media library and return the new attachment. Dedupes by source URL: if that URL was imported before, the existing attachment is returned unchanged (title and alt overrides are not re-applied).', 'hm-abilities-mcp' ),
			'category'            => CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'source_url' => [
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'URL of the file to download and import.', 'hm-abilities-mcp' ),
						],
						'filename'   => [
							'type'        => 'string',
							'description' => __( 'Override the stored filename. Defaults to the basename of the source URL.', 'hm-abilities-mcp' ),
						],
						'title'      => [
							'type'        => 'string',
							'description' => __( 'Attachment title.', 'hm-abilities-mcp' ),
						],
						'alt'        => [
							'type'        => 'string',
							'description' => __( 'Alt text for images.', 'hm-abilities-mcp' ),
						],
						'post_id'    => [
							'type'        => 'integer',
							'description' => __( 'Post to attach the media to. Requires permission to edit that post. Defaults to unattached.', 'hm-abilities-mcp' ),
						],
					]
				),
				[ 'source_url' ]
			),
			'output_schema'       => [
				'type'                 => 'object',
				'properties'           => [
					'id'              => [
						'type'        => 'integer',
						'description' => __( 'Attachment ID.', 'hm-abilities-mcp' ),
					],
					'url'             => [
						'type'        => 'string',
						'format'      => 'uri',
						'description' => __( 'URL of the imported attachment on this site. Use this when rewriting content.', 'hm-abilities-mcp' ),
					],
					'original_url'    => [
						'type'        => 'string',
						'format'      => 'uri',
						'description' => __( 'The source URL the file was imported from.', 'hm-abilities-mcp' ),
					],
					'title'           => [
						'type'        => 'string',
						'description' => __( 'Attachment title.', 'hm-abilities-mcp' ),
					],
					'mime'            => [
						'type'        => 'string',
						'description' => __( 'Attachment MIME type.', 'hm-abilities-mcp' ),
					],
					'alt'             => [
						'type'        => 'string',
						'description' => __( 'Alt text stored for the attachment.', 'hm-abilities-mcp' ),
					],
					'already_existed' => [
						'type'        => 'boolean',
						'description' => __( 'True when a prior import of the same source URL was returned instead of creating a new attachment.', 'hm-abilities-mcp' ),
					],
				],
				'required'             => [ 'id', 'url', 'original_url', 'already_existed' ],
				'additionalProperties' => false,
			],
			'execute_callback'    => __NAMESPACE__ . '\\upload_media',
			'permission_callback' => __NAMESPACE__ . '\\can_upload',
			'meta'                => [
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
				'annotations'  => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		]
	);
}

/**
 * Permission: upload media on the targeted subsite.
 *
 * @param mixed $input Ability input (read for `site_id`).
 * @return bool|\WP_Error
 */
function can_upload( $input = null ) {
	return Support\can_on_site( $input, 'upload_files' );
}

/**
 * Sideload a file from a URL into the media library.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function upload_media( $input = null ) {
	return Support\on_site(
		$input,
		static function () use ( $input ) {
			return sideload( is_array( $input ) ? $input : [] );
		}
	);
}

/**
 * Perform the sideload in the (already switched) target site context.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function sideload( array $input ) {
	if ( ! isset( $input['source_url'] ) || ! is_string( $input['source_url'] ) || $input['source_url'] === '' ) {
		return Support\error( 'hm_abilities_missing_source_url', __( 'A source_url is required.', 'hm-abilities-mcp' ), 400 );
	}

	$source_url = wp_http_validate_url( $input['source_url'] );

	if ( $source_url === false ) {
		return Support\error( 'hm_abilities_invalid_source_url', __( 'The source_url is not a valid, fetchable URL.', 'hm-abilities-mcp' ), 400 );
	}

	if ( ! source_host_allowed( $source_url ) ) {
		return Support\error( 'hm_abilities_forbidden_url', __( 'The source_url host is not permitted.', 'hm-abilities-mcp' ), 403 );
	}

	$post_id = isset( $input['post_id'] ) && is_int( $input['post_id'] ) && $input['post_id'] > 0 ? $input['post_id'] : 0;

	if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
		return Support\error( 'hm_abilities_forbidden', __( 'You are not allowed to attach media to that post.', 'hm-abilities-mcp' ), 403 );
	}

	// Dedupe: a prior import of the same URL is reused rather than re-fetched.
	$existing = existing_attachment_id( $source_url );

	if ( $existing > 0 ) {
		return Support\format_attachment( $existing, $source_url ) + [ 'already_existed' => true ];
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$temp = download_to_temp( $source_url );

	if ( is_wp_error( $temp ) ) {
		return $temp;
	}

	$file_array = [
		'name'     => filename_for( $source_url, isset( $input['filename'] ) ? (string) $input['filename'] : '', $temp ),
		'tmp_name' => $temp,
	];

	$title = isset( $input['title'] ) ? (string) $input['title'] : '';

	$id = media_handle_sideload( $file_array, $post_id, $title !== '' ? $title : null );

	if ( is_wp_error( $id ) ) {
		// media_handle_sideload cleans up the temp file on its own error paths,
		// but guard against the early-return cases that don't.
		if ( file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}

		return Support\error( 'hm_abilities_sideload_failed', $id->get_error_message(), 422 );
	}

	update_post_meta( $id, Support\SOURCE_URL_META, $source_url );

	if ( isset( $input['alt'] ) && (string) $input['alt'] !== '' ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
	}

	return Support\format_attachment( $id, $source_url ) + [ 'already_existed' => false ];
}

/**
 * Decide whether the ability may fetch from the given URL's host.
 *
 * `wp_http_validate_url()` only screens IPv4 RFC1918 ranges, so it lets through
 * the cloud-metadata address (169.254.169.254) and every IPv6 loopback/ULA
 * target. This closes those gaps and, when the
 * `hm_abilities_media_allowed_source_hosts` filter returns a non-empty list,
 * restricts sideloading to those hosts (recommended for site-to-site sync).
 *
 * @param string $url Validated source URL.
 * @return bool
 */
function source_host_allowed( string $url ): bool {
	$host = wp_parse_url( $url, PHP_URL_HOST );

	if ( ! is_string( $host ) || $host === '' ) {
		return false;
	}

	/**
	 * Filters the hosts the media sideload ability may fetch from.
	 *
	 * Return a non-empty list to restrict sideloading to those hosts. An empty
	 * list (the default) allows any host that does not resolve to a private or
	 * reserved address.
	 *
	 * @param string[] $hosts Allowed source hosts. Default empty.
	 */
	$allowed = apply_filters( 'hm_abilities_media_allowed_source_hosts', [] );

	if ( is_array( $allowed ) && $allowed !== [] ) {
		return in_array( strtolower( $host ), array_map( 'strtolower', $allowed ), true );
	}

	foreach ( resolve_host_ips( $host ) as $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Resolve a host to its IP addresses (IPv4 and, where available, IPv6).
 *
 * An IP literal is returned as-is. A hostname that cannot be resolved returns
 * an empty list; the caller treats "no IPs to vet" as allowed because an
 * unresolvable host is not fetchable anyway, and the dangerous literal targets
 * (metadata, loopback) take the IP-literal branch above.
 *
 * @param string $host Host or IP literal.
 * @return string[]
 */
function resolve_host_ips( string $host ): array {
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return [ $host ];
	}

	$ips = [];

	if ( function_exists( 'dns_get_record' ) ) {
		$records = dns_get_record( $host, DNS_A | DNS_AAAA );

		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = (string) $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}
	}

	if ( $ips === [] ) {
		$v4 = gethostbynamel( $host );

		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
	}

	return $ips;
}

/**
 * Download a URL to a temp file, bounding the response size and request time.
 *
 * Uses the safe HTTP path (`wp_safe_remote_get()` re-validates the URL and
 * every redirect) and caps the body so an allowed-but-hostile source cannot
 * fill the temp disk.
 *
 * @param string $url Validated source URL.
 * @return string|\WP_Error Temp file path, or an error.
 */
function download_to_temp( string $url ) {
	$temp = wp_tempnam( $url );

	if ( ! $temp ) {
		return Support\error( 'hm_abilities_download_failed', __( 'Could not create a temporary file.', 'hm-abilities-mcp' ), 500 );
	}

	$upload_limit = (int) wp_max_upload_size();
	$max_bytes    = $upload_limit > 0 ? $upload_limit : DEFAULT_MAX_BYTES;

	/**
	 * Filters the maximum number of bytes the sideload may download.
	 *
	 * @param int    $max_bytes Maximum bytes. Defaults to the upload limit.
	 * @param string $url       Source URL being fetched.
	 */
	$max_bytes = (int) apply_filters( 'hm_abilities_media_max_download_bytes', $max_bytes, $url );

	$response = wp_safe_remote_get(
		$url,
		[
			'timeout'             => 30,
			'stream'              => true,
			'filename'            => $temp,
			'limit_response_size' => $max_bytes > 0 ? $max_bytes : null,
		]
	);

	if ( is_wp_error( $response ) ) {
		wp_delete_file( $temp );

		return Support\error( 'hm_abilities_download_failed', $response->get_error_message(), 502 );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code < 200 || $code >= 300 ) {
		wp_delete_file( $temp );

		return Support\error(
			'hm_abilities_download_failed',
			/* translators: %d: HTTP status code returned by the source. */
			sprintf( __( 'The source returned HTTP %d.', 'hm-abilities-mcp' ), $code ),
			502
		);
	}

	return $temp;
}

/**
 * Find an attachment previously imported from the given source URL.
 *
 * @param string $source_url Original URL recorded at import time.
 * @return int Attachment ID, or 0 when none exists.
 */
function existing_attachment_id( string $source_url ): int {
	$ids = get_posts(
		[
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dedupe lookup keyed on the recorded source URL.
			'meta_query'             => [
				[
					'key'   => Support\SOURCE_URL_META,
					'value' => $source_url,
				],
			],
		]
	);

	return $ids ? (int) $ids[0] : 0;
}

/**
 * Decide the stored filename, preferring an explicit override and falling back
 * to the basename of the source URL's path. Appends an extension derived from
 * the downloaded file's MIME type when the name has none, so query-string and
 * extensionless source URLs still pass `media_handle_sideload()`'s type check.
 *
 * @param string $source_url Source URL.
 * @param string $override   Explicit filename, or empty.
 * @param string $file       Path to the downloaded temp file.
 * @return string
 */
function filename_for( string $source_url, string $override, string $file ): string {
	$raw  = $override !== '' ? $override : (string) wp_parse_url( $source_url, PHP_URL_PATH );
	$name = sanitize_file_name( basename( $raw ) );

	if ( $name === '' ) {
		$name = 'upload';
	}

	if ( (string) pathinfo( $name, PATHINFO_EXTENSION ) === '' ) {
		$ext = extension_from_file( $file );

		if ( $ext !== '' ) {
			$name .= '.' . $ext;
		}
	}

	return $name;
}

/**
 * Guess a file extension from a downloaded file's MIME type.
 *
 * @param string $file Path to the file.
 * @return string Extension without the dot, or empty when undetermined.
 */
function extension_from_file( string $file ): string {
	$mime = '';

	if ( function_exists( 'mime_content_type' ) ) {
		$detected = mime_content_type( $file );

		if ( is_string( $detected ) && $detected !== '' ) {
			$mime = $detected;
		}
	}

	if ( $mime === '' ) {
		return '';
	}

	$ext = wp_get_default_extension_for_mime_type( $mime );

	return is_string( $ext ) ? $ext : '';
}
