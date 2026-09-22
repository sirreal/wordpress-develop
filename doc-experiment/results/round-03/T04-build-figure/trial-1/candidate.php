<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a basic figure structure
	$html = '<figure><img><figcaption></figcaption></figure>';
	
	// Use WP_HTML_Tag_Processor to properly set attributes with escaping
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the img tag and set src and alt attributes in order
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Get the HTML after img modifications
	$html = $processor->get_updated_html();
	
	// Process the figcaption text
	$processor = new WP_HTML_Tag_Processor( $html );
	if ( $processor->next_tag( array( 'tag_name' => 'figcaption' ) ) ) {
		$processor->set_modifiable_text( $caption );
	}
	
	return $processor->get_updated_html();
}
