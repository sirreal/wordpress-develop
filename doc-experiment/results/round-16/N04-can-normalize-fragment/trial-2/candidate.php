<?php

function can_normalize_fragment( string $html ): bool {
    return null !== WP_HTML_Processor::normalize( $html );
}
