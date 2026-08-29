<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed, including
	// placeholder text for the figcaption since empty elements have no text node
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a processor for the template
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and set attributes on the img tag
	if ( $processor->next_tag( 'img' ) ) {
		// Set attributes in the required order: src, then alt
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and replace the figcaption text
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			// This should be the placeholder text in figcaption
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the updated HTML
	return $processor->get_updated_html();
}
