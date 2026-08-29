<?php

function build_figure( string $url, string $alt, string $caption ): string {
	$processor = WP_HTML_Processor::create_fragment(
		'<figure><img src="" alt=""><figcaption>.</figcaption></figure>'
	);

	if ( null === $processor ) {
		return '';
	}

	if ( $processor->next_tag( 'IMG' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$processor->set_modifiable_text( $caption );
			break;
		}
	}

	return $processor->get_updated_html();
}
