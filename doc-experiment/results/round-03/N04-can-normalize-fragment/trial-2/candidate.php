<?php

function can_normalize_fragment( string $html ): bool {
	$result = WP_HTML_Processor::normalize( $html );
	return $result !== null;
}
