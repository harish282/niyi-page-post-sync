<?php
/**
 * Receiver management (storage + settings UI) for Yishi Page Post Sync.
 *
 * @package Niyi\PagePostSync
 */

namespace Niyi\PagePostSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION_KEY = 'niyi_page_post_sync_options';
	const PAGE_SLUG  = 'niyi-receivers';

	/* ------------------------------------------------------------------ */
	/* Option helpers                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array Raw options array.
	 */
	private static function options(): array {
		$options = get_option( self::OPTION_KEY, array() );
		return is_array( $options ) ? $options : array();
	}

	/**
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get_option( string $key, $default = null ) {
		$options = self::options();
		return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
	}

	/**
	 * Persist arbitrary plugin options while preserving the rest.
	 *
	 * @param array $updates Options to merge in.
	 * @return bool
	 */
	public static function update_options( array $updates ): bool {
		return update_option( self::OPTION_KEY, array_merge( self::options(), $updates ) );
	}

	/**
	 * Default sync options.
	 *
	 * @return array
	 */
	public static function sync_defaults(): array {
		return array(
			'sync_meta'           => 1,
			'meta_include_private' => 1,
			'meta_exclude'        => array(),
		);
	}

	/**
	 * Persist the whole receivers list.
	 *
	 * @param array $receivers Receivers.
	 * @return bool
	 */
	private static function save_receivers( array $receivers ): bool {
		$options            = self::options();
		$options['receivers'] = array_values( $receivers );
		return update_option( self::OPTION_KEY, $options );
	}

	/* ------------------------------------------------------------------ */
	/* Receiver queries                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array List of all receivers.
	 */
	public static function get_receivers(): array {
		$receivers = self::get_option( 'receivers', array() );
		return is_array( $receivers ) ? $receivers : array();
	}

	/**
	 * @param string $id Receiver ID.
	 * @return array|null
	 */
	public static function get_receiver( string $id ) {
		foreach ( self::get_receivers() as $receiver ) {
			if ( isset( $receiver['id'] ) && $receiver['id'] === $id ) {
				return $receiver;
			}
		}
		return null;
	}

	/**
	 * @return array Active receivers only.
	 */
	public static function get_active_receivers(): array {
		return array_values( array_filter( self::get_receivers(), function ( $receiver ) {
			return ! empty( $receiver['active'] );
		} ) );
	}

	/**
	 * Build connection credentials for an ApiClient.
	 *
	 * @param string $id Receiver ID.
	 * @return array|\WP_Error
	 */
	public static function get_credentials( string $id ) {
		$receiver = self::get_receiver( $id );
		if ( ! $receiver ) {
			return new \WP_Error( 'receiver_not_found', __( 'Receiver not found.', 'niyi-page-post-sync' ) );
		}

		return array(
			'id'           => $receiver['id'],
			'name'         => $receiver['name'],
			'url'          => $receiver['url'],
			'username'     => $receiver['username'],
			'app_password' => self::decrypt( $receiver['password_enc'] ?? '' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Password encryption (reversible, needs the Basic Auth password)     */
	/* ------------------------------------------------------------------ */

	/**
	 * Encryption key derived from a WordPress salt.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function encryption_key(): string {
		return substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
	}

	/**
	 * @param string $value Plain text.
	 * @return string Encoded value.
	 */
	public static function encrypt( string $value ): string {
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = function_exists( 'random_bytes' ) ? random_bytes( 16 ) : openssl_random_pseudo_bytes( 16 );
			$cipher = openssl_encrypt( $value, 'aes-256-cbc', self::encryption_key(), 0, $iv );
			return 'enc:' . base64_encode( $iv . $cipher );
		}
		return 'b64:' . base64_encode( $value );
	}

	/**
	 * @param string $value Encoded value.
	 * @return string Plain text.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( strpos( $value, 'b64:' ) === 0 ) {
			return (string) base64_decode( substr( $value, 4 ) );
		}
		if ( strpos( $value, 'enc:' ) !== 0 ) {
			return $value; // Legacy plain-text value.
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw    = base64_decode( substr( $value, 4 ) );
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		return (string) openssl_decrypt( $cipher, 'aes-256-cbc', self::encryption_key(), 0, $iv );
	}

	/* ------------------------------------------------------------------ */
	/* Receiver CRUD                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate and normalise submitted receiver fields.
	 *
	 * @param array $data    Raw input.
	 * @param string $skip_id Receiver ID to exclude from the uniqueness check.
	 * @return array|\WP_Error
	 */
	private static function validate( array $data, string $skip_id = '' ) {
		$name     = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';
		$url      = isset( $data['url'] ) ? esc_url_raw( wp_unslash( $data['url'] ) ) : '';
		$username = isset( $data['username'] ) ? sanitize_user( wp_unslash( $data['username'] ), true ) : '';
		$password = isset( $data['password'] ) ? wp_unslash( $data['password'] ) : '';
		$active   = ! empty( $data['active'] ) ? 1 : 0;

		if ( '' === $name || '' === $url || '' === $username ) {
			return new \WP_Error( 'missing_field', __( 'Name, Site URL and Username are required.', 'niyi-page-post-sync' ) );
		}
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $url ) ) {
			return new \WP_Error( 'invalid_url', __( 'Please enter a valid http(s) site URL.', 'niyi-page-post-sync' ) );
		}

		foreach ( self::get_receivers() as $receiver ) {
			if ( $skip_id && isset( $receiver['id'] ) && $receiver['id'] === $skip_id ) {
				continue;
			}
			if ( isset( $receiver['name'] ) && strcasecmp( $receiver['name'], $name ) === 0 ) {
				return new \WP_Error( 'duplicate_name', sprintf( __( 'A receiver named "%s" already exists.', 'niyi-page-post-sync' ), $name ) );
			}
		}

		return array(
			'name'     => $name,
			'url'      => rtrim( $url, '/' ),
			'username' => $username,
			'password' => $password,
			'active'   => $active,
		);
	}

	/**
	 * @param array $data Raw input.
	 * @return string|\WP_Error New receiver ID or error.
	 */
	public static function add_receiver( array $data ) {
		$validated = self::validate( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( '' === $validated['password'] ) {
			return new \WP_Error( 'missing_password', __( 'An application password is required.', 'niyi-page-post-sync' ) );
		}

		$id         = 'rcv_' . wp_generate_password( 12, false, false );
		$receivers  = self::get_receivers();
		$receivers[] = array(
			'id'          => $id,
			'name'        => $validated['name'],
			'url'         => $validated['url'],
			'username'    => $validated['username'],
			'password_enc'=> self::encrypt( $validated['password'] ),
			'active'      => $validated['active'],
			'created_at'  => current_time( 'c' ),
		);

		self::save_receivers( $receivers );
		return $id;
	}

	/**
	 * @param string $id   Receiver ID.
	 * @param array  $data Raw input.
	 * @return bool|\WP_Error
	 */
	public static function update_receiver( string $id, array $data ) {
		if ( ! self::get_receiver( $id ) ) {
			return new \WP_Error( 'not_found', __( 'Receiver not found.', 'niyi-page-post-sync' ) );
		}

		$validated = self::validate( $data, $id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$receivers = self::get_receivers();
		foreach ( $receivers as $index => $receiver ) {
			if ( $receiver['id'] !== $id ) {
				continue;
			}
			$receivers[ $index ]['name']     = $validated['name'];
			$receivers[ $index ]['url']      = $validated['url'];
			$receivers[ $index ]['username'] = $validated['username'];
			$receivers[ $index ]['active']   = $validated['active'];
			if ( '' !== $validated['password'] ) {
				$receivers[ $index ]['password_enc'] = self::encrypt( $validated['password'] );
			}
			break;
		}

		self::save_receivers( $receivers );
		return true;
	}

	/**
	 * @param string $id Receiver ID.
	 * @return bool
	 */
	public static function delete_receiver( string $id ): bool {
		$receivers = array_values( array_filter( self::get_receivers(), function ( $receiver ) use ( $id ) {
			return ! isset( $receiver['id'] ) || $receiver['id'] !== $id;
		} ) );
		return self::save_receivers( $receivers );
	}

	/**
	 * Toggle the active flag.
	 *
	 * @param string $id Receiver ID.
	 * @return bool
	 */
	public static function toggle_receiver( string $id ): bool {
		$receivers = self::get_receivers();
		foreach ( $receivers as $index => $receiver ) {
			if ( isset( $receiver['id'] ) && $receiver['id'] === $id ) {
				$receivers[ $index ]['active'] = empty( $receiver['active'] ) ? 1 : 0;
				return self::save_receivers( $receivers );
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Admin-post handlers                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * URL of the receivers settings screen.
	 *
	 * @param string $notice Notice key.
	 * @param string $message Optional message.
	 * @return string
	 */
	private static function page_url( string $notice = '', string $message = '' ): string {
		$base    = admin_url( 'edit.php?post_type=post' );
		$referer = wp_get_referer();

		// Keep users on the Settings → Niyi Sync Setup screen when they came from there.
		if ( $referer && strpos( $referer, 'options-general.php' ) !== false && strpos( $referer, 'page=' . self::PAGE_SLUG ) !== false ) {
			$base = remove_query_arg( 'niyi_edit', $referer );
		}

		$args = array( 'page' => self::PAGE_SLUG );
		if ( strpos( $base, 'options-general.php' ) === false ) {
			$args['post_type'] = 'post';
		}
		if ( $notice ) {
			$args['niyi_notice'] = $notice;
		}
		if ( $message ) {
			$args['niyi_msg'] = $message;
		}
		return add_query_arg( $args, $base );
	}

	/**
	 * Handle add / edit form submission.
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'niyi_pps_receiver_save' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'niyi-page-post-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'niyi-page-post-sync' ) );
		}

		$id     = isset( $_POST['receiver_id'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_id'] ) ) : '';
		$result = $id ? self::update_receiver( $id, $_POST ) : self::add_receiver( $_POST );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::page_url( 'error', $result->get_error_message() ) );
		} else {
			wp_safe_redirect( self::page_url( $id ? 'updated' : 'added' ) );
		}
		exit;
	}

	/**
	 * Handle delete form submission.
	 */
	public static function handle_delete() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'niyi_pps_receiver_delete' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'niyi-page-post-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'niyi-page-post-sync' ) );
		}

		$id = isset( $_POST['receiver_id'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_id'] ) ) : '';
		self::delete_receiver( $id );
		wp_safe_redirect( self::page_url( 'deleted' ) );
		exit;
	}

	/**
	 * Handle active toggle form submission.
	 */
	public static function handle_toggle() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'niyi_pps_receiver_toggle' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'niyi-page-post-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'niyi-page-post-sync' ) );
		}

		$id = isset( $_POST['receiver_id'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_id'] ) ) : '';
		self::toggle_receiver( $id );
		wp_safe_redirect( self::page_url( 'updated' ) );
		exit;
	}

	/**
	 * Handle the sync-options form submission.
	 */
	public static function handle_options_save() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'niyi_pps_save_options' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'niyi-page-post-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'niyi-page-post-sync' ) );
		}

		$raw_exclude = isset( $_POST['meta_exclude'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meta_exclude'] ) ) : '';
		$excluded    = array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $raw_exclude ) ) ), 'strlen' ) );

		self::update_options( array(
			'sync_meta'           => ! empty( $_POST['sync_meta'] ) ? 1 : 0,
			'meta_include_private' => ! empty( $_POST['meta_include_private'] ) ? 1 : 0,
			'meta_exclude'        => $excluded,
		) );

		wp_safe_redirect( self::page_url( 'options_saved' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Settings page UI                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the receivers settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'niyi-page-post-sync' ) );
		}

		$notice  = isset( $_GET['niyi_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['niyi_notice'] ) ) : '';
		$message = isset( $_GET['niyi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['niyi_msg'] ) ) : '';
		$edit_id = isset( $_GET['niyi_edit'] ) ? sanitize_text_field( wp_unslash( $_GET['niyi_edit'] ) ) : '';
		$editing = $edit_id ? self::get_receiver( $edit_id ) : null;
		$receivers = self::get_receivers();

		$defaults       = self::sync_defaults();
		$sync_meta      = (bool) self::get_option( 'sync_meta', $defaults['sync_meta'] );
		$include_private = (bool) self::get_option( 'meta_include_private', $defaults['meta_include_private'] );
		$excluded       = self::get_option( 'meta_exclude', $defaults['meta_exclude'] );
		if ( ! is_array( $excluded ) ) {
			$excluded = array();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Niyi Sync Setup', 'niyi-page-post-sync' ); ?></h1>

			<?php if ( 'error' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $message ?: __( 'Something went wrong.', 'niyi-page-post-sync' ) ); ?></p></div>
			<?php elseif ( 'options_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sync options updated.', 'niyi-page-post-sync' ); ?></p></div>
			<?php elseif ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Receiver settings updated.', 'niyi-page-post-sync' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Add remote WordPress sites here. Use an Application Password (Users → Profile → Application Passwords) as the password.', 'niyi-page-post-sync' ); ?>
			</p>

			<h2><?php echo $editing ? esc_html__( 'Edit Receiver', 'niyi-page-post-sync' ) : esc_html__( 'Add Receiver', 'niyi-page-post-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="niyi-pps-form">
				<input type="hidden" name="action" value="niyi_pps_receiver_save">
				<?php wp_nonce_field( 'niyi_pps_receiver_save' ); ?>
				<?php if ( $editing ) : ?>
					<input type="hidden" name="receiver_id" value="<?php echo esc_attr( $editing['id'] ); ?>">
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="niyi_name"><?php esc_html_e( 'Receiver Name', 'niyi-page-post-sync' ); ?></label></th>
							<td><input type="text" id="niyi_name" name="name" required class="regular-text" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="niyi_url"><?php esc_html_e( 'Site URL', 'niyi-page-post-sync' ); ?></label></th>
							<td><input type="url" id="niyi_url" name="url" required class="regular-text" placeholder="https://example.com" value="<?php echo esc_attr( $editing['url'] ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="niyi_username"><?php esc_html_e( 'Username', 'niyi-page-post-sync' ); ?></label></th>
							<td><input type="text" id="niyi_username" name="username" required class="regular-text" value="<?php echo esc_attr( $editing['username'] ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="niyi_password"><?php esc_html_e( 'Application Password', 'niyi-page-post-sync' ); ?></label></th>
							<td>
								<input type="password" id="niyi_password" name="password" class="regular-text" value="" <?php echo $editing ? '' : 'required'; ?>>
								<?php if ( $editing ) : ?>
									<p class="description"><?php esc_html_e( 'Leave blank to keep the current password.', 'niyi-page-post-sync' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Active', 'niyi-page-post-sync' ); ?></th>
							<td>
								<label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $editing['active'] ) || ! $editing ); ?>> <?php esc_html_e( 'Available for syncing', 'niyi-page-post-sync' ); ?></label>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Update Receiver', 'niyi-page-post-sync' ) : esc_html__( 'Add Receiver', 'niyi-page-post-sync' ); ?></button>
					<button type="button" class="button niyi-pps-test" data-receiver-id="<?php echo esc_attr( $editing['id'] ?? '' ); ?>"><?php esc_html_e( 'Test Connection', 'niyi-page-post-sync' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Sync Options', 'niyi-page-post-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="niyi-pps-form">
				<input type="hidden" name="action" value="niyi_pps_save_options">
				<?php wp_nonce_field( 'niyi_pps_save_options' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Custom fields / meta', 'niyi-page-post-sync' ); ?></th>
							<td>
								<label><input type="checkbox" name="sync_meta" value="1" <?php checked( $sync_meta ); ?>> <?php esc_html_e( 'Sync post meta (custom fields, SEO fields, etc.)', 'niyi-page-post-sync' ); ?></label>
								<p class="description"><?php esc_html_e( 'Meta keys must be registered with show_in_rest on the receiver to be accepted by the WordPress REST API. Unregistered keys may be ignored or rejected; problems are reported as warnings.', 'niyi-page-post-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Private meta keys', 'niyi-page-post-sync' ); ?></th>
							<td>
								<label><input type="checkbox" name="meta_include_private" value="1" <?php checked( $include_private ); ?>> <?php esc_html_e( 'Include meta keys starting with an underscore (e.g. Yoast/RankMath fields)', 'niyi-page-post-sync' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="niyi_meta_exclude"><?php esc_html_e( 'Excluded meta keys', 'niyi-page-post-sync' ); ?></label></th>
							<td>
								<textarea id="niyi_meta_exclude" name="meta_exclude" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", $excluded ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One key per line. Supports * wildcards, e.g. _my_plugin_*. Tracking and WordPress lock/revision meta are always excluded.', 'niyi-page-post-sync' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Sync Options', 'niyi-page-post-sync' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Available Receivers', 'niyi-page-post-sync' ); ?></h2>
			<?php if ( empty( $receivers ) ) : ?>
				<p><?php esc_html_e( 'No receivers configured yet.', 'niyi-page-post-sync' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'niyi-page-post-sync' ); ?></th>
							<th><?php esc_html_e( 'Site URL', 'niyi-page-post-sync' ); ?></th>
							<th><?php esc_html_e( 'Username', 'niyi-page-post-sync' ); ?></th>
							<th><?php esc_html_e( 'Status', 'niyi-page-post-sync' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'niyi-page-post-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $receivers as $receiver ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $receiver['name'] ); ?></strong></td>
								<td><a href="<?php echo esc_url( $receiver['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $receiver['url'] ); ?></a></td>
								<td><?php echo esc_html( $receiver['username'] ); ?></td>
								<td>
									<?php if ( ! empty( $receiver['active'] ) ) : ?>
										<span class="niyi-pps-status active"><?php esc_html_e( 'Active', 'niyi-page-post-sync' ); ?></span>
									<?php else : ?>
										<span class="niyi-pps-status inactive"><?php esc_html_e( 'Inactive', 'niyi-page-post-sync' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'niyi_edit' => $receiver['id'] ), self::page_url() ) ); ?>"><?php esc_html_e( 'Edit', 'niyi-page-post-sync' ); ?></a>
									&nbsp;|&nbsp;
									<button type="button" class="button-link niyi-pps-test" data-receiver-id="<?php echo esc_attr( $receiver['id'] ); ?>"><?php esc_html_e( 'Test', 'niyi-page-post-sync' ); ?></button>
									&nbsp;|&nbsp;
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="niyi_pps_receiver_toggle">
										<input type="hidden" name="receiver_id" value="<?php echo esc_attr( $receiver['id'] ); ?>">
										<?php wp_nonce_field( 'niyi_pps_receiver_toggle' ); ?>
										<button type="submit" class="button-link"><?php echo empty( $receiver['active'] ) ? esc_html__( 'Activate', 'niyi-page-post-sync' ) : esc_html__( 'Deactivate', 'niyi-page-post-sync' ); ?></button>
									</form>
									&nbsp;|&nbsp;
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="niyi_pps_receiver_delete">
										<input type="hidden" name="receiver_id" value="<?php echo esc_attr( $receiver['id'] ); ?>">
										<?php wp_nonce_field( 'niyi_pps_receiver_delete' ); ?>
										<button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Delete this receiver?', 'niyi-page-post-sync' ) ); ?>');"><?php esc_html_e( 'Delete', 'niyi-page-post-sync' ); ?></button>
									</form>
									<span class="niyi-pps-test-slot"></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
