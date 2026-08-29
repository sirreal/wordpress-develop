<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();

	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect IMG elements in the HTML namespace, not SVG <image> elements
		if ( 'html' === $processor->get_namespace() ) {
			$src = $processor->get_attribute( 'src' );
			// Only include if src attribute exists and has a value
			if ( null !== $src && '' !== $src ) {
				$sources[] = $src;
			}
		}
	}

	return $sources;
}
