<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed
	// Include empty placeholder values and text so that updates preserve order
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a Tag Processor with the template
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find the img tag and set its attributes in the required order
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find the figcaption tag and update its text content
	if ( $processor->next_tag( array( 'tag_name' => 'figcaption' ) ) ) {
		// Use next_token to find the text node inside figcaption
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
				break;
			}
		}
	}
	
	return $processor->get_updated_html();
}
