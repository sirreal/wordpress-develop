<?php

function get_data_attributes( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );
	if ( ! $processor->next_tag( 'DIV' ) ) {
		return array();
	}

	$data       = array();
	$attributes = $processor->get_attribute_names_with_prefix( 'data-' );
	foreach ( $attributes ?? array() as $name ) {
		$data[ $name ] = $processor->get_attribute( $name );
	}

	return $data;
}
