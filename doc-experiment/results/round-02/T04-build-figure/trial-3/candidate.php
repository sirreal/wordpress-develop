<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Build initial HTML structure with attributes in the required order: src, then alt
	// Include placeholder text in figcaption to be replaced
	$html = '<figure><img src="" alt=""><figcaption>placeholder</figcaption></figure>';
	
	// Use Tag Processor to set attribute values and text with proper encoding
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the img tag and set attribute values in the required order: src, then alt
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Get the updated HTML with properly encoded attribute values
	$html = $processor->get_updated_html();
	
	// Create a new processor to replace the text inside figcaption
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the figcaption tag
	if ( $processor->next_tag( 'figcaption' ) ) {
		// Advance to the next token to find the text node inside figcaption
		if ( $processor->next_token() && '#text' === $processor->get_token_name() ) {
			// Set the caption text with proper HTML encoding
			$processor->set_modifiable_text( $caption );
		}
	}
	
	// Return the complete HTML with all values properly encoded
	return $processor->get_updated_html();
}
