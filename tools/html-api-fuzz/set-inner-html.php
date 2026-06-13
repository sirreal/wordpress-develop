#!/usr/bin/env php
<?php
/**
 * Fuzz WP_HTML_Processor::set_inner_html().
 *
 * @package WordPress
 * @subpackage HTML-API
 */

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
	}
}

if ( ! function_exists( '_deprecated_argument' ) ) {
	function _deprecated_argument( $function_name, $version, $message = '' ) {
	}
}

if ( ! function_exists( 'wp_trigger_error' ) ) {
	function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {
	}
}

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	function wp_kses_uri_attributes() {
		return array(
			'action',
			'archive',
			'background',
			'cite',
			'classid',
			'codebase',
			'data',
			'formaction',
			'href',
			'icon',
			'longdesc',
			'manifest',
			'poster',
			'profile',
			'src',
			'usemap',
			'xmlns',
		);
	}
}

/**
 * Deterministic pseudo-random generator.
 */
class WP_HTML_Set_Inner_HTML_Fuzzer_PRNG {
	/**
	 * Seed.
	 *
	 * @var string
	 */
	private $seed;

	/**
	 * Hash counter.
	 *
	 * @var int
	 */
	private $counter = 0;

	/**
	 * Buffered random bytes.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Constructor.
	 *
	 * @param int|string $seed Seed.
	 */
	public function __construct( $seed ) {
		$this->seed = (string) $seed;
	}

	/**
	 * Returns pseudo-random bytes.
	 *
	 * @param int $length Byte count.
	 * @return string Bytes.
	 */
	public function bytes( int $length ): string {
		while ( strlen( $this->buffer ) < $length ) {
			$this->buffer .= hash( 'sha256', $this->seed . ':' . $this->counter++, true );
		}

		$out          = substr( $this->buffer, 0, $length );
		$this->buffer = substr( $this->buffer, $length );
		return $out;
	}

	/**
	 * Returns an integer in a closed interval.
	 *
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int Number.
	 */
	public function int( int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}

		$parts = unpack( 'Nvalue', $this->bytes( 4 ) );
		return $min + ( (int) $parts['value'] % ( $max - $min + 1 ) );
	}

	/**
	 * Returns one value from a list.
	 *
	 * @param array $values Values.
	 * @return mixed Value.
	 */
	public function choice( array $values ) {
		return $values[ $this->int( 0, count( $values ) - 1 ) ];
	}

	/**
	 * Returns true with the given percentage chance.
	 *
	 * @param int $percent Percent chance.
	 * @return bool Whether selected.
	 */
	public function chance( int $percent ): bool {
		return $this->int( 1, 100 ) <= $percent;
	}
}

/**
 * Prints usage.
 */
function wp_html_set_inner_html_fuzzer_usage(): void {
	echo "Usage: php tools/html-api-fuzz/set-inner-html.php [--iterations N] [--start-seed N] [--output-dir DIR] [--stop-on-failure]\n";
}

/**
 * Parses simple CLI options.
 *
 * @param string[] $argv Arguments.
 * @return array<string, mixed> Options.
 */
function wp_html_set_inner_html_fuzzer_parse_options( array $argv ): array {
	$options = array();
	$count   = count( $argv );

	for ( $i = 1; $i < $count; ++$i ) {
		$arg = $argv[ $i ];
		if ( 0 !== strpos( $arg, '--' ) ) {
			continue;
		}

		$arg = substr( $arg, 2 );
		if ( false !== strpos( $arg, '=' ) ) {
			list( $name, $value ) = explode( '=', $arg, 2 );
			$options[ $name ]     = $value;
			continue;
		}

		if ( $i + 1 < $count && 0 !== strpos( $argv[ $i + 1 ], '--' ) ) {
			$options[ $arg ] = $argv[ ++$i ];
		} else {
			$options[ $arg ] = true;
		}
	}

	return $options;
}

/**
 * Returns an integer option.
 *
 * @param array<string, mixed> $options Options.
 * @param string               $name    Option name.
 * @param int                  $default Default value.
 * @return int Option value.
 */
function wp_html_set_inner_html_fuzzer_int_option( array $options, string $name, int $default ): int {
	if ( ! array_key_exists( $name, $options ) || true === $options[ $name ] ) {
		return $default;
	}

	$value = filter_var( $options[ $name ], FILTER_VALIDATE_INT );
	if ( false === $value ) {
		throw new InvalidArgumentException( "Expected --{$name} to be an integer." );
	}

	return (int) $value;
}

