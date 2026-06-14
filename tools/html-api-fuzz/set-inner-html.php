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
	echo "Usage: php tools/html-api-fuzz/set-inner-html.php [--iterations N] [--start-seed N] [--output-dir DIR] [--stop-on-failure] [--lexbor-oracle-bin PATH]\n";
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
 * Returns the optional Lexbor oracle binary path.
 *
 * @param array<string, mixed> $options Options.
 * @return string|null Binary path, or null when unavailable.
 */
function wp_html_set_inner_html_fuzzer_lexbor_oracle_bin( array $options ): ?string {
	$root      = dirname( __DIR__, 2 );
	$from_env  = getenv( 'HTML_API_FUZZ_LEXBOR_ORACLE' );
	$candidate = wp_html_set_inner_html_fuzzer_string_option(
		$options,
		'lexbor-oracle-bin',
		false !== $from_env && '' !== $from_env
			? $from_env
			: $root . '/tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle'
	);

	return is_string( $candidate ) && is_file( $candidate ) && is_executable( $candidate )
		? $candidate
		: null;
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
 * Returns current HTML elements.
 *
 * @return string[] Element names.
 */
function wp_html_set_inner_html_fuzzer_html_elements(): array {
	return array(
		'a',
		'abbr',
		'address',
		'area',
		'article',
		'aside',
		'audio',
		'b',
		'base',
		'bdi',
		'bdo',
		'blockquote',
		'body',
		'br',
		'button',
		'canvas',
		'caption',
		'cite',
		'code',
		'col',
		'colgroup',
		'data',
		'datalist',
		'dd',
		'del',
		'details',
		'dfn',
		'dialog',
		'div',
		'dl',
		'dt',
		'em',
		'embed',
		'fieldset',
		'figcaption',
		'figure',
		'footer',
		'form',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'head',
		'header',
		'hgroup',
		'hr',
		'html',
		'i',
		'iframe',
		'img',
		'input',
		'ins',
		'kbd',
		'label',
		'legend',
		'li',
		'link',
		'main',
		'map',
		'mark',
		'menu',
		'meta',
		'meter',
		'nav',
		'noscript',
		'object',
		'ol',
		'optgroup',
		'option',
		'output',
		'p',
		'picture',
		'pre',
		'progress',
		'q',
		'rb',
		'rp',
		'rt',
		'rtc',
		'ruby',
		's',
		'samp',
		'script',
		'search',
		'section',
		'select',
		'selectedcontent',
		'slot',
		'small',
		'source',
		'span',
		'strong',
		'style',
		'sub',
		'summary',
		'sup',
		'table',
		'tbody',
		'td',
		'template',
		'textarea',
		'tfoot',
		'th',
		'thead',
		'time',
		'title',
		'tr',
		'track',
		'u',
		'ul',
		'var',
		'video',
		'wbr',
	);
}

/**
 * Returns historical HTML elements that remain useful parser coverage.
 *
 * @return string[] Element names.
 */
function wp_html_set_inner_html_fuzzer_deprecated_html_elements(): array {
	return array(
		'acronym',
		'applet',
		'basefont',
		'bgsound',
		'big',
		'blink',
		'center',
		'command',
		'content',
		'dir',
		'font',
		'frame',
		'frameset',
		'image',
		'isindex',
		'keygen',
		'listing',
		'marquee',
		'menuitem',
		'multicol',
		'nextid',
		'nobr',
		'noembed',
		'noframes',
		'param',
		'plaintext',
		'shadow',
		'spacer',
		'strike',
		'tt',
		'xmp',
	);
}

/**
 * Returns HTML elements that do not have normal inner HTML.
 *
 * @return string[] Element names.
 */
function wp_html_set_inner_html_fuzzer_html_void_elements(): array {
	return array(
		'area',
		'base',
		'basefont',
		'bgsound',
		'br',
		'col',
		'command',
		'embed',
		'frame',
		'hr',
		'image',
		'img',
		'input',
		'isindex',
		'keygen',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	);
}

/**
 * Returns SVG elements.
 *
 * @return string[] Element names.
 */
function wp_html_set_inner_html_fuzzer_svg_elements(): array {
	return array(
		'a',
		'altGlyph',
		'altGlyphDef',
		'altGlyphItem',
		'animate',
		'animateColor',
		'animateMotion',
		'animateTransform',
		'circle',
		'clipPath',
		'color-profile',
		'cursor',
		'defs',
		'desc',
		'discard',
		'ellipse',
		'feBlend',
		'feColorMatrix',
		'feComponentTransfer',
		'feComposite',
		'feConvolveMatrix',
		'feDiffuseLighting',
		'feDisplacementMap',
		'feDistantLight',
		'feDropShadow',
		'feFlood',
		'feFuncA',
		'feFuncB',
		'feFuncG',
		'feFuncR',
		'feGaussianBlur',
		'feImage',
		'feMerge',
		'feMergeNode',
		'feMorphology',
		'feOffset',
		'fePointLight',
		'feSpecularLighting',
		'feSpotLight',
		'feTile',
		'feTurbulence',
		'filter',
		'font',
		'font-face',
		'font-face-format',
		'font-face-name',
		'font-face-src',
		'font-face-uri',
		'foreignObject',
		'g',
		'glyph',
		'glyphRef',
		'hatch',
		'hatchpath',
		'hkern',
		'image',
		'line',
		'linearGradient',
		'marker',
		'mask',
		'metadata',
		'mesh',
		'meshgradient',
		'meshpatch',
		'meshrow',
		'missing-glyph',
		'mpath',
		'path',
		'pattern',
		'polygon',
		'polyline',
		'radialGradient',
		'rect',
		'script',
		'set',
		'solidcolor',
		'stop',
		'style',
		'svg',
		'switch',
		'symbol',
		'text',
		'textPath',
		'title',
		'tref',
		'tspan',
		'use',
		'view',
		'vkern',
	);
}

/**
 * Returns MathML elements from MathML Core and MathML 3.
 *
 * @return string[] Element names.
 */
function wp_html_set_inner_html_fuzzer_mathml_elements(): array {
	return array(
		'abs',
		'and',
		'annotation',
		'annotation-xml',
		'apply',
		'approx',
		'arccos',
		'arccosh',
		'arccot',
		'arccoth',
		'arccsc',
		'arccsch',
		'arcsec',
		'arcsech',
		'arcsin',
		'arcsinh',
		'arctan',
		'arctanh',
		'arg',
		'bind',
		'bvar',
		'card',
		'cartesianproduct',
		'cbytes',
		'ceiling',
		'cerror',
		'ci',
		'cn',
		'codomain',
		'complexes',
		'compose',
		'condition',
		'conjugate',
		'cos',
		'cosh',
		'cot',
		'coth',
		'cs',
		'csc',
		'csch',
		'csymbol',
		'curl',
		'declare',
		'degree',
		'determinant',
		'diff',
		'divergence',
		'divide',
		'domain',
		'domainofapplication',
		'emptyset',
		'eq',
		'equivalent',
		'eulergamma',
		'exists',
		'exp',
		'exponentiale',
		'factorial',
		'factorof',
		'false',
		'floor',
		'fn',
		'forall',
		'gcd',
		'geq',
		'grad',
		'gt',
		'ident',
		'image',
		'imaginary',
		'imaginaryi',
		'implies',
		'in',
		'infinity',
		'int',
		'integers',
		'intersect',
		'interval',
		'inverse',
		'lambda',
		'laplacian',
		'lcm',
		'leq',
		'limit',
		'list',
		'ln',
		'log',
		'logbase',
		'lowlimit',
		'lt',
		'maction',
		'maligngroup',
		'malignmark',
		'math',
		'matrix',
		'matrixrow',
		'max',
		'mean',
		'median',
		'menclose',
		'merror',
		'mfenced',
		'mfrac',
		'mi',
		'min',
		'minus',
		'mlabeledtr',
		'mmultiscripts',
		'mn',
		'mo',
		'mode',
		'moment',
		'momentabout',
		'mover',
		'mpadded',
		'mphantom',
		'mprescripts',
		'mroot',
		'mrow',
		'ms',
		'mscarry',
		'mscarries',
		'msgroup',
		'msline',
		'mslongdiv',
		'mspace',
		'msqrt',
		'msrow',
		'mstack',
		'mstyle',
		'msub',
		'msubsup',
		'msup',
		'mtable',
		'mtd',
		'mtext',
		'mtr',
		'multiscripts',
		'munder',
		'munderover',
		'naturalnumbers',
		'neq',
		'none',
		'not',
		'notanumber',
		'notin',
		'notsubset',
		'notprsubset',
		'or',
		'otherwise',
		'outerproduct',
		'partialdiff',
		'piece',
		'piecewise',
		'pi',
		'plus',
		'power',
		'primes',
		'product',
		'prsubset',
		'quotient',
		'rationals',
		'reals',
		'real',
		'reln',
		'rem',
		'root',
		'scalarproduct',
		'sdev',
		'sec',
		'sech',
		'selector',
		'semantics',
		'sep',
		'set',
		'setdiff',
		'share',
		'sin',
		'sinh',
		'subset',
		'sum',
		'tan',
		'tanh',
		'tendsto',
		'times',
		'transpose',
		'true',
		'union',
		'uplimit',
		'variance',
		'vector',
		'vectorproduct',
		'xor',
	);
}

/**
 * Returns all HTML element names the fuzzer should cover.
 *
 * @return string[] Element names.
 */
function wp_html_set_inner_html_fuzzer_all_html_elements(): array {
	return array_values(
		array_unique(
			array_merge(
				wp_html_set_inner_html_fuzzer_html_elements(),
				wp_html_set_inner_html_fuzzer_deprecated_html_elements()
			)
		)
	);
}

/**
 * Returns a deterministic custom element name.
 *
 * @param WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng PRNG.
 * @return string Custom element name.
 */
function wp_html_set_inner_html_fuzzer_custom_element_name( WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng ): string {
	$prefix = $rng->choice( array( 'x', 'wp', 'codex', 'fuzz', 'html-api' ) );
	$suffix = $rng->choice( array( 'alpha', 'beta', 'panel', 'card', 'thing', 'node' ) );
	return "{$prefix}-{$suffix}-" . $rng->int( 0, 999 );
}

/**
 * Returns randomized attributes.
 *
 * @param WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng PRNG.
 * @param string                             $namespace Element namespace.
 * @return string Attribute text.
 */
function wp_html_set_inner_html_fuzzer_attrs( WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng, string $namespace = 'html' ): string {
	$attributes = array(
		'id'               => 'fuzz-' . $rng->int( 0, 99 ),
		'class'            => $rng->choice( array( 'alpha beta', 'one', 'two', 'targetish' ) ),
		'data-fuzz'        => (string) $rng->int( 0, 999 ),
		'title'            => $rng->choice( array( 'title', 'a &amp; b', '<not markup>' ) ),
		'aria-label'       => 'label',
		'hidden'           => null,
		'xml:space'        => 'preserve',
		'xlink:href'       => '#fuzz',
		'encoding'         => $rng->choice( array( 'text/html', 'application/xhtml+xml', 'application/xml' ) ),
		'xmlns'            => 'svg' === $namespace ? 'http://www.w3.org/2000/svg' : 'http://www.w3.org/1998/Math/MathML',
	);

	$out   = '';
	$count = $rng->int( 0, 4 );
	$keys  = array_keys( $attributes );
	for ( $i = 0; $i < $count; ++$i ) {
		$name  = $rng->choice( $keys );
		$value = $attributes[ $name ];
		if ( null === $value ) {
			$out .= " {$name}";
			continue;
		}
		$quote = $rng->choice( array( '"', "'" ) );
		$out  .= " {$name}={$quote}{$value}{$quote}";
	}

	if ( $rng->chance( 8 ) ) {
		$out .= ' data-fuzz data-fuzz="duplicate"';
	}

	return $out;
}

/**
 * Renders one HTML element.
 *
 * @param string                             $tag Element name.
 * @param WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng PRNG.
 * @param string                             $content Element contents.
 * @return string HTML.
 */
function wp_html_set_inner_html_fuzzer_render_html_element( string $tag, WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng, string $content = 'x' ): string {
	$attrs = wp_html_set_inner_html_fuzzer_attrs( $rng, 'html' );
	if ( in_array( $tag, wp_html_set_inner_html_fuzzer_html_void_elements(), true ) ) {
		return "<{$tag}{$attrs}>";
	}

	if ( in_array( $tag, array( 'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext' ), true ) ) {
		$content = 'style' === $tag ? 'a{color:red}' : '1 < 2 & 3';
	}

	if ( in_array( $tag, array( 'textarea', 'title' ), true ) ) {
		$content = 'rcdata &amp; text';
	}

	return "<{$tag}{$attrs}>{$content}</{$tag}>";
}

/**
 * Renders one SVG element inside an SVG container.
 *
 * @param string                             $tag Element name.
 * @param WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng PRNG.
 * @return string HTML.
 */
function wp_html_set_inner_html_fuzzer_render_svg_element( string $tag, WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng ): string {
	$attrs   = wp_html_set_inner_html_fuzzer_attrs( $rng, 'svg' );
	$content = in_array( $tag, array( 'script', 'style' ), true ) ? '1 < 2' : '<title>svg</title>';
	return "<svg><{$tag}{$attrs}>{$content}</{$tag}></svg>";
}

/**
 * Renders one MathML element inside a MathML container.
 *
 * @param string                             $tag Element name.
 * @param WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng PRNG.
 * @return string HTML.
 */
function wp_html_set_inner_html_fuzzer_render_mathml_element( string $tag, WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng ): string {
	$attrs   = wp_html_set_inner_html_fuzzer_attrs( $rng, 'math' );
	$content = 'annotation-xml' === $tag ? '<p>html integration</p>' : '<mi>x</mi>';
	return "<math><{$tag}{$attrs}>{$content}</{$tag}></math>";
}

/**
 * Returns HTML element tags suitable for structurally safe source interiors.
 *
 * @return string[] Element names.
 */
function wp_html_set_inner_html_fuzzer_safe_html_elements(): array {
	return array_values(
		array_diff(
			wp_html_set_inner_html_fuzzer_all_html_elements(),
			array(
				'body',
				'frame',
				'frameset',
				'head',
				'html',
				'plaintext',
			)
		)
	);
}

/**
 * Returns one random balanced tree.
 *
 * @param WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng PRNG.
 * @param int                                $depth Remaining depth.
 * @param bool                               $allow_leaks Whether leak-prone syntax is allowed.
 * @return string HTML.
 */
function wp_html_set_inner_html_fuzzer_tree( WP_HTML_Set_Inner_HTML_Fuzzer_PRNG $rng, int $depth, bool $allow_leaks ): string {
	if ( $depth <= 0 ) {
		return $rng->choice( array( '', 'text', ' &amp; ', '<!--leaf-->' ) );
	}

	$count = $rng->int( 1, 4 );
	$html  = '';
	for ( $i = 0; $i < $count; ++$i ) {
		$kind = $rng->choice(
			$allow_leaks
				? array( 'text', 'html', 'svg', 'math', 'custom', 'template', 'table', 'leak' )
				: array( 'text', 'html', 'svg', 'math', 'custom', 'template', 'table' )
		);

		switch ( $kind ) {
			case 'text':
				$html .= $rng->choice( array( 'text', '0', "line\nbreak", '<!--comment-->', ' &amp; ' ) );
				break;

			case 'html':
				$tags = $allow_leaks
					? wp_html_set_inner_html_fuzzer_all_html_elements()
					: wp_html_set_inner_html_fuzzer_safe_html_elements();
				$tag  = $rng->choice( $tags );
				$html .= wp_html_set_inner_html_fuzzer_render_html_element(
					$tag,
					$rng,
					wp_html_set_inner_html_fuzzer_tree( $rng, $depth - 1, false )
				);
				break;

			case 'svg':
				$html .= wp_html_set_inner_html_fuzzer_render_svg_element(
					$rng->choice( wp_html_set_inner_html_fuzzer_svg_elements() ),
					$rng
				);
				break;

			case 'math':
				$html .= wp_html_set_inner_html_fuzzer_render_mathml_element(
					$rng->choice( wp_html_set_inner_html_fuzzer_mathml_elements() ),
					$rng
				);
				break;

			case 'custom':
				$tag   = wp_html_set_inner_html_fuzzer_custom_element_name( $rng );
				$html .= "<{$tag}" . wp_html_set_inner_html_fuzzer_attrs( $rng ) . '>' .
					wp_html_set_inner_html_fuzzer_tree( $rng, $depth - 1, false ) .
					"</{$tag}>";
				break;

			case 'template':
				$html .= '<template>' . wp_html_set_inner_html_fuzzer_tree( $rng, $depth - 1, $allow_leaks ) . '</template>';
				break;

			case 'table':
				$html .= $rng->choice(
					array(
						'<table><caption>c</caption><tbody><tr><td>cell</td></tr></tbody></table>',
						'<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>c</td></tr></tbody></table>',
						'<table><td>c</table>',
					)
				);
				break;

			case 'leak':
				$html .= $rng->choice(
					array(
						'</div><p>leak</p>',
						'</section><span>leak</span>',
						'<a>nested</a>',
						'<b>unclosed',
						'<body add-class>x',
						'<html lang="en">x',
						'<plaintext>tail',
					)
				);
				break;
		}
	}

	return $html;
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
			$tag = $rng->choice( wp_html_set_inner_html_fuzzer_all_html_elements() );
			return wp_html_set_inner_html_fuzzer_render_html_element(
				$tag,
				$rng,
				$rng->choice( array( 'x', 'y', '<em>z</em>', '' ) )
			);
		},
		'omitted'     => static function () use ( $rng ): string {
			return $rng->choice( array( '<p>one<p>two', '<ul><li>one<li>two</ul>', '<dl><dt>a<dd>b' ) );
		},
		'foreign'     => static function () use ( $rng ): string {
			return $rng->choice(
				array(
					wp_html_set_inner_html_fuzzer_render_svg_element(
						$rng->choice( wp_html_set_inner_html_fuzzer_svg_elements() ),
						$rng
					),
					'<svg><html lang="fr"></html></svg>',
					wp_html_set_inner_html_fuzzer_render_mathml_element(
						$rng->choice( wp_html_set_inner_html_fuzzer_mathml_elements() ),
						$rng
					),
				)
			);
		},
		'template'    => static function () use ( $rng ): string {
			return $rng->choice( array( '<template><body add-class>t</template>', '<template></body><p>x</p></template>' ) );
		},
		'rawtext'     => static function () use ( $rng ): string {
			return $rng->choice( array( '<script>1 < 2</script>', '<style>a{color:red}</style>', '<textarea>x</textarea>' ) );
		},
		'leak'        => static function () use ( $rng ): string {
			return $rng->choice( array( '</div><p>leak</p>', '</section><span>leak</span>', '<a>nested</a>', '<b>unclosed', '<body add-class>x', '<html lang="en">x', '<plaintext>tail' ) );
		},
		'table'       => static function () use ( $rng ): string {
			return $rng->choice( array( '<table><tr><td>c</td></tr></table>', '<table><td>c</table>' ) );
		},
		'tree'        => static function () use ( $rng, $allow_leaks ): string {
			return wp_html_set_inner_html_fuzzer_tree( $rng, 2, $allow_leaks );
		},
		'custom'      => static function () use ( $rng ): string {
			$tag = wp_html_set_inner_html_fuzzer_custom_element_name( $rng );
			return "<{$tag}" . wp_html_set_inner_html_fuzzer_attrs( $rng ) . '>custom</' . $tag . '>';
		},
	);

	if ( ! $allow_leaks ) {
		unset( $snippets['leak'] );
		unset( $snippets['omitted'] );
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
	$prefix     = wp_html_set_inner_html_fuzzer_tree( $rng, 2, false );
	$inner      = wp_html_set_inner_html_fuzzer_tree( $rng, 3, false );
	$suffix     = wp_html_set_inner_html_fuzzer_tree( $rng, 2, false );
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
	$cases = array(
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

	foreach ( wp_html_set_inner_html_fuzzer_all_html_elements() as $tag ) {
		$rng           = new WP_HTML_Set_Inner_HTML_Fuzzer_PRNG( 'corpus-html-' . $tag );
		$replacement   = wp_html_set_inner_html_fuzzer_render_html_element( $tag, $rng, '<span>html</span>' );
		$cases[]       = array(
			'seed'        => 0,
			'name'        => 'coverage-html-' . $tag,
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1">Old</div><span>After</span>',
			'replacement' => $replacement,
			'expected'    => '<div data-fuzz-target="1">' . $replacement . '</div><span>After</span>',
			'expectSet'   => null,
		);
	}

	foreach ( wp_html_set_inner_html_fuzzer_svg_elements() as $tag ) {
		$rng           = new WP_HTML_Set_Inner_HTML_Fuzzer_PRNG( 'corpus-svg-' . $tag );
		$replacement   = wp_html_set_inner_html_fuzzer_render_svg_element( $tag, $rng );
		$cases[]       = array(
			'seed'        => 0,
			'name'        => 'coverage-svg-' . strtolower( $tag ),
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1">Old</div><span>After</span>',
			'replacement' => $replacement,
			'expected'    => '<div data-fuzz-target="1">' . $replacement . '</div><span>After</span>',
			'expectSet'   => null,
		);
	}

	foreach ( wp_html_set_inner_html_fuzzer_mathml_elements() as $tag ) {
		$rng           = new WP_HTML_Set_Inner_HTML_Fuzzer_PRNG( 'corpus-mathml-' . $tag );
		$replacement   = wp_html_set_inner_html_fuzzer_render_mathml_element( $tag, $rng );
		$cases[]       = array(
			'seed'        => 0,
			'name'        => 'coverage-mathml-' . $tag,
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1">Old</div><span>After</span>',
			'replacement' => $replacement,
			'expected'    => '<div data-fuzz-target="1">' . $replacement . '</div><span>After</span>',
			'expectSet'   => null,
		);
	}

	for ( $i = 0; $i < 32; ++$i ) {
		$rng           = new WP_HTML_Set_Inner_HTML_Fuzzer_PRNG( 'corpus-custom-' . $i );
		$tag           = wp_html_set_inner_html_fuzzer_custom_element_name( $rng );
		$replacement   = "<{$tag}" . wp_html_set_inner_html_fuzzer_attrs( $rng ) . '>custom</' . $tag . '>';
		$cases[]       = array(
			'seed'        => 0,
			'name'        => 'coverage-custom-' . $i . '-' . $tag,
			'full'        => false,
			'targetTag'   => 'DIV',
			'html'        => '<div data-fuzz-target="1">Old</div><span>After</span>',
			'replacement' => $replacement,
			'expected'    => '<div data-fuzz-target="1">' . $replacement . '</div><span>After</span>',
			'expectSet'   => null,
		);
	}

	return $cases;
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
 * Renders a tree with the optional Lexbor oracle.
 *
 * @param string $html HTML.
 * @param bool   $full Whether to parse a full document.
 * @param string $lexbor_oracle_bin Oracle binary path.
 * @return array<string, mixed> Oracle result.
 */
function wp_html_set_inner_html_fuzzer_lexbor_tree( string $html, bool $full, string $lexbor_oracle_bin ): array {
	$input = tempnam( sys_get_temp_dir(), 'wp-html-set-inner-html-' );
	if ( false === $input ) {
		return array(
			'status' => 'error',
			'error'  => 'Could not create temporary oracle input.',
		);
	}

	file_put_contents( $input, $html );
	$mode    = $full ? 'full-document' : 'fragment-body';
	$command = escapeshellarg( $lexbor_oracle_bin ) .
		' --mode ' . escapeshellarg( $mode ) .
		' --context body --max-nodes 10000 --input ' . escapeshellarg( $input );
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	@unlink( $input );

	if ( 0 !== $status ) {
		return array(
			'status' => 'error',
			'error'  => 'Lexbor oracle exited with status ' . $status,
			'output' => implode( "\n", $output ),
		);
	}

	$result = json_decode( implode( "\n", $output ), true );
	if ( ! is_array( $result ) ) {
		return array(
			'status' => 'error',
			'error'  => 'Lexbor oracle returned invalid JSON.',
			'output' => implode( "\n", $output ),
		);
	}

	return $result;
}

/**
 * Counts the leading spaces in a rendered tree line.
 *
 * @param string $line Rendered tree line.
 * @return int Leading spaces.
 */
function wp_html_set_inner_html_fuzzer_tree_indent( string $line ): int {
	return strspn( $line, ' ' );
}

/**
 * Returns an outside-target signature from an html5lib-style rendered tree.
 *
 * @param string $tree Rendered tree.
 * @return string|null Signature, or null when the target marker is absent.
 */
function wp_html_set_inner_html_fuzzer_lexbor_outer_tree_signature( string $tree ): ?string {
	$lines        = preg_split( "/\r\n|\n|\r/", trim( $tree ) );
	$signature    = array();
	$target_found = false;
	$count        = count( $lines );

	for ( $i = 0; $i < $count; ++$i ) {
		$line = $lines[ $i ];
		if ( ! preg_match( '/^(\s*)<[^>]+>$/', $line, $matches ) ) {
			$signature[] = $line;
			continue;
		}

		$indent      = strlen( $matches[1] );
		$is_target   = false;
		$lookahead_i = $i + 1;
		while ( $lookahead_i < $count ) {
			$lookahead        = $lines[ $lookahead_i ];
			$lookahead_indent = wp_html_set_inner_html_fuzzer_tree_indent( $lookahead );
			if ( $lookahead_indent <= $indent ) {
				break;
			}
			if ( $lookahead_indent === $indent + 2 && preg_match( '/^\s*data-fuzz-target="1"$/', $lookahead ) ) {
				$is_target = true;
				break;
			}
			++$lookahead_i;
		}

		if ( ! $is_target ) {
			$signature[] = $line;
			continue;
		}

		$target_found = true;
		$signature[]  = $line;
		for ( $j = $i + 1; $j < $count; ++$j ) {
			$child_line   = $lines[ $j ];
			$child_indent = wp_html_set_inner_html_fuzzer_tree_indent( $child_line );
			if ( $child_indent <= $indent ) {
				$i = $j - 1;
				break;
			}
			if ( $child_indent === $indent + 2 && preg_match( '/^\s*[^<"\s][^=]*=".*"$/', $child_line ) ) {
				$signature[] = $child_line;
			}
			if ( $j === $count - 1 ) {
				$i = $j;
			}
		}
	}

	return $target_found ? implode( "\n", $signature ) : null;
}

/**
 * Checks accepted updates with the optional Lexbor oracle.
 *
 * @param string      $original          Original HTML.
 * @param string      $updated           Updated HTML.
 * @param bool        $full              Whether to parse a full document.
 * @param string|null $lexbor_oracle_bin Optional Lexbor oracle binary.
 * @return array<string, mixed> Check result.
 */
function wp_html_set_inner_html_fuzzer_check_lexbor_outside_tree( string $original, string $updated, bool $full, ?string $lexbor_oracle_bin ): array {
	if ( null === $lexbor_oracle_bin ) {
		return array( 'status' => 'skipped' );
	}

	$original_tree = wp_html_set_inner_html_fuzzer_lexbor_tree( $original, $full, $lexbor_oracle_bin );
	$updated_tree  = wp_html_set_inner_html_fuzzer_lexbor_tree( $updated, $full, $lexbor_oracle_bin );

	if ( 'ok' !== ( $original_tree['status'] ?? null ) || 'ok' !== ( $updated_tree['status'] ?? null ) ) {
		return array(
			'status'       => 'skipped',
			'originalTree' => $original_tree,
			'updatedTree'  => $updated_tree,
		);
	}

	$original_signature = wp_html_set_inner_html_fuzzer_lexbor_outer_tree_signature( (string) $original_tree['tree'] );
	$updated_signature  = wp_html_set_inner_html_fuzzer_lexbor_outer_tree_signature( (string) $updated_tree['tree'] );
	if ( null === $original_signature || null === $updated_signature ) {
		return array(
			'status'            => 'skipped',
			'originalSignature' => $original_signature,
			'updatedSignature'  => $updated_signature,
		);
	}

	return array(
		'status'            => $original_signature === $updated_signature ? 'ok' : 'changed',
		'originalSignature' => $original_signature,
		'updatedSignature'  => $updated_signature,
		'originalOracle'    => $original_tree['oracle'] ?? null,
		'updatedOracle'     => $updated_tree['oracle'] ?? null,
		'originalSelfCheck' => $original_tree['selfCheck'] ?? null,
		'updatedSelfCheck'  => $updated_tree['selfCheck'] ?? null,
	);
}

/**
 * Returns a compact coverage inventory summary.
 *
 * @return array<string, int> Coverage counts.
 */
function wp_html_set_inner_html_fuzzer_coverage_summary(): array {
	return array(
		'htmlElements'        => count( wp_html_set_inner_html_fuzzer_all_html_elements() ),
		'svgElements'         => count( wp_html_set_inner_html_fuzzer_svg_elements() ),
		'mathmlElements'      => count( wp_html_set_inner_html_fuzzer_mathml_elements() ),
		'customElementCorpus' => 32,
	);
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
 * @param array<string, string|bool|int|null> $case              Case.
 * @param string|null                         $lexbor_oracle_bin Optional Lexbor oracle binary.
 * @return array<string, mixed> Result.
 */
function wp_html_set_inner_html_fuzzer_run_case( array $case, ?string $lexbor_oracle_bin = null ): array {
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

	$lexbor_check = wp_html_set_inner_html_fuzzer_check_lexbor_outside_tree(
		$case['html'],
		$updated,
		$case['full'],
		$lexbor_oracle_bin
	);
	if ( 'changed' === $lexbor_check['status'] ) {
		return array(
			'ok'          => false,
			'failure'     => 'accepted-update-changed-lexbor-outside-tree',
			'lexborCheck' => $lexbor_check,
			'updated'     => $updated,
		);
	}

	if ( 'ok' === $lexbor_check['status'] ) {
		return array(
			'ok'     => true,
			'status' => 'accepted-lexbor-checked',
		);
	}

	if ( 'skipped' === $lexbor_check['status'] && null !== $lexbor_oracle_bin ) {
		return array(
			'ok'     => true,
			'status' => 'accepted-lexbor-skipped',
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
$lexbor_oracle_bin = wp_html_set_inner_html_fuzzer_lexbor_oracle_bin( $options );

wp_html_set_inner_html_fuzzer_bootstrap();

$counts = array(
	'corpus'                                => 0,
	'accepted'                              => 0,
	'accepted-lexbor-checked'               => 0,
	'accepted-lexbor-skipped'               => 0,
	'accepted-original-signature-unsupported' => 0,
	'rejected'                              => 0,
	'unsupported-original'                  => 0,
	'failures'                              => 0,
);

foreach ( wp_html_set_inner_html_fuzzer_corpus_cases() as $case ) {
	$result = wp_html_set_inner_html_fuzzer_run_case( $case, $lexbor_oracle_bin );
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
					'coverage'   => wp_html_set_inner_html_fuzzer_coverage_summary(),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			) . "\n";
			exit( 1 );
		}
	}

	$status = $result['status'];
	if ( ! isset( $counts[ $status ] ) ) {
		$counts[ $status ] = 0;
	}
	++$counts[ $status ];
}

for ( $i = 0; $i < $iterations; ++$i ) {
	$seed   = $start_seed + $i;
	$case   = wp_html_set_inner_html_fuzzer_case( $seed );
	$result = wp_html_set_inner_html_fuzzer_run_case( $case, $lexbor_oracle_bin );

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
		'coverage'   => wp_html_set_inner_html_fuzzer_coverage_summary(),
		'lexborOracle' => null === $lexbor_oracle_bin ? null : $lexbor_oracle_bin,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";

exit( 0 === $counts['failures'] ? 0 : 1 );
