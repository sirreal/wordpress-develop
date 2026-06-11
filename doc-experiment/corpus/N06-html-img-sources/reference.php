<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();
	while ( $processor->next_tag( 'IMG' ) ) {
		$src = $processor->get_attribute( 'src' );
		if ( is_string( $src ) && '' !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
