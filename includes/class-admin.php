<?php
/**
 * Ecomiq page under WooCommerce and the approval screen.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Ecomiq page under WooCommerce and the approval screen.
 */
final class Admin {

	const PAGE           = 'ecomiq';
	const DISMISSED_META = 'ecomiq_notice_dismissed';

	/**
	 * Hook suffix of the Ecomiq page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hooks the admin page, notices and form handlers.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'admin_notices', array( __CLASS__, 'activation_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_post_ecomiq_authorize', array( __CLASS__, 'authorize' ) );
		add_action( 'admin_post_ecomiq_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_ecomiq_server', array( __CLASS__, 'server' ) );
		add_action( 'admin_post_ecomiq_dismiss', array( __CLASS__, 'dismiss' ) );
		add_action( 'admin_post_ecomiq_diagnose', array( __CLASS__, 'diagnose' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the Ecomiq page under WooCommerce.
	 */
	public static function menu() {
		self::$hook = (string) add_submenu_page( 'woocommerce', 'Ecomiq', 'Ecomiq', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
		add_action( 'load-' . self::$hook, array( __CLASS__, 'consent' ) );
	}

	/**
	 * Loads the page styles on the Ecomiq page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function styles( $hook ) {
		if ( '' === self::$hook || self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ecomiq-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Plugin action links.
	 *
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ecomiq-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Shows a dismissible notice on the Plugins screen until the store is connected.
	 */
	public static function activation_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) || null !== Connection::current() ) {
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( 'action', 'ecomiq_dismiss', admin_url( 'admin-post.php' ) ), 'ecomiq_dismiss' );
		echo '<div class="notice notice-info" style="position:relative;padding-right:38px">';
		echo '<p>' . esc_html__( 'Connect your store to Ecomiq to sync products, stock, prices and orders.', 'ecomiq-for-woocommerce' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Connect to Ecomiq', 'ecomiq-for-woocommerce' ) . '</a></p>';
		echo '<a class="notice-dismiss" href="' . esc_url( $dismiss ) . '"><span class="screen-reader-text">' . esc_html__( 'Dismiss this notice.', 'ecomiq-for-woocommerce' ) . '</span></a>';
		echo '</div>';
	}

	/**
	 * Hides the connection notice for the current user.
	 */
	public static function dismiss() {
		self::guard();
		check_admin_referer( 'ecomiq_dismiss' );
		update_user_meta( get_current_user_id(), self::DISMISSED_META, 1 );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Suggests text for the site privacy policy.
	 */
	public static function privacy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = sprintf(
			/* translators: %s: Ecomiq privacy policy URL */
			__( 'When you connect this store, Ecomiq receives a WooCommerce API key and reads and updates products, stock, prices and orders, including the name, email, phone and addresses of the customer on each order. Ecomiq handles this data under its privacy policy: %s', 'ecomiq-for-woocommerce' ),
			'https://ecomiq.pe/privacidad'
		);
		wp_add_privacy_policy_content( 'Ecomiq for WooCommerce', wpautop( esc_html( $text ) ) );
	}

	/**
	 * Returns the URL of the Ecomiq page.
	 *
	 * @param array $args Optional. Query arguments.
	 *
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the Ecomiq page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="wrap ecomiq">';
		echo '<h1 class="wp-heading-inline">Ecomiq</h1><hr class="wp-header-end">';
		self::notice();
		$connection = Connection::current();
		if ( null !== $connection && 'disconnect' === self::query( 'ecomiq_view' ) ) {
			self::render_disconnect();
		} elseif ( null !== $connection ) {
			self::render_connected( $connection );
			self::render_diagnostics();
			self::render_activity();
		} else {
			self::render_connect();
			self::render_diagnostics();
			self::render_activity();
		}
		echo '</div>';
	}

	/**
	 * Renders the connection diagnostics.
	 */
	private static function render_diagnostics() {
		$icons = array(
			'ok'      => 'yes-alt',
			'warning' => 'warning',
			'error'   => 'dismiss',
			'info'    => 'shield',
			'unknown' => 'editor-help',
		);
		echo '<div class="ecomiq-diagnostics">';
		echo '<div class="ecomiq-activity-head"><h2>' . esc_html__( 'Diagnostics', 'ecomiq-for-woocommerce' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ecomiq_diagnose">';
		wp_nonce_field( 'ecomiq_diagnose' );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Check again', 'ecomiq-for-woocommerce' ) . '</button>';
		echo '</form></div>';
		echo '<table class="widefat"><tbody>';
		$firewall = false;
		foreach ( Diagnostics::checks() as $check ) {
			if ( 'info' === $check['status'] || 'error' === $check['status'] || 'warning' === $check['status'] ) {
				$firewall = true;
			}
			echo '<tr class="' . esc_attr( 'ecomiq-check-' . $check['status'] ) . '">';
			echo '<td class="ecomiq-check-icon"><span class="dashicons dashicons-' . esc_attr( $icons[ $check['status'] ] ) . '" aria-hidden="true"></span></td>';
			echo '<td><span class="ecomiq-activity-what">' . esc_html( $check['label'] ) . '</span>';
			if ( '' !== $check['detail'] ) {
				echo '<span class="ecomiq-activity-detail">' . esc_html( $check['detail'] ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$ips = Diagnostics::outbound_ips();
		if ( $firewall && ! empty( $ips ) ) {
			echo '<p class="ecomiq-ips">' . esc_html__( 'Ecomiq connects from:', 'ecomiq-for-woocommerce' ) . ' <code>' . esc_html( implode( ', ', $ips ) ) . '</code></p>';
		}
		echo '</div>';
	}

	/**
	 * Runs the diagnostics again.
	 */
	public static function diagnose() {
		self::guard();
		check_admin_referer( 'ecomiq_diagnose' );
		Diagnostics::reset();
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Renders the latest activity.
	 */
	private static function render_activity() {
		$events = Activity::events();
		if ( empty( $events ) ) {
			return;
		}
		echo '<div class="ecomiq-activity">';
		echo '<div class="ecomiq-activity-head"><h2>' . esc_html__( 'Activity', 'ecomiq-for-woocommerce' ) . '</h2>';
		echo '<a href="' . esc_url( Activity::logs_url() ) . '">' . esc_html__( 'Full logs', 'ecomiq-for-woocommerce' ) . '</a></div>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( array_slice( $events, 0, 20 ) as $event ) {
			$class = '';
			if ( ! empty( $event['failed'] ) ) {
				$class = 'ecomiq-activity-failed';
			}
			$when = sprintf(
				/* translators: %s: human time difference */
				__( '%s ago', 'ecomiq-for-woocommerce' ),
				human_time_diff( (int) $event['time'], time() )
			);
			echo '<tr class="' . esc_attr( $class ) . '"><td class="ecomiq-activity-when">' . esc_html( $when ) . '</td><td>';
			echo '<span class="ecomiq-activity-what">' . esc_html( Activity::label( (string) $event['type'] ) ) . '</span>';
			if ( '' !== (string) $event['subject'] ) {
				echo '<span class="ecomiq-activity-detail">' . esc_html( (string) $event['subject'] ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Handles the approval form and returns to Ecomiq with a code or an error.
	 */
	public static function authorize() {
		self::guard();
		check_admin_referer( 'ecomiq_authorize' );
		$request = Authorization::request( Authorization::fields( $_POST ) );
		if ( is_wp_error( $request ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		$args = array( 'state' => rawurlencode( $request['state'] ) );
		if ( isset( $_POST['decision'] ) && 'approve' === sanitize_key( wp_unslash( $_POST['decision'] ) ) ) {
			$args['code'] = Authorization::issue( $request, get_current_user_id() );
		} else {
			$args['error'] = 'access_denied';
		}
		$host = wp_parse_url( Connection::server(), PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return $hosts;
			}
		);
		wp_safe_redirect( add_query_arg( $args, $request['redirect_uri'] ) );
		exit;
	}

	/**
	 * Handles the disconnect form.
	 */
	public static function disconnect() {
		self::guard();
		check_admin_referer( 'ecomiq_disconnect' );
		Connection::disconnect();
		wp_safe_redirect( self::url( array( 'ecomiq_notice' => 'disconnected' ) ) );
		exit;
	}

	/**
	 * Handles the Ecomiq address form.
	 */
	public static function server() {
		self::guard();
		check_admin_referer( 'ecomiq_server' );
		$value = '';
		if ( isset( $_POST['server'] ) ) {
			$value = sanitize_text_field( wp_unslash( $_POST['server'] ) );
		}
		$notice = 'server_saved';
		if ( is_wp_error( Connection::save_server( $value ) ) ) {
			$notice = 'server_invalid';
		}
		wp_safe_redirect( self::url( array( 'ecomiq_notice' => $notice ) ) );
		exit;
	}

	/**
	 * Stops the request when the user cannot manage WooCommerce.
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'ecomiq-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Renders the standalone approval screen when Ecomiq sends an authorization request.
	 */
	public static function consent() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth authorization request from Ecomiq; approving it posts a nonce.
		$fields = Authorization::fields( $_GET );
		if ( '' === $fields['code_challenge'] && '' === $fields['redirect_uri'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$request = Authorization::request( $fields );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		wp_register_style( 'ecomiq-consent', plugins_url( 'assets/consent.css', FILE ), array(), VERSION );
		echo '<!DOCTYPE html><html ';
		language_attributes();
		echo '><head>';
		echo '<meta charset="' . esc_attr( get_option( 'blog_charset' ) ) . '">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex, nofollow">';
		echo '<title>' . esc_html__( 'Connect to Ecomiq', 'ecomiq-for-woocommerce' ) . '</title>';
		wp_print_styles( 'ecomiq-consent' );
		echo '</head><body class="ecomiq-consent"><main class="ecomiq-consent-card">';
		echo '<div class="ecomiq-consent-logos" aria-hidden="true">';
		echo '<img src="' . esc_url( plugins_url( 'assets/ecomiq.svg', FILE ) ) . '" width="48" height="48" alt="">';
		echo '<span class="ecomiq-consent-line"></span>';
		$icon = get_site_icon_url( 96 );
		if ( '' !== $icon ) {
			echo '<img src="' . esc_url( $icon ) . '" width="48" height="48" alt="">';
		} else {
			echo '<span class="ecomiq-consent-site">' . esc_html( self::initial( self::site_name() ) ) . '</span>';
		}
		echo '</div>';
		if ( is_wp_error( $request ) ) {
			self::consent_invalid( $request );
		} else {
			self::consent_request( $request, Connection::current() );
		}
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Renders the approval form.
	 *
	 * @param array      $request    Validated authorization request.
	 * @param array|null $connection Current connection.
	 */
	private static function consent_request( array $request, $connection ) {
		$user = wp_get_current_user();
		echo '<h1>' . esc_html(
			sprintf(
				/* translators: %s: store name */
				__( 'Connect %s to Ecomiq', 'ecomiq-for-woocommerce' ),
				self::site_name()
			)
		) . '</h1>';
		echo '<p class="ecomiq-consent-lead">' . esc_html__( 'Ecomiq will be able to read and update products, stock, prices and orders.', 'ecomiq-for-woocommerce' ) . '</p>';
		$rows = array();
		if ( '' !== $request['store'] ) {
			$rows[ __( 'Ecomiq store', 'ecomiq-for-woocommerce' ) ] = $request['store'];
		}
		$rows[ __( 'Access as', 'ecomiq-for-woocommerce' ) ]  = $user->display_name;
		$rows[ __( 'Returns to', 'ecomiq-for-woocommerce' ) ] = self::host( $request['redirect_uri'] );
		self::rows( $rows );
		if ( null !== $connection ) {
			$replaces = __( 'This replaces the current connection.', 'ecomiq-for-woocommerce' );
			if ( '' !== (string) $connection['store_name'] ) {
				$replaces = sprintf(
					/* translators: %s: Ecomiq store name */
					__( 'This replaces the current connection with %s.', 'ecomiq-for-woocommerce' ),
					$connection['store_name']
				);
			}
			echo '<p class="ecomiq-consent-warning">' . esc_html( $replaces ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ecomiq_authorize">';
		wp_nonce_field( 'ecomiq_authorize' );
		foreach ( array( 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'store' ) as $field ) {
			echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $request[ $field ] ) . '">';
		}
		echo '<div class="ecomiq-consent-actions">';
		echo '<button type="submit" class="ecomiq-consent-primary" name="decision" value="approve">' . esc_html__( 'Approve', 'ecomiq-for-woocommerce' ) . '</button>';
		echo '<button type="submit" class="ecomiq-consent-secondary" name="decision" value="deny">' . esc_html__( 'Cancel', 'ecomiq-for-woocommerce' ) . '</button>';
		echo '</div></form>';
	}

	/**
	 * Explains why an authorization request is invalid.
	 *
	 * @param \WP_Error $error Validation error.
	 */
	private static function consent_invalid( \WP_Error $error ) {
		$body  = __( 'The link is incomplete or expired. Start again from Ecomiq.', 'ecomiq-for-woocommerce' );
		$extra = '';
		if ( 'ecomiq_redirect' === $error->get_error_code() ) {
			$data = $error->get_error_data();
			$body = sprintf(
				/* translators: 1: address the request returns to, 2: trusted Ecomiq address */
				__( 'This request wants to return to %1$s, but this store only trusts %2$s. Do not approve it.', 'ecomiq-for-woocommerce' ),
				self::host( isset( $data['redirect_uri'] ) ? (string) $data['redirect_uri'] : '' ),
				self::host( Connection::server() )
			);
			$extra = __( 'If you are testing your own Ecomiq installation, change the address under Advanced.', 'ecomiq-for-woocommerce' );
		}
		echo '<h1>' . esc_html__( 'Invalid request', 'ecomiq-for-woocommerce' ) . '</h1>';
		echo '<p class="ecomiq-consent-lead">' . esc_html( $body ) . '</p>';
		if ( '' !== $extra ) {
			echo '<p class="ecomiq-consent-note">' . esc_html( $extra ) . '</p>';
		}
		echo '<div class="ecomiq-consent-actions"><a class="ecomiq-consent-secondary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back', 'ecomiq-for-woocommerce' ) . '</a></div>';
	}

	/**
	 * Renders the card to connect the store.
	 */
	private static function render_connect() {
		self::open_card(
			__( 'Connect to Ecomiq', 'ecomiq-for-woocommerce' ),
			sprintf(
				/* translators: %s: store name */
				__( 'Sync products, stock, prices and orders of %s with Ecomiq.', 'ecomiq-for-woocommerce' ),
				self::site_name()
			)
		);
		if ( ! is_ssl() ) {
			echo '<p class="ecomiq-warning">' . esc_html__( 'WordPress does not detect HTTPS. WooCommerce rejects API keys without it.', 'ecomiq-for-woocommerce' ) . '</p>';
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			echo '<p class="ecomiq-warning"><a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Turn on permalinks in Settings → Permalinks.', 'ecomiq-for-woocommerce' ) . '</a></p>';
		}
		$connect = add_query_arg( 'store', rawurlencode( home_url() ), Authorization::redirect_uri() );
		echo '<div class="ecomiq-actions"><a class="button button-primary" href="' . esc_url( $connect ) . '">' . esc_html__( 'Connect', 'ecomiq-for-woocommerce' ) . '</a></div>';
		self::close_card();
		echo '<details class="ecomiq-advanced"><summary>' . esc_html__( 'Advanced', 'ecomiq-for-woocommerce' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ecomiq_server">';
		wp_nonce_field( 'ecomiq_server' );
		echo '<label for="ecomiq-server">' . esc_html__( 'Ecomiq address', 'ecomiq-for-woocommerce' ) . '</label>';
		echo '<div class="ecomiq-inline"><input id="ecomiq-server" class="regular-text" type="url" name="server" value="' . esc_attr( Connection::server() ) . '" required>';
		echo '<button type="submit" class="button">' . esc_html__( 'Save', 'ecomiq-for-woocommerce' ) . '</button></div>';
		echo '<p class="description">' . esc_html__( 'Change it only to test your own Ecomiq installation.', 'ecomiq-for-woocommerce' ) . '</p>';
		echo '</form></details>';
	}

	/**
	 * Renders the card of a connected store.
	 *
	 * @param array $connection Current connection.
	 */
	private static function render_connected( array $connection ) {
		self::open_card(
			__( 'Connected to Ecomiq', 'ecomiq-for-woocommerce' ),
			sprintf(
				/* translators: %s: store name */
				__( 'Ecomiq syncs products, stock, prices and orders of %s.', 'ecomiq-for-woocommerce' ),
				self::site_name()
			)
		);
		$rows = array();
		if ( '' !== (string) $connection['store_name'] ) {
			$rows[ __( 'Ecomiq store', 'ecomiq-for-woocommerce' ) ] = $connection['store_name'];
		}
		$last = __( 'Not yet', 'ecomiq-for-woocommerce' );
		if ( ! empty( $connection['last_access'] ) ) {
			$last = sprintf(
				/* translators: %s: human time difference */
				__( '%s ago', 'ecomiq-for-woocommerce' ),
				human_time_diff( (int) $connection['last_access'], time() )
			);
		}
		$rows[ __( 'Last access', 'ecomiq-for-woocommerce' ) ] = $last;
		$rows[ __( 'API key', 'ecomiq-for-woocommerce' ) ]     = '…' . $connection['truncated_key'];
		$connected_on = wp_date( get_option( 'date_format' ), (int) $connection['connected_at'] );
		$user         = get_userdata( (int) $connection['user_id'] );
		if ( $user ) {
			$connected_on = sprintf(
				/* translators: 1: date, 2: user name */
				__( '%1$s by %2$s', 'ecomiq-for-woocommerce' ),
				$connected_on,
				$user->display_name
			);
		}
		$rows[ __( 'Connected on', 'ecomiq-for-woocommerce' ) ] = $connected_on;
		self::rows( $rows );
		$open = Connection::server();
		if ( '' !== (string) $connection['store_url'] ) {
			$open = $connection['store_url'];
		}
		echo '<div class="ecomiq-actions">';
		echo '<a class="button button-primary" href="' . esc_url( $open ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open Ecomiq', 'ecomiq-for-woocommerce' ) . '</a>';
		echo '<a class="button" href="' . esc_url( self::url( array( 'ecomiq_view' => 'disconnect' ) ) ) . '">' . esc_html__( 'Disconnect', 'ecomiq-for-woocommerce' ) . '</a>';
		echo '</div>';
		self::close_card();
	}

	/**
	 * Renders the disconnect confirmation.
	 */
	private static function render_disconnect() {
		self::open_card(
			__( 'Disconnect from Ecomiq?', 'ecomiq-for-woocommerce' ),
			__( 'Ecomiq stops syncing this store. Its API key and webhooks are deleted. Your products and orders do not change.', 'ecomiq-for-woocommerce' )
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ecomiq_disconnect">';
		wp_nonce_field( 'ecomiq_disconnect' );
		echo '<div class="ecomiq-actions">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Disconnect', 'ecomiq-for-woocommerce' ) . '</button>';
		echo '<a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'ecomiq-for-woocommerce' ) . '</a>';
		echo '</div></form>';
		self::close_card();
	}

	/**
	 * Renders the result of the last form.
	 */
	private static function notice() {
		$messages = array(
			'disconnected'   => array( 'success', __( 'Disconnected from Ecomiq.', 'ecomiq-for-woocommerce' ) ),
			'server_saved'   => array( 'success', __( 'Address saved.', 'ecomiq-for-woocommerce' ) ),
			'server_invalid' => array( 'error', __( 'Use an address like https://admin.ecomiq.pe, without a path.', 'ecomiq-for-woocommerce' ) ),
		);
		$key      = self::query( 'ecomiq_notice' );
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $messages[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $key ][1] ) . '</p></div>';
	}

	/**
	 * Opens a card with the Ecomiq logo.
	 *
	 * @param string $title Card title.
	 * @param string $body  Card text.
	 */
	private static function open_card( $title, $body ) {
		echo '<div class="ecomiq-card"><div class="ecomiq-head">';
		echo '<img src="' . esc_url( plugins_url( 'assets/ecomiq.svg', FILE ) ) . '" width="40" height="40" alt="">';
		echo '<div><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $body ) . '</p></div></div>';
	}

	/**
	 * Closes a card.
	 */
	private static function close_card() {
		echo '</div>';
	}

	/**
	 * Renders label and value rows.
	 *
	 * @param array $rows Values keyed by label.
	 */
	private static function rows( array $rows ) {
		echo '<dl class="ecomiq-rows">';
		foreach ( $rows as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';
	}

	/**
	 * Returns the host and port of a URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function host( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$host = $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}
		return $host;
	}

	/**
	 * Reads a key from the query string.
	 *
	 * @param string $key Query argument.
	 *
	 * @return string
	 */
	private static function query( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		return sanitize_key( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * Returns the first letter of a name.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	private static function initial( $name ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return 'W';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strtoupper( mb_substr( $name, 0, 1 ) );
		}
		return strtoupper( substr( $name, 0, 1 ) );
	}

	/**
	 * Returns the decoded site name.
	 *
	 * @return string
	 */
	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
