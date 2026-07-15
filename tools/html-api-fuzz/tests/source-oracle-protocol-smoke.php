#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_source_protocol_fail( string $message ): void {
	throw new RuntimeException( $message );
}

function html_api_fuzz_source_protocol_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_source_protocol_fail( $message );
	}
}

function html_api_fuzz_source_protocol_locked_package( string $lock, string $name ): array {
	preg_match_all( '/\[\[package\]\]\s*(.*?)(?=\n\[\[package\]\]|\z)/s', $lock, $packages );
	foreach ( $packages[1] as $package ) {
		if ( ! preg_match( '/^name\s*=\s*"([^\"]+)"/m', $package, $package_name ) || $name !== $package_name[1] ) {
			continue;
		}
		preg_match( '/^version\s*=\s*"([^\"]+)"/m', $package, $version );
		preg_match( '/^checksum\s*=\s*"([^\"]+)"/m', $package, $checksum );
		return array(
			'version'  => $version[1] ?? null,
			'checksum' => $checksum[1] ?? null,
		);
	}
	html_api_fuzz_source_protocol_fail( "Could not find {$name} in Cargo.lock." );
}

function html_api_fuzz_source_protocol_fake(
	string $directory,
	array $version_result,
	array $render_result,
	int $version_exit = 0,
	int $render_exit = 0
): string {
	static $counter = 0;
	++$counter;
	$path = $directory . '/fake-source-oracle-' . $counter . '.php';
	$version_json = base64_encode( json_encode( $version_result, JSON_THROW_ON_ERROR ) . "\n" );
	$render_json = base64_encode( json_encode( $render_result, JSON_THROW_ON_ERROR ) . "\n" );
	$script = "#!/usr/bin/env php\n<?php\n";
	$script .= "\$version = base64_decode( '{$version_json}' );\n";
	$script .= "\$render = base64_decode( '{$render_json}' );\n";
	$script .= "if ( in_array( '--version', \$argv, true ) ) { echo \$version; exit( {$version_exit} ); }\n";
	$script .= "echo \$render;\nexit( {$render_exit} );\n";
	if ( false === file_put_contents( $path, $script ) || ! chmod( $path, 0700 ) ) {
		html_api_fuzz_source_protocol_fail( 'Could not create a fake source oracle.' );
	}
	return $path;
}

function html_api_fuzz_source_protocol_renderer( string $kind, string $binary ): \HtmlApiFuzz\OracleRenderer {
	$options = array( 'dom-oracle' => $kind );
	if ( \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE === $kind ) {
		$options['lexbor-oracle-bin'] = $binary;
	} else {
		$options['html5ever-oracle-bin'] = $binary;
	}
	return \HtmlApiFuzz\OracleRenderer::from_options( $options );
}

function html_api_fuzz_source_protocol_version( array $oracle, string $status = 'ok' ): array {
	return array(
		'status' => $status,
		'oracle' => $oracle,
	);
}

function html_api_fuzz_source_protocol_render( array $oracle, array $fields = array() ): array {
	return array_merge(
		array(
			'status'     => 'ok',
			'oracle'     => $oracle,
			'nodeCount'  => 1,
			'tree'       => "\"x\"\n\n",
			'treeBase64' => base64_encode( "\"x\"\n\n" ),
		),
		$fields
	);
}

function html_api_fuzz_source_protocol_metadata_case(
	string $directory,
	string $kind,
	array $version,
	bool $available,
	string $message,
	int $version_exit = 0
): void {
	$binary = html_api_fuzz_source_protocol_fake( $directory, $version, array(), $version_exit );
	$metadata = html_api_fuzz_source_protocol_renderer( $kind, $binary )->metadata();
	html_api_fuzz_source_protocol_assert( $available === ( $metadata['available'] ?? false ), $message );
}

function html_api_fuzz_source_protocol_render_case(
	string $directory,
	array $oracle,
	array $render,
	string $expected_status,
	string $expected_failure_class,
	string $message,
	int $render_exit = 0
): array {
	$version = html_api_fuzz_source_protocol_version( $oracle );
	$binary = html_api_fuzz_source_protocol_fake( $directory, $version, $render, 0, $render_exit );
	$result = html_api_fuzz_source_protocol_renderer( \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE, $binary )->render(
		'x',
		\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		array( 'maxNodes' => 20 ),
		'body'
	);
	html_api_fuzz_source_protocol_assert( $expected_status === ( $result['status'] ?? null ), $message . ' status' );
	html_api_fuzz_source_protocol_assert( $expected_failure_class === ( $result['failureClass'] ?? '' ), $message . ' failure class' );
	return $result;
}

$root = \HtmlApiFuzz\repo_root();
$work_dir = sys_get_temp_dir() . '/html-api-fuzz-source-protocol-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );
$failure = null;

