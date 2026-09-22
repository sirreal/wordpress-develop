<?php

function find_images_missing_alt( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	$missing = array();
	while ( $processor->next_tag( 'IMG' ) ) {
		$src = $processor->get_attribute( 'src' );
		if ( ! is_string( $src ) || '' === $src ) {
			continue;
		}

		$alt = $processor->get_attribute( 'alt' );
		if ( null === $alt || true === $alt || '' === $alt ) {
			$missing[] = $src;
		}
	}

	return $missing;
}
