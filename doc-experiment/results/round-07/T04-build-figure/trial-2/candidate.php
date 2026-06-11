<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed, including placeholder text
	// Attributes are pre-declared to maintain order: src, then alt
	$processor = new WP_HTML_Tag_Processor(
		'<figure><img src="" alt=""><figcaption>.</figcaption></figure>'
	);
	
	// Set the src attribute on the img tag
	$processor->next_tag( 'img' );
	$processor->set_attribute( 'src', $url );
	$processor->set_attribute( 'alt', $alt );
	
	// Find and replace the placeholder text in figcaption with the caption
	$processor = new WP_HTML_Tag_Processor( $processor->get_updated_html() );
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	return $processor->get_updated_html();
}
