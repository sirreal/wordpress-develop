<?php
/**
 * Executes one test case in an isolated process.
 *
 * Reads a JSON object from stdin:
 *   { "candidate_file": "/abs/path.php", "function": "fn_name", "args": [...] }
 *
 * Writes a JSON object to stdout:
 *   { "status": "ok"|"error", "result": <value>, "error": null|string,
 *     "doing_it_wrong": [...], "trigger_error": [...] }
 *
 * Process isolation means parse errors, fatal errors, and infinite loops
 * in candidate code cannot take down the test orchestrator.
 */

require __DIR__ . '/bootstrap.php';

$spec = json_decode( stream_get_contents( STDIN ), true );
if ( ! is_array( $spec ) || ! isset( $spec['candidate_file'], $spec['function'], $spec['args'] ) ) {
	fwrite( STDERR, "Invalid case spec on stdin.\n" );
	exit( 2 );
}

$out = array(
	'status'         => 'ok',
	'result'         => null,
	'error'          => null,
	'doing_it_wrong' => array(),
	'trigger_error'  => array(),
);

try {
	require $spec['candidate_file'];

	if ( ! function_exists( $spec['function'] ) ) {
		$out['status'] = 'error';
		$out['error']  = "Candidate file does not define function '{$spec['function']}'.";
	} else {
		$out['result'] = call_user_func_array( $spec['function'], $spec['args'] );
	}
} catch ( \Throwable $e ) {
	$out['status'] = 'error';
	$out['error']  = get_class( $e ) . ': ' . $e->getMessage();
}

$out['doing_it_wrong'] = $GLOBALS['harness_doing_it_wrong'];
$out['trigger_error']  = $GLOBALS['harness_trigger_error'];

echo json_encode( $out, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
