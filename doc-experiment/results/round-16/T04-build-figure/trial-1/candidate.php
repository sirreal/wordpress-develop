<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a processor with a figure template containing placeholders
	$processor = new WP_HTML_Tag_Processor( '<figure><img src="" alt=""><figcaption>.</figcaption></figure>' );
	
	// Set the img tag attributes
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Set the figcaption text
	if ( $processor->next_tag( 'figcaption' ) ) {
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
				break;
			}
		}
	}
	
	return $processor->get_updated_html();
}
