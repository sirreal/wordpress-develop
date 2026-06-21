<?php
require_once __DIR__ . '/../lib/autoload.php';

\ComponentFuzz\WpBootstrap::load();

$required_functions = array(
	'wp_kses_post',
	'wp_kses_bad_protocol',
	'safecss_filter_attr',
	'esc_url',
	'wp_parse_url',
	'wp_normalize_path',
	'validate_file',
	'sanitize_file_name',
	'wp_check_filetype',
	'parse_blocks',
	'serialize_blocks',
	'shortcode_parse_atts',
	'force_balance_tags',
	'sanitize_title_with_dashes',
	'sanitize_post_field',
	'sanitize_term_field',
	'maybe_serialize',
	'maybe_unserialize',
	'rest_validate_value_from_schema',
	'rest_sanitize_value_from_schema',
	'sanitize_user',
	'sanitize_email',
	'is_email',
	'wp_generate_password',
	'wp_hash_password',
	'wp_check_password',
	'wp_interactivity_process_directives',
	'wp_interactivity_state',
	'wp_interactivity_config',
	'wp_interactivity_data_wp_context',
	'get_self_link',
	'wp_register_ability',
	'wp_get_abilities',
);

$required_classes = array(
	'WP_HTML_Processor',
	'WP_HTML_Tag_Processor',
	'WP_Interactivity_API',
	'WP_Interactivity_API_Directives_Processor',
	'WP_Block_Parser',
	'WP_REST_Request',
	'WP_REST_Server',
	'WP_REST_Response',
	'WP_Date_Query',
	'WP_Ability',
	'WP_Abilities_Registry',
	'WP_Ability_Category',
	'WP_Ability_Categories_Registry',
);

$missing = array();
foreach ( $required_functions as $function ) {
	if ( ! function_exists( $function ) ) {
		$missing[] = "function {$function}";
	}
}
foreach ( $required_classes as $class ) {
	if ( ! class_exists( $class ) ) {
		$missing[] = "class {$class}";
	}
}

if ( $missing ) {
	fwrite( STDERR, "Missing bootstrap symbols:\n- " . implode( "\n- ", $missing ) . "\n" );
	exit( 1 );
}

$sample_html = wp_kses_post( '<script>alert(1)</script><p onclick="x">ok</p>' );
if ( false !== stripos( $sample_html, '<script' ) || false !== stripos( $sample_html, 'onclick' ) ) {
	fwrite( STDERR, "KSES smoke invariant failed: {$sample_html}\n" );
	exit( 1 );
}

$request = new WP_REST_Request( 'POST', '/component-fuzz/v1/item/123' );
$request->set_query_params( array( 'id' => 'query' ) );
$request->set_body_params( array( 'id' => 'body' ) );
if ( 'body' !== $request->get_param( 'id' ) ) {
	fwrite( STDERR, "REST request precedence smoke invariant failed.\n" );
	exit( 1 );
}

$blocks = parse_blocks( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' );
if ( 1 !== count( $blocks ) || 'core/paragraph' !== $blocks[0]['blockName'] ) {
	fwrite( STDERR, "Block parser smoke invariant failed.\n" );
	exit( 1 );
}

$hash = wp_hash_password( 'component-fuzz' );
if ( ! wp_check_password( 'component-fuzz', $hash ) ) {
	fwrite( STDERR, "Password hash smoke invariant failed.\n" );
	exit( 1 );
}

$interactivity = new WP_Interactivity_API();
$interactivity->state( 'component-fuzz', array( 'text' => 'ok' ) );
$processed = $interactivity->process_directives( '<div data-wp-interactive="component-fuzz"><span data-wp-text="state.text">x</span></div>' );
if ( ! str_contains( $processed, '>ok</span>' ) ) {
	fwrite( STDERR, "Interactivity smoke invariant failed: {$processed}\n" );
	exit( 1 );
}

$_SERVER['REQUEST_URI'] = '/component-fuzz/router-smoke?x=1';
$router = new WP_Interactivity_API();
$GLOBALS['wp_interactivity'] = $router;
$router_region = wp_interactivity_process_directives( '<main data-wp-interactive="core/router" data-wp-router-region>body</main>' );
$router_state  = wp_interactivity_state( 'core/router' );
if (
	! str_contains( $router_region, 'data-wp-router-region' )
	|| 'http://example.test/component-fuzz/router-smoke?x=1' !== ( $router_state['url'] ?? null )
	|| false === has_action( 'wp_footer', array( $router, 'print_router_markup' ) )
) {
	fwrite( STDERR, "Interactivity router smoke invariant failed: {$router_region}\n" );
	exit( 1 );
}

fwrite( STDOUT, "component-fuzz bootstrap smoke passed\n" );
