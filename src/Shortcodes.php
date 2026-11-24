<?php
/**
 * Shortcode detection and receiver capability checks.
 *
 * @package Niyi\PagePostSync
 */

namespace Niyi\PagePostSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcodes {

	/**
	 * Cached capability responses, keyed by receiver ID.
	 *
	 * @var array
	 */
	private static $capabilities_cache = array();

	/**
	 * Core shortcodes that need no extra plugin on the receiver.
	 *
	 * @var array
	 */
	private static $core = array(
		'caption', 'wp_caption', 'gallery', 'audio', 'video', 'playlist', 'embed',
	);

	/**
	 * Detect shortcode tags used in a piece of content.
	 *
	 * Uses the registered shortcode regex (accurate for active plugins) plus a
	 * generic bracket scan so tags from deactivated plugins are still caught.
	 *
	 * @param string $content Post content.
	 * @return array Lowercased shortcode tags.
	 */
	public static function detect( string $content ): array {
		global $shortcode_tags;

		$tags = array();

		$registered = is_array( $shortcode_tags ) ? array_keys( $shortcode_tags ) : array();
		if ( ! empty( $registered ) ) {
			preg_match_all( '/' . get_shortcode_regex( $registered ) . '/', $content, $matches );
			if ( ! empty( $matches[2] ) ) {
				$tags = array_merge( $tags, $matches[2] );
			}
		}

		// Fallback: any [tag ...] token, including plugins not active locally.
		preg_match_all( '/\[\s*([a-zA-Z][a-zA-Z0-9_-]*)(?:\s|\])/', $content, $matches );
		if ( ! empty( $matches[1] ) ) {
			$tags = array_merge( $tags, $matches[1] );
		}

		$tags = array_unique( array_map( 'strtolower', array_filter( $tags, 'strlen' ) ) );

		return array_values( array_filter( $tags, function ( $tag ) {
			return strlen( $tag ) > 1 && ! in_array( $tag, self::$core, true );
		} ) );
	}

	/**
	 * Guess which plugin provides a shortcode tag.
	 *
	 * @param string $tag Lowercased shortcode tag.
	 * @return string|false Plugin name or false when unknown.
	 */
	public static function plugin_for( string $tag ) {
		$exact = array(
			'wpcf7'                 => 'Contact Form 7',
			'contact-form-7'        => 'Contact Form 7',
			'wpforms'               => 'WPForms',
			'gravityform'           => 'Gravity Forms',
			'gravityforms'          => 'Gravity Forms',
			'gform'                 => 'Gravity Forms',
			'ninja_form'            => 'Ninja Forms',
			'forminator'            => 'Forminator',
			'tablepress'            => 'TablePress',
			'give'                  => 'GiveWP',
			'revslider'             => 'Slider Revolution',
			'smartslider3'          => 'Smart Slider 3',
			'vc_row'                => 'WPBakery Page Builder',
			'et_pb_section'         => 'Divi Builder',
			'elementor_template'    => 'Elementor',
			'fl_builder'            => 'Beaver Builder',
			'woocommerce'           => 'WooCommerce',
			'products'              => 'WooCommerce',
			'product'               => 'WooCommerce',
			'ultimate-member'       => 'Ultimate Member',
			'download_manager'      => 'Download Manager',
		);

		if ( isset( $exact[ $tag ] ) ) {
			return $exact[ $tag ];
		}

		$prefixes = array(
			'vc_'          => 'WPBakery Page Builder',
			'et_pb_'       => 'Divi Builder',
			'fusion_'      => 'Avada / Fusion Builder',
			'elementor_'   => 'Elementor',
			'brizy_'       => 'Brizy',
			'gform_'       => 'Gravity Forms',
			'gravityform'  => 'Gravity Forms',
			'wpforms'      => 'WPForms',
			'wpcf7'        => 'Contact Form 7',
			'cf7'          => 'Contact Form 7',
			'woo_'         => 'WooCommerce',
			'wc_'          => 'WooCommerce',
			'give_'        => 'GiveWP',
			'tbl_'         => 'TablePress',
		);

		foreach ( $prefixes as $prefix => $plugin ) {
			if ( strpos( $tag, $prefix ) === 0 ) {
				return $plugin;
			}
		}

		return false;
	}

	/**
	 * Check posts/receivers for shortcodes that are unavailable remotely.
	 *
	 * The receiver reports its registered shortcodes through the REST route
	 * added by this plugin. When that route is unavailable, an informational
	 * message is returned instead so the user knows to verify manually.
	 *
	 * @param array $post_ids     Local post IDs.
	 * @param array $receiver_ids Receiver IDs.
	 * @return array Array with a unique `messages` list.
	 */
	public static function check( array $post_ids, array $receiver_ids ): array {
		$messages = array();

		foreach ( $receiver_ids as $receiver_id ) {
			$receiver = Settings::get_receiver( (string) $receiver_id );
			if ( ! $receiver ) {
				continue;
			}

			$credentials = Settings::get_credentials( (string) $receiver_id );
			if ( is_wp_error( $credentials ) ) {
				continue;
			}

			try {
				$client = new ApiClient( $credentials );
			} catch ( \Exception $e ) {
				continue;
			}

			$cache_key = (string) $receiver_id;
			if ( ! array_key_exists( $cache_key, self::$capabilities_cache ) ) {
				self::$capabilities_cache[ $cache_key ] = $client->get_capabilities();
			}
			$capabilities = self::$capabilities_cache[ $cache_key ];
			$verified     = ! is_wp_error( $capabilities );
			$supported    = $verified
				? array_map( 'strtolower', (array) ( $capabilities['shortcodes'] ?? array() ) )
				: array();

			$receiver_name = $receiver['name'] ?? (string) $receiver_id;

			foreach ( $post_ids as $post_id ) {
				$post = get_post( (int) $post_id );
				if ( ! $post ) {
					continue;
				}

				$tags = self::detect( $post->post_content );
				foreach ( $tags as $tag ) {
					$key = ( $verified ? 'missing' : 'unknown' ) . '|' . $receiver_id . '|' . $tag;
					if ( isset( $messages[ $key ] ) ) {
						continue;
					}

					if ( ! $verified ) {
						$messages[ $key ] = sprintf(
							/* translators: 1: receiver name, 2: shortcode tag */
							__( 'Receiver "%1$s" could not be checked for shortcode [%2$s] (Niyi Sync is not active there). If the shortcode depends on a plugin, install/activate that plugin on the receiver.', 'niyi-page-post-sync' ),
							$receiver_name,
							$tag
						);
						continue;
					}

					if ( in_array( $tag, $supported, true ) ) {
						continue;
					}

					$plugin  = self::plugin_for( $tag );
					$message = sprintf(
						/* translators: 1: shortcode tag, 2: receiver name */
						__( 'Shortcode [%1$s] used in "%2$s" is not available on the receiver.', 'niyi-page-post-sync' ),
						$tag,
						$receiver_name
					);
					if ( $plugin ) {
						$message .= ' ' . sprintf(
							/* translators: %s: plugin name */
							__( 'Install and activate %s on the receiver WordPress site.', 'niyi-page-post-sync' ),
							$plugin
						);
					} else {
						$message .= ' ' . __( 'Install and activate the plugin that provides this shortcode on the receiver WordPress site.', 'niyi-page-post-sync' );
					}
					$messages[ $key ] = $message;
				}
			}
		}

		return array( 'messages' => array_values( $messages ) );
	}
}
