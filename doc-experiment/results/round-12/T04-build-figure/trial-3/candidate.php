<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a processor with a template containing the desired markup shape
	// with placeholder attributes and text
	$processor = new WP_HTML_Tag_Processor( '<figure><img src="" alt=""><figcaption>.</figcaption></figure>' );

	// Find and update the img tag
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	// Find and update the figcaption text
	$processor = new WP_HTML_Tag_Processor( $processor->get_updated_html() );
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}

	return $processor->get_updated_html();
}
