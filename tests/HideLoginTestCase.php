<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\EntryCookie;
use BeltoftHardening\HideLogin;
use BeltoftHardening\NotFound;
use BeltoftHardening\Settings;

class Halted extends \Exception {}

/**
 * Simulates one request against the hide-login control. A request is: method, path, script (pagenow), GET, POST, cookies.
 */
abstract class HideLoginTestCase extends TestCase {

	/** @var array<string,mixed> */
	protected array $seen = array();

	public function set_up(): void {
		parent::set_up();

		$this->seen = array( 'status' => null, 'location' => null, 'cookies' => array(), 'headers' => array() );

		NotFound::$terminator = static function () {
			throw new Halted();
		};
		EntryCookie::$sink = function ( $name, $value, $args ) {
			$this->seen['cookies'][ $name ] = array( 'value' => $value, 'args' => $args );
		};
		add_filter(
			'status_header',
			function ( $header, $code ) {
				$this->seen['status'] = $code;
				return $header;
			},
			10,
			2
		);
		add_filter(
			'wp_redirect',
			function ( $location, $status ) {
				$this->seen['location'] = $location;
				$this->seen['redirect_status'] = $status;
				return false;
			},
			99,
			2
		);
		add_filter(
			'nocache_headers',
			function ( $headers ) {
				$this->seen['nocache'] = $headers;
				return $headers;
			}
		);
		wp_set_current_user( 0 );
		$_COOKIE = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	public function tear_down(): void {
		NotFound::$terminator = null;
		EntryCookie::$sink    = null;
		HideLogin::unregister();
		$_COOKIE  = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['current_screen'], $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/**
	 * Switches the control on with the given extra settings and hooks it.
	 *
	 * @param array<string,mixed> $extra Settings.
	 */
	protected function enable( array $extra = array() ): void {
		update_option( Settings::OPTION, array_merge( array( Settings::HIDE_LOGIN => 1, Settings::ENTRY_SLUG => 'staff' ), $extra ) );
		HideLogin::register();
	}

	/**
	 * Runs one request through the guard and the wp_loaded step. Returns 'pass', '404' or 'redirect'.
	 *
	 * @param string              $script Script name: wp-login.php, index.php, index.php for wp-admin etc.
	 * @param array<string,mixed> $get    Query string.
	 * @param array<string,mixed> $post   Body.
	 * @param string              $method Method.
	 * @param string              $uri    Request path.
	 * @param bool                $admin  Whether it is a wp-admin script.
	 */
	protected function request( string $script, array $get = array(), array $post = array(), string $method = 'GET', string $uri = '/', bool $admin = false ): string {
		$this->seen['status'] = $this->seen['location'] = null;
		$GLOBALS['pagenow']   = $script;
		$_GET                 = $get;
		$_POST                = $post;
		$_REQUEST             = array_merge( $get, $post );
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI']    = $uri . ( $get ? '?' . http_build_query( $get ) : '' );

		if ( $admin ) {
			set_current_screen( 'dashboard' );
		} else {
			unset( $GLOBALS['current_screen'] );
			$GLOBALS['current_screen'] = null;
		}

		$level = ob_get_level();
		ob_start();
		try {
			HideLogin::guard();
			HideLogin::send_pending();
			$result = 'pass';
		} catch ( Halted $e ) {
			$result = null !== $this->seen['location'] ? 'redirect' : ( 404 === $this->seen['status'] ? '404' : 'halt' );
		}
		$this->seen['body'] = '';
		while ( ob_get_level() > $level ) {
			$this->seen['body'] = ob_get_clean() . $this->seen['body'];
		}

		return $result;
	}

	protected function login( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	protected function with_cookie(): void {
		$_COOKIE[ EntryCookie::NAME ] = EntryCookie::value();
	}
}
