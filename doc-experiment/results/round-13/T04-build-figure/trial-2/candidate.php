<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the correct HTML structure and attribute order
	// Use placeholder text in empty figcaption element to have a text node to modify
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create the processor with the template
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and modify the img tag
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and modify the figcaption text
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
