<?php
/**
 * Thin wrapper around the WordPress REST API of a remote receiver site.
 *
 * @package Niyi\PagePostSync
 */

namespace Niyi\PagePostSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ApiClient {

	/** @var string */
	private $url;

	/** @var string */
	private $username;

	/** @var string */
	private $app_password;

	/**
	 * @param array $receiver_data Must contain url, username and app_password.
	 */
	public function __construct( array $receiver_data ) {
		$this->url          = rtrim( (string) ( $receiver_data['url'] ?? '' ), '/' );
		$this->username     = (string) ( $receiver_data['username'] ?? '' );
		$this->app_password = (string) ( $receiver_data['app_password'] ?? '' );

		if ( '' === $this->url || '' === $this->username || '' === $this->app_password ) {
			throw new \InvalidArgumentException( __( 'Receiver credentials are incomplete.', 'niyi-page-post-sync' ) );
		}
	}

	/**
	 * @return string REST API v2 base URL.
	 */
	public function rest_base(): string {
		return $this->url . '/wp-json/wp/v2';
	}

	/**
	 * @return string Receiver site URL.
	 */
	public function get_url(): string {
		return $this->url;
	}

	/* ------------------------------------------------------------------ */
	/* Core request helper                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Perform an authenticated REST request.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Endpoint relative to the REST base.
	 * @param array  $args     Optional query/headers/body/timeout overrides.
	 * @return array|\WP_Error Array with code, data, raw, headers, response.
	 */
	private function request( string $method, string $endpoint, array $args = array() ) {
		// Endpoints starting with "/" are relative to the site root (custom routes).
		$url = ( strpos( $endpoint, '/' ) === 0 )
			? $this->url . $endpoint
			: $this->rest_base() . '/' . ltrim( $endpoint, '/' );

		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
			unset( $args['query'] );
		}

		$headers = array(
			'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->app_password ),
			'Accept'        => 'application/json',
		);
		if ( ! empty( $args['headers'] ) ) {
			$headers = array_merge( $headers, $args['headers'] );
			unset( $args['headers'] );
		}

		$defaults = array(
			'method'    => strtoupper( $method ),
			'timeout'   => 30,
			'sslverify' => (bool) apply_filters( 'niyi_pps_sslverify', false ),
			'headers'   => $headers,
		);
		$args     = array_merge( $defaults, $args );

