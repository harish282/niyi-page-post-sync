<?php
/**
 * WordPress bootstrap: hooks, admin UI integration and AJAX endpoints.
 *
 * @package Niyi\PagePostSync
 */

namespace Niyi\PagePostSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	/**
	 * Register all hooks.
	 */
	public function run() {
		// Settings screen.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_niyi_pps_receiver_save', array( 'Niyi\\PagePostSync\\Settings', 'handle_save' ) );
		add_action( 'admin_post_niyi_pps_receiver_delete', array( 'Niyi\\PagePostSync\\Settings', 'handle_delete' ) );
		add_action( 'admin_post_niyi_pps_receiver_toggle', array( 'Niyi\\PagePostSync\\Settings', 'handle_toggle' ) );
		add_action( 'admin_post_niyi_pps_save_options', array( 'Niyi\\PagePostSync\\Settings', 'handle_options_save' ) );

		// Assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Bulk actions + sync column on post/page list tables.
		add_filter( 'bulk_actions-edit-post', array( $this, 'add_bulk_action' ) );
		add_filter( 'bulk_actions-edit-page', array( $this, 'add_bulk_action' ) );
		add_filter( 'manage_edit-post_columns', array( $this, 'add_sync_column' ) );
		add_filter( 'manage_edit-page_columns', array( $this, 'add_sync_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_sync_column' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'render_sync_column' ), 10, 2 );

		// AJAX endpoints.
		add_action( 'wp_ajax_niyi_pps_sync', array( $this, 'ajax_sync' ) );
		add_action( 'wp_ajax_niyi_pps_test_receiver', array( $this, 'ajax_test_receiver' ) );
		add_action( 'wp_ajax_niyi_pps_check', array( $this, 'ajax_check' ) );

		// REST route used by receivers to report supported shortcodes.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Receiver capability endpoint                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the route that lets other sites query this site's shortcodes.
	 */
	public function register_rest_routes() {
		register_rest_route( 'niyi-pps/v1', '/capabilities', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_capabilities' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			),
		) );
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function rest_capabilities() {
		global $shortcode_tags;

		$shortcodes = array_keys( is_array( $shortcode_tags ) ? $shortcode_tags : array() );

		return rest_ensure_response( array(
			'plugin'     => 'niyi-page-post-sync',
			'version'    => NIYI_PPS_VERSION,
			'shortcodes' => array_values( array_map( 'strtolower', $shortcodes ) ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin menu                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Add the receivers screen under both Posts and Pages.
	 */
	public function admin_menu() {
		$title = __( 'Sync Receivers', 'niyi-page-post-sync' );
		add_submenu_page( 'edit.php?post_type=post', $title, $title, 'manage_options', Settings::PAGE_SLUG, array( 'Niyi\\PagePostSync\\Settings', 'render_page' ) );
		add_submenu_page( 'edit.php?post_type=page', $title, $title, 'manage_options', Settings::PAGE_SLUG, array( 'Niyi\\PagePostSync\\Settings', 'render_page' ) );

		// Shortcut under WordPress Settings → Niyi Sync Setup.
		add_options_page(
			__( 'Niyi Sync Setup', 'niyi-page-post-sync' ),
			__( 'Niyi Sync Setup', 'niyi-page-post-sync' ),
			'manage_options',
			Settings::PAGE_SLUG,
			array( 'Niyi\\PagePostSync\\Settings', 'render_page' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Enqueue admin CSS/JS only where needed.
	 */
	public function enqueue_assets() {
		$screen      = get_current_screen();
		$is_listing  = $screen && in_array( $screen->id, array( 'edit-post', 'edit-page' ), true );
		$is_settings = isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === Settings::PAGE_SLUG;

		if ( ! $is_listing && ! $is_settings ) {
			return;
		}

		wp_enqueue_style( 'niyi-pps-admin', NIYI_PPS_PLUGIN_URL . 'assets/css/admin.css', array(), NIYI_PPS_VERSION );

		if ( $is_settings ) {
			wp_enqueue_script( 'niyi-pps-admin', NIYI_PPS_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), NIYI_PPS_VERSION, true );
			wp_localize_script( 'niyi-pps-admin', 'niyiPpsAdmin', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'niyi_pps_sync' ),
				'i18n'     => array(
					'testing'   => __( 'Testing...', 'niyi-page-post-sync' ),
					'test'      => __( 'Test Connection', 'niyi-page-post-sync' ),
					'success'   => __( 'Connection successful.', 'niyi-page-post-sync' ),
					'failed'    => __( 'Connection failed.', 'niyi-page-post-sync' ),
				),
			) );
		}

		if ( $is_listing ) {
			wp_enqueue_script( 'niyi-pps-sync', NIYI_PPS_PLUGIN_URL . 'assets/js/sync.js', array( 'jquery' ), NIYI_PPS_VERSION, true );
			wp_localize_script( 'niyi-pps-sync', 'niyiSync', array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'niyi_pps_sync' ),
				'receivers' => array_map( function ( $receiver ) {
					return array(
						'id'     => $receiver['id'],
						'name'   => $receiver['name'],
						'active' => ! empty( $receiver['active'] ),
					);
				}, Settings::get_receivers() ),
				'i18n'      => array(
					'selectReceiver' => __( 'Select receivers to sync to:', 'niyi-page-post-sync' ),
					'noReceivers'    => __( 'No active receivers configured. Add one under Sync Receivers.', 'niyi-page-post-sync' ),
					'noItems'        => __( 'Please select at least one item.', 'niyi-page-post-sync' ),
					'noneSelected'   => __( 'Please select at least one receiver.', 'niyi-page-post-sync' ),
					'sync'           => __( 'Sync', 'niyi-page-post-sync' ),
					'cancel'         => __( 'Cancel', 'niyi-page-post-sync' ),
					'syncing'        => __( 'Syncing...', 'niyi-page-post-sync' ),
					'success'        => __( '%d item(s) synced successfully.', 'niyi-page-post-sync' ),
					'failed'         => __( '%d item(s) failed.', 'niyi-page-post-sync' ),
					'error'          => __( 'Sync request failed.', 'niyi-page-post-sync' ),
					'checking'       => __( 'Checking shortcode compatibility...', 'niyi-page-post-sync' ),
					'shortcodes'     => __( 'Receiver compatibility notes:', 'niyi-page-post-sync' ),
					'notes'          => __( 'Sync notes:', 'niyi-page-post-sync' ),
				),
			) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* List table integration                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_action( array $actions ): array {
		$actions['niyi_sync'] = __( 'Sync to Receiver(s)', 'niyi-page-post-sync' );
		return $actions;
	}

	/**
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_sync_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['niyi_pps_sync'] = __( 'Sync', 'niyi-page-post-sync' );
			}
		}
		return $new;
	}

	/**
	 * Render the Sync column content.
	 *
	 * @param string $column    Column name.
	 * @param int    $post_id   Post ID.
	 */
	public function render_sync_column( string $column, int $post_id ) {
		if ( 'niyi_pps_sync' !== $column ) {
			return;
		}

		$receivers = Settings::get_receivers();
		if ( empty( $receivers ) ) {
			echo '<em>' . esc_html__( 'No receivers', 'niyi-page-post-sync' ) . '</em>';
			return;
		}

		echo '<div class="niyi-pps-cell">';
		foreach ( $receivers as $receiver ) {
			$meta_prefix = '_niyi_pps_' . $receiver['id'] . '_';
			$remote_id   = (int) get_post_meta( $post_id, $meta_prefix . 'post_id', true );
			$warnings    = get_post_meta( $post_id, $meta_prefix . 'shortcode_notices', true );
			echo '<div class="niyi-pps-cell-row">';
			echo '<span class="dashicons ' . ( $remote_id ? 'dashicons-yes' : 'dashicons-no' ) . '"></span> ';
			echo esc_html( $receiver['name'] );
			if ( $remote_id ) {
				echo ' <small>#' . absint( $remote_id ) . '</small>';
			}
			if ( is_array( $warnings ) && $warnings ) {
				echo ' <span class="dashicons dashicons-warning niyi-pps-warning" title="' . esc_attr( implode( "\n", $warnings ) ) . '"></span>';
			}
			echo '</div>';
		}
		echo '<button type="button" class="button button-small niyi-sync-single" data-post-id="' . absint( $post_id ) . '">' . esc_html__( 'Sync', 'niyi-page-post-sync' ) . '</button>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Perform the sync requested from the listing screen.
	 */
	public function ajax_sync() {
		check_ajax_referer( 'niyi_pps_sync', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to sync posts.', 'niyi-page-post-sync' ) ), 403 );
		}

		$post_ids     = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : array();
		$receiver_ids = isset( $_POST['receiver_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['receiver_ids'] ) ) : array();

		if ( empty( $post_ids ) || empty( $receiver_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No items or receivers selected.', 'niyi-page-post-sync' ) ) );
		}

		// Only allow active receivers that actually exist.
		$valid = array();
		foreach ( Settings::get_active_receivers() as $receiver ) {
			if ( in_array( $receiver['id'], $receiver_ids, true ) ) {
				$valid[] = $receiver['id'];
			}
		}
		if ( empty( $valid ) ) {
			wp_send_json_error( array( 'message' => __( 'None of the selected receivers is active.', 'niyi-page-post-sync' ) ) );
		}

		$result   = Synchronizer::bulk_sync( $post_ids, $valid );
		$messages = array();
		$notices  = array();
		foreach ( $result['results'] as $item ) {
			if ( empty( $item['result']['success'] ) && ! empty( $item['result']['message'] ) ) {
				$messages[] = $item['result']['message'];
			}
			if ( ! empty( $item['result']['notices'] ) ) {
				$notices = array_merge( $notices, $item['result']['notices'] );
			}
		}
		$result['messages'] = array_slice( array_unique( $messages ), 0, 5 );
		$result['notices']  = array_slice( array_unique( $notices ), 0, 10 );
		wp_send_json_success( $result );
	}

	/**
	 * Pre-flight shortcode compatibility check for the selection popup.
	 */
	public function ajax_check() {
		check_ajax_referer( 'niyi_pps_sync', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to sync posts.', 'niyi-page-post-sync' ) ), 403 );
		}

		$post_ids     = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : array();
		$receiver_ids = isset( $_POST['receiver_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['receiver_ids'] ) ) : array();

		$result = Shortcodes::check( $post_ids, $receiver_ids );
		wp_send_json_success( $result );
	}

	/**
	 * Test a receiver connection (or unsaved form values).
	 */
	public function ajax_test_receiver() {
		check_ajax_referer( 'niyi_pps_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'niyi-page-post-sync' ) ), 403 );
		}

		$id = isset( $_POST['receiver_id'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_id'] ) ) : '';

		if ( $id ) {
			$credentials = Settings::get_credentials( $id );
		} else {
			$credentials = array(
				'url'          => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
				'username'     => isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '',
				'app_password' => isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '',
			);
		}

		if ( is_wp_error( $credentials ) ) {
			wp_send_json_error( array( 'message' => $credentials->get_error_message() ) );
		}

		try {
			$client = new ApiClient( is_array( $credentials ) ? $credentials : array() );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		$test = $client->verify_connection();
		if ( is_wp_error( $test ) ) {
			wp_send_json_error( array( 'message' => $test->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connection successful.', 'niyi-page-post-sync' ) ) );
	}
}
