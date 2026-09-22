<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template that has the correct structure and attribute order.
	$html = '<figure><img src="" alt=""><figcaption>x</figcaption></figure>';

	$processor = new WP_HTML_Tag_Processor( $html );

	// Find the img tag and set src and alt attributes (preserving order).
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	// Get the updated HTML so far, then process it again to set the caption text.
	$html = $processor->get_updated_html();

	// Now scan for the text node inside figcaption to set its content.
	$processor2 = new WP_HTML_Tag_Processor( $html );

	// Skip past img, find figcaption opener, then find text node.
	while ( $processor2->next_token() ) {
		if ( '#text' === $processor2->get_token_name() ) {
			// Check if we've passed the figcaption opener; this text node
			// is the placeholder "x" inside figcaption.
			$processor2->set_modifiable_text( $caption );
			break;
		}
	}

	return $processor2->get_updated_html();
}
