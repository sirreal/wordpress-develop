<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the required structure
	// Include a placeholder inside figcaption for text replacement
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and modify the img tag
	if ( $processor->next_tag( 'img' ) ) {
		// Set src and alt attributes in the required order
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and modify the figcaption text
	if ( $processor->next_tag( 'figcaption' ) ) {
		// Move to the next token which should be the text node
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
				break;
			}
		}
	}
	
	return $processor->get_updated_html();
}
