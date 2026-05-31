#!/usr/bin/env php
<?php
require_once __DIR__ . '/bootstrap-html-api.php';
$html = file_get_contents( dirname( __DIR__ ) . '/bench-html-api/tests/benchmarks/data/html-standard.html' );
$p    = WP_HTML_Processor::create_full_parser( $html );
while ( $p->next_token() ) {
}
