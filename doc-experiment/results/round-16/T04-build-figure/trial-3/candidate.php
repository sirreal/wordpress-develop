<?php
function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Create a processor for the template
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find the img tag and set its attributes
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and replace the figcaption text
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	return $processor->get_updated_html();
}
