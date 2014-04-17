<?php
/**
 * Plugin Name: Stream
 * Plugin URI: http://wordpress.org/plugins/stream/
 * Description: Stream tracks logged-in user activity so you can monitor every change made on your WordPress site in beautifully organized detail. All activity is organized by context, action and IP address for easy filtering. Developers can extend Stream with custom connectors to log any kind of action.
 * Version: 1.3.2
 * Author: X-Team
 * Author URI: http://x-team.com/wordpress/
 * License: GPLv2+
 * Text Domain: stream
 * Domain Path: /languages
 */

/**
 * Copyright (c) 2014 X-Team (http://x-team.com/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

class WP_Stream {

	/**
	 * Plugin version number
	 *
	 * @const string
	 */
	const VERSION = '1.3.2';

	/**
	 * Hold Stream instance
	 *
	 * @var string
	 */
	public static $instance;

	/**
	 * @var WP_Stream_DB
	 */
	public $db = null;

	/**
	 * Admin notices messages
	 *
	 * @var array
	 */
	public static $messages = array();

	/**
	 * Class constructor
	 */
	private function __construct() {
		define( 'WP_STREAM_DIR', plugin_dir_path( __FILE__ ) );
		define( 'WP_STREAM_URL', plugin_dir_url( __FILE__ ) );
		define( 'WP_STREAM_INC_DIR', WP_STREAM_DIR . 'includes/' );

		// Load filters polyfill
		require_once WP_STREAM_INC_DIR . 'filter-input.php';

		// Load DB helper class
		require_once WP_STREAM_INC_DIR . 'db.php';
		$this->db = new WP_Stream_DB;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Check DB and add message if not present
		$this->verify_database_present();

		// Load languages
		add_action( 'plugins_loaded', array( __CLASS__, 'i18n' ) );

		// Load settings, enabling extensions to hook in
		require_once WP_STREAM_INC_DIR . 'settings.php';
		add_action( 'init', array( 'WP_Stream_Settings', 'load' ) );

		// Load logger class
		require_once WP_STREAM_INC_DIR . 'log.php';
		add_action( 'plugins_loaded', array( 'WP_Stream_Log', 'load' ) );

		// Load connectors
		require_once WP_STREAM_INC_DIR . 'connectors.php';
		add_action( 'init', array( 'WP_Stream_Connectors', 'load' ) );

		// Load query class
		require_once WP_STREAM_INC_DIR . 'query.php';
		require_once WP_STREAM_INC_DIR . 'context-query.php';

		// Load support for feeds
		require_once WP_STREAM_INC_DIR . 'feeds.php';
		add_action( 'init', array( 'WP_Stream_Feeds', 'load' ) );

		if ( is_admin() ) {
			require_once WP_STREAM_INC_DIR . 'admin.php';
			add_action( 'plugins_loaded', array( 'WP_Stream_Admin', 'load' ) );

			add_action( 'init', array( __CLASS__, 'install' ) );

			// Registers a hook that connectors and other plugins can use whenever a stream update happens
			add_action( 'admin_init', array( __CLASS__, 'update_activation_hook' ) );

			require_once WP_STREAM_INC_DIR . 'dashboard.php';
			add_action( 'plugins_loaded', array( 'WP_Stream_Dashboard_Widget', 'load' ) );

			require_once WP_STREAM_INC_DIR . 'live-update.php';
			add_action( 'plugins_loaded', array( 'WP_Stream_Live_Update', 'load' ) );
		}

		// Load deprecated functions
		require_once WP_STREAM_INC_DIR . 'deprecated.php';
	}

	/**
	 * Invoked when the PHP version check fails. Load up the translations and
	 * add the error message to the admin notices
	 */
	static function fail_php_version() {
		add_action( 'all_admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'i18n' ) );
		self::$messages[] = sprintf(
			'<div class="error"><p>%s</p></div>',
			__( 'Stream requires PHP version 5.3+, plugin is currently NOT ACTIVE.', 'stream' )
		);
	}

	/**
	 * Loads the translation files.
	 *
	 * @access public
	 * @action plugins_loaded
	 * @return void
	 */
	public static function i18n() {
		load_plugin_textdomain( 'stream', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Installation / Upgrade checks
	 *
	 * @action register_activation_hook
	 * @return void
	 */
	public static function install() {
		/**
		 * Filter will halt install() if set to true
		 *
		 * @param  bool
		 * @return bool
		 */
		if ( apply_filters( 'wp_stream_no_tables', false ) ) {
			return;
		}

		// Install plugin tables
		require_once WP_STREAM_INC_DIR . 'install.php';
		$update = WP_Stream_Install::get_instance();
	}

	/**
	 * Verify that all needed databases are present and add an error message if not.
	 *
	 * @return void
	 */
	private function verify_database_present() {
		/**
		 * Filter will halt verify_database_present() if set to true
		 *
		 * @param  bool
		 * @return bool
		 */
		if ( apply_filters( 'wp_stream_no_tables', false ) ) {
			return;
		}

		global $wpdb;

		$message = '';

		// Check if all needed DB is present
		foreach ( $this->db->get_table_names() as $table_name ) {
			if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
				$message .= sprintf( '<p>%s %s</p>', __( 'The following table is not present in the WordPress database :', 'stream' ), $table_name );
			}
		}

		if ( ! empty( $message ) ) {
			self::$messages['wp_stream_db_error'] = sprintf(
				'<div class="error">%s<p>%s</p></div>',
				$message,
				sprintf( __( 'Please <a href="%s">uninstall</a> the Stream plugin and activate it again.', 'stream' ), admin_url( 'plugins.php#stream' ) )
			); // xss ok
		}
	}

	static function update_activation_hook() {
		WP_Stream_Admin::register_update_hook( dirname( plugin_basename( __FILE__ ) ), array( __CLASS__, 'install' ), self::VERSION );
	}

	/**
	 * Display a notice about php version
	 *
	 * @action all_admin_notices
	 */
	public static function is_valid_php_version() {
		return version_compare( PHP_VERSION, '5.3', '>=' );
	}

	/**
	 * Display all messages on admin board
	 *
	 * @return void
	 */
	public static function admin_notices() {
		foreach ( self::$messages as $message ) {
			echo wp_kses_post( $message );
		}
	}

	/**
	 * Return active instance of WP_Stream, create one if it doesn't exist
	 *
	 * @return WP_Stream
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			$class = __CLASS__;
			self::$instance = new $class;
		}

		return self::$instance;
	}

}

if ( WP_Stream::is_valid_php_version() ) {
	$GLOBALS['wp_stream'] = WP_Stream::get_instance();
	register_activation_hook( __FILE__, array( 'WP_Stream', 'install' ) );
} else {
	WP_Stream::fail_php_version();
}
