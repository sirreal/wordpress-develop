#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_replay_compatibility_fail( string $message ): void {
	throw new RuntimeException( $message );
}

function html_api_fuzz_replay_compatibility_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_replay_compatibility_fail( $message );
	}
}

function html_api_fuzz_replay_compatibility_locked_package( string $lock, string $name ): array {
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
	html_api_fuzz_replay_compatibility_fail( "Could not find {$name} in Cargo.lock." );
}

function html_api_fuzz_replay_compatibility_fake_oracle( string $path, array $oracle ): void {
	$version = base64_encode(
		json_encode(
			array(
				'status' => 'ok',
				'oracle' => $oracle,
			),
			JSON_THROW_ON_ERROR
		) . "\n"
	);
	$render = base64_encode(
		json_encode(
			array(
				'status'     => 'ok',
				'oracle'     => $oracle,
				'nodeCount'  => 0,
				'tree'       => "\n",
				'treeBase64' => base64_encode( "\n" ),
			),
			JSON_THROW_ON_ERROR
		) . "\n"
	);
	$script = "#!/usr/bin/env php\n<?php\n";
	$script .= "if ( in_array( '--version', \$argv, true ) ) { echo base64_decode( '{$version}' ); exit( 0 ); }\n";
	$script .= "echo base64_decode( '{$render}' );\nexit( 0 );\n";
	if ( false === file_put_contents( $path, $script ) || ! chmod( $path, 0700 ) ) {
		html_api_fuzz_replay_compatibility_fail( 'Could not create the fake html5ever oracle.' );
	}
}

function html_api_fuzz_replay_compatibility_command(
	string $script,
	string $replay_path,
	string $output_dir,
	string $oracle_path,
	string $key
): void {
	$args = array(
		$script,
		'--replay',
		$replay_path,
		'--output-dir',
		$output_dir,
		'--dom-oracle',
		HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
		'--html5ever-oracle-bin',
		$oracle_path,
		'--oracle-timeout-ms',
		'1000',
	);
	if ( basename( $script ) === 'minimize.php' ) {
		$args[] = '--max-attempts';
		$args[] = '0';
	}
	$result = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), 5000 );
	$label = basename( $script );
	html_api_fuzz_replay_compatibility_assert( ! $result['timedOut'], "Expected {$label} {$key} mismatch check not to time out." );
	html_api_fuzz_replay_compatibility_assert( 0 !== $result['code'], "Expected {$label} to reject wrong {$key}." );
	html_api_fuzz_replay_compatibility_assert(
		false !== strpos( $result['output'], "Replay oracle mismatch for {$key}:" ),
		"Expected {$label} failure to name {$key}."
	);
}

$root = \HtmlApiFuzz\repo_root();
$work_dir = sys_get_temp_dir() . '/html-api-fuzz-replay-compatibility-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );
$failure = null;

try {
	$lock = file_get_contents( $root . '/tools/html-api-fuzz/oracles/html5ever/Cargo.lock' );
	$html5ever = html_api_fuzz_replay_compatibility_locked_package( $lock, 'html5ever' );
	$rcdom = html_api_fuzz_replay_compatibility_locked_package( $lock, 'markup5ever_rcdom' );
	$toolchain = file_get_contents( $root . '/tools/html-api-fuzz/oracles/html5ever/rust-toolchain.toml' );
	preg_match( '/^channel\s*=\s*"([^\"]+)"/m', $toolchain, $toolchain_match );
	$oracle = array(
		'kind'                      => \HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
		'available'                 => true,
		'html5everVersion'          => $html5ever['version'],
		'html5everChecksum'         => $html5ever['checksum'],
		'markup5everRcdomVersion'   => $rcdom['version'],
		'markup5everRcdomChecksum'  => $rcdom['checksum'],
		'rustToolchain'             => $toolchain_match[1] ?? null,
	);
	$oracle_path = $work_dir . '/fake-html5ever-oracle.php';
	html_api_fuzz_replay_compatibility_fake_oracle( $oracle_path, $oracle );
	$renderer = \HtmlApiFuzz\OracleRenderer::from_options(
		array(
			'dom-oracle'           => \HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
			'html5ever-oracle-bin' => $oracle_path,
		)
	);
	$current = $renderer->metadata();
	html_api_fuzz_replay_compatibility_assert( true === ( $current['available'] ?? false ), 'Expected the complete fake html5ever identity to be valid.' );

	$keys = array( 'markup5everRcdomVersion', 'markup5everRcdomChecksum', 'rustToolchain' );
	$legacy = $oracle;
	foreach ( $keys as $key ) {
		unset( $legacy[ $key ] );
	}
	$renderer->assert_replay_compatible( $legacy );
	$legacy_empty = $oracle;
	foreach ( $keys as $key ) {
		$legacy_empty[ $key ] = '';
	}
	$renderer->assert_replay_compatible( $legacy_empty );

	foreach ( $keys as $key ) {
		$recorded_oracle = $oracle;
		$recorded_oracle[ $key ] = 'wrong-' . $key;
		$replay = array(
			'inputBase64' => base64_encode( '<p>x' ),
			'mode'        => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
			'profile'     => 'replay-compatibility-smoke',
			'seed'        => 1,
			'fragmentContext' => 'body',
			'limits'      => array(
				'maxTokens' => 20,
				'maxNodes'  => 20,
			),
			'options'     => array(
				'domOracle'          => \HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
				'html5everOracleBin' => $oracle_path,
			),
			'oracle'      => $recorded_oracle,
			'signature'   => array( 'hash' => str_repeat( 'a', 64 ) ),
		);
		$replay_path = $work_dir . '/replay-' . $key . '.json';
		\HtmlApiFuzz\write_json_file( $replay_path, $replay );
		foreach ( array( 'replay.php', 'minimize.php' ) as $script_name ) {
			html_api_fuzz_replay_compatibility_command(
				$root . '/tools/html-api-fuzz/' . $script_name,
				$replay_path,
				$work_dir . '/output-' . $script_name . '-' . $key,
				$oracle_path,
				$key
			);
		}
	}
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
	fwrite( STDERR, "FAIL: Expected replay compatibility work directory cleanup.\n" );
	exit( 1 );
}
echo "OK replay-oracle-compatibility-smoke\n";
