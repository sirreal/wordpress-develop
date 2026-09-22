<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template that has the correct structure and attribute order.
	// The placeholder text 'x' in figcaption gives us a #text token to replace.
	$html = '<figure><img src="" alt=""><figcaption>x</figcaption></figure>';

	$processor = new WP_HTML_Tag_Processor( $html );

	// Find the img tag and set the src and alt attributes (in order: src first, then alt).
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	// Now scan tokens to find the #text node inside the figcaption.
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_name() ) {
			// This is the placeholder text inside figcaption; replace it with the caption.
			$processor->set_modifiable_text( $caption );
			break;
		}
	}

	return $processor->get_updated_html();
}
