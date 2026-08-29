<?php

function build_figure( string $url, string $alt, string $caption ): string {
	$template = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';
	$processor = new WP_HTML_Tag_Processor( $template );
	if ( $processor->next_tag( 'img' ) ) {
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