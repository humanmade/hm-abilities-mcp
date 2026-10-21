<?php
/**
 * Media abilities: import a file into the media library from a URL or base64.
 *
 * Built for content sync: when content is copied to this site, the media it
 * references is sideloaded here and the markup rewritten to the new URLs. The
 * upload ability records the original URL so a re-run dedupes instead of
 * importing the same file twice.
 *
 * Dedupe is by URL identity, not content: the same URL is never re-fetched
 * (so a file replaced in place at the source stays stale here until the URL
 * changes), and the same file served from two URLs imports twice. A base64
 * upload dedupes by a SHA-256 of its bytes instead, which never matches a URL
 * import. The lookup is not transactional, so two concurrent calls for a
 * not-yet-imported file can both create an attachment; a sync should run
 * sequentially per file.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Media;

use HM\Abilities\Media_Data;
use HM\Abilities\Support;
use WP_Http;

defined( 'ABSPATH' ) || exit;

/**
 * Hard ceiling on a single download, used when no upload limit is configured.
 *
 * @var int
 */
const DEFAULT_MAX_BYTES = 67108864; // 64 MB.

/**
 * Largest number of redirects the sideload follows.
 *
 * @var int
 */
const MAX_REDIRECTS = 3;

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
			'description'         => __( 'Import a file into the media library from a URL (source_url) or from base64 (data), and return the new attachment. Pass exactly one. Dedupes by source URL, or for data by file contents: a repeat import returns the existing attachment unchanged (title and alt overrides are not re-applied).', 'hm-abilities-mcp' ),
			'category'            => Support\CATEGORY,
			'input_schema'        => Support\object_schema(
				Support\with_site_id(
					[
						'source_url' => [
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'URL of the file to download and import. Use this for any file that is already hosted somewhere.', 'hm-abilities-mcp' ),
						],
						'data'       => [
							'type'        => 'string',
							'description' => sprintf(
								/* translators: %s: Largest file size accepted as base64, e.g. "5 MB". */
								__( 'Base64-encoded file contents, optionally as a data: URI. Limited to %s decoded; host a larger file and pass source_url instead.', 'hm-abilities-mcp' ),
								size_format( Media_Data\max_bytes() )
							),
						],
						'filename'   => [
							'type'        => 'string',
							'description' => __( 'Override the stored filename. Defaults to the basename of the source URL, or for data to "upload" with an extension from the detected file type.', 'hm-abilities-mcp' ),
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
				)
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
						'description' => __( 'The source URL the file was imported from. Absent for a data upload.', 'hm-abilities-mcp' ),
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
						'description' => __( 'True when a prior import of the same source URL, or of the same data, was returned instead of creating a new attachment.', 'hm-abilities-mcp' ),
					],
				],
				'required'             => [ 'id', 'url', 'already_existed' ],
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
	return Support\can_write_on_site( $input, 'upload_files' );
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
 * Perform the import in the (already switched) target site context.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function sideload( array $input ) {
	$has_url  = isset( $input['source_url'] ) && is_string( $input['source_url'] ) && $input['source_url'] !== '';
	$has_data = isset( $input['data'] ) && is_string( $input['data'] ) && $input['data'] !== '';

	if ( $has_url && $has_data ) {
		return Support\error( 'hm_abilities_ambiguous_source', __( 'Pass either source_url or data, not both.', 'hm-abilities-mcp' ), 400 );
	}

	if ( ! $has_url && ! $has_data ) {
		return Support\error( 'hm_abilities_missing_source_url', __( 'A source_url or data is required.', 'hm-abilities-mcp' ), 400 );
	}

	return $has_url ? sideload_url( $input ) : Media_Data\sideload( $input );
}

/**
 * Download `source_url` and import it, reusing a prior import of the same URL.
 *
 * @param array<string, mixed> $input Ability input, with a non-empty `source_url`.
 * @return array<string, mixed>|\WP_Error
 */
