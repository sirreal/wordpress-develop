<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed, with placeholders
	// Include the attributes in the template so their order is preserved
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Use WP_HTML_Tag_Processor to modify the template
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and modify the img tag
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and modify the figcaption text
	// We need to use next_token() to find the text node inside figcaption
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	return $processor->get_updated_html();
}
