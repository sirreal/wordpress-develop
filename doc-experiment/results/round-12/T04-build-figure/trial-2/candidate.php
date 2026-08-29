<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the correct structure and attribute order
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Use the Tag Processor to safely fill in the values
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and update the img tag
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and update the figcaption text
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
