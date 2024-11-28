<?php

interface WP_CSS_HTML_Processor_Matcher {
	/**
	 * @return bool
	 */
	public function matches( WP_HTML_Processor $processor ): bool;
}
