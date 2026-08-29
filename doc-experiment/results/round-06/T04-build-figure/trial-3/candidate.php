<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed.
	// Include placeholder attributes (src, alt) in the correct order so they remain in that order.
	// Include placeholder text in figcaption so set_modifiable_text() can replace it.
	$processor = new WP_HTML_Tag_Processor( '<figure><img src="" alt=""><figcaption>.</figcaption></figure>' );
	
	// Find and modify the IMG tag
	$processor->next_tag( 'img' );
	$processor->set_attribute( 'src', $url );
	$processor->set_attribute( 'alt', $alt );
	
	// Find and modify the text in the FIGCAPTION tag
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	return $processor->get_updated_html();
}
