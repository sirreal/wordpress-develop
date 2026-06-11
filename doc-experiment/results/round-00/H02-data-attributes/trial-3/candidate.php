<?php

function get_data_attributes( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	if ( ! $processor->next_tag( 'DIV' ) ) {
		return array();
	}

	$attribute_names = $processor->get_attribute_names_with_prefix( 'data-' );

	if ( null === $attribute_names || array() === $attribute_names ) {
		return array();
	}

	$result = array();
	foreach ( $attribute_names as $name ) {
		$result[ $name ] = $processor->get_attribute( $name );
	}

	return $result;
}
