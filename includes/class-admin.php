<?php

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const PAGE = 'ecomiq';

	private static $hook = '';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'admin_post_ecomiq_authorize', array( __CLASS__, 'authorize' ) );
		add_action( 'admin_post_ecomiq_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_ecomiq_server', array( __CLASS__, 'server' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		self::$hook = (string) add_submenu_page( 'woocommerce', 'Ecomiq', 'Ecomiq', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function styles( $hook ) {
		if ( '' === self::$hook || self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ecomiq-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ecomiq-for-woocommerce' ) . '</a>' );
		return $links;
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="wrap ecomiq">';
		echo '<h1 class="wp-heading-inline">Ecomiq</h1><hr class="wp-header-end">';
		self::notice();
		$connection = Connection::current();
		if ( isset( $_GET['code_challenge'] ) || isset( $_GET['redirect_uri'] ) ) {
			self::render_authorize( $connection );
		} elseif ( null !== $connection && 'disconnect' === self::query( 'ecomiq_view' ) ) {
			self::render_disconnect();
		} elseif ( null !== $connection ) {
			self::render_connected( $connection );
		} else {
			self::render_connect();
		}
		echo '</div>';
	}

	public static function authorize() {
		self::guard( 'ecomiq_authorize' );
		$request = Authorization::request( $_POST );
		if ( is_wp_error( $request ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		$args = array( 'state' => rawurlencode( $request['state'] ) );
		if ( isset( $_POST['decision'] ) && 'approve' === $_POST['decision'] ) {
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

	public static function disconnect() {
		self::guard( 'ecomiq_disconnect' );
		Connection::disconnect();
		wp_safe_redirect( self::url( array( 'ecomiq_notice' => 'disconnected' ) ) );
		exit;
	}

	public static function server() {
		self::guard( 'ecomiq_server' );
		$value  = isset( $_POST['server'] ) ? wp_unslash( $_POST['server'] ) : '';
		$notice = 'server_saved';
		if ( is_wp_error( Connection::save_server( $value ) ) ) {
			$notice = 'server_invalid';
		}
		wp_safe_redirect( self::url( array( 'ecomiq_notice' => $notice ) ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'ecomiq-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function render_authorize( $connection ) {
		$request = Authorization::request( $_GET );
		if ( is_wp_error( $request ) ) {
			self::render_invalid( $request );
			return;
		}
		$user = wp_get_current_user();
		self::open_card(
			sprintf(
				/* translators: %s: store name */
				__( 'Connect %s to Ecomiq', 'ecomiq-for-woocommerce' ),
				self::site_name()
			),
			__( 'Ecomiq will be able to read and update products, stock, prices and orders.', 'ecomiq-for-woocommerce' )
		);
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
			echo '<p class="ecomiq-warning">' . esc_html( $replaces ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ecomiq_authorize">';
		wp_nonce_field( 'ecomiq_authorize' );
		foreach ( array( 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'store' ) as $field ) {
			echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $request[ $field ] ) . '">';
		}
		echo '<div class="ecomiq-actions">';
		echo '<button type="submit" class="button button-primary" name="decision" value="approve">' . esc_html__( 'Approve', 'ecomiq-for-woocommerce' ) . '</button>';
		echo '<button type="submit" class="button" name="decision" value="deny">' . esc_html__( 'Cancel', 'ecomiq-for-woocommerce' ) . '</button>';
		echo '</div></form>';
		self::close_card();
	}

	private static function render_invalid( \WP_Error $error ) {
		$body  = __( 'The link is incomplete or expired. Start again from Ecomiq.', 'ecomiq-for-woocommerce' );
		$extra = '';
		if ( 'ecomiq_redirect' === $error->get_error_code() ) {
			$data  = $error->get_error_data();
			$body  = sprintf(
				/* translators: 1: address the request returns to, 2: trusted Ecomiq address */
				__( 'This request wants to return to %1$s, but this store only trusts %2$s. Do not approve it.', 'ecomiq-for-woocommerce' ),
				self::host( isset( $data['redirect_uri'] ) ? (string) $data['redirect_uri'] : '' ),
				self::host( Connection::server() )
			);
			$extra = __( 'If you are testing your own Ecomiq installation, change the address under Advanced.', 'ecomiq-for-woocommerce' );
		}
		self::open_card( __( 'Invalid request', 'ecomiq-for-woocommerce' ), $body );
		if ( '' !== $extra ) {
			echo '<p class="description">' . esc_html( $extra ) . '</p>';
		}
		echo '<div class="ecomiq-actions"><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back', 'ecomiq-for-woocommerce' ) . '</a></div>';
		self::close_card();
	}

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

	private static function notice() {
		$messages = array(
			'disconnected'   => array( 'success', __( 'Disconnected from Ecomiq.', 'ecomiq-for-woocommerce' ) ),
			'server_saved'   => array( 'success', __( 'Address saved.', 'ecomiq-for-woocommerce' ) ),
			'server_invalid' => array( 'error', __( 'Use an address like https://admin.ecomiq.pe, without a path.', 'ecomiq-for-woocommerce' ) ),
		);
		$key = self::query( 'ecomiq_notice' );
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $messages[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $key ][1] ) . '</p></div>';
	}

	private static function open_card( $title, $body ) {
		echo '<div class="ecomiq-card"><div class="ecomiq-head">';
		echo '<img src="' . esc_url( plugins_url( 'assets/ecomiq.svg', FILE ) ) . '" width="40" height="40" alt="">';
		echo '<div><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $body ) . '</p></div></div>';
	}

	private static function close_card() {
		echo '</div>';
	}

	private static function rows( array $rows ) {
		echo '<dl class="ecomiq-rows">';
		foreach ( $rows as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';
	}

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

	private static function query( $key ) {
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return '';
		}
		return sanitize_key( wp_unslash( $_GET[ $key ] ) );
	}

	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
