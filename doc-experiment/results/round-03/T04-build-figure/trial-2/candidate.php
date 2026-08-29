<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with correct attribute order (src before alt)
	// Include a space in figcaption to ensure there's a text node
	$html = '<figure><img src="" alt=""><figcaption> </figcaption></figure>';
	
	// Create a processor to handle HTML encoding
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find and set the img tag attributes
	// Since both src and alt exist in the template, they will preserve their order
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Continue from after the img tag to find figcaption
	if ( $processor->next_tag( 'figcaption' ) ) {
		// The next token should be the text node inside figcaption
		if ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
			}
		}
	}
	
	// Get the updated HTML with all modifications applied
	$result = $processor->get_updated_html();
	return $result;
}
