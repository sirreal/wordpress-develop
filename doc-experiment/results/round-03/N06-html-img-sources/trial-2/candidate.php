<?php
function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$sources = array();

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Only collect HTML img tags, not SVG image tags in foreign content
		if ( 'IMG' === $tag && 'html' === $processor->get_namespace() ) {
			$src = $processor->get_attribute( 'src' );
			
			// Include src if it's a non-empty string (not null, not true/empty)
			if ( is_string( $src ) && '' !== $src ) {
				$sources[] = $src;
			}
		}
	}

	return $sources;
}