/**
 * Returns a string option.
 *
 * @param array<string, mixed> $options Options.
 * @param string               $name    Option name.
 * @param string|null          $default Default value.
 * @return string|null Option value.
 */
function wp_html_set_inner_html_fuzzer_string_option( array $options, string $name, ?string $default ): ?string {
	return array_key_exists( $name, $options ) && true !== $options[ $name ]
		? (string) $options[ $name ]
		: $default;
}

/**
 * Loads the HTML API without bootstrapping WordPress.
 */
function wp_html_set_inner_html_fuzzer_bootstrap(): void {
	$root  = dirname( __DIR__, 2 );
	$files = array(
		'src/wp-includes/compat.php',
		'src/wp-includes/compat-utf8.php',
		'src/wp-includes/utf8.php',
		'src/wp-includes/class-wp-token-map.php',
		'src/wp-includes/html-api/html5-named-character-references.php',
		'src/wp-includes/html-api/class-wp-html-attribute-token.php',
		'src/wp-includes/html-api/class-wp-html-span.php',
		'src/wp-includes/html-api/class-wp-html-doctype-info.php',
		'src/wp-includes/html-api/class-wp-html-text-replacement.php',
		'src/wp-includes/html-api/class-wp-html-decoder.php',
		'src/wp-includes/html-api/class-wp-html-tag-processor.php',
		'src/wp-includes/html-api/class-wp-html-unsupported-exception.php',
		'src/wp-includes/html-api/class-wp-html-active-formatting-elements.php',
		'src/wp-includes/html-api/class-wp-html-open-elements.php',
		'src/wp-includes/html-api/class-wp-html-token.php',
		'src/wp-includes/html-api/class-wp-html-stack-event.php',
		'src/wp-includes/html-api/class-wp-html-processor-state.php',
		'src/wp-includes/html-api/class-wp-html-processor.php',
	);

	foreach ( $files as $file ) {
		require_once $root . DIRECTORY_SEPARATOR . $file;
	}
}

/**
 * Returns a generated HTML fragment.
 *
 * @param WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng PRNG.
 * @param int                                $max_snippets Maximum snippets.
 * @param bool                               $allow_leaks Whether to include snippets intended to leak.
 * @return string HTML.
 */
function wp_html_set_inner_html_fuzzer_fragment( WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng, int $max_snippets = 5, bool $allow_leaks = true ): string {
	$texts    = array( '', 'text', ' &amp; ', '0', "line\nbreak", '<!--comment-->' );
	$snippets = array(
		'plain'       => static function () use ( $rng, $texts ): string {
			return $rng->choice( $texts );
		},
		'element'     => static function () use ( $rng ): string {
			$tag = $rng->choice( array( 'div', 'span', 'p', 'section', 'main', 'button', 'em', 'strong', 'b', 'i', 'a' ) );
			return "<{$tag}>" . $rng->choice( array( 'x', 'y', '<em>z</em>', '' ) ) . "</{$tag}>";
		},
		'omitted'     => static function () use ( $rng ): string {
			return $rng->choice( array( '<p>one<p>two', '<ul><li>one<li>two</ul>', '<dl><dt>a<dd>b' ) );
		},
		'foreign'     => static function () use ( $rng ): string {
			return $rng->choice( array( '<svg><title>t</title></svg>', '<svg><html lang="fr"></html></svg>', '<math><mi>x</mi></math>' ) );
		},
		'template'    => static function () use ( $rng ): string {
			return $rng->choice( array( '<template><body add-class>t</template>', '<template></body><p>x</p></template>' ) );
		},
		'rawtext'     => static function () use ( $rng ): string {
			return $rng->choice( array( '<script>1 < 2</script>', '<style>a{color:red}</style>', '<textarea>x</textarea>' ) );
		},
		'leak'        => static function () use ( $rng ): string {
			return $rng->choice( array( '</div><p>leak</p>', '</section><span>leak</span>', '<a>nested</a>', '<b>unclosed', '<body add-class>x', '<html lang="en">x' ) );
		},
		'table'       => static function () use ( $rng ): string {
			return $rng->choice( array( '<table><tr><td>c</td></tr></table>', '<table><td>c</table>' ) );
		},
	);

	if ( ! $allow_leaks ) {
		unset( $snippets['leak'] );
	}

	$html  = '';
	$count = $rng->int( 0, $max_snippets );
	for ( $i = 0; $i < $count; ++$i ) {
		$factory = $rng->choice( array_values( $snippets ) );
		$html   .= $factory();
	}

	return $html;
}