try {
	$lexbor_oracle = array(
		'kind'          => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'available'     => true,
		'lexborCommit'  => trim( file_get_contents( $root . '/tools/html-api-fuzz/oracles/lexbor/COMMIT' ) ),
		'lexborVersion' => 'test-version',
	);
	$valid_lexbor_version = html_api_fuzz_source_protocol_version( $lexbor_oracle );
	html_api_fuzz_source_protocol_metadata_case( $work_dir, \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE, $valid_lexbor_version, true, 'Expected valid Lexbor identity to be available.' );

	$lexbor_mutations = array(
		'wrong kind'              => array( 'kind' => 'html5ever-source' ),
		'missing kind'            => array( 'kind' => null ),
		'available false'         => array( 'available' => false ),
		'missing available'       => array( 'available' => null ),
		'wrong commit'            => array( 'lexborCommit' => str_repeat( '0', 40 ) ),
		'missing version'         => array( 'lexborVersion' => null ),
		'empty version'           => array( 'lexborVersion' => '' ),
		'non-string version'      => array( 'lexborVersion' => 310 ),
	);
	foreach ( $lexbor_mutations as $label => $mutation ) {
		$oracle = $lexbor_oracle;
		$key = array_key_first( $mutation );
		if ( null === $mutation[ $key ] ) {
			unset( $oracle[ $key ] );
		} else {
			$oracle[ $key ] = $mutation[ $key ];
		}
		html_api_fuzz_source_protocol_metadata_case(
			$work_dir,
			\HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
			html_api_fuzz_source_protocol_version( $oracle ),
			false,
			"Expected Lexbor metadata to reject {$label}."
		);
	}
	html_api_fuzz_source_protocol_metadata_case(
		$work_dir,
		\HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		html_api_fuzz_source_protocol_version( $lexbor_oracle, 'error' ),
		false,
		'Expected Lexbor metadata to reject a non-ok version status.'
	);
	html_api_fuzz_source_protocol_metadata_case(
		$work_dir,
		\HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		$valid_lexbor_version,
		false,
		'Expected Lexbor metadata to reject a nonzero version exit.',
		3
	);

	$lock = file_get_contents( $root . '/tools/html-api-fuzz/oracles/html5ever/Cargo.lock' );
	$html5ever = html_api_fuzz_source_protocol_locked_package( $lock, 'html5ever' );
	$rcdom = html_api_fuzz_source_protocol_locked_package( $lock, 'markup5ever_rcdom' );
	$toolchain = file_get_contents( $root . '/tools/html-api-fuzz/oracles/html5ever/rust-toolchain.toml' );
	preg_match( '/^channel\s*=\s*"([^\"]+)"/m', $toolchain, $toolchain_match );
	$html5ever_oracle = array(
		'kind'                       => \HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
		'available'                  => true,
		'html5everVersion'           => $html5ever['version'],
		'html5everChecksum'          => $html5ever['checksum'],
		'markup5everRcdomVersion'    => $rcdom['version'],
		'markup5everRcdomChecksum'   => $rcdom['checksum'],
		'rustToolchain'               => $toolchain_match[1] ?? null,
	);
	html_api_fuzz_source_protocol_metadata_case(
		$work_dir,
		\HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
		html_api_fuzz_source_protocol_version( $html5ever_oracle ),
		true,
		'Expected valid html5ever identity to be available.'
	);
	foreach ( array( 'html5everVersion', 'html5everChecksum', 'markup5everRcdomVersion', 'markup5everRcdomChecksum', 'rustToolchain' ) as $field ) {
		$wrong = $html5ever_oracle;
		$wrong[ $field ] = 'wrong';
		html_api_fuzz_source_protocol_metadata_case(
			$work_dir,
			\HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
			html_api_fuzz_source_protocol_version( $wrong ),
			false,
			"Expected html5ever metadata to reject wrong {$field}."
		);
		$missing = $html5ever_oracle;
		unset( $missing[ $field ] );
		html_api_fuzz_source_protocol_metadata_case(
			$work_dir,
			\HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
			html_api_fuzz_source_protocol_version( $missing ),
			false,
			"Expected html5ever metadata to reject missing {$field}."
		);
	}

	$valid_render = html_api_fuzz_source_protocol_render( $lexbor_oracle );
	$valid = html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $valid_render, 'ok', '', 'Expected a valid ok result.' );
	html_api_fuzz_source_protocol_assert( "\"x\"\n\n" === ( $valid['tree'] ?? null ), 'Expected valid treeBase64 to decode.' );

	$unsupported = html_api_fuzz_source_protocol_render(
		$lexbor_oracle,
		array(
			'status'       => 'unsupported',
			'failureClass' => 'oracle-unsupported',
			'unsupported'  => array( 'message' => 'not supported' ),
		)
	);
	$unsupported_result = html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $unsupported, 'unsupported', 'oracle-unsupported', 'Expected a well-formed unsupported result.' );
	html_api_fuzz_source_protocol_assert( 'not supported' === ( $unsupported_result['unsupported']['message'] ?? null ), 'Expected unsupported details to survive.' );

	foreach ( array( 'oracle-parse-error', 'node-limit-exceeded', 'oracle-renderer-error' ) as $failure_class ) {
		$semantic_error = html_api_fuzz_source_protocol_render(
			$lexbor_oracle,
			array(
				'status'       => 'error',
				'failureClass' => $failure_class,
				'error'        => 'semantic ' . $failure_class,
			)
		);
		$semantic_result = html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $semantic_error, 'error', $failure_class, "Expected allowed semantic error {$failure_class}." );
		html_api_fuzz_source_protocol_assert( 'semantic ' . $failure_class === ( $semantic_result['error'] ?? null ), "Expected {$failure_class} details to survive." );
		html_api_fuzz_source_protocol_assert( 0 === ( $semantic_result['process']['code'] ?? null ), "Expected {$failure_class} to exit zero." );
	}
	$semantic_error = html_api_fuzz_source_protocol_render(
		$lexbor_oracle,
		array(
			'status'       => 'error',
			'failureClass' => 'node-limit-exceeded',
			'error'        => 'limit',
		)
	);

	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $semantic_error, 'error', 'oracle-renderer-error', 'Expected a nonzero render exit to be a process failure.', 4 );
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, html_api_fuzz_source_protocol_render( $lexbor_oracle, array( 'status' => 'unknown' ) ), 'error', 'oracle-renderer-error', 'Expected an unknown status to fail.' );
	foreach ( array( 'unknown-error-class', 'oracle-cli-error' ) as $failure_class ) {
		$invalid_error_class = html_api_fuzz_source_protocol_render(
			$lexbor_oracle,
			array(
				'status'       => 'error',
				'failureClass' => $failure_class,
				'error'        => 'invalid class',
			)
		);
		html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $invalid_error_class, 'error', 'oracle-renderer-error', "Expected {$failure_class} to fail." );
	}
	$wrong_render_identity = $lexbor_oracle;
	$wrong_render_identity['lexborCommit'] = str_repeat( 'f', 40 );
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, html_api_fuzz_source_protocol_render( $wrong_render_identity ), 'error', 'oracle-renderer-error', 'Expected render identity mismatch to fail.' );
	$tree_without_base64 = html_api_fuzz_source_protocol_render( $lexbor_oracle );
	unset( $tree_without_base64['treeBase64'] );
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $tree_without_base64, 'error', 'oracle-renderer-error', 'Expected tree without treeBase64 to fail.' );
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, html_api_fuzz_source_protocol_render( $lexbor_oracle, array( 'treeBase64' => '***' ) ), 'error', 'oracle-renderer-error', 'Expected invalid treeBase64 to fail.' );
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, html_api_fuzz_source_protocol_render( $lexbor_oracle, array( 'tree' => 'different' ) ), 'error', 'oracle-renderer-error', 'Expected disagreeing trees to fail.' );
	foreach ( array( 'missing' => null, 'non-integer' => '1', 'negative' => -1 ) as $label => $node_count ) {
		$render = html_api_fuzz_source_protocol_render( $lexbor_oracle );
		if ( null === $node_count ) {
			unset( $render['nodeCount'] );
		} else {
			$render['nodeCount'] = $node_count;
		}
		html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $render, 'error', 'oracle-renderer-error', "Expected {$label} nodeCount to fail." );
	}
	$malformed_unsupported = $unsupported;
	$malformed_unsupported['failureClass'] = 'wrong';
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $malformed_unsupported, 'error', 'oracle-renderer-error', 'Expected malformed unsupported failureClass to fail.' );
	$malformed_unsupported = $unsupported;
	unset( $malformed_unsupported['unsupported']['message'] );
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $malformed_unsupported, 'error', 'oracle-renderer-error', 'Expected malformed unsupported details to fail.' );
	$malformed_error = $semantic_error;
	unset( $malformed_error['failureClass'] );
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $malformed_error, 'error', 'oracle-renderer-error', 'Expected a missing error failureClass to fail.' );
	$malformed_error = $semantic_error;
	unset( $malformed_error['error'] );
	html_api_fuzz_source_protocol_render_case( $work_dir, $lexbor_oracle, $malformed_error, 'error', 'oracle-renderer-error', 'Expected missing error details to fail.' );
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
}

if ( null !== $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}
if ( is_dir( $work_dir ) ) {
	fwrite( STDERR, "FAIL: Expected protocol smoke work directory cleanup.\n" );
	exit( 1 );
}
echo "OK source-oracle-protocol-smoke\n";
