<?php
function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed, including placeholder values
	// Attributes are defined with empty values so they maintain their order when updated
	// Figcaption has a placeholder text node that will be replaced
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and update the img tag
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and update the figcaption text
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the updated HTML
	return $processor->get_updated_html();
}
