<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the required structure
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a processor from the template
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and populate the img tag
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and populate the figcaption text
	if ( $processor->next_tag( array( 'tag_name' => 'figcaption' ) ) ) {
		// Move to the text node inside figcaption
		$processor->next_token();
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
		}
	}
	
	// Return the updated HTML
	return $processor->get_updated_html();
}
