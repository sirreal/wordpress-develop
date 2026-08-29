<?php
function get_document_title( string $html ): ?string {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	if ( null === $processor ) {
		return null;
	}

	// Find the title element
	if ( $processor->next_tag( 'title' ) ) {
		// Get the modifiable text content of the title element
		// Character references are automatically decoded
		return $processor->get_modifiable_text();
	}

	return null;
}
?>