<?php
/**
 * Synchronisation orchestrator: local post -> remote receiver.
 *
 * Handles create/update decisions, remote ID tracking, media uploads and
 * rewriting of local attachment IDs/URLs inside post content.
 *
 * @package Niyi\PagePostSync
 */

namespace Niyi\PagePostSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Synchronizer {

	/** @var ApiClient */
	private $client;

	/** @var string Receiver ID (used in meta keys) */
	private $receiver_id;

	/** @var array Collected warnings/errors */
	private $errors = array();

	/** @var int Number of media items uploaded in this run */
	private $media_count = 0;

	/** @var array Non-fatal informational messages for the user */
	private $notices = array();

	/**
	 * @param ApiClient $client      Configured API client.
	 * @param string    $receiver_id Receiver identifier.
	 */
	public function __construct( ApiClient $client, string $receiver_id ) {
		$this->client      = $client;
		$this->receiver_id = $receiver_id;
	}

	/* ------------------------------------------------------------------ */
	/* Static entry points                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Build a synchronizer from a stored receiver ID.
	 *
	 * @param string $receiver_id Receiver ID.
	 * @return self|\WP_Error
	 */
	public static function for_receiver( string $receiver_id ) {
		$credentials = Settings::get_credentials( $receiver_id );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		try {
			return new self( new ApiClient( $credentials ), $receiver_id );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'client_error', $e->getMessage() );
		}
	}

	/**
	 * Sync a single post to a receiver.
	 *
	 * @param int    $post_id     Local post ID.
	 * @param string $receiver_id Receiver ID.
	 * @return array Result array.
	 */
	public static function sync_post( int $post_id, string $receiver_id ): array {
		$sync = self::for_receiver( $receiver_id );
		if ( is_wp_error( $sync ) ) {
			return self::fail_result( $sync->get_error_message() );
		}
		return $sync->sync( $post_id );
	}

	/**
	 * Sync several posts to several receivers.
	 *
	 * @param array $post_ids     Local post IDs.
	 * @param array $receiver_ids Receiver IDs.
	 * @return array Aggregated result.
	 */
	public static function bulk_sync( array $post_ids, array $receiver_ids ): array {
		$summary = array(
			'total'   => 0,
			'synced'  => 0,
			'failed'  => 0,
			'results' => array(),
		);

		foreach ( $receiver_ids as $receiver_id ) {
			foreach ( $post_ids as $post_id ) {
				$summary['total']++;
				$result = self::sync_post( (int) $post_id, (string) $receiver_id );

				$summary['results'][] = array(
					'post_id'     => (int) $post_id,
					'receiver_id' => (string) $receiver_id,
					'result'      => $result,
				);

				if ( ! empty( $result['success'] ) ) {
					$summary['synced']++;
				} else {
					$summary['failed']++;
				}
			}
		}

		return $summary;
	}

	/* ------------------------------------------------------------------ */
	/* Main sync                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Execute the sync for one local post.
	 *
	 * @param int $post_id Local post ID.
	 * @return array Result with success, remote_id, message, media_count, errors.
	 */
	public function sync( int $post_id ): array {
		$this->errors      = array();
		$this->notices     = array();
		$this->media_count = 0;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->fail( __( 'Local post not found.', 'niyi-page-post-sync' ) );
		}

		$endpoint = $this->endpoint_for( $post->post_type );
		if ( ! $endpoint ) {
			return $this->fail( sprintf( __( 'Post type "%s" is not supported.', 'niyi-page-post-sync' ), $post->post_type ) );
		}

		$post_meta_key  = $this->meta_key( 'post_id' );
		$remote_id      = (int) get_post_meta( $post_id, $post_meta_key, true );

		// Verify the tracked remote post still exists; reset if it does not.
		if ( $remote_id ) {
			$existing = $this->client->get_post( $endpoint, $remote_id );
			if ( is_wp_error( $existing ) || empty( $existing['id'] ) ) {
				$remote_id = 0;
				delete_post_meta( $post_id, $post_meta_key );
			}
		}

		// No tracked ID: try to find an existing remote post by slug so we update
		// instead of creating a duplicate.
		if ( ! $remote_id ) {
			$slug  = $post->post_name ?: sanitize_title( $post->post_title );
			$found = $this->client->find_post_by_slug( $slug, $endpoint );
			if ( is_wp_error( $found ) ) {
				$this->errors[] = sprintf( __( 'Remote lookup by slug failed: %s', 'niyi-page-post-sync' ), $found->get_error_message() );
			} elseif ( ! empty( $found['id'] ) ) {
				$remote_id = (int) $found['id'];
				update_post_meta( $post_id, $post_meta_key, $remote_id );
			}
		}

		$media_map = get_post_meta( $post_id, $this->meta_key( 'media_map' ), true );
		if ( ! is_array( $media_map ) ) {
			$media_map = array();
		}

		$data = array(
			'title'          => $post->post_title,
			'slug'           => $post->post_name ?: sanitize_title( $post->post_title ),
			'status'         => $this->map_status( $post->post_status ),
			'excerpt'        => $post->post_excerpt,
			'content'        => $this->prepare_content( $post->post_content, $media_map ),
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'date'           => $post->post_date,
			'menu_order'     => (int) $post->menu_order,
		);

		/**
		 * Filter the REST fields sent to the receiver.
		 *
		 * @param array  $data        Post data.
		 * @param int    $post_id     Local post ID.
		 * @param string $receiver_id Receiver ID.
		 */
		$data = apply_filters( 'niyi_pps_post_data', $data, $post_id, $this->receiver_id );

		// Featured image.
		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$remote_media = $this->ensure_media( $thumbnail_id, $media_map );
			if ( $remote_media ) {
				$data['featured_media'] = $remote_media['remote_id'];
			}
		}

		// Persist the media map even if a later step fails, so we never upload
		// the same media twice.
		update_post_meta( $post_id, $this->meta_key( 'media_map' ), $media_map );

		$result = null;
		if ( $remote_id ) {
			$result = $this->client->update_post( $endpoint, $remote_id, $data );
			if ( is_wp_error( $result ) && 'api_error_404' === $result->get_error_code() ) {
				// Remote was deleted between the check and the update.
				$remote_id = 0;
				delete_post_meta( $post_id, $post_meta_key );
				$result = null;
			} elseif ( is_wp_error( $result ) ) {
				return $this->fail( sprintf( __( 'Remote update failed: %s', 'niyi-page-post-sync' ), $result->get_error_message() ) );
			}
		}

		if ( ! $remote_id ) {
			$result = $this->client->create_post( $endpoint, $data );
			if ( is_wp_error( $result ) ) {
				return $this->fail( sprintf( __( 'Remote create failed: %s', 'niyi-page-post-sync' ), $result->get_error_message() ) );
			}
			$remote_id = (int) ( $result['id'] ?? 0 );
			if ( ! $remote_id ) {
				return $this->fail( __( 'Remote did not return a post ID.', 'niyi-page-post-sync' ) );
			}
			update_post_meta( $post_id, $post_meta_key, $remote_id );
		}

		if ( ! empty( $result['link'] ) ) {
			update_post_meta( $post_id, $this->meta_key( 'remote_url' ), esc_url_raw( $result['link'] ) );
		}

		// Custom fields / SEO meta are sent separately so an unregistered meta
		// key on the receiver cannot break the main post create/update.
		$this->sync_meta( $post_id, $endpoint, $remote_id );

		// Shortcode compatibility: warn when the receiver cannot render the
		// shortcodes used in this content.
		$this->check_shortcodes( $post_id );

		$message = $remote_id
			? sprintf( __( 'Synced to remote post #%d.', 'niyi-page-post-sync' ), $remote_id )
			: __( 'Synced successfully.', 'niyi-page-post-sync' );

		return array(
			'success'     => true,
			'remote_id'   => $remote_id,
			'message'     => $message,
			'media_count' => $this->media_count,
			'notices'     => $this->notices,
			'errors'      => $this->errors,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Content / media rewriting                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Rewrite local media references in post content to their remote IDs/URLs.
	 *
	 * @param string $content   Local post content.
	 * @param array  $media_map Local media ID => remote info (by reference).
	 * @return string
	 */
	private function prepare_content( string $content, array &$media_map ): string {
		$local_ids = $this->collect_media_ids( $content );

		foreach ( $local_ids as $local_id ) {
			$remote = $this->ensure_media( (int) $local_id, $media_map );
			if ( ! $remote ) {
				continue;
			}

			$remote_id  = (int) $remote['remote_id'];
			$remote_url = (string) ( $remote['url'] ?? '' );

			// Replace ID references (classes, block attributes, data attributes).
			// Word boundaries prevent "12" from corrupting "123".
			$content = preg_replace( '/wp-image-' . $local_id . '\b/i', 'wp-image-' . $remote_id, $content );
			$content = preg_replace( '/("id"\s*:\s*)' . $local_id . '\b/', '${1}' . $remote_id, $content );
			$content = str_replace( 'data-id="' . $local_id . '"', 'data-id="' . $remote_id . '"', $content );
			$content = str_replace( "data-id='" . $local_id . "'", "data-id='" . $remote_id . "'", $content );
			$content = str_replace( 'data-image-id="' . $local_id . '"', 'data-image-id="' . $remote_id . '"', $content );
			$content = str_replace( 'id="attachment_' . $local_id . '"', 'id="attachment_' . $remote_id . '"', $content );

			// Replace URLs (including WordPress resized variants).
			$local_url = wp_get_attachment_url( (int) $local_id );
			if ( $local_url && $remote_url ) {
				$content = $this->replace_image_urls( $content, $local_url, $remote_url );
			}
		}

		// Gallery shortcodes / blocks using ids="1,2,3".
		$content = preg_replace_callback( '/(ids=["\'])([^"\']+)(["\'])/i', function ( $matches ) use ( $media_map ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $matches[2] ) ), 'strlen' );
			foreach ( $parts as $index => $part ) {
				$id = (int) $part;
				if ( isset( $media_map[ $id ]['remote_id'] ) ) {
					$parts[ $index ] = (string) $media_map[ $id ]['remote_id'];
				}
			}
			return $matches[1] . implode( ',', $parts ) . $matches[3];
		}, $content );

		return $content;
	}

	/**
	 * Collect local attachment IDs referenced in content.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	private function collect_media_ids( string $content ): array {
		$ids = array();

		// wp-image-123 classes and aria-describedby="wp-image-123".
		if ( preg_match_all( '/wp-image-(\d+)/i', $content, $matches ) ) {
			$ids = array_merge( $ids, $matches[1] );
		}

		// Gutenberg image block attributes: "id":123.
		if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $matches ) ) {
			$ids = array_merge( $ids, $matches[1] );
		}

		// Gallery shortcode ids="1,2,3".
		if ( preg_match_all( '/ids=["\']([\d,\s]+)["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $group ) {
				$ids = array_merge( $ids, preg_split( '/[\s,]+/', trim( $group ) ) );
			}
		}

		// data-id / data-image-id attributes.
		if ( preg_match_all( '/data-(?:image-)?id=["\'](\d+)["\']/i', $content, $matches ) ) {
			$ids = array_merge( $ids, $matches[1] );
		}

		// <img src="..."> and srcset URLs that belong to this site.
		$urls = array();
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
		if ( preg_match_all( '/srcset=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $srcset ) {
				foreach ( explode( ',', $srcset ) as $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ) );
					if ( ! empty( $parts[0] ) ) {
						$urls[] = $parts[0];
					}
				}
			}
		}

		foreach ( array_unique( $urls ) as $url ) {
			$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
			if ( strpos( $url, home_url() ) === 0 ) {
				$attachment_id = attachment_url_to_postid( $url );
				if ( $attachment_id ) {
					$ids[] = $attachment_id;
				}
			}
		}

		$ids = array_unique( array_filter( array_map( 'absint', $ids ) ) );

		return array_values( array_filter( $ids, function ( $id ) {
			$attachment = get_post( $id );
			return $attachment && 'attachment' === $attachment->post_type;
		} ) );
	}

	/**
	 * Replace a local media URL (and its resized variants) with the remote URL.
	 *
	 * @param string $content    Post content.
	 * @param string $local_url  Local full-size URL.
	 * @param string $remote_url Remote full-size URL.
	 * @return string
	 */
	private function replace_image_urls( string $content, string $local_url, string $remote_url ): string {
		$content = str_replace( $local_url, $remote_url, $content );

		$local_dir  = preg_replace( '#/[^/]+$#', '', $local_url );
		$remote_dir = preg_replace( '#/[^/]+$#', '', $remote_url );
		$local_base = pathinfo( $local_url, PATHINFO_FILENAME );
		$remote_base = pathinfo( $remote_url, PATHINFO_FILENAME );
		$local_ext  = pathinfo( $local_url, PATHINFO_EXTENSION );
		$remote_ext = pathinfo( $remote_url, PATHINFO_EXTENSION );

		// e.g. photo-300x200.jpg -> remote-photo-300x200.jpg
		$pattern = '#' . preg_quote( $local_dir . '/' . $local_base, '#' ) . '(-\d+x\d+)\.' . preg_quote( $local_ext, '#' ) . '#i';
		$content = preg_replace( $pattern, $remote_dir . '/' . $remote_base . '$1.' . $remote_ext, $content );

		return $content;
	}

	/**
	 * Ensure a local attachment exists on the remote site; cache the mapping.
	 *
	 * @param int   $local_id  Local attachment ID.
	 * @param array $media_map Media map (by reference).
	 * @return array|false Remote info or false on failure.
	 */
	private function ensure_media( int $local_id, array &$media_map ) {
		if ( ! empty( $media_map[ $local_id ]['remote_id'] ) ) {
			return $media_map[ $local_id ];
		}

		$uploaded = $this->client->upload_media( $local_id );
		if ( is_wp_error( $uploaded ) || empty( $uploaded['id'] ) ) {
			$message = is_wp_error( $uploaded ) ? $uploaded->get_error_message() : __( 'unknown upload error', 'niyi-page-post-sync' );
			$this->errors[] = sprintf( __( 'Media #%d could not be uploaded: %s', 'niyi-page-post-sync' ), $local_id, $message );
			return false;
		}

		$media_map[ $local_id ] = array(
			'remote_id' => (int) $uploaded['id'],
			'url'       => (string) ( $uploaded['source_url'] ?? '' ),
		);
		$this->media_count++;

		return $media_map[ $local_id ];
	}

	/* ------------------------------------------------------------------ */
	/* Meta & shortcodes                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Sync custom fields in a separate request so failures are non-fatal.
	 *
	 * @param int    $post_id   Local post ID.
	 * @param string $endpoint  REST endpoint.
	 * @param int    $remote_id Remote post ID.
	 */
	private function sync_meta( int $post_id, string $endpoint, int $remote_id ) {
		$meta = $this->collect_meta( $post_id );
		if ( empty( $meta ) ) {
			return;
		}

		$result = $this->client->update_post( $endpoint, $remote_id, array( 'meta' => $meta ) );
		if ( is_wp_error( $result ) ) {
			$this->notices[] = sprintf(
				/* translators: %s: error message */
				__( 'Custom fields could not be synced: %s. On the receiver, register these meta keys with show_in_rest so the REST API accepts them.', 'niyi-page-post-sync' ),
				$result->get_error_message()
			);
		}
	}

	/**
	 * Collect the meta keys that should be synced.
	 *
	 * @param int $post_id Local post ID.
	 * @return array
	 */
	private function collect_meta( int $post_id ): array {
		if ( ! Settings::get_option( 'sync_meta', true ) ) {
			return array();
		}

		$defaults        = Settings::sync_defaults();
		$include_private = (bool) Settings::get_option( 'meta_include_private', $defaults['meta_include_private'] );
		$excluded        = Settings::get_option( 'meta_exclude', $defaults['meta_exclude'] );
		$excluded        = is_array( $excluded ) ? $excluded : array();

		// Internal / plugin-owned meta that must never be copied.
		$skip = array(
			'_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
			'_wp_attachment_metadata', '_wp_attached_file', '_thumbnail_id',
		);

		$collected = array();
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( strpos( $key, '_niyi_pps_' ) === 0 || in_array( $key, $skip, true ) ) {
				continue;
			}
			if ( ! $include_private && strpos( $key, '_' ) === 0 ) {
				continue;
			}

			foreach ( $excluded as $pattern ) {
				$pattern = trim( (string) $pattern );
				if ( '' === $pattern ) {
					continue;
				}
				if ( strpos( $pattern, '*' ) !== false ) {
					$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
					if ( preg_match( $regex, $key ) ) {
						continue 2;
					}
				} elseif ( $key === $pattern ) {
					continue 2;
				}
			}

			$collected[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			$collected[ $key ] = maybe_unserialize( $collected[ $key ] );
		}

		/**
		 * Filter the meta values sent to the receiver.
		 *
		 * @param array  $collected   Meta key => value.
		 * @param int    $post_id     Local post ID.
		 * @param string $receiver_id Receiver ID.
		 */
		return apply_filters( 'niyi_pps_meta_to_sync', $collected, $post_id, $this->receiver_id );
	}

	/**
	 * Check shortcode compatibility and remember warnings for the list column.
	 *
	 * @param int $post_id Local post ID.
	 */
	private function check_shortcodes( int $post_id ) {
		$content = get_post_field( 'post_content', $post_id );
		if ( '' === trim( (string) $content ) ) {
			return;
		}
		if ( empty( Shortcodes::detect( $content ) ) ) {
			delete_post_meta( $post_id, $this->meta_key( 'shortcode_notices' ) );
			return;
		}

		$check   = Shortcodes::check( array( $post_id ), array( $this->receiver_id ) );
		$warning = ! empty( $check['messages'] ) ? $check['messages'] : array();
		if ( $warning ) {
			$this->notices = array_merge( $this->notices, $warning );
			update_post_meta( $post_id, $this->meta_key( 'shortcode_notices' ), $warning );
		} else {
			delete_post_meta( $post_id, $this->meta_key( 'shortcode_notices' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * REST endpoint slug for a local post type.
	 *
	 * @param string $post_type Local post type.
	 * @return string|false
	 */
	private function endpoint_for( string $post_type ) {
		$map = array(
			'post' => 'posts',
			'page' => 'pages',
		);
		return $map[ $post_type ] ?? false;
	}

	/**
	 * Map local status to REST status.
	 *
	 * @param string $status Local status.
	 * @return string
	 */
	private function map_status( string $status ): string {
		$allowed = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Build a per-receiver meta key.
	 *
	 * @param string $suffix Key suffix.
	 * @return string
	 */
	private function meta_key( string $suffix ): string {
		return '_niyi_pps_' . $this->receiver_id . '_' . $suffix;
	}

	/**
	 * @param string $message Success message.
	 * @return array
	 */
	private function fail( string $message ): array {
		$this->errors[] = $message;
		return array(
			'success'     => false,
			'remote_id'   => 0,
			'message'     => $message,
			'media_count' => $this->media_count,
			'notices'     => $this->notices,
			'errors'      => $this->errors,
		);
	}

	/**
	 * @param string $message Error message.
	 * @return array
	 */
	private static function fail_result( string $message ): array {
		return array(
			'success'     => false,
			'remote_id'   => 0,
			'message'     => $message,
			'media_count' => 0,
			'notices'     => array(),
			'errors'      => array( $message ),
		);
	}
}
