<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed.
	// Include placeholder text in figcaption so it can be replaced.
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	
	// Use WP_HTML_Tag_Processor to safely populate the template.
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and update the img tag's src attribute.
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and update the figcaption's text content.
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	return $processor->get_updated_html();
}
