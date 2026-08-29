<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template that already has the correct structure and attribute order.
	// Using a placeholder text node inside figcaption so set_modifiable_text() can target it.
	$template = '<figure><img src="" alt=""><figcaption>x</figcaption></figure>';

	$processor = new WP_HTML_Tag_Processor( $template );

	// Find the img tag and set src then alt (preserving attribute order from template).
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	// Walk through tokens to find the text node inside figcaption.
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_name() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}

	return $processor->get_updated_html();
}
