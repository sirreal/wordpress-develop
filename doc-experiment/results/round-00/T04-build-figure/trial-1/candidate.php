<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template containing placeholder text in the figcaption.
	// The img has src before alt to satisfy the required attribute order.
	$html = '<figure><img src="" alt=""><figcaption>x</figcaption></figure>';

	$processor = new WP_HTML_Tag_Processor( $html );

	// Find the img tag and set src and alt attributes (handles HTML encoding).
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	// Scan through tokens to find the #text node inside figcaption.
	// next_token() visits all tokens including text nodes.
	$in_figcaption = false;
	while ( $processor->next_token() ) {
		$token_name = $processor->get_token_name();

		if ( 'FIGCAPTION' === $token_name && ! $processor->is_tag_closer() ) {
			$in_figcaption = true;
			continue;
		}

		if ( $in_figcaption && '#text' === $token_name ) {
			// set_modifiable_text handles HTML encoding for text nodes.
			$processor->set_modifiable_text( $caption );
			break;
		}
	}

	return $processor->get_updated_html();
}
