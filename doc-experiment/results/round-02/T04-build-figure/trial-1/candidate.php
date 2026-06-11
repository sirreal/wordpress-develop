<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a minimal figure structure
	// Using a placeholder in figcaption ensures there's a text node to modify
	$html = '<figure><img src=""><figcaption> </figcaption></figure>';
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find img tag and set attributes in the required order: src, then alt
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Get the HTML after img updates
	$html = $processor->get_updated_html();
	
	// Create a new processor to update figcaption text
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor->next_tag( 'figcaption' ) ) {
		// Move to the next token (the text node inside figcaption)
		if ( $processor->next_token() ) {
			// Ensure we're on a text node
			if ( '#text' === $processor->get_token_type() ) {
				// Replace text content using the HTML API's escaping
				$processor->set_modifiable_text( $caption );
			}
		}
	}
	
	// Return the final processed HTML
	return $processor->get_updated_html();
}
