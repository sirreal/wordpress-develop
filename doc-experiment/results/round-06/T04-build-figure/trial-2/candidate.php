<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure and attributes in the required order.
	// Include placeholder text in figcaption so we can set its content.
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a processor to work with the template.
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and modify the img tag.
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and replace the figcaption text.
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the processed HTML with all values properly encoded.
	return $processor->get_updated_html();
}