/**
 * Builds one fuzz case.
 *
 * @param int $seed Seed.
 * @return array<string, string|bool|int> Case data.
 */
function wp_html_set_inner_html_fuzzer_case( int $seed ): array {
	$rng        = new WP_HTML_Set_Inner_HTML_Fuzzer_PRNG( $seed );
	$full       = $rng->chance( 35 );
	$target_tag = $rng->choice( array( 'div', 'section', 'main', 'article' ) );
	$prefix     = wp_html_set_inner_html_fuzzer_fragment( $rng, 3 );
	$inner      = wp_html_set_inner_html_fuzzer_fragment( $rng, 4, false );
	$suffix     = wp_html_set_inner_html_fuzzer_fragment( $rng, 3 );
	$replace    = wp_html_set_inner_html_fuzzer_fragment( $rng, 5 );
	$opener     = "<{$target_tag} data-fuzz-target=\"1\">";
	$closer     = "</{$target_tag}>";
	$fragment   = $prefix . $opener . $inner . $closer . $suffix;
	$expected   = $prefix . $opener . $replace . $closer . $suffix;

	if ( $full ) {
		$fragment = '<!DOCTYPE html><html><body>' . $fragment . '</body></html>';
		$expected = '<!DOCTYPE html><html><body>' . $expected . '</body></html>';
	}

	return array(
		'seed'        => $seed,
		'full'        => $full,
		'targetTag'   => strtoupper( $target_tag ),
		'html'        => $fragment,
		'replacement' => $replace,
		'expected'    => $expected,
	);
}

/**
 * Returns deterministic regression cases to run before random fuzz cases.
 *
 * @return array<int, array<string, string|bool|int|null>> Corpus cases.
 */
function wp_html_set_inner_html_fuzzer_corpus_cases(): array {
	return array(
		array(
			'seed'        => 0,
			'name'        => 'fragment-body-attribute-hoist',
			'full'        => false,
			'targetTag'   => 'MAIN',
			'html'        => '<main data-fuzz-target="1">Old</main><span>After</span>',
			'replacement' => '<body add-class>New',
			'expected'    => '<main data-fuzz-target="1"><body add-class>New</main><span>After</span>',
			'expectSet'   => false,
		),
		array(
			'seed'        => 0,
			'name'        => 'fragment-html-attribute-hoist',
			'full'        => false,
			'targetTag'   => 'MAIN',
			'html'        => '<main data-fuzz-target="1">Old</main><span>After</span>',
			'replacement' => '<html lang="en">New',
			'expected'    => '<main data-fuzz-target="1"><html lang="en">New</main><span>After</span>',
			'expectSet'   => false,
		),
		array(
			'seed'        => 0,
			'name'        => 'escaped-target-body-attribute-hoist',
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1">Old</div><span>After</span>',
			'replacement' => '</div><body add-class>',
			'expected'    => '<div data-fuzz-target="1"></div><body add-class></div><span>After</span>',
			'expectSet'   => false,
		),
		array(
			'seed'        => 0,
			'name'        => 'escaped-target-html-attribute-hoist',
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1">Old</div><span>After</span>',
			'replacement' => '</div><html lang="en">',
			'expected'    => '<div data-fuzz-target="1"></div><html lang="en"></div><span>After</span>',
			'expectSet'   => false,
		),
		array(
			'seed'        => 0,
			'name'        => 'original-body-attribute-hoist-would-be-removed',
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1"><body add-class>Old</div><span>After</span>',
			'replacement' => '<p>New</p>',
			'expected'    => '<div data-fuzz-target="1"><p>New</p></div><span>After</span>',
			'expectSet'   => false,
		),
		array(
			'seed'        => 0,
			'name'        => 'original-html-attribute-hoist-would-be-removed',
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1"><html lang="en">Old</div><span>After</span>',
			'replacement' => '<p>New</p>',
			'expected'    => '<div data-fuzz-target="1"><p>New</p></div><span>After</span>',
			'expectSet'   => false,
		),
		array(
			'seed'        => 0,
			'name'        => 'template-body-tag-does-not-hoist',
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1">Old</div><span>After</span>',
			'replacement' => '<template><body add-class>New</template>',
			'expected'    => '<div data-fuzz-target="1"><template><body add-class>New</template></div><span>After</span>',
			'expectSet'   => true,
		),
		array(
			'seed'        => 0,
			'name'        => 'foreign-html-tag-does-not-hoist',
			'full'        => false,
			'targetTag'   => 'SVG',
			'html'        => '<svg data-fuzz-target="1"><title>Old</title></svg><span>After</span>',
			'replacement' => '<html lang="fr"></html>',
			'expected'    => '<svg data-fuzz-target="1"><html lang="fr"></html></svg><span>After</span>',
			'expectSet'   => true,
		),
		array(
			'seed'        => 0,
			'name'        => 'full-document-body-attribute-hoist',
			'full'        => true,
			'targetTag'   => 'MAIN',
			'html'        => '<!DOCTYPE html><html><body><main data-fuzz-target="1">Old</main><span>After</span></body></html>',
			'replacement' => '<body add-class>New',
			'expected'    => '<!DOCTYPE html><html><body><main data-fuzz-target="1"><body add-class>New</main><span>After</span></body></html>',
			'expectSet'   => false,
		),
		array(
			'seed'        => 0,
			'name'        => 'full-document-html-attribute-hoist',
			'full'        => true,
			'targetTag'   => 'MAIN',
			'html'        => '<!DOCTYPE html><html><body><main data-fuzz-target="1">Old</main><span>After</span></body></html>',
			'replacement' => '<html lang="en">New',
			'expected'    => '<!DOCTYPE html><html><body><main data-fuzz-target="1"><html lang="en">New</main><span>After</span></body></html>',
			'expectSet'   => false,
		),
	);
}

