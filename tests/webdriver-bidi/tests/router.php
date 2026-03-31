<?php
$aliases = array(
	'/qunit.css' => array(
		'file'         => realpath( __DIR__ . '/../../../node_modules/qunit/qunit/qunit.css' ),
		'content-type' => 'text/css',
	),
	'/qunit.js'  => array(
		'file'         => realpath( __DIR__ . '/../../../node_modules/qunit/qunit/qunit.js' ),
		'content-type' => 'application/javascript',

	),
);

$uri = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
// var_dump( $_SERVER['REQUEST_URI'], $uri );

if ( isset( $aliases[ $uri ] ) ) {
	$alias = $aliases[ $uri ] ?? null;
	if ( ! $alias ) {
		http_response_code( 404 );
		echo 'Not found';
		return true;
	}
	header( 'Content-Type: ' . $alias['content-type'] );
	readfile( $alias['file'] );
	return true;
}

return false;
