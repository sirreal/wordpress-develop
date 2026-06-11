<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Create a template with the exact structure needed, including empty values
	// for attributes and placeholder text for the figcaption
	$processor = new WP_HTML_Tag_Processor( '<figure><img src="" alt=""><figcaption>.</figcaption></figure>' );

	// Find and modify the img tag
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	// Find and modify the figcaption text
	if ( $processor->next_tag( 'figcaption' ) ) {
		// Need to find the text node inside the figcaption
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
				break;
			}
		}
	}

	return $processor->get_updated_html();
}
