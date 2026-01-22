<?php
/**
 * Installs WordPress for running the tests and loads WordPress and the test libraries
 */

if ( defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	$config_file_path = WP_TESTS_CONFIG_FILE_PATH;
} else {
	$config_file_path = dirname( __DIR__ );
	if ( ! file_exists( $config_file_path . '/wp-tests-config.php' ) ) {
		// Support the config file from the root of the develop repository.
		if ( basename( $config_file_path ) === 'phpunit' && basename( dirname( $config_file_path ) ) === 'tests' ) {
			$config_file_path = dirname( $config_file_path, 2 );
		}
	}
	$config_file_path .= '/wp-tests-config.php';
}

/*
 * Globalize some WordPress variables, because PHPUnit loads this file inside a function.
 * See: https://github.com/sebastianbergmann/phpunit/issues/325
 */
global $wpdb, $current_site, $current_blog, $wp_rewrite, $shortcode_tags, $wp, $phpmailer, $wp_theme_directories;

if ( ! is_readable( $config_file_path ) ) {
	echo 'Error: wp-tests-config.php is missing! Please use wp-tests-config-sample.php to create a config file.' . PHP_EOL;
	exit( 1 );
}

require_once $config_file_path;
require_once __DIR__ . '/functions.php';

if ( defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS && ! is_dir( ABSPATH ) ) {
	if ( substr( ABSPATH, -7 ) !== '/build/' ) {
		printf(
			'Error: The ABSPATH constant in the `wp-tests-config.php` file is set to a non-existent path "%s". Please verify.' . PHP_EOL,
			ABSPATH
		);
		exit( 1 );
	} else {
		echo 'Error: The PHPUnit tests should be run on the /src/ directory, not the /build/ directory.'
			. ' Please update the ABSPATH constant in your `wp-tests-config.php` file to `dirname( __FILE__ ) . \'/src/\'`'
			. ' or run `npm run build` prior to running PHPUnit.' . PHP_EOL;
		exit( 1 );
	}
}

// If running core tests, check if all the required PHP extensions are loaded before running the test suite.
if ( defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS ) {
	$required_extensions = array(
		'gd',
	);
	$missing_extensions  = array();

	foreach ( $required_extensions as $extension ) {
		if ( ! extension_loaded( $extension ) ) {
			$missing_extensions[] = $extension;
		}
	}

	if ( $missing_extensions ) {
		printf(
			'Error: The following required PHP extensions are missing from the testing environment: %s.' . PHP_EOL,
			implode( ', ', $missing_extensions )
		);
		echo 'Please make sure they are installed and enabled.' . PHP_EOL,
		exit( 1 );
	}
}

$required_constants = array(
	'WP_PHP_BINARY',
);
$missing_constants  = array();

foreach ( $required_constants as $constant ) {
	if ( ! defined( $constant ) ) {
		$missing_constants[] = $constant;
	}
}

if ( $missing_constants ) {
	printf(
		'Error: The following required constants are not defined: %s.' . PHP_EOL,
		implode( ', ', $missing_constants )
	);
	echo 'Please check out `wp-tests-config-sample.php` for an example.' . PHP_EOL,
	exit( 1 );
}

tests_reset__SERVER();

define( 'WP_TESTS_TABLE_PREFIX', $table_prefix );
define( 'DIR_TESTDATA', __DIR__ . '/../data' );
define( 'DIR_TESTROOT', realpath( dirname( __DIR__ ) ) );

define( 'WP_LANG_DIR', realpath( DIR_TESTDATA . '/languages' ) );

if ( defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS ) {
	define( 'WP_PLUGIN_DIR', realpath( DIR_TESTDATA . '/plugins' ) );
}

/*
 * Cron tries to make an HTTP request to the site, which always fails,
 * because tests are run in CLI mode only.
 */
define( 'DISABLE_WP_CRON', true );

define( 'WP_MEMORY_LIMIT', -1 );
define( 'WP_MAX_MEMORY_LIMIT', -1 );

$PHP_SELF            = '/index.php';
$GLOBALS['PHP_SELF'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

if ( ! defined( 'WP_DEFAULT_THEME' ) ) {
	define( 'WP_DEFAULT_THEME', 'default' );
}
$wp_theme_directories = array();

if ( file_exists( DIR_TESTDATA . '/themedir1' ) ) {
	$wp_theme_directories[] = DIR_TESTDATA . '/themedir1';
}

if ( '1' !== getenv( 'WP_TESTS_SKIP_INSTALL' ) ) {
	$core_tests = ( defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS ) ? 'run_core_tests' : 'no_core_tests';
	$ms_tests   = $multisite ? 'run_ms_tests' : 'no_ms_tests';

	system( WP_PHP_BINARY . ' ' . escapeshellarg( __DIR__ . '/install.php' ) . ' ' . escapeshellarg( $config_file_path ) . ' ' . $ms_tests . ' ' . $core_tests, $retval );
	if ( 0 !== $retval ) {
		exit( $retval );
	}
}

echo 'Running as single site...' . PHP_EOL;

// Load WordPress.
require_once ABSPATH . 'wp-settings.php';

// Override the PHPMailer.
require_once __DIR__ . '/mock-mailer.php';

$phpmailer = new MockPHPMailer( true );

// Delete any default posts & related data.
_delete_all_posts();