/**
 * Creates a processor for a case.
 *
 * @param string $html HTML.
 * @param bool   $full Whether to create a full parser.
 * @return WP_HTML_Processor|null Processor.
 */
function wp_html_set_inner_html_fuzzer_create_processor( string $html, bool $full ): ?WP_HTML_Processor {
	return $full ? WP_HTML_Processor::create_full_parser( $html ) : WP_HTML_Processor::create_fragment( $html );
}

/**
 * Moves a processor to the fuzz target.
 *
 * @param WP_HTML_Processor $processor Processor.
 * @return bool Whether the target was found.
 */
function wp_html_set_inner_html_fuzzer_seek_target( WP_HTML_Processor $processor ): bool {
	while ( $processor->next_tag() ) {
		if ( '1' === $processor->get_attribute( 'data-fuzz-target' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns a signature for the current token.
 *
 * @param WP_HTML_Processor $processor Processor.
 * @return array<string, mixed> Token signature.
 */
function wp_html_set_inner_html_fuzzer_token_signature( WP_HTML_Processor $processor ): array {
	return array(
		'type'        => $processor->get_token_type(),
		'name'        => $processor->get_token_name(),
		'namespace'   => $processor->get_namespace(),
		'isCloser'    => $processor->is_tag_closer(),
		'breadcrumbs' => $processor->get_breadcrumbs(),
		'html'        => $processor->serialize_token(),
	);
}

/**
 * Returns a signature for parser continuation from the current token.
 *
 * @param WP_HTML_Processor $processor Processor.
 * @return array{tokens: array<int, array<string, mixed>>, lastError: string|null} Continuation signature.
 */
function wp_html_set_inner_html_fuzzer_continuation_signature( WP_HTML_Processor $processor ): array {
	$signature = array();

	while ( $processor->next_token() ) {
		$signature[] = wp_html_set_inner_html_fuzzer_token_signature( $processor );
	}

	return array(
		'tokens'    => $signature,
		'lastError' => $processor->get_last_error(),
	);
}

/**
 * Returns a token signature outside the fuzz target.
 *
 * @param string $html HTML.
 * @param bool   $full Whether to create a full parser.
 * @return array<int, array<string, mixed>>|null Signature, or null if unsupported.
 */
function wp_html_set_inner_html_fuzzer_outer_signature( string $html, bool $full ): ?array {
	$processor = wp_html_set_inner_html_fuzzer_create_processor( $html, $full );
	if ( null === $processor ) {
		return null;
	}

	$signature           = array();
	$skipping            = false;
	$target_tag          = null;
	$target_parent_depth = null;

	while ( $processor->next_token() ) {
		if ( ! $skipping ) {
			$signature[] = wp_html_set_inner_html_fuzzer_token_signature( $processor );

			if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() && '1' === $processor->get_attribute( 'data-fuzz-target' ) ) {
				$skipping            = true;
				$target_tag          = $processor->get_tag();
				$target_parent_depth = count( $processor->get_breadcrumbs() ) - 1;
			}
			continue;
		}

		if (
			'#tag' === $processor->get_token_type() &&
			$processor->is_tag_closer() &&
			$processor->get_tag() === $target_tag &&
			count( $processor->get_breadcrumbs() ) === $target_parent_depth
		) {
			$signature[] = wp_html_set_inner_html_fuzzer_token_signature( $processor );
			$skipping = false;
		}
	}

	if ( null !== $processor->get_last_error() || $skipping ) {
		return null;
	}

	return $signature;
}

/**
 * Writes a failing case.
 *
 * @param string               $output_dir Output directory.
 * @param array<string, mixed> $failure    Failure.
 */
function wp_html_set_inner_html_fuzzer_write_failure( string $output_dir, array $failure ): void {
	if ( ! is_dir( $output_dir ) ) {
		mkdir( $output_dir, 0777, true );
	}

	$name = isset( $failure['case']['name'] )
		? '-' . preg_replace( '/[^A-Za-z0-9_.-]+/', '-', (string) $failure['case']['name'] )
		: '';

	file_put_contents(
		$output_dir . '/failure-seed-' . $failure['seed'] . $name . '.json',
		json_encode( $failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n"
	);
}

/**
 * Runs one fuzz case.
 *
 * @param array<string, string|bool|int|null> $case Case.
 * @return array<string, mixed> Result.
 */
function wp_html_set_inner_html_fuzzer_run_case( array $case ): array {
	$processor = wp_html_set_inner_html_fuzzer_create_processor( $case['html'], $case['full'] );
	if ( null === $processor || ! wp_html_set_inner_html_fuzzer_seek_target( $processor ) ) {
		return array(
			'ok'     => true,
			'status' => 'unsupported-original',
		);
	}

	$original_signature = wp_html_set_inner_html_fuzzer_outer_signature( $case['html'], $case['full'] );
	$set                = $processor->set_inner_html( $case['replacement'] );
	$updated            = $processor->get_updated_html();
	$last_error         = $processor->get_last_error();

	if ( array_key_exists( 'expectSet', $case ) && null !== $case['expectSet'] && $set !== $case['expectSet'] ) {
		return array(
			'ok'        => false,
			'failure'   => $case['expectSet'] ? 'expected-acceptance' : 'expected-rejection',
			'updated'   => $updated,
			'lastError' => $processor->get_last_error(),
		);
	}

	$expected_continuation_html       = $set ? $updated : $case['html'];
	$expected_continuation_processor = wp_html_set_inner_html_fuzzer_create_processor( $expected_continuation_html, $case['full'] );
	if ( null !== $expected_continuation_processor && wp_html_set_inner_html_fuzzer_seek_target( $expected_continuation_processor ) ) {
		$expected_continuation = wp_html_set_inner_html_fuzzer_continuation_signature( $expected_continuation_processor );
		$actual_continuation   = wp_html_set_inner_html_fuzzer_continuation_signature( $processor );

		if ( $actual_continuation !== $expected_continuation ) {
			return array(
				'ok'                   => false,
				'failure'              => 'set-inner-html-changed-live-continuation',
				'expectedContinuation' => $expected_continuation,
				'actualContinuation'   => $actual_continuation,
				'updated'              => $updated,
			);
		}
	}

	if ( ! $set ) {
		if ( $updated !== $case['html'] ) {
			return array(
				'ok'      => false,
				'failure' => 'rejected-update-changed-html',
				'updated' => $updated,
			);
		}

		if ( null !== $last_error ) {
			return array(
				'ok'        => false,
				'failure'   => 'rejected-update-poisoned-processor',
				'lastError' => $last_error,
			);
		}

		return array(
			'ok'     => true,
			'status' => 'rejected',
		);
	}

	if ( $updated !== $case['expected'] ) {
		return array(
			'ok'       => false,
			'failure'  => 'accepted-update-did-not-set-raw-inner-html',
			'expected' => $case['expected'],
			'updated'  => $updated,
		);
	}

	if ( null === $original_signature ) {
		return array(
			'ok'     => true,
			'status' => 'accepted-original-signature-unsupported',
		);
	}

	$updated_signature = wp_html_set_inner_html_fuzzer_outer_signature( $updated, $case['full'] );
	if ( null === $updated_signature ) {
		return array(
			'ok'      => false,
			'failure' => 'accepted-update-produced-unsupported-output',
			'updated' => $updated,
		);
	}

	if ( $original_signature !== $updated_signature ) {
		return array(
			'ok'               => false,
			'failure'          => 'accepted-update-changed-outside-tree',
			'originalSignature' => $original_signature,
			'updatedSignature'  => $updated_signature,
			'updated'           => $updated,
		);
	}

	return array(
		'ok'     => true,
		'status' => 'accepted',
	);
}

$options = wp_html_set_inner_html_fuzzer_parse_options( $argv );
if ( isset( $options['help'] ) || isset( $options['h'] ) ) {
	wp_html_set_inner_html_fuzzer_usage();
	exit( 0 );
}

$iterations      = wp_html_set_inner_html_fuzzer_int_option( $options, 'iterations', 1000 );
$start_seed      = wp_html_set_inner_html_fuzzer_int_option( $options, 'start-seed', 1 );
$stop_on_failure = isset( $options['stop-on-failure'] );
$output_dir      = wp_html_set_inner_html_fuzzer_string_option( $options, 'output-dir', dirname( __DIR__, 2 ) . '/artifacts/html-api-fuzz/set-inner-html' );

wp_html_set_inner_html_fuzzer_bootstrap();

$counts = array(
	'corpus'                                => 0,
	'accepted'                              => 0,
	'accepted-original-signature-unsupported' => 0,
	'rejected'                              => 0,
	'unsupported-original'                  => 0,
	'failures'                              => 0,
);

foreach ( wp_html_set_inner_html_fuzzer_corpus_cases() as $case ) {
	$result = wp_html_set_inner_html_fuzzer_run_case( $case );
	++$counts['corpus'];

	if ( ! $result['ok'] ) {
		++$counts['failures'];
		$failure = array(
			'seed'   => $case['seed'],
			'case'   => $case,
			'result' => $result,
		);
		wp_html_set_inner_html_fuzzer_write_failure( $output_dir, $failure );
		fwrite( STDERR, 'Failure in corpus case ' . $case['name'] . ': ' . $result['failure'] . "\n" );
		if ( $stop_on_failure ) {
			echo json_encode(
				array(
					'ok'         => false,
					'startSeed'  => $start_seed,
					'iterations' => $iterations,
					'counts'     => $counts,
					'outputDir'  => $output_dir,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			) . "\n";
			exit( 1 );
		}
	}
}

for ( $i = 0; $i < $iterations; ++$i ) {
	$seed   = $start_seed + $i;
	$case   = wp_html_set_inner_html_fuzzer_case( $seed );
	$result = wp_html_set_inner_html_fuzzer_run_case( $case );

	if ( ! $result['ok'] ) {
		++$counts['failures'];
		$failure = array(
			'seed'   => $seed,
			'case'   => $case,
			'result' => $result,
		);
		wp_html_set_inner_html_fuzzer_write_failure( $output_dir, $failure );
		fwrite( STDERR, 'Failure at seed ' . $seed . ': ' . $result['failure'] . "\n" );
		if ( $stop_on_failure ) {
			break;
		}
		continue;
	}

	$status = $result['status'];
	if ( ! isset( $counts[ $status ] ) ) {
		$counts[ $status ] = 0;
	}
	++$counts[ $status ];
}

echo json_encode(
	array(
		'ok'         => 0 === $counts['failures'],
		'startSeed'  => $start_seed,
		'iterations' => $iterations,
		'counts'     => $counts,
		'outputDir'  => $output_dir,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";

exit( 0 === $counts['failures'] ? 0 : 1 );
