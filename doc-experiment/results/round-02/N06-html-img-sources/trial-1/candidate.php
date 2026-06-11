<?php
function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	$sources   = array();

	if ( null === $processor ) {
		return $sources;
	}

	while ( $processor->next_tag( 'IMG' ) ) {
		// Only collect IMG tags from the HTML namespace, not SVG image elements.
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}

		$src = $processor->get_attribute( 'src' );

		// Skip images with no src attribute or empty src value.
		if ( null === $src || '' === $src ) {
			continue;
		}

		$sources[] = $src;
	}

	return $sources;
}
