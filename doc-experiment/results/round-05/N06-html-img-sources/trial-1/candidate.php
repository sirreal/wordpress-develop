<?php
function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();

	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect IMG elements in the HTML namespace, not SVG's IMAGE elements
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}

		// Get the src attribute value (returns null if not present)
		$src = $processor->get_attribute( 'src' );

		// Skip if src is null (attribute doesn't exist) or if it's a boolean (attribute present but no value)
		if ( null !== $src && true !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
