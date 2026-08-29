<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip if we're in SVG namespace (SVG uses <image> not <img>)
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}

		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );

		// Only add if src exists and has a non-empty value
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
