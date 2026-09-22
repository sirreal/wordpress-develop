<?php
function collect_html_img_sources( string $html ): array {
	$sources = array();
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $sources;
	}
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		$src = $processor->get_attribute( 'src' );
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	return $sources;
}