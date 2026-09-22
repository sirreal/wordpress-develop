<?php
function get_data_attributes( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	if ( ! $processor->next_tag( 'div' ) ) {
		return array();
	}

	$data_attribute_names = $processor->get_attribute_names_with_prefix( 'data-' );

	if ( null === $data_attribute_names || array() === $data_attribute_names ) {
		return array();
	}

	$result = array();
	foreach ( $data_attribute_names as $name ) {
		$value = $processor->get_attribute( $name );
		if ( null !== $value ) {
			$result[ $name ] = $value;
		}
	}

	return $result;
}
