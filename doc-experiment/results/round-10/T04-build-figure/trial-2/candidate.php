<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the expected structure, including empty attributes
	// to preserve their order and a placeholder for the figcaption text.
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a tag processor to modify the template.
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and modify the IMG tag.
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Set src and alt attributes in the correct order.
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and modify the figcaption text.
	// We need to scan through tokens to find the text node inside figcaption.
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			// Replace the placeholder text with the caption.
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the updated HTML.
	return $processor->get_updated_html();
}
