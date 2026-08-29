<?php

function build_figure( string $url, string $alt, string $caption ): string {
	$processor = new WP_HTML_Tag_Processor( '<figure><img src="" alt=""><figcaption>.</figcaption></figure>' );

	if ( ! $processor->next_tag( 'img' ) ) {
		return '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	}

	$processor->set_attribute( 'src', $url );
	$processor->set_attribute( 'alt', $alt );

	if ( $processor->next_tag( 'figcaption' ) ) {
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$processor->set_modifiable_text( $caption );
				break;
			}
		}
	}

	return $processor->get_updated_html();
}
