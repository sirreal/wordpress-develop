<?php

function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template that already has the attributes in the required order (src, then alt)
	// and a placeholder text node inside figcaption so set_modifiable_text() can replace it.
	$p = new WP_HTML_Tag_Processor( '<figure><img src="" alt=""><figcaption> </figcaption></figure>' );

	// Position on the IMG tag and set attributes. set_attribute() handles all HTML encoding.
	$p->next_tag( 'img' );
	$p->set_attribute( 'src', $url );
	$p->set_attribute( 'alt', $alt );

	// Advance to the FIGCAPTION opener, then to the text node inside it.
	$p->next_token(); // <figcaption>
	$p->next_token(); // #text node (the placeholder " ")

	// set_modifiable_text() handles all HTML encoding for the text node.
	$p->set_modifiable_text( $caption );

	return $p->get_updated_html();
}
