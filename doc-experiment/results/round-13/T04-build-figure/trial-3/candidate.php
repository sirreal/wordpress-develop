<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed.
	// Include placeholder attribute values and text to ensure they're present for modification.
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and modify the img tag
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and modify the figcaption text
	if ( $processor->next_tag( array( 'tag_name' => 'figcaption' ) ) ) {
		// Move to the text node inside figcaption
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
				break;
			}
		}
	}
	
	return $processor->get_updated_html();
}
