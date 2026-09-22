<?php

function build_figure( string $url, string $alt, string $caption ): string {
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	$processor = new WP_HTML_Tag_Processor( $template );
	
	// Find and modify the img tag
	if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and modify the figcaption text
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	return $processor->get_updated_html();
}
