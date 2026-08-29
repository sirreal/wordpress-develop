<?php

function find_images_missing_alt( string $html ): array {
	$missing_alt_srcs = array();

	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'img' ) ) {
		$src = $processor->get_attribute( 'src' );

		// Skip IMG tags with no src attribute.
		if ( null === $src || true === $src ) {
			continue;
		}

		$alt = $processor->get_attribute( 'alt' );

		// "Missing or empty" means:
		// - absent (null)
		// - written without a value, i.e. boolean attribute (true)
		// - has the empty string as its value ("")
		if ( null === $alt || true === $alt || '' === $alt ) {
			$missing_alt_srcs[] = $src;
		}
	}

	return $missing_alt_srcs;
}
