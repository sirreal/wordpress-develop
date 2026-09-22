<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template containing all required elements with placeholders
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a processor with the template
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and set the img tag attributes
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find the figcaption's text node and set its content
	if ( $processor->next_tag( 'figcaption' ) ) {
		// Scan to the next token to find the text node inside figcaption
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
				break;
			}
		}
	}
	
	return $processor->get_updated_html();
}
