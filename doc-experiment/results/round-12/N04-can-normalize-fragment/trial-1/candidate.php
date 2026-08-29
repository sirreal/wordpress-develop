<?php
function can_normalize_fragment( string $html ): bool {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return false;
	}
	
	$serialized = $processor->serialize();
	
	return null !== $serialized;
}
