<?php
/**
 * Build-time policy for the Code Reference invariant base.
 *
 * Final runtime restrictions are installed only after the reference import.
 */

if ( ! defined( 'WPORG_DEVELOPER_PREVIEW' ) ) {
	define( 'WPORG_DEVELOPER_PREVIEW', true );
}

if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
	define( 'WP_ENVIRONMENT_TYPE', 'local' );
}

// The wporg parent theme renders its header and footer only when this is set.
if ( ! defined( 'FEATURE_2021_GLOBAL_HEADER_FOOTER' ) ) {
	define( 'FEATURE_2021_GLOBAL_HEADER_FOOTER', true );
}