function sideload_url( array $input ) {
	$source_url = wp_http_validate_url( $input['source_url'] );

	if ( $source_url === false ) {
		return Support\error( 'hm_abilities_invalid_source_url', __( 'The source_url is not a valid, fetchable URL.', 'hm-abilities-mcp' ), 400 );
	}

	// Screened again per hop inside the fetch; this rejects a bad host early,
	// before a temp file is created or the dedupe lookup runs.
	$screened = resolve_source_target( $source_url );

	if ( is_wp_error( $screened ) ) {
		return $screened;
	}

	$post_id = attach_target( $input );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Dedupe: a prior import of the same URL is reused rather than re-fetched.
	$existing = existing_attachment_id( Support\SOURCE_URL_META, $source_url );

	if ( $existing > 0 ) {
		return Support\format_attachment( $existing, $source_url ) + [ 'already_existed' => true ];
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$temp = download_to_temp( $source_url );

	if ( is_wp_error( $temp ) ) {
		return $temp;
	}

	$name = filename_for( $source_url, isset( $input['filename'] ) ? (string) $input['filename'] : '', $temp );
	$id   = import_file( $temp, $name, $post_id, $input );

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	update_post_meta( $id, Support\SOURCE_URL_META, wp_slash( $source_url ) );

	return Support\format_attachment( $id, $source_url ) + [ 'already_existed' => false ];
}

/**
 * Returns the post to attach the import to, 0 for unattached.
 *
 * @param array<string, mixed> $input Ability input.
 * @return int|\WP_Error Post ID, or a 403 when the caller cannot edit that post.
 */
function attach_target( array $input ) {
	$post_id = isset( $input['post_id'] ) && is_int( $input['post_id'] ) && $input['post_id'] > 0 ? $input['post_id'] : 0;

	if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
		return Support\error( 'hm_abilities_forbidden', __( 'You are not allowed to attach media to that post.', 'hm-abilities-mcp' ), 403 );
	}

	return $post_id;
}

/**
 * Hands a temp file to the media library and applies the alt text.
 *
 * Deletes the temp file when the import fails.
 *
 * @param string               $temp    Path to the temp file.
 * @param string               $name    Filename to store it under.
 * @param int                  $post_id Post to attach to, or 0.
 * @param array<string, mixed> $input   Ability input, read for `title` and `alt`.
 * @return int|\WP_Error Attachment ID.
 */
function import_file( string $temp, string $name, int $post_id, array $input ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$file_array = [
		'name'     => $name,
		'tmp_name' => $temp,
	];

	$title = isset( $input['title'] ) ? (string) $input['title'] : '';

	$id = media_handle_sideload( $file_array, $post_id, $title !== '' ? wp_slash( $title ) : null );

	if ( is_wp_error( $id ) ) {
		// media_handle_sideload cleans up the temp file on its own error paths,
		// but guard against the early-return cases that don't.
		if ( file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}

		return Support\error( 'hm_abilities_sideload_failed', $id->get_error_message(), 422 );
	}

	if ( isset( $input['alt'] ) && (string) $input['alt'] !== '' ) {
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $input['alt'] ) ) );
	}

	return $id;
}

/**
 * Resolve a source URL's host and return the addresses the fetch may use.
 *
 * Screens IPv4 and IPv6 against the private and reserved ranges. A non-empty
 * `hm_abilities_media_allowed_source_hosts` filter restricts to those hosts
 * and skips the address screen. A host that resolves to nothing is refused.
 *
 * @param string $url Validated source URL.
 * @return string[]|WP_Error Addresses to connect to, or an error.
 */
function resolve_source_target( string $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );

	if ( ! is_string( $host ) || $host === '' ) {
		return Support\error( 'hm_abilities_invalid_source', __( 'Could not read a host from that source URL.', 'hm-abilities-mcp' ), 400 );
	}

	$ips = resolve_host_ips( $host );

	if ( $ips === [] ) {
		return Support\error( 'hm_abilities_source_not_allowed', __( 'That source host could not be resolved.', 'hm-abilities-mcp' ), 400 );
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
		if ( ! in_array( strtolower( $host ), array_map( 'strtolower', $allowed ), true ) ) {
			return Support\error( 'hm_abilities_source_not_allowed', __( 'That host is not in the allowed source list for this site.', 'hm-abilities-mcp' ), 403 );
		}

		return $ips;
	}

	foreach ( $ips as $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return Support\error( 'hm_abilities_source_not_allowed', __( 'That source host resolves to a private or reserved address.', 'hm-abilities-mcp' ), 403 );
		}
	}

	return $ips;
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
 * Fetch a URL to a temp file, screening and pinning every hop.
 *
 * Core's redirect handling is off: each iteration screens the URL's addresses,
 * connects to one of them, then reads the next `Location`. Stops after
 * MAX_REDIRECTS hops.
 *
 * @param string $url       Validated source URL.
 * @param string $temp      Temp file to stream into.
 * @param int    $max_bytes Largest body to accept.
 * @return array<string, mixed>|\WP_Error Response array, or an error.
 */
