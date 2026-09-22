<?php

function can_normalize_fragment( string $html ): bool {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return false;
	}
	
	try {
		$result = $processor->serialize();
		return null !== $result;
	} catch ( WP_HTML_Unsupported_Exception $e ) {
		return false;
	}
}
