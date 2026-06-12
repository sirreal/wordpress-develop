<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with placeholder attributes in the correct order and placeholder text
	$html = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a Tag Processor to modify the template
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the img tag and set its attributes
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find the text node inside figcaption and set its content
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the updated HTML
	return $processor->get_updated_html();
}