function fetch_pinned( string $url, string $temp, int $max_bytes ) {
	$hops = 0;

	while ( true ) {
		$ips = resolve_source_target( $url );

		if ( is_wp_error( $ips ) ) {
			return $ips;
		}

		$response = request_pinned( $url, (string) reset( $ips ), $temp, $max_bytes );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 300 || $code >= 400 ) {
			return $response;
		}

		++$hops;

		if ( $hops > MAX_REDIRECTS ) {
			return Support\error( 'hm_abilities_download_failed', __( 'The source redirected too many times.', 'hm-abilities-mcp' ), 502 );
		}

		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( ! is_string( $location ) || $location === '' ) {
			return Support\error( 'hm_abilities_download_failed', __( 'The source returned a redirect with no destination.', 'hm-abilities-mcp' ), 502 );
		}

		$next = wp_http_validate_url( WP_Http::make_absolute_url( $location, $url ) );

		if ( ! $next ) {
			return Support\error( 'hm_abilities_source_not_allowed', __( 'The source redirected to a URL that is not allowed.', 'hm-abilities-mcp' ), 403 );
		}

		$url = $next;
	}
}

/**
 * Run one request with the connection pinned to a screened address.
 *
 * The pin is registered for this request only, and does not apply where the
 * request does not go through curl.
 *
 * @param string $url       URL to fetch.
 * @param string $ip        Screened address to connect to.
 * @param string $temp      Temp file to stream into.
 * @param int    $max_bytes Largest body to accept.
 * @return array<string, mixed>|\WP_Error
 */
function request_pinned( string $url, string $ip, string $temp, int $max_bytes ) {
	$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
	$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
	$port   = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ?: ( $scheme === 'https' ? 443 : 80 ) );

	$pin = static function ( $handle ) use ( $host, $port, $ip ): void {
		if ( ! function_exists( 'curl_setopt' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- This IS the wp_remote_get() handle; pinning the address it connects to has no HTTP-API equivalent.
		curl_setopt( $handle, CURLOPT_RESOLVE, [ sprintf( '%s:%d:%s', $host, $port, $ip ) ] );
	};

	add_action( 'http_api_curl', $pin );

	try {
		return wp_safe_remote_get(
			$url,
			[
				'timeout'             => 30,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $temp,
				'limit_response_size' => $max_bytes > 0 ? $max_bytes : null,
			]
		);
	} finally {
		remove_action( 'http_api_curl', $pin );
	}
}

/**
 * Creates an empty temp file named after `$name`.
 *
 * `wp_tempnam()` returns a path even when it could not create the file, so the
 * file existing is what shows it worked.
 *
 * @param string $name Name to base the temp file on.
 * @return string|\WP_Error Temp file path.
 */
function create_temp_file( string $name ) {
	$temp = wp_tempnam( $name );

	if ( ! file_exists( $temp ) ) {
		return Support\error( 'hm_abilities_temp_file_failed', __( 'Could not create a temporary file.', 'hm-abilities-mcp' ), 500 );
	}

	return $temp;
}

/**
 * Download a URL to a temp file, bounding the response size and request time.
 *
 * @param string $url Validated source URL.
 * @return string|\WP_Error Temp file path, or an error.
 */
function download_to_temp( string $url ) {
	$temp = create_temp_file( $url );

	if ( is_wp_error( $temp ) ) {
		return $temp;
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

	$response = fetch_pinned( $url, $temp, $max_bytes );

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
 * Find an attachment previously imported with the given dedupe key.
 *
 * @param string $meta_key `Support\SOURCE_URL_META` or `Media_Data\CONTENT_HASH_META`.
 * @param string $value    Source URL or content hash recorded at import time.
 * @return int Attachment ID, or 0 when none exists.
 */
function existing_attachment_id( string $meta_key, string $value ): int {
	$ids = get_posts(
		[
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// An equality lookup on an unindexed meta value is the only way to
			// resolve a source URL or hash back to its attachment; it is bounded to a
			// single row and only runs on import.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query,HM.Performance.SlowMetaQuery.nonperformant_comparison -- Dedupe lookup keyed on the recorded source URL or content hash.
			'meta_query'             => [
				[
					'key'   => $meta_key,
					'value' => $value,
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
 * @param string $source_url Source URL, or empty for a base64 upload (named `upload`).
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
