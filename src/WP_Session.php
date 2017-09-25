<?php
namespace Awethemes\WP_Session;

use Countable;
use ArrayAccess;

class WP_Session implements ArrayAccess, Countable {
	/**
	 * The session cookie name.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * The session store instance.
	 *
	 * @var Session
	 */
	protected $session;

	/**
	 * Default session configure.
	 *
	 * @var array
	 */
	protected $config = [
		// The session lifetime in minutes.
		'lifetime' => 1440,

		// If true, the session immediately expire on the browser closing.
		'expire_on_close' => false,
	];

	/**
	 * Create new session.
	 *
	 * @param string $name   The session cookie name, should be unique.
	 * @param array  $config The session configure.
	 */
	public function __construct( $name, array $config = [] ) {
		$this->name    = sanitize_key( $name );
		$this->config  = array_merge( $this->config, $config );
		$this->session = new Store( $name, new WP_Session_Handler( $this->config['lifetime'] ) );
	}

	/**
	 * Hooks into WordPress to start, commit and run garbage collector.
	 *
	 * @return void
	 */
	public function hooks() {
		// Start and commit the session.
		add_action( 'plugins_loaded', [ $this, 'start_session' ] );
		add_action( 'shutdown', [ $this, 'commit_session' ] );

		// Register the garbage collector.
		add_action( 'wp', [ $this, 'register_garbage_collection' ] );
		add_action( 'awebooking_session_garbage_collection', [ $this, 'cleanup_expired_sessions' ] );
	}

	/**
	 * Start the session when `plugin_loaded`.
	 *
	 * @access private
	 *
	 * @return void
	 */
	public function start_session() {
		$session = $this->session;

		// Maybe set set ID from cookie.
		$session_name = $session->get_name();
		$session->set_id( isset( $_COOKIE[ $session_name ] ) ? sanitize_text_field( $_COOKIE[ $session_name ] ) : null );

		// Start the session.
		$session->start();

		if ( ! $this->running_in_cli() ) {
			// Add the session identifier to cookie, so we can re-use that in lifetime.
			$expiration_date = $this->config['expire_on_close'] ? 0 : time() + $this->lifetime_in_seconds();
			setcookie( $session->get_name(), $session->get_id(), $expiration_date, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl() );
		}
	}

	/**
	 * Commit session when `shutdown` fired.
	 *
	 * @access private
	 *
	 * @return void
	 */
	public function commit_session() {
		$this->session->save();
	}

	/**
	 * Clean up expired sessions by removing data and their expiration entries from
	 * the WordPress options table.
	 *
	 * This method should never be called directly and should instead be triggered as part
	 * of a scheduled task or cron job.
	 *
	 * @access private
	 */
	public function cleanup_expired_sessions() {
		if ( defined( 'WP_SETUP_CONFIG' ) || defined( 'WP_INSTALLING' ) ) {
			return;
		}

		$this->session->get_handler()->gc( $this->lifetime_in_seconds() );
	}

	/**
	 * Register the garbage collector as a hourly event.
	 *
	 * @access private
	 */
	public function register_garbage_collection() {
		if ( ! wp_next_scheduled( 'awebooking_session_garbage_collection' ) ) {
			wp_schedule_event( time(), 'hourly', 'awebooking_session_garbage_collection' );
		}
	}

	/**
	 * Returns lifetime minutes in seconds.
	 *
	 * @return int
	 */
	protected function lifetime_in_seconds() {
		return $this->config['lifetime'] * 60;
	}

	/**
	 * Determines current process is running in CLI.
	 *
	 * @return bool
	 */
	protected function running_in_cli() {
		return php_sapi_name() === 'cli' || defined( 'WP_CLI' );
	}

	/**
	 * Get the session implementation.
	 */
	public function get_store() {
		return $this->session;
	}

	/**
	 * Count the number of items in the collection.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->session->all() );
	}

	/**
	 * Determine if an item exists at an offset.
	 *
	 * @param  mixed $key The offset key.
	 * @return bool
	 */
	public function offsetExists( $key ) {
		return $this->session->exists( $key );
	}

	/**
	 * Get an item at a given offset.
	 *
	 * @param  mixed $key The offset key.
	 * @return mixed
	 */
	public function offsetGet( $key ) {
		return $this->session->get( $key );
	}

	/**
	 * Set the item at a given offset.
	 *
	 * @param  mixed $key   The offset key.
	 * @param  mixed $value The offset value.
	 * @return void
	 */
	public function offsetSet( $key, $value ) {
		$this->session->put( $key, $value );
	}

	/**
	 * Unset the item at a given offset.
	 *
	 * @param  string $key Offset key.
	 * @return void
	 */
	public function offsetUnset( $key ) {
		$this->session->remove( $key );
	}

	/**
	 * Dynamically call the default driver instance.
	 *
	 * @param  string $method     Call method.
	 * @param  array  $parameters Call method parameters.
	 * @return mixed
	 */
	public function __call( $method, $parameters ) {
		return $this->session->$method( ...$parameters );
	}
}
