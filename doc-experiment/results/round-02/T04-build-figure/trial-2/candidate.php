<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a fragment parser with a figure element containing an img and figcaption
	$processor = WP_HTML_Processor::create_fragment( '<figure><img><figcaption></figcaption></figure>' );
	
	if ( ! $processor ) {
		return '';
	}
	
	// Find the img tag and set its attributes in the correct order: src, then alt
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find the figcaption tag and set its text content
	if ( $processor->next_tag( array( 'tag_name' => 'figcaption' ) ) ) {
		$processor->set_modifiable_text( $caption );
	}
	
	// Serialize and return the HTML
	$output = $processor->serialize();
	return $output ?: '';
}
