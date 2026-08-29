<?php
function strip_inline_styles( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag() ) {
		$processor->remove_attribute( 'style' );
	}
	return $processor->get_updated_html();
}
