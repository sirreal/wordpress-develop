<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template containing the exact structure needed
	$processor = new WP_HTML_Tag_Processor( '<figure><img src="" alt=""><figcaption>.</figcaption></figure>' );
	
	// Find the img tag and set its attributes
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find the figcaption text node and set its text
	if ( $processor->next_tag( array( 'tag_name' => 'figcaption' ) ) ) {
		// Move to the next token (should be the text node)
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
				break;
			}
		}
	}
	
	return $processor->get_updated_html();
}
