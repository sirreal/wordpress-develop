<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a fixed template so attribute order (src before alt) is preserved
	// and there is always a text node inside figcaption to update.
	$html = '<figure><img src="" alt=""><figcaption>x</figcaption></figure>';

	$processor = new WP_HTML_Tag_Processor( $html );

	// Find the img tag and set both attributes (updates in-place, preserving order).
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	// Advance to the figcaption opening tag token.
	$processor->next_token(); // <figcaption>

	// Advance to the text node inside figcaption.
	$processor->next_token(); // text node "x"

	// Replace the placeholder text with the caption (handles all HTML encoding).
	$processor->set_modifiable_text( $caption );

	return $processor->get_updated_html();
}
