<?php

if ( ! function_exists( 'strip_tracking_attributes' ) ) {
	/**
	 * Remove attributes whose names start with data-track- from all tags.
	 *
	 * @param string $html HTML document or fragment.
	 * @return string Modified HTML.
	 */
	function strip_tracking_attributes( string $html ): string {
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			$attribute_names = $processor->get_attribute_names_with_prefix( 'data-track-' );

			if ( null === $attribute_names ) {
				continue;
			}

			foreach ( $attribute_names as $attribute_name ) {
				$processor->remove_attribute( $attribute_name );
			}
		}

		return $processor->get_updated_html();
	}
}
