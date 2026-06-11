<?php
function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip IMG elements that are in SVG namespace (i.e., SVG <image> elements)
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}

		$src = $processor->get_attribute( 'src' );
		// Only include if src attribute exists and has a non-empty value
		// get_attribute returns null if attribute doesn't exist,
		// true for boolean attributes, or a string (possibly empty)
		if ( is_string( $src ) && '' !== $src ) {
			$sources[] = $src;
		}
	}

	return $sources;
}
?>