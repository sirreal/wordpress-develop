<?php

function can_normalize_fragment( string $html ): bool {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return false;
	}
	
	$normalized = $processor->serialize();
	
	if ( $normalized === null ) {
		return false;
	}
	
	return true;
}
