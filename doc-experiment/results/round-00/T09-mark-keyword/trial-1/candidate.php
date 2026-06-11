<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$result = '';

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_name() ) {
			$text = $processor->get_modifiable_text();
			if ( str_contains( $text, $keyword ) ) {
				// Serialize the text node normally (gives properly-encoded text content),
				// then wrap it in <mark>...</mark>.
				$serialized = $processor->serialize_token();
				$result    .= '<mark>' . $serialized . '</mark>';
				continue;
			}
		}
		$result .= $processor->serialize_token();
	}

	return $result;
}
