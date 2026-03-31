#!/usr/bin/env php
<?php


declare(strict_types=1);
error_reporting( E_ALL );
set_error_handler(
	function ( int $severity, string $message, string $file, int $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

require_once __DIR__ . '/bootstrap.php';

// bidi-check-style.php

// --- Minimal WebSocket client (RFC 6455) ---

function ws_connect( string $url ): mixed {
	$parts = parse_url( $url );
	$host  = $parts['host'];
	$port  = $parts['port'] ?? 80;
	$path  = $parts['path'] ?? '/';
	if ( isset( $parts['query'] ) ) {
		$path .= '?' . $parts['query'];
	}

	$sock = stream_socket_client( "tcp://$host:$port", $errno, $errstr, 5 );
	if ( ! $sock ) {
		throw new RuntimeException( "Connect failed: $errstr ($errno)" );
	}

	$key = base64_encode( random_bytes( 16 ) );
	$req = "GET $path HTTP/1.1\r\n"
		. "Host: $host:$port\r\n"
		. "Upgrade: websocket\r\n"
		. "Connection: Upgrade\r\n"
		. "Sec-WebSocket-Key: $key\r\n"
		. "Sec-WebSocket-Version: 13\r\n"
		. "\r\n";

	fwrite( $sock, $req );

	// Read until end of headers
	$headers = '';
	while ( ! str_contains( $headers, "\r\n\r\n" ) ) {
		$headers .= fread( $sock, 1 );
	}

	if ( ! str_contains( $headers, '101' ) ) {
		throw new RuntimeException( "WebSocket upgrade failed:\n$headers" );
	}

	return $sock;
}

function ws_send( mixed $sock, string $text ): void {
	$len  = strlen( $text );
	$mask = random_bytes( 4 );

	// Opcode 0x1 (text), FIN bit set, masked
	$frame = chr( 0x81 );

	if ( $len < 126 ) {
		$frame .= chr( 0x80 | $len );
	} elseif ( $len < 65536 ) {
		$frame .= chr( 0x80 | 126 ) . pack( 'n', $len );
	} else {
		$frame .= chr( 0x80 | 127 ) . pack( 'J', $len );
	}

	$frame .= $mask;
	for ( $i = 0; $i < $len; $i++ ) {
		$frame .= $text[ $i ] ^ $mask[ $i % 4 ];
	}

	fwrite( $sock, $frame );
}

function ws_recv( mixed $sock ): string {
	$head = fread( $sock, 2 );
	$len  = ord( $head[1] ) & 0x7F;

	if ( $len === 126 ) {
		$len = unpack( 'n', fread( $sock, 2 ) )[1];
	} elseif ( $len === 127 ) {
		$len = unpack( 'J', fread( $sock, 8 ) )[1];
	}

	// Server frames are unmasked per spec
	$data = '';
	while ( strlen( $data ) < $len ) {
		$data .= fread( $sock, $len - strlen( $data ) );
	}

	return $data;
}

function ws_close( mixed $sock ): void {
	// Send close frame
	$mask = random_bytes( 4 );
	fwrite( $sock, chr( 0x88 ) . chr( 0x80 ) . $mask );
	fclose( $sock );
}

// --- BiDi helpers ---

$nextId = 0;

function bidi_send( mixed $ws, string $method, array $params = array() ): array {
	global $nextId;
	$id = ++$nextId;

	ws_send(
		$ws,
		json_encode(
			array(
				'id'     => $id,
				'method' => $method,
				'params' => (object) $params,
			)
		)
	);

	// Read messages until we get our response
	while ( true ) {
		$msg = json_decode( ws_recv( $ws ), true );
		if ( isset( $msg['id'] ) && $msg['id'] === $id ) {
			return $msg;
		}
		// else it's an event, ignore
	}
}

// --- Main ---

$browser = $argv[1] ?? 'chrome';
$configs = array(
	'chrome'  => array(
		'port'    => 9515,
		'options' => array( 'goog:chromeOptions' => array( 'args' => array( '--headless=new' ) ) ),
	),
	'firefox' => array(
		'port'    => 4444,
		'options' => array( 'moz:firefoxOptions' => array( 'args' => array( '-headless' ) ) ),
	),
);
$config  = $configs[ $browser ];

// Render test page from PHP file
ob_start();
require __DIR__ . '/pages/list-style.php';
$html = ob_get_clean();

// 1. Create session via classic HTTP
$ch = curl_init( "http://localhost:{$config['port']}/session" );
curl_setopt_array(
	$ch,
	array(
		CURLOPT_POST           => true,
		CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json; charset=UTF-8' ),
		CURLOPT_POSTFIELDS     => json_encode(
			array(
				'capabilities' => array(
					'alwaysMatch' => array_merge( array( 'webSocketUrl' => true ), $config['options'] ),
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
		),
		CURLOPT_RETURNTRANSFER => true,
	)
);
$result = json_decode( curl_exec( $ch ), true );

// Deprecated since 8.0
try {
	curl_close( $ch );
} catch ( ErrorException $e ) {
}

$wsUrl = $result['value']['capabilities']['webSocketUrl'];
echo "[{$browser}] BiDi: {$wsUrl}\n";

// 2. Connect BiDi WebSocket
$ws = ws_connect( $wsUrl );

// 3. Get context
$tree = bidi_send( $ws, 'browsingContext.getTree' );
$ctx  = $tree['result']['contexts'][0]['context'];

// 4. Inject rendered HTML into browser context.
// The HTML is produced by our own test PHP files, not user input.
// json_encode safely escapes it for embedding in JS.
$js_html = json_encode( $html );
bidi_send(
	$ws,
	'script.evaluate',
	array(
		'expression'   => "document.open(); document.write({$js_html}); document.close();",
		'target'       => array( 'context' => $ctx ),
		'awaitPromise' => false,
	)
);

// 5. Check inline style
$inline = bidi_send(
	$ws,
	'script.evaluate',
	array(
		'expression'   => "document.getElementById('target').style.listStyleType",
		'target'       => array( 'context' => $ctx ),
		'awaitPromise' => false,
	)
);
echo "[$browser] style.listStyleType = \"{$inline['result']['result']['value']}\"\n";

// 6. Check computed style
$computed = bidi_send(
	$ws,
	'script.evaluate',
	array(
		'expression'   => "getComputedStyle(document.getElementById('target')).listStyleType",
		'target'       => array( 'context' => $ctx ),
		'awaitPromise' => false,
	)
);
echo "[$browser] computed = \"{$computed['result']['result']['value']}\"\n";

// Cleanup
bidi_send( $ws, 'browser.close' );
ws_close( $ws );
