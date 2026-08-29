<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();

	while ( $processor->next_tag( 'IMG' ) ) {
		// Only collect IMG elements in HTML namespace, not SVG namespace
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}

		// Get the src attribute value (returned decoded by the API)
		$src = $processor->get_attribute( 'src' );

		// Skip if src is not set (null), is a boolean (shouldn't happen for src), or is empty string
		if ( null !== $src && true !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
