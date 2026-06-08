<?php
/**
 * Shared HTML API benchmark helpers.
 *
 * @package WordPress
 * @subpackage Benchmark
 */

/**
 * Parses CLI options.
 *
 * @param array $argv     Raw argv values.
 * @param array $defaults Default option values.
 * @return array<string,mixed> Parsed options.
 */
function wp_html_api_benchmark_parse_options( $argv, $defaults = array() ) {
	$options = $defaults;

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( '--help' === $arg || '-h' === $arg ) {
			$options['help'] = true;
			continue;
		}

		if ( '--quiet' === $arg ) {
			$options['quiet'] = true;
			continue;
		}

		if ( 0 !== strpos( $arg, '--' ) ) {
			wp_html_api_benchmark_fail( "Unknown argument: {$arg}" );
		}

		$option = substr( $arg, 2 );
		$parts  = explode( '=', $option, 2 );
		$name   = $parts[0];
		$value  = isset( $parts[1] ) ? $parts[1] : true;

		if ( 'case' === $name ) {
			if ( ! isset( $options['case'] ) ) {
				$options['case'] = array();
			}
			$options['case'][] = $value;
			continue;
		}

		$options[ $name ] = $value;
	}

	return $options;
}

/**
 * Fails with a message.
 *
 * @param string $message Failure message.
 * @return never
 */
function wp_html_api_benchmark_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Validates a positive integer option.
 *
 * @param mixed  $value Option value.
 * @param string $name  Option name.
 */
function wp_html_api_benchmark_assert_positive_int( $value, $name ) {
	if ( false === filter_var( $value, FILTER_VALIDATE_INT ) || (int) $value < 1 ) {
		wp_html_api_benchmark_fail( "Option --{$name} must be a positive integer." );
	}
}

/**
 * Validates a non-negative integer option.
 *
 * @param mixed  $value Option value.
 * @param string $name  Option name.
 */
function wp_html_api_benchmark_assert_non_negative_int( $value, $name ) {
	if ( false === filter_var( $value, FILTER_VALIDATE_INT ) || (int) $value < 0 ) {
		wp_html_api_benchmark_fail( "Option --{$name} must be a non-negative integer." );
	}
}

/**
 * Validates a positive number option.
 *
 * @param mixed  $value Option value.
 * @param string $name  Option name.
 */
function wp_html_api_benchmark_assert_positive_number( $value, $name ) {
	if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
		wp_html_api_benchmark_fail( "Option --{$name} must be a positive number." );
	}
}

/**
 * Returns all benchmark cases.
 *
 * @return array<string,array<string,mixed>> Benchmark cases.
 */
function wp_html_api_benchmark_cases() {
	$processors = array(
		'tag-processor'  => array(
			'title' => 'Tag Processor',
			'modes' => array(
				'block-post' => 'tag',
				'full-page'  => 'tag',
			),
		),
		'html-processor' => array(
			'title' => 'HTML Processor',
			'modes' => array(
				'block-post' => 'html-fragment',
				'full-page'  => 'html-full',
			),
		),
	);

	$operations = array(
		'parse'            => 'Parse',
		'attribute-names'  => 'Attribute Names',
		'attribute-values' => 'Attribute Values',
		'modifiable-text'  => 'Modifiable Text',
		'token-getters'    => 'Token Getters',
	);

	$documents = array(
		'block-post' => 'block-post',
		'full-page'  => 'full-page',
	);

	$cases = array();

	foreach ( $processors as $processor_id => $processor ) {
		foreach ( $documents as $document_id => $document_title ) {
			foreach ( $operations as $operation_id => $operation_title ) {
				$case_id = 'parse' === $operation_id
					? "{$processor_id}:{$document_id}"
					: "{$processor_id}:{$operation_id}:{$document_id}";

				$cases[ $case_id ] = array(
					'title'     => "HTML API > {$processor['title']} > {$operation_title} > {$document_title}",
					'processor' => $processor_id,
					'document'  => $document_id,
					'operation' => $operation_id,
					'mode'      => $processor['modes'][ $document_id ],
				);
			}
		}
	}

	return $cases;
}

