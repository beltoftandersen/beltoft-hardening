<?php
/**
 * Settings > Hardening.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen.
 */
final class SettingsPage {

	private const PAGE = 'beltoft-hardening';

	/**
	 * Hooks the screen.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BELTOFT_HARDENING_FILE ), array( __CLASS__, 'links' ) );
	}

	/**
	 * Adds the page under Settings.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'Hardening', 'beltoft-hardening' ),
			__( 'Hardening', 'beltoft-hardening' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param mixed $links Action links.
	 * @return mixed
	 */
	public static function links( $links ) {
		if ( is_array( $links ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'beltoft-hardening' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * Registers the option and its fields.
	 */
	public static function settings(): void {
		register_setting(
			self::PAGE,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section( 'beltoft_hardening_main', '', '__return_empty_string', self::PAGE );

		$boxes = array(
			Settings::REST_USERS      => array(
				__( 'REST user routes', 'beltoft-hardening' ),
				__( 'Hide /wp/v2/users from visitors and accounts that cannot list users', 'beltoft-hardening' ),
			),
			Settings::AUTHOR_ARCHIVES => array(
				__( 'Author archives', 'beltoft-hardening' ),
				__( 'Answer author archives and ?author= on the front end with a 404', 'beltoft-hardening' ),
			),
			Settings::UNIFORM_ERRORS  => array(
				__( 'Login errors', 'beltoft-hardening' ),
				__( 'Show one message for a wrong username, email or password', 'beltoft-hardening' ),
			),
			Settings::ADMIN_LOGIN     => array(
				__( 'Administrator sign-in', 'beltoft-hardening' ),
				__( 'Administrators can only sign in at wp-login.php', 'beltoft-hardening' ),
			),
			Settings::AUTHOR_DETAILS  => array(
				__( 'Author details', 'beltoft-hardening' ),
				__( 'Hide author details from oEmbed and the public REST API', 'beltoft-hardening' ),
			),
		);

		foreach ( $boxes as $key => $labels ) {
			add_settings_field(
				'beltoft_hardening_' . $key,
				$labels[0],
				array( __CLASS__, 'checkbox' ),
				self::PAGE,
				'beltoft_hardening_main',
				array(
					'key'  => $key,
					'desc' => $labels[1],
				)
			);
		}

		add_settings_field( 'beltoft_hardening_hide_login', __( 'Hide the login', 'beltoft-hardening' ), array( __CLASS__, 'hide_login_field' ), self::PAGE, 'beltoft_hardening_main' );
		add_settings_field( 'beltoft_hardening_entry_slug', __( 'Staff sign-in address', 'beltoft-hardening' ), array( __CLASS__, 'slug_field' ), self::PAGE, 'beltoft_hardening_main' );
		add_settings_field( 'beltoft_hardening_public_login_url', __( 'Public sign-in address', 'beltoft-hardening' ), array( __CLASS__, 'public_url_field' ), self::PAGE, 'beltoft_hardening_main' );
		add_settings_field( 'beltoft_hardening_allow_lostpassword', __( 'Lost-password screen', 'beltoft-hardening' ), array( __CLASS__, 'lostpassword_field' ), self::PAGE, 'beltoft_hardening_main' );

		foreach ( array(
			Settings::DISABLE_XMLRPC        => array( __( 'XML-RPC', 'beltoft-hardening' ), __( 'Disable XML-RPC (its methods check passwords without the login screen)', 'beltoft-hardening' ) ),
			Settings::DISABLE_APP_PASSWORDS => array( __( 'Application passwords', 'beltoft-hardening' ), __( 'Disable application passwords (REST accepts them as HTTP Basic passwords)', 'beltoft-hardening' ) ),
		) as $key => $labels ) {
			add_settings_field( 'beltoft_hardening_' . $key, $labels[0], array( __CLASS__, 'extra_checkbox' ), self::PAGE, 'beltoft_hardening_main', array( 'key' => $key, 'desc' => $labels[1] ) );
		}

		add_settings_field(
			'beltoft_hardening_message',
			__( 'Login error text', 'beltoft-hardening' ),
			array( __CLASS__, 'message_field' ),
			self::PAGE,
			'beltoft_hardening_main'
		);
	}

	/**
	 * One checkbox.
	 *
	 * @param array<string,string> $args Field arguments.
	 */
	public static function checkbox( array $args ): void {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( Settings::OPTION ),
			esc_attr( $args['key'] ),
			checked( Settings::enabled( $args['key'] ), true, false ),
			esc_html( $args['desc'] )
		);
	}

	/**
	 * A default-off checkbox.
	 *
	 * @param array<string,string> $args Field arguments.
	 */
	public static function extra_checkbox( array $args ): void {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( Settings::OPTION ),
			esc_attr( $args['key'] ),
			checked( Settings::extra_enabled( $args['key'] ), true, false ),
			esc_html( $args['desc'] )
		);
	}

	/**
	 * Saved value of one of the hide-login fields.
	 *
	 * @param string $key Settings key.
	 */
	private static function saved( string $key ): string {
		$saved = get_option( Settings::OPTION, array() );

		return is_array( $saved ) && isset( $saved[ $key ] ) ? (string) $saved[ $key ] : '';
	}

	/**
	 * The hide-login checkbox, its state and the warning. Off by default.
	 */
	public static function hide_login_field(): void {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( Settings::OPTION ),
			esc_attr( Settings::HIDE_LOGIN ),
			checked( Settings::hide_login_enabled(), true, false ),
			esc_html__( 'Visitors who are not signed in get a 404 for the login screen and the admin area', 'beltoft-hardening' )
		);

		$reason = HideLogin::disabled_reason();
		if ( Settings::hide_login_enabled() && '' !== $reason ) {
			echo '<p class="description"><strong>' . esc_html( $reason ) . '</strong></p>';
		} elseif ( HideLogin::active() ) {
			echo '<p class="description"><strong>' . esc_html__( 'Staff sign-in address:', 'beltoft-hardening' ) . '</strong> <code>' . esc_html( home_url( '/' . Settings::entry_slug() . '/' ) ) . '</code></p>';
		}

		echo '<p class="description">' . esc_html__( 'Warning: save the staff sign-in address somewhere safe before switching this on. If you lose it, add define( \'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN\', true ); to wp-config.php to get the normal login back.', 'beltoft-hardening' ) . '</p>';
	}

	/**
	 * The staff sign-in address field.
	 */
	public static function slug_field(): void {
		printf(
			'<code>%1$s</code> <input type="text" class="regular-text" name="%2$s[%3$s]" value="%4$s" /><p class="description">%5$s</p>',
			esc_html( trailingslashit( home_url() ) ),
			esc_attr( Settings::OPTION ),
			esc_attr( Settings::ENTRY_SLUG ),
			esc_attr( self::saved( Settings::ENTRY_SLUG ) ),
			esc_html__( 'Letters, digits and hyphens. Visiting this address sets a 15-minute cookie and opens the login. It must be filled in before the control can be switched on, and it must not be the address of an existing page.', 'beltoft-hardening' )
		);
	}

	/**
	 * The public sign-in address field.
	 */
	public static function public_url_field(): void {
		printf(
			'<input type="url" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" /><p class="description">%5$s</p>',
			esc_attr( Settings::OPTION ),
			esc_attr( Settings::PUBLIC_LOGIN_URL ),
			esc_attr( self::saved( Settings::PUBLIC_LOGIN_URL ) ),
			esc_attr( Settings::public_login_url() ),
			esc_html__( 'Where "Log in" links WordPress prints are sent for visitors. Empty: the My Account page when WooCommerce is active, otherwise the home page.', 'beltoft-hardening' )
		);
	}

	/**
	 * The lost-password select.
	 */
	public static function lostpassword_field(): void {
		$value = self::saved( Settings::ALLOW_LOSTPASSWORD );

		printf( '<select name="%1$s[%2$s]">', esc_attr( Settings::OPTION ), esc_attr( Settings::ALLOW_LOSTPASSWORD ) );
		foreach ( array(
			''  => __( 'Automatic (on without WooCommerce, off with it)', 'beltoft-hardening' ),
			'1' => __( 'Allow the wp-login.php lost-password screen', 'beltoft-hardening' ),
			'0' => __( 'Hide it as well', 'beltoft-hardening' ),
		) as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $key ), selected( $value, (string) $key, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Only used while the login is hidden. WooCommerce has its own lost-password screen on My Account.', 'beltoft-hardening' ) . '</p>';
	}

	/**
	 * The custom message field.
	 */
	public static function message_field(): void {
		$saved = get_option( Settings::OPTION, array() );
		$text  = is_array( $saved ) && isset( $saved[ Settings::MESSAGE ] ) ? (string) $saved[ Settings::MESSAGE ] : '';

		printf(
			'<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" />',
			esc_attr( Settings::OPTION ),
			esc_attr( Settings::MESSAGE ),
			esc_attr( $text ),
			esc_attr__( 'Incorrect username or password.', 'beltoft-hardening' )
		);
		echo '<p class="description">' . esc_html__( 'Leave empty to use the default text.', 'beltoft-hardening' ) . '</p>';
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
