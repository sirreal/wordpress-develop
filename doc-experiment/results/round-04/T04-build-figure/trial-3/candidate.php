<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create initial HTML structure with placeholders
	$html = '<figure><img src="" alt=""><figcaption></figcaption></figure>';
	
	// Create processor to set attributes
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find and set the img tag attributes
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Get the updated HTML with escaped attributes
	$html = $processor->get_updated_html();
	
	// Now set the figcaption text
	$processor = new WP_HTML_Tag_Processor( $html );
	if ( $processor->next_tag( 'figcaption' ) ) {
		$processor->set_modifiable_text( $caption );
	}
	
	return $processor->get_updated_html();
}