/**
 * Returns a benchmark document.
 *
 * @param string $document_id Document ID.
 * @return string HTML document.
 */
function wp_html_api_benchmark_document( $document_id ) {
	switch ( $document_id ) {
		case 'block-post':
			return wp_html_api_benchmark_block_post_document();

		case 'full-page':
			return wp_html_api_benchmark_full_page_document();
	}

	wp_html_api_benchmark_fail( "Unknown benchmark document: {$document_id}" );
}

/**
 * Creates a WordPress-like block post fragment.
 *
 * @return string HTML fragment.
 */
function wp_html_api_benchmark_block_post_document() {
	$parts = array();

	for ( $i = 1; $i <= 40; $i++ ) {
		$parts[] = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->";
		$parts[] = '<section class="wp-block-group benchmark-section benchmark-section-' . $i . '" data-wp-interactive="benchmark" data-wp-context=\'{"id":' . $i . '}\' aria-labelledby="heading-' . $i . '">';
		$parts[] = '<h2 id="heading-' . $i . '">Benchmark section ' . $i . '</h2>';
		$parts[] = '<p class="has-text-align-left">This paragraph contains text, <a href="https://example.com/post-' . $i . '?ref=benchmark&amp;source=html-api">a link</a>, <strong>strong text</strong>, and <em>emphasized text</em>.</p>';
		$parts[] = '<figure class="wp-block-image size-large"><img decoding="async" loading="lazy" width="1200" height="800" src="https://example.com/image-' . $i . '.jpg" alt="Benchmark image ' . $i . '" data-wp-bind--src="state.image" /><figcaption>Caption with <code>inline-code-' . $i . '</code>.</figcaption></figure>';
		$parts[] = '<ul class="wp-block-list"><li>First item ' . $i . '</li><li>Second item with <span data-prefix-name="prefix-' . $i . '">metadata</span></li><li>Third item</li></ul>';
		$parts[] = '<blockquote class="wp-block-quote"><p>Quoted text for parser coverage.</p><cite>Source ' . $i . '</cite></blockquote>';
		$parts[] = '</section>';
		$parts[] = '<!-- /wp:group -->';
	}

	return implode( "\n", $parts );
}

/**
 * Creates a full HTML page document.
 *
 * @return string Full HTML document.
 */
function wp_html_api_benchmark_full_page_document() {
	return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>HTML API Benchmark</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>.benchmark-section{margin:1rem 0}</style></head><body>' .
		wp_html_api_benchmark_block_post_document() .
		'<script type="application/json" id="benchmark-data">{"ready":true,"items":40}</script></body></html>';
}

/**
 * Loads the HTML API classes from a WordPress source tree.
 *
 * @param string $target WordPress source root or wp-includes directory.
 */
function wp_html_api_benchmark_load_html_api( $target ) {
	$target = rtrim( $target, '/\\' );

	if ( is_dir( $target . '/wp-includes/html-api' ) ) {
		$wp_includes = $target . '/wp-includes';
	} elseif ( is_dir( $target . '/html-api' ) ) {
		$wp_includes = $target;
	} else {
		wp_html_api_benchmark_fail( "Could not find wp-includes/html-api below target: {$target}" );
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( $wp_includes ) . '/' );
	}

	if ( ! defined( 'WPINC' ) ) {
		define( 'WPINC', basename( $wp_includes ) );
	}

	$files = array(
		'compat.php',
		'class-wp-token-map.php',
		'html-api/html5-named-character-references.php',
		'html-api/class-wp-html-attribute-token.php',
		'html-api/class-wp-html-span.php',
		'html-api/class-wp-html-doctype-info.php',
		'html-api/class-wp-html-text-replacement.php',
		'html-api/class-wp-html-decoder.php',
		'html-api/class-wp-html-tag-processor.php',
		'html-api/class-wp-html-unsupported-exception.php',
		'html-api/class-wp-html-active-formatting-elements.php',
		'html-api/class-wp-html-open-elements.php',
		'html-api/class-wp-html-token.php',
		'html-api/class-wp-html-stack-event.php',
		'html-api/class-wp-html-processor-state.php',
		'html-api/class-wp-html-processor.php',
	);

	foreach ( $files as $file ) {
		$path = $wp_includes . '/' . $file;
		if ( ! file_exists( $path ) ) {
			wp_html_api_benchmark_fail( "Required HTML API file not found: {$path}" );
		}
		require_once $path;
	}
}

