<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a processor with the template
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find the img tag and set its attributes
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and set the figcaption text
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the updated HTML
	return $processor->get_updated_html();
}
