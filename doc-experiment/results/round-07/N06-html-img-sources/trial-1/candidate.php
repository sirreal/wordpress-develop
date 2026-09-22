<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();

	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only process IMG tags in the HTML namespace, not in SVG
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}

		// Get the src attribute value (already decoded)
		$src = $processor->get_attribute( 'src' );

		// Include only if src exists and is not empty string
		if ( null !== $src && '' !== $src && true !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