/**
 * Creates a processor for a case.
 *
 * @param array  $case Benchmark case.
 * @param string $html HTML document.
 * @return WP_HTML_Tag_Processor|WP_HTML_Processor Processor instance.
 */
function wp_html_api_benchmark_create_processor( $case, $html ) {
	switch ( $case['mode'] ) {
		case 'tag':
			return new WP_HTML_Tag_Processor( $html );

		case 'html-fragment':
			$processor = WP_HTML_Processor::create_fragment( $html );
			break;

		case 'html-full':
			$processor = WP_HTML_Processor::create_full_parser( $html );
			break;

		default:
			wp_html_api_benchmark_fail( 'Unknown benchmark mode: ' . $case['mode'] );
	}

	if ( null === $processor ) {
		wp_html_api_benchmark_fail( 'Failed to create processor for benchmark mode: ' . $case['mode'] );
	}

	return $processor;
}

/**
 * Runs one parsing revolution.
 *
 * @param array  $case Benchmark case.
 * @param string $html HTML document.
 * @return int Token count.
 */
function wp_html_api_benchmark_run_revolution( $case, $html ) {
	$processor = wp_html_api_benchmark_create_processor( $case, $html );
	$tokens    = 0;
	$work      = 0;
	$checksum  = 0;

	while ( $processor->next_token() ) {
		++$tokens;

		switch ( $case['operation'] ) {
			case 'parse':
				++$work;
				$checksum += $tokens;
				break;

			case 'attribute-names':
				if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
					break;
				}

				foreach ( array( 'data-', 'aria-' ) as $prefix ) {
					$names = $processor->get_attribute_names_with_prefix( $prefix );
					++$work;

					if ( is_array( $names ) ) {
						$checksum += count( $names );
						foreach ( $names as $name ) {
							$checksum += strlen( $name );
						}
					}
				}
				break;

			case 'attribute-values':
				if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
					break;
				}

				foreach ( array( 'class', 'data-wp-interactive', 'data-wp-context', 'href', 'src', 'alt', 'aria-labelledby', 'loading', 'missing' ) as $name ) {
					$value = $processor->get_attribute( $name );
					++$work;
					$checksum += wp_html_api_benchmark_value_score( $value );
				}
				break;

			case 'modifiable-text':
				$text = $processor->get_modifiable_text();
				++$work;
				$checksum += strlen( $text );
				break;

			case 'token-getters':
				++$work;
				$checksum += strlen( (string) $processor->get_token_type() );
				$checksum += strlen( (string) $processor->get_token_name() );
				$checksum += strlen( (string) $processor->get_tag() );
				$checksum += $processor->is_tag_closer() ? 1 : 0;
				break;

			default:
				wp_html_api_benchmark_fail( 'Unknown benchmark operation: ' . $case['operation'] );
		}
	}

	return array(
		'tokens'   => $tokens,
		'work'     => $work,
		'checksum' => $checksum,
	);
}

/**
 * Converts a getter return value into a deterministic checksum contribution.
 *
 * @param mixed $value Getter return value.
 * @return int Checksum contribution.
 */
