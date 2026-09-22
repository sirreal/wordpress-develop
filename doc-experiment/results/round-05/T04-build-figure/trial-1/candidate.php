<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact HTML structure needed.
	// The template includes empty attribute values to preserve order,
	// and placeholder text for elements that need text content.
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a processor to modify the template.
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find the img tag and set its attributes.
	if ( $processor->next_tag( 'img' ) ) {
		// set_attribute handles all necessary encoding.
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find the figcaption tag's text and replace it.
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			// set_modifiable_text handles all necessary encoding.
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the updated HTML.
	return $processor->get_updated_html();
}
