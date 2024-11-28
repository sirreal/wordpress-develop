<?php

interface WP_CSS_HTML_Tag_Processor_Matcher {
	/**
	 * @return bool
	 */
	public function matches( WP_HTML_Tag_Processor $processor ): bool;
}
