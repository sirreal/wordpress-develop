<?php
/**
 * Runtime policy and provenance banner for the Code Reference preview.
 *
 * The build writes the provenance file this reads; see lib/snapshot.mjs.
 */

$wporg_docs_preview = require WP_CONTENT_DIR . '/docs-preview-provenance.php';

add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error(
			'docs_preview_network_disabled',
			'Outbound networking is disabled in the Code Reference preview.'
		);
	},
	PHP_INT_MAX
);

$wporg_docs_preview_banner = static function () use ( $wporg_docs_preview ) {
	static $rendered = false;

	if ( $rendered || is_admin() ) {
		return;
	}
	$rendered   = true;
	$commit_url = sprintf(
		'https://github.com/%s/commit/%s',
		$wporg_docs_preview['repository'],
		$wporg_docs_preview['sha']
	);
	?>
	<aside id="wporg-code-reference-preview-provenance" role="note" style="padding:12px 24px;background:#fff8c5;color:#1e1e1e;border-bottom:1px solid #dba617">
		<strong><?php echo esc_html__( 'Code Reference preview', 'wporg' ); ?></strong>
		<?php
		printf(
			/* translators: 1: source repository, 2: commit SHA, 3: UTC generation time. */
			esc_html__( 'Generated from %1$s at %2$s on %3$s UTC.', 'wporg' ),
			esc_html( $wporg_docs_preview['repository'] ),
			'<a href="' . esc_url( $commit_url ) . '"><code>' . esc_html( $wporg_docs_preview['sha'] ) . '</code></a>',
			esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $wporg_docs_preview['generated'] ) ) )
		);
		if ( $wporg_docs_preview['runUrl'] ) {
			printf(
				' <a href="%s">%s</a>',
				esc_url( $wporg_docs_preview['runUrl'] ),
				esc_html__( 'Build run', 'wporg' )
			);
		}
		?>
	</aside>
	<?php
};

// Themes that do not call wp_body_open still render the banner.
add_action( 'wp_body_open', $wporg_docs_preview_banner, 0 );
add_action( 'wp_footer', $wporg_docs_preview_banner, PHP_INT_MAX );

/*
 * The build validates the finished snapshot through this route: it reports the
 * completed import and the runtime policy the site actually enforces.
 */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'docs-preview/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$marker_file = WP_CONTENT_DIR . '/docs-preview-import.json';
					$network     = wp_remote_get( 'https://api.wordpress.org/' );

					return array(
						'import'                  => file_exists( $marker_file )
							? json_decode( file_get_contents( $marker_file ), true )
							: null,
						'outboundNetworkDisabled' => is_wp_error( $network ) && 'docs_preview_network_disabled' === $network->get_error_code(),
						'constants'               => array(
							'DISABLE_WP_CRON'            => defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON,
							'AUTOMATIC_UPDATER_DISABLED' => defined( 'AUTOMATIC_UPDATER_DISABLED' ) && true === AUTOMATIC_UPDATER_DISABLED,
							'WP_AUTO_UPDATE_CORE'        => defined( 'WP_AUTO_UPDATE_CORE' ) && false === WP_AUTO_UPDATE_CORE,
							'DISALLOW_FILE_MODS'         => defined( 'DISALLOW_FILE_MODS' ) && true === DISALLOW_FILE_MODS,
						),
					);
				},
			)
		);
	}
);
