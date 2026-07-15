<?php

declare(strict_types=1);

use CcAnalyzer\Analysis\HtmlAnalysisInput;
use HtmlApiFuzz\CommonCrawlRunner;

require_once __DIR__ . '/lib/autoload.php';

$runner = CommonCrawlRunner::from_environment();

return static function ( HtmlAnalysisInput $document ) use ( $runner ): void {
	$runner->analyze_document( $document );
};
