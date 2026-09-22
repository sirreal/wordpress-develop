<?php

function can_normalize_fragment( string $html ): bool {
	return WP_HTML_Processor::normalize( $html ) !== null;
}