		// JSON-encode array bodies unless a raw body (multipart) was supplied.
		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $args['body'] );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'http_error', $response->get_error_message(), $response );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$data    = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : $raw;
			if ( '' === $message ) {
				$message = sprintf( __( 'Remote API returned HTTP %d.', 'niyi-page-post-sync' ), $code );
			}
			return new \WP_Error( 'api_error_' . $code, $message, array( 'status' => $code, 'body' => $data ) );
		}

		return array(
			'code'     => $code,
			'data'     => is_array( $data ) ? $data : array(),
			'raw'      => $raw,
			'headers'  => wp_remote_retrieve_headers( $response ),
			'response' => $response,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Connection                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Verify connectivity and credentials.
	 *
	 * @return true|\WP_Error
	 */
	public function verify_connection() {
		$result = $this->request( 'GET', 'users/me', array( 'timeout' => 15 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['data']['id'] ) ) {
			return new \WP_Error( 'auth_failed', __( 'Authentication failed. Check the application password.', 'niyi-page-post-sync' ) );
		}
		return true;
	}

	/**
	 * Ask the receiver which shortcodes it supports.
	 *
	 * Requires this plugin to be active on the receiver. A WP_Error means the
	 * capability could not be verified (callers should warn the user).
	 *
	 * @return array|\WP_Error
	 */
	public function get_capabilities() {
		$result = $this->request( 'GET', '/wp-json/niyi-pps/v1/capabilities', array( 'timeout' => 15 ) );
		return is_wp_error( $result ) ? $result : $result['data'];
	}

	/* ------------------------------------------------------------------ */
	/* Posts & pages                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * List posts/pages, following pagination headers.
	 *
	 * @param string $endpoint 'posts' or 'pages'.
	 * @param array  $query    Extra query parameters.
	 * @return array|\WP_Error
	 */
	public function list_posts( string $endpoint, array $query = array() ) {
		$query   = array_merge( array( 'per_page' => 100, 'page' => 1, 'status' => 'any', 'context' => 'edit' ), $query );
		$all     = array();
		$page    = 1;
		$max     = 50; // Safety cap.

		do {
			$query['page'] = $page;
			$result        = $this->request( 'GET', $endpoint, array( 'query' => $query ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$posts = $result['data'];
			if ( ! is_array( $posts ) || empty( $posts ) ) {
				break;
			}
			$all = array_merge( $all, $posts );

			$total_pages = (int) wp_remote_retrieve_header( $result['response'], 'X-WP-TotalPages' );
			$page++;
		} while ( $total_pages > 0 && $page <= $total_pages && $page <= $max );

		return $all;
	}

	/**
	 * Find a remote post by slug.
	 *
	 * @param string $slug     Slug to search.
	 * @param string $endpoint 'posts' or 'pages'.
	 * @return array|\WP_Error|null
	 */
	public function find_post_by_slug( string $slug, string $endpoint ) {
		$result = $this->list_posts( $endpoint, array( 'slug' => $slug, 'per_page' => 1 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return ! empty( $result[0] ) ? $result[0] : null;
	}

	/**
	 * @param string $endpoint 'posts' or 'pages'.
	 * @param int    $id       Remote post ID.
	 * @return array|\WP_Error
	 */
	public function get_post( string $endpoint, int $id ) {
		$result = $this->request( 'GET', $endpoint . '/' . $id );
		return is_wp_error( $result ) ? $result : $result['data'];
	}

	/**
	 * @param string $endpoint 'posts' or 'pages'.
	 * @param array  $data     REST post fields.
	 * @return array|\WP_Error Remote post data.
	 */
	public function create_post( string $endpoint, array $data ) {
		$result = $this->request( 'POST', $endpoint, array( 'body' => $data ) );
		return is_wp_error( $result ) ? $result : $result['data'];
	}

	/**
	 * @param string $endpoint 'posts' or 'pages'.
	 * @param int    $id       Remote post ID.
	 * @param array  $data     REST post fields.
	 * @return array|\WP_Error Remote post data.
	 */
	public function update_post( string $endpoint, int $id, array $data ) {
		$result = $this->request( 'POST', $endpoint . '/' . $id, array( 'body' => $data ) );
		return is_wp_error( $result ) ? $result : $result['data'];
	}

	/* ------------------------------------------------------------------ */
	/* Media                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Build a multipart/form-data body for a file upload.
	 *
	 * @param string $path     Local file path.
	 * @param string $filename Filename to use remotely.
	 * @param string $mime     MIME type.
	 * @param array  $fields   Additional form fields.
	 * @return array|\WP_Error
	 */
	private function multipart_body( string $path, string $filename, string $mime, array $fields ) {
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return new \WP_Error( 'file_read_failed', __( 'Could not read the media file.', 'niyi-page-post-sync' ) );
		}

		$boundary = 'niyi' . wp_generate_password( 24, false, false );
		$body     = '';

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . str_replace( '"', '', sanitize_file_name( $filename ) ) . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$body .= $contents . "\r\n";

		foreach ( $fields as $name => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= (string) $value . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";

		return array(
			'body'         => $body,
			'content_type' => 'multipart/form-data; boundary=' . $boundary,
		);
	}

	/**
	 * Upload a local WP media attachment to the remote site.
	 *
	 * @param int $attachment_id Local attachment ID.
	 * @return array|\WP_Error Remote media data.
	 */
	public function upload_media( int $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'file_missing', sprintf( __( 'File for attachment #%d is missing.', 'niyi-page-post-sync' ), $attachment_id ) );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! $mime ) {
			$mime = 'application/octet-stream';
		}

		$fields = array(
			'title'       => get_the_title( $attachment_id ),
			'caption'     => wp_get_attachment_caption( $attachment_id ),
			'description' => get_post_field( 'post_content', $attachment_id ),
			'alt_text'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);

		$multipart = $this->multipart_body( $file, basename( $file ), $mime, $fields );
		if ( is_wp_error( $multipart ) ) {
			return $multipart;
		}

		$result = $this->request( 'POST', 'media', array(
			'headers' => array( 'Content-Type' => $multipart['content_type'] ),
			'body'    => $multipart['body'],
			'timeout' => 60,
		) );

		return is_wp_error( $result ) ? $result : $result['data'];
	}

	/**
	 * @param int $id Remote media ID.
	 * @return array|\WP_Error
	 */
	public function get_media( int $id ) {
		$result = $this->request( 'GET', 'media/' . $id );
		return is_wp_error( $result ) ? $result : $result['data'];
	}

	/**
	 * @param int   $id    Remote media ID.
	 * @param array $data  Fields to update.
	 * @return array|\WP_Error
	 */
	public function update_media( int $id, array $data ) {
		$result = $this->request( 'POST', 'media/' . $id, array( 'body' => $data ) );
		return is_wp_error( $result ) ? $result : $result['data'];
	}

	/**
	 * @param int  $id    Remote media ID.
	 * @param bool $force Force delete.
	 * @return true|\WP_Error
	 */
	public function delete_media( int $id, bool $force = true ) {
		$result = $this->request( 'DELETE', 'media/' . $id, array( 'query' => array( 'force' => $force ? 1 : 0 ) ) );
		return is_wp_error( $result ) ? $result : true;
	}
}
