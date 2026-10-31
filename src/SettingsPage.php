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
