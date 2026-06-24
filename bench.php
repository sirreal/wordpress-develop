#!/usr/bin/env php
<?php
require_once __DIR__ . '/bootstrap-html-api.php';
$html = file_get_contents( dirname( __DIR__ ) . '/bench-html-api/tests/benchmarks/data/html-standard.html' );

$p = new WP_HTML_Tag_Processor( $html );
while ( $p->next_token() ) {
}
$p = new WP_HTML_Tag_Processor( $html );
while ( $p->next_token() ) {
}
$p = new WP_HTML_Tag_Processor( $html );
while ( $p->next_token() ) {
}