function wp_html_api_benchmark_value_score( $value ) {
	if ( true === $value ) {
		return 1;
	}

	if ( null === $value || false === $value ) {
		return 0;
	}

	if ( is_array( $value ) ) {
		$score = 0;
		foreach ( $value as $item ) {
			$score += wp_html_api_benchmark_value_score( $item );
		}
		return $score;
	}

	return strlen( (string) $value );
}

/**
 * Returns the median from a list of numbers.
 *
 * @param array $values Values.
 * @return float Median.
 */
function wp_html_api_benchmark_median( $values ) {
	sort( $values, SORT_NUMERIC );
	$count = count( $values );
	$mid   = (int) floor( $count / 2 );

	if ( 0 === $count ) {
		return 0.0;
	}

	if ( 1 === $count % 2 ) {
		return (float) $values[ $mid ];
	}

	return ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
}

/**
 * Returns the standard deviation from a list of numbers.
 *
 * @param array $values Values.
 * @return float Standard deviation.
 */
function wp_html_api_benchmark_standard_deviation( $values ) {
	$count = count( $values );

	if ( 0 === $count ) {
		return 0.0;
	}

	$mean = array_sum( $values ) / $count;
	$sum  = 0.0;

	foreach ( $values as $value ) {
		$sum += pow( $value - $mean, 2 );
	}

	return sqrt( $sum / $count );
}

/**
 * Returns summary statistics from samples.
 *
 * @param array $samples Samples.
 * @return array<string,float> Statistics.
 */
function wp_html_api_benchmark_statistics( $samples ) {
	$median     = wp_html_api_benchmark_median( $samples );
	$deviations = array();

	foreach ( $samples as $sample ) {
		$deviations[] = abs( $sample - $median );
	}

	$minimum = count( $samples ) ? min( $samples ) : 0.0;
	$maximum = count( $samples ) ? max( $samples ) : 0.0;
	$stddev  = wp_html_api_benchmark_standard_deviation( $samples );

	return array(
		'median'         => $median,
		'mad'            => wp_html_api_benchmark_median( $deviations ),
		'standardDev'    => $stddev,
		'min'            => $minimum,
		'max'            => $maximum,
		'relativeStdDev' => $median > 0 ? $stddev / $median : 0.0,
	);
}

/**
 * Writes pretty JSON to a file, creating the parent directory if needed.
 *
 * @param string $file File path.
 * @param mixed  $data Data to encode.
 */
function wp_html_api_benchmark_write_json_file( $file, $data ) {
	$directory = dirname( $file );

	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
		wp_html_api_benchmark_fail( "Failed to create output directory: {$directory}" );
	}

	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( false === $json || false === file_put_contents( $file, $json . "\n" ) ) {
		wp_html_api_benchmark_fail( "Failed to write output file: {$file}" );
	}
}

/**
 * Returns PHP environment metadata.
 *
 * @return array<string,mixed> Environment metadata.
 */
function wp_html_api_benchmark_environment() {
	return array(
		'phpVersion'       => PHP_VERSION,
		'phpSapi'          => PHP_SAPI,
		'phpBinary'        => PHP_BINARY,
		'os'               => php_uname(),
		'opcacheEnableCli' => ini_get( 'opcache.enable_cli' ),
		'opcacheJit'       => ini_get( 'opcache.jit' ),
		'xdebugMode'       => getenv( 'XDEBUG_MODE' ),
		'xdebugLoaded'     => extension_loaded( 'xdebug' ),
		'loadedExtensions' => get_loaded_extensions(),
	);
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal translation shim for valid benchmark inputs.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string Text.
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * Minimal doing-it-wrong shim for valid benchmark inputs.
	 *
	 * @param string $function_name Function name.
	 * @param string $message       Message.
	 * @param string $version       Version.
	 */
	function _doing_it_wrong( $function_name, $message, $version ) {
		unset( $function_name, $message, $version );
	}
}
