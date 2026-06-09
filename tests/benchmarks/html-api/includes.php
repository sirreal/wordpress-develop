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
 * Validates a non-negative number option.
 *
 * @param mixed  $value Option value.
 * @param string $name  Option name.
 */
function wp_html_api_benchmark_assert_non_negative_number( $value, $name ) {
	if ( ! is_numeric( $value ) || (float) $value < 0 ) {
		wp_html_api_benchmark_fail( "Option --{$name} must be a non-negative number." );
	}
}

/**
 * Validates a ratio option.
 *
 * @param mixed  $value Option value.
 * @param string $name  Option name.
 */
function wp_html_api_benchmark_assert_ratio( $value, $name ) {
	if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 1 ) {
		wp_html_api_benchmark_fail( "Option --{$name} must be a number between 0 and 1." );
	}
}

/**
 * Validates that an option is one of the allowed values.
 *
 * @param mixed  $value   Option value.
 * @param array  $allowed Allowed values.
 * @param string $name    Option name.
 */
function wp_html_api_benchmark_assert_allowed_value( $value, $allowed, $name ) {
	if ( ! in_array( $value, $allowed, true ) ) {
		wp_html_api_benchmark_fail( "Option --{$name} must be one of: " . implode( ', ', $allowed ) . '.' );
	}
}

/**
 * Returns an environment variable value or a default.
 *
 * @param string $name    Environment variable name.
 * @param mixed  $fallback Fallback value.
 * @return mixed Environment variable value or default.
 */
function wp_html_api_benchmark_getenv_or_default( $name, $fallback ) {
	$value = getenv( $name );

	if ( false === $value || '' === $value ) {
		return $fallback;
	}

	return $value;
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
		),
		'html-processor' => array(
			'title' => 'HTML Processor',
		),
	);

	$operations = array(
		'parse'            => 'Parse',
		'attribute-names'  => 'Attribute Names',
		'attribute-values' => 'Attribute Values',
		'modifiable-text'  => 'Modifiable Text',
		'token-getters'    => 'Token Getters',
	);

	$documents = wp_html_api_benchmark_document_definitions();

	$cases = array();

	foreach ( $processors as $processor_id => $processor ) {
		foreach ( $documents as $document_id => $document ) {
			foreach ( $operations as $operation_id => $operation_title ) {
				$case_id = 'parse' === $operation_id
					? "{$processor_id}:{$document_id}"
					: "{$processor_id}:{$operation_id}:{$document_id}";

				$cases[ $case_id ] = array(
					'title'     => "HTML API > {$processor['title']} > {$operation_title} > {$document['title']}",
					'processor' => $processor_id,
					'document'  => $document_id,
					'operation' => $operation_id,
					'mode'      => 'tag-processor' === $processor_id ? 'tag' : $document['html_processor_mode'],
				);
			}
		}
	}

	return $cases;
}

/**
 * Returns selected benchmark cases.
 *
 * @param array $options Runner options.
 * @return array<string,array<string,mixed>> Selected cases.
 */
function wp_html_api_benchmark_select_cases( $options ) {
	$cases    = wp_html_api_benchmark_cases();
	$selected = array();

	if ( isset( $options['case'] ) ) {
		$case_ids = is_array( $options['case'] ) ? $options['case'] : array( $options['case'] );
		foreach ( $case_ids as $case_id ) {
			if ( ! isset( $cases[ $case_id ] ) ) {
				wp_html_api_benchmark_fail( "Unknown benchmark case: {$case_id}" );
			}
			$selected[ $case_id ] = $cases[ $case_id ];
		}

		return $selected;
	}

	$processors = wp_html_api_benchmark_expand_filter(
		$options['processor'],
		array( 'tag-processor', 'html-processor' ),
		'processor'
	);

	$documents = wp_html_api_benchmark_expand_filter(
		$options['document'],
		wp_html_api_benchmark_document_ids(),
		'document'
	);

	$operations = wp_html_api_benchmark_expand_filter(
		$options['operation'],
		array( 'parse', 'attribute-names', 'attribute-values', 'modifiable-text', 'token-getters' ),
		'operation'
	);

	foreach ( $cases as $case_id => $case ) {
		if (
			in_array( $case['processor'], $processors, true ) &&
			in_array( $case['document'], $documents, true ) &&
			in_array( $case['operation'], $operations, true )
		) {
			$selected[ $case_id ] = $case;
		}
	}

	return $selected;
}

/**
 * Expands an option filter into selected values.
 *
 * @param string $value   Raw filter value.
 * @param array  $allowed Allowed values.
 * @param string $label   Filter label for errors.
 * @return array Selected values.
 */
function wp_html_api_benchmark_expand_filter( $value, $allowed, $label ) {
	if ( 'all' === $value ) {
		return $allowed;
	}

	$selected = array_filter( array_map( 'trim', explode( ',', $value ) ) );

	foreach ( $selected as $item ) {
		if ( ! in_array( $item, $allowed, true ) ) {
			wp_html_api_benchmark_fail( "Unknown {$label}: {$item}" );
		}
	}

	return $selected;
}

/**
 * Returns benchmark document definitions.
 *
 * @return array<string,array<string,string>> Benchmark document definitions.
 */
function wp_html_api_benchmark_document_definitions() {
	return array(
		'block-post'                  => array(
			'title'               => 'block-post',
			'html_processor_mode' => 'html-fragment',
			'generator'           => 'wp_html_api_benchmark_block_post_document',
		),
		'full-page'                   => array(
			'title'               => 'full-page',
			'html_processor_mode' => 'html-full',
			'generator'           => 'wp_html_api_benchmark_full_page_document',
		),
		'wiki-article'                => array(
			'title'               => 'wiki-article',
			'html_processor_mode' => 'html-full',
			'generator'           => 'wp_html_api_benchmark_wiki_article_document',
		),
		'wikipedia-quantum-mechanics' => array(
			'title'               => 'wikipedia-quantum-mechanics',
			'html_processor_mode' => 'html-full',
			'generator'           => 'wp_html_api_benchmark_wikipedia_quantum_mechanics_document',
		),
		'commerce-page'               => array(
			'title'               => 'commerce-page',
			'html_processor_mode' => 'html-full',
			'generator'           => 'wp_html_api_benchmark_commerce_page_document',
		),
		'form-heavy'                  => array(
			'title'               => 'form-heavy',
			'html_processor_mode' => 'html-fragment',
			'generator'           => 'wp_html_api_benchmark_form_heavy_document',
		),
	);
}

/**
 * Returns available benchmark document IDs.
 *
 * @return string[] Benchmark document IDs.
 */
function wp_html_api_benchmark_document_ids() {
	return array_keys( wp_html_api_benchmark_document_definitions() );
}

/**
 * Returns a benchmark document.
 *
 * @param string $document_id Document ID.
 * @return string HTML document.
 */
function wp_html_api_benchmark_document( $document_id ) {
	$documents = wp_html_api_benchmark_document_definitions();

	if ( ! isset( $documents[ $document_id ] ) ) {
		wp_html_api_benchmark_fail( "Unknown benchmark document: {$document_id}" );
	}

	return call_user_func( $documents[ $document_id ]['generator'] );
}

/**
 * Creates a WordPress-like block post fragment.
 *
 * @return string HTML fragment.
 */
function wp_html_api_benchmark_block_post_document() {
	$parts = array();

	for ( $i = 1; $i <= 40; $i++ ) {
		$parts[] = '<!-- wp:group {"layout":{"type":"constrained"}} -->';
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
	return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>HTML API Benchmark</title><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="preload" href="/assets/app.css" as="style"><style>.benchmark-section{margin:1rem 0}.layout{display:grid;grid-template-columns:1fr 18rem;gap:2rem}</style></head><body>' .
		'<header class="site-header" data-wp-interactive="navigation"><a class="skip-link" href="#content">Skip to content</a><nav aria-label="Primary"><ul><li><a href="/">Home</a></li><li><a href="/articles">Articles</a></li><li><a href="/about">About</a></li></ul></nav></header>' .
		'<main id="content" class="layout"><article class="entry-content">' .
		wp_html_api_benchmark_block_post_document() .
		'</article><aside aria-label="Related content"><form action="/search" method="get"><label for="full-page-search">Search</label><input id="full-page-search" name="s" type="search" autocomplete="off"><button type="submit">Search</button></form><ol><li><a href="/guide">Guide</a></li><li><a href="/reference">Reference</a></li><li><a href="/support">Support</a></li></ol></aside></main>' .
		'<template id="card-template"><article class="card"><h2></h2><p></p></article></template><footer><p>Benchmark footer</p></footer><script type="application/json" id="benchmark-data">{"ready":true,"items":40}</script></body></html>';
}

/**
 * Creates a synthetic encyclopedia article full-page document.
 *
 * The structure is modeled after article pages such as Wikipedia's Quantum
 * Mechanics page, but the content is generated and not copied from Wikipedia.
 *
 * @return string Full HTML document.
 */
function wp_html_api_benchmark_wiki_article_document() {
	$sections = array(
		'Overview',
		'Mathematical formulation',
		'Experiments',
		'Applications',
		'History',
		'References',
	);

	$language_links = array();
	for ( $i = 1; $i <= 48; $i++ ) {
		$language_links[] = '<li><a lang="x-' . $i . '" href="/wiki/Quantum_mechanics?lang=' . $i . '" data-language-code="l' . $i . '">Language ' . $i . '</a></li>';
	}

	$toc = array();
	foreach ( $sections as $index => $section ) {
		$toc[] = '<li><a href="#section-' . ( $index + 1 ) . '">' . $section . '</a></li>';
	}

	$article_sections = array();
	foreach ( $sections as $index => $section ) {
		$section_number     = $index + 1;
		$article_sections[] = '<section id="section-' . $section_number . '" class="mw-section mw-section-' . $section_number . '" data-mw-section-id="' . $section_number . '">';
		$article_sections[] = '<h2><span class="mw-headline">' . $section . '</span><a class="mw-editsection" href="/edit/section-' . $section_number . '">edit</a></h2>';
		$article_sections[] = '<p>Generated article prose with <a href="/wiki/Wave_function" title="Wave function">dense links</a>, <dfn data-term-id="term-' . $section_number . '">technical terms</dfn>, citation markers <sup id="cite_ref-' . $section_number . '"><a href="#cite_note-' . $section_number . '">[' . $section_number . ']</a></sup>, and inline equation text <span class="mwe-math-element" data-equation="E=hnu">E = h nu</span>.</p>';
		$article_sections[] = '<figure typeof="mw:File/Thumb" class="thumb tright"><img src="/static/article-image-' . $section_number . '.jpg" width="220" height="140" alt="Generated article figure ' . $section_number . '"><figcaption>Generated figure caption with <a href="/wiki/Reference">reference link</a>.</figcaption></figure>';
		$article_sections[] = '<dl><dt>Term ' . $section_number . '</dt><dd>Definition text with <code>state-' . $section_number . '</code> and <var>psi</var>.</dd></dl>';
		$article_sections[] = '</section>';
	}

	$references = array();
	for ( $i = 1; $i <= 24; $i++ ) {
		$references[] = '<li id="cite_note-' . $i . '"><cite>Generated reference ' . $i . '</cite> <a href="#cite_ref-' . $i . '">back</a></li>';
	}

	return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Quantum mechanics - Benchmark Encyclopedia</title><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="canonical" href="https://example.test/wiki/Quantum_mechanics"></head><body class="skin-vector">' .
		'<header class="vector-header"><a href="#content" class="mw-jump-link">Jump to content</a><form role="search" action="/search"><label for="searchInput">Search</label><input id="searchInput" name="search" type="search" accesskey="f"><button type="submit">Search</button></form><nav aria-label="Personal tools"><a href="/login">Log in</a><a href="/create-account">Create account</a></nav></header>' .
		'<div class="mw-page-container"><aside class="vector-sidebar" aria-label="Main menu"><nav><ul><li><a href="/wiki/Main_Page">Main page</a></li><li><a href="/wiki/Contents">Contents</a></li><li><a href="/wiki/Current_events">Current events</a></li></ul></nav><section aria-label="Languages"><h2>Languages</h2><ul>' . implode( '', $language_links ) . '</ul></section></aside>' .
		'<main id="content" class="mw-body" data-mw-ve-target-container><article class="mw-parser-output"><h1 id="firstHeading">Quantum mechanics</h1><p class="hatnote">This generated benchmark page imitates the shape of a large encyclopedia article.</p><nav id="toc" class="toc" aria-labelledby="contents-heading"><h2 id="contents-heading">Contents</h2><ol>' . implode( '', $toc ) . '</ol></nav><aside class="infobox" aria-label="Article facts"><h2>Quantum mechanics</h2><img src="/static/infobox.jpg" alt="Generated orbital diagram" width="260" height="180"><dl><dt>Type</dt><dd>Physical theory</dd><dt>Scale</dt><dd>Atomic and subatomic</dd></dl></aside>' . implode( '', $article_sections ) . '<section id="references"><h2>References</h2><ol class="references">' . implode( '', $references ) . '</ol></section></article></main></div>' .
		'<footer class="mw-footer"><ul><li><a href="/privacy">Privacy policy</a></li><li><a href="/about">About</a></li><li><a href="/disclaimer">Disclaimers</a></li></ul></footer><script type="application/json" id="mw-page-config">{"page":"Quantum mechanics","generated":true}</script></body></html>';
}

/**
 * Returns the frozen Wikipedia Quantum mechanics article HTML fixture.
 *
 * @return string Full HTML document.
 */
function wp_html_api_benchmark_wikipedia_quantum_mechanics_document() {
	$file = __DIR__ . '/fixtures/wikipedia-quantum-mechanics.html';
	$html = file_get_contents( $file );

	if ( false === $html ) {
		wp_html_api_benchmark_fail( "Failed to read benchmark fixture: {$file}" );
	}

	return $html;
}

/**
 * Creates a synthetic commerce page full-page document.
 *
 * @return string Full HTML document.
 */
function wp_html_api_benchmark_commerce_page_document() {
	$filters = array(
		'<label><input type="checkbox" name="category[]" value="shirts"> Shirts</label>',
		'<label><input type="checkbox" name="category[]" value="shoes"> Shoes</label>',
		'<label><input type="checkbox" name="category[]" value="bags"> Bags</label>',
		'<label><input type="checkbox" name="sale" value="1"> Sale</label>',
	);

	$products = array();
	for ( $i = 1; $i <= 36; $i++ ) {
		$products[] = '<article class="product-card" data-product-id="' . $i . '" data-sku="SKU-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) . '" aria-labelledby="product-title-' . $i . '"><a href="/products/' . $i . '"><img loading="lazy" src="/images/product-' . $i . '.jpg" width="320" height="240" alt="Generated product ' . $i . '"></a><h2 id="product-title-' . $i . '">Generated product ' . $i . '</h2><p class="price" data-currency="USD">$' . ( 20 + $i ) . '.00</p><p class="rating" aria-label="' . ( 3 + ( $i % 3 ) ) . ' out of 5 stars">*****</p><button type="button" data-wp-on--click="actions.addToCart" data-product-id="' . $i . '">Add to cart</button></article>';
	}

	return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Commerce Benchmark</title><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><link rel="preconnect" href="https://cdn.example.test"></head><body>' .
		'<header><a class="brand" href="/">Store</a><nav aria-label="Shop navigation"><a href="/new">New</a><a href="/sale">Sale</a><a href="/account">Account</a></nav><form role="search"><label for="commerce-search">Search products</label><input id="commerce-search" type="search" name="q"><button>Search</button></form></header>' .
		'<main><section class="hero" data-campaign="summer"><h1>Generated catalog page</h1><p>Commerce layout with repeated cards, filters, buttons, and product metadata.</p></section><form class="filters" method="get" action="/shop"><fieldset><legend>Filter results</legend>' . implode( '', $filters ) . '<label for="sort">Sort</label><select id="sort" name="sort"><option value="popular">Popular</option><option value="price">Price</option></select><button type="submit">Apply</button></fieldset></form><section class="products" aria-label="Products">' . implode( '', $products ) . '</section></main>' .
		'<template id="cart-line-template"><li><span class="name"></span><span class="quantity"></span></li></template><footer><nav><a href="/shipping">Shipping</a><a href="/returns">Returns</a><a href="/contact">Contact</a></nav></footer><script type="application/json" id="commerce-state">{"cart":[],"currency":"USD"}</script></body></html>';
}

/**
 * Creates a form-heavy fragment document.
 *
 * @return string HTML fragment.
 */
function wp_html_api_benchmark_form_heavy_document() {
	$fieldsets = array();

	for ( $i = 1; $i <= 18; $i++ ) {
		$fieldsets[] = '<fieldset data-step="' . $i . '"><legend>Step ' . $i . '</legend><label for="field-' . $i . '-name">Name</label><input id="field-' . $i . '-name" name="step[' . $i . '][name]" type="text" required aria-describedby="field-' . $i . '-help"><p id="field-' . $i . '-help">Generated help text.</p><label for="field-' . $i . '-choice">Choice</label><select id="field-' . $i . '-choice" name="step[' . $i . '][choice]"><option value="a">Alpha</option><option value="b">Beta</option><option value="c">Gamma</option></select><textarea name="step[' . $i . '][notes]" rows="3">Initial notes for step ' . $i . '</textarea><button type="button" data-action="remove-step" data-step="' . $i . '">Remove</button></fieldset>';
	}

	return '<form class="complex-form" method="post" action="/submit" data-wp-interactive="forms" data-wp-context=\'{"dirty":false}\'><input type="hidden" name="_wpnonce" value="benchmark-nonce"><div role="alert" hidden>Validation message</div>' . implode( '', $fieldsets ) . '<button type="submit">Submit</button></form>';
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
 * @param array  $benchmark_case Benchmark case.
 * @param string $html           HTML document.
 * @return WP_HTML_Tag_Processor|WP_HTML_Processor Processor instance.
 */
function wp_html_api_benchmark_create_processor( $benchmark_case, $html ) {
	switch ( $benchmark_case['mode'] ) {
		case 'tag':
			return new WP_HTML_Tag_Processor( $html );

		case 'html-fragment':
			$processor = WP_HTML_Processor::create_fragment( $html );
			break;

		case 'html-full':
			$processor = WP_HTML_Processor::create_full_parser( $html );
			break;

		default:
			wp_html_api_benchmark_fail( 'Unknown benchmark mode: ' . $benchmark_case['mode'] );
	}

	if ( null === $processor ) {
		wp_html_api_benchmark_fail( 'Failed to create processor for benchmark mode: ' . $benchmark_case['mode'] );
	}

	return $processor;
}

/**
 * Runs one parsing revolution.
 *
 * @param array  $benchmark_case Benchmark case.
 * @param string $html           HTML document.
 * @return array<string,int> Revolution result.
 */
function wp_html_api_benchmark_run_revolution( $benchmark_case, $html ) {
	$processor = wp_html_api_benchmark_create_processor( $benchmark_case, $html );
	$tokens    = 0;
	$work      = 0;
	$checksum  = 0;

	while ( $processor->next_token() ) {
		++$tokens;

		switch ( $benchmark_case['operation'] ) {
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
				wp_html_api_benchmark_fail( 'Unknown benchmark operation: ' . $benchmark_case['operation'] );
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
 * Returns the delta for a getrusage() counter.
 *
 * @param array  $start Starting resource usage.
 * @param array  $end   Ending resource usage.
 * @param string $key   Resource usage key.
 * @return int Counter delta.
 */
function wp_html_api_benchmark_resource_delta( $start, $end, $key ) {
	$start_value = isset( $start[ $key ] ) ? (int) $start[ $key ] : 0;
	$end_value   = isset( $end[ $key ] ) ? (int) $end[ $key ] : 0;

	return $end_value - $start_value;
}

/**
 * Returns CPU time in nanoseconds from a getrusage() array.
 *
 * @param array $usage Resource usage.
 * @return int CPU time in nanoseconds.
 */
function wp_html_api_benchmark_resource_cpu_time_ns( $usage ) {
	$user_seconds   = isset( $usage['ru_utime.tv_sec'] ) ? (int) $usage['ru_utime.tv_sec'] : 0;
	$user_useconds  = isset( $usage['ru_utime.tv_usec'] ) ? (int) $usage['ru_utime.tv_usec'] : 0;
	$system_seconds = isset( $usage['ru_stime.tv_sec'] ) ? (int) $usage['ru_stime.tv_sec'] : 0;
	$system_usecs   = isset( $usage['ru_stime.tv_usec'] ) ? (int) $usage['ru_stime.tv_usec'] : 0;

	return ( ( $user_seconds + $system_seconds ) * 1000000000 ) + ( ( $user_useconds + $system_usecs ) * 1000 );
}

/**
 * Returns command output, or null when the command cannot be run.
 *
 * @param string $command    Command to run.
 * @param int    $timeout_ms Timeout in milliseconds.
 * @return string|null Command output.
 */
function wp_html_api_benchmark_read_command_output( $command, $timeout_ms = 1000 ) {
	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open( $command, $descriptors, $pipes );

	if ( ! is_resource( $process ) ) {
		return null;
	}

	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stdout   = '';
	$deadline = microtime( true ) + ( $timeout_ms / 1000 );

	do {
		$stdout .= stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );

		$status = proc_get_status( $process );
		if ( empty( $status['running'] ) ) {
			break;
		}

		if ( microtime( true ) >= $deadline ) {
			proc_terminate( $process );
			break;
		}

		usleep( 10000 );
	} while ( true );

	$stdout .= stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$status = proc_close( $process );

	if ( 0 !== $status ) {
		return null;
	}

	return trim( $stdout );
}

/**
 * Counts CPUs from a Linux cpuset list such as "0-3,8".
 *
 * @param string $cpuset CPU set.
 * @return int|null CPU count, or null when unavailable.
 */
function wp_html_api_benchmark_count_cpuset_cpus( $cpuset ) {
	$count = 0;
	$parts = array_filter( array_map( 'trim', explode( ',', $cpuset ) ) );

	foreach ( $parts as $part ) {
		if ( preg_match( '/^(\d+)-(\d+)$/', $part, $matches ) ) {
			$start = (int) $matches[1];
			$end   = (int) $matches[2];
			if ( $end >= $start ) {
				$count += $end - $start + 1;
			}
		} elseif ( false !== filter_var( $part, FILTER_VALIDATE_INT ) ) {
			++$count;
		}
	}

	return $count > 0 ? $count : null;
}

/**
 * Returns the Linux cgroup CPU count, when constrained.
 *
 * @return int|null CPU count, or null when unavailable.
 */
function wp_html_api_benchmark_cgroup_cpu_count() {
	if ( 'Linux' !== PHP_OS_FAMILY ) {
		return null;
	}

	$counts = array();

	if ( is_readable( '/sys/fs/cgroup/cpu.max' ) ) {
		$cpu_max = trim( (string) file_get_contents( '/sys/fs/cgroup/cpu.max' ) );
		$parts   = preg_split( '/\s+/', $cpu_max );
		if ( is_array( $parts ) && count( $parts ) >= 2 && 'max' !== $parts[0] && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) && (int) $parts[1] > 0 ) {
			$counts[] = max( 1, (int) ceil( (int) $parts[0] / (int) $parts[1] ) );
		}
	}

	if ( is_readable( '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' ) && is_readable( '/sys/fs/cgroup/cpu/cpu.cfs_period_us' ) ) {
		$quota  = (int) trim( (string) file_get_contents( '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' ) );
		$period = (int) trim( (string) file_get_contents( '/sys/fs/cgroup/cpu/cpu.cfs_period_us' ) );
		if ( $quota > 0 && $period > 0 ) {
			$counts[] = max( 1, (int) ceil( $quota / $period ) );
		}
	}

	foreach ( array( '/sys/fs/cgroup/cpuset.cpus.effective', '/sys/fs/cgroup/cpuset/cpuset.cpus' ) as $file ) {
		if ( is_readable( $file ) ) {
			$count = wp_html_api_benchmark_count_cpuset_cpus( trim( (string) file_get_contents( $file ) ) );
			if ( null !== $count ) {
				$counts[] = $count;
			}
		}
	}

	return count( $counts ) ? min( $counts ) : null;
}

/**
 * Returns the logical CPU count, when available.
 *
 * @return int|null Logical CPU count.
 */
function wp_html_api_benchmark_logical_cpu_count() {
	static $cpu_count = null;
	static $probed    = false;

	if ( $probed ) {
		return $cpu_count;
	}

	$probed = true;

	$environment_value = getenv( 'HTML_API_BENCHMARK_CPU_COUNT' );
	if ( false !== $environment_value && false !== filter_var( $environment_value, FILTER_VALIDATE_INT ) && (int) $environment_value > 0 ) {
		$cpu_count = (int) $environment_value;
		return $cpu_count;
	}

	$commands         = array();
	$cgroup_cpu_count = wp_html_api_benchmark_cgroup_cpu_count();
	if ( null !== $cgroup_cpu_count ) {
		$cpu_count = $cgroup_cpu_count;
		return $cpu_count;
	}

	if ( 'Darwin' === PHP_OS_FAMILY ) {
		$commands[] = 'sysctl -n hw.logicalcpu';
	}
	$commands[] = 'getconf _NPROCESSORS_ONLN';

	foreach ( $commands as $command ) {
		$output = wp_html_api_benchmark_read_command_output( $command );
		if ( null !== $output && false !== filter_var( $output, FILTER_VALIDATE_INT ) && (int) $output > 0 ) {
			$cpu_count = (int) $output;
			return $cpu_count;
		}
	}

	return null;
}

/**
 * Parses Linux /proc/stat CPU counters.
 *
 * @return array<string,int>|null CPU counters, or null when unavailable.
 */
function wp_html_api_benchmark_linux_cpu_counters() {
	$line = false;
	if ( is_readable( '/proc/stat' ) ) {
		$handle = fopen( '/proc/stat', 'r' );
		if ( false !== $handle ) {
			$line = fgets( $handle );
			fclose( $handle );
		}
	}

	if ( false === $line || 0 !== strpos( $line, 'cpu ' ) ) {
		return null;
	}

	$parts  = array_values( array_filter( explode( ' ', trim( $line ) ), 'strlen' ) );
	$values = array_map( 'intval', array_slice( $parts, 1 ) );

	if ( count( $values ) < 4 ) {
		return null;
	}

	$idle  = $values[3] + ( isset( $values[4] ) ? $values[4] : 0 );
	$total = array_sum( $values );

	return array(
		'idle'  => $idle,
		'total' => $total,
	);
}

/**
 * Returns the host CPU idle ratio, when available.
 *
 * @return float|null CPU idle ratio.
 */
function wp_html_api_benchmark_host_cpu_idle_ratio() {
	if ( 'Linux' === PHP_OS_FAMILY ) {
		$before = wp_html_api_benchmark_linux_cpu_counters();
		if ( null === $before ) {
			return null;
		}

		usleep( 100000 );
		$after = wp_html_api_benchmark_linux_cpu_counters();
		if ( null === $after ) {
			return null;
		}

		$total_delta = $after['total'] - $before['total'];
		$idle_delta  = $after['idle'] - $before['idle'];

		return $total_delta > 0 ? max( 0.0, min( 1.0, $idle_delta / $total_delta ) ) : null;
	}

	if ( 'Darwin' === PHP_OS_FAMILY ) {
		/*
		 * macOS does not expose an unprivileged procfs CPU counter. top is used
		 * only as a best-effort current-idle probe; strict mode still records a
		 * null value when the command is unavailable in restricted environments.
		 */
		$output = wp_html_api_benchmark_read_command_output( 'top -l 1 -n 0 -s 0' );
		if ( null === $output ) {
			return null;
		}

		if ( preg_match( '/CPU usage:\s+[\d.]+%\s+user,\s+[\d.]+%\s+sys,\s+([\d.]+)%\s+idle/', $output, $matches ) ) {
			return max( 0.0, min( 1.0, (float) $matches[1] / 100 ) );
		}
	}

	return null;
}

/**
 * Returns a host load snapshot.
 *
 * @param bool $include_cpu_idle Whether to include the current CPU idle ratio.
 * @return array<string,mixed> Host snapshot.
 */
function wp_html_api_benchmark_host_snapshot( $include_cpu_idle = false ) {
	$load_average      = sys_getloadavg();
	$logical_cpu_count = wp_html_api_benchmark_logical_cpu_count();

	$snapshot = array(
		'loadAverage'       => false === $load_average ? null : $load_average,
		'logicalCpuCount'   => $logical_cpu_count,
		'loadAveragePerCpu' => false !== $load_average && $logical_cpu_count ? $load_average[0] / $logical_cpu_count : null,
	);

	if ( $include_cpu_idle ) {
		$snapshot['cpuIdleRatio'] = wp_html_api_benchmark_host_cpu_idle_ratio();
	}

	return $snapshot;
}

/**
 * Returns host stability warnings for a snapshot.
 *
 * @param array $snapshot Host snapshot.
 * @param array $options  Runner options.
 * @return string[] Stability warnings.
 */
function wp_html_api_benchmark_host_stability_warnings( $snapshot, $options ) {
	$warnings = array();

	if ( null === $snapshot['logicalCpuCount'] ) {
		$warnings[] = 'Logical CPU count is unavailable; load average could not be normalized.';
	} elseif ( null !== $snapshot['loadAveragePerCpu'] && $snapshot['loadAveragePerCpu'] > (float) $options['max-load-ratio'] ) {
		$warnings[] = sprintf(
			'1-minute load average is %.2f per logical CPU, above the configured %.2f limit.',
			$snapshot['loadAveragePerCpu'],
			(float) $options['max-load-ratio']
		);
	}

	if ( array_key_exists( 'cpuIdleRatio', $snapshot ) ) {
		if ( null !== $snapshot['cpuIdleRatio'] && $snapshot['cpuIdleRatio'] < (float) $options['min-host-idle'] ) {
			$warnings[] = sprintf(
				'Host CPU idle ratio is %.2f, below the configured %.2f minimum.',
				$snapshot['cpuIdleRatio'],
				(float) $options['min-host-idle']
			);
		}
	}

	return $warnings;
}

/**
 * Formats a host stability snapshot for console output.
 *
 * @param array $snapshot Host snapshot.
 * @return string Formatted snapshot.
 */
function wp_html_api_benchmark_format_host_snapshot( $snapshot ) {
	$parts = array();

	if ( null !== $snapshot['loadAveragePerCpu'] ) {
		$parts[] = sprintf( 'load/cpu %.2f', $snapshot['loadAveragePerCpu'] );
	}

	if ( array_key_exists( 'cpuIdleRatio', $snapshot ) && null !== $snapshot['cpuIdleRatio'] ) {
		$parts[] = sprintf( 'idle %.2f', $snapshot['cpuIdleRatio'] );
	}

	if ( null !== $snapshot['logicalCpuCount'] ) {
		$parts[] = sprintf( '%d CPUs', $snapshot['logicalCpuCount'] );
	}

	return implode( ', ', $parts );
}

/**
 * Waits for a stable host according to runner options.
 *
 * @param array $options Runner options.
 * @return array<string,mixed> Stability snapshot and warnings.
 */
function wp_html_api_benchmark_wait_for_stable_host( $options ) {
	$mode = $options['stability-check'];

	if ( 'off' === $mode ) {
		return array(
			'snapshot' => wp_html_api_benchmark_host_snapshot( false ),
			'warnings' => array(),
		);
	}

	$deadline = time() + (int) $options['stability-wait-seconds'];

	do {
		$snapshot = wp_html_api_benchmark_host_snapshot( true );
		$warnings = wp_html_api_benchmark_host_stability_warnings( $snapshot, $options );

		if ( empty( $warnings ) ) {
			return array(
				'snapshot' => $snapshot,
				'warnings' => array(),
			);
		}

		if ( time() >= $deadline ) {
			break;
		}

		if ( empty( $options['quiet'] ) ) {
			fwrite(
				STDERR,
				sprintf(
					"Waiting for stable benchmark host (%s; %ds remaining): %s\n",
					wp_html_api_benchmark_format_host_snapshot( $snapshot ),
					max( 0, $deadline - time() ),
					implode( ' ', $warnings )
				)
			);
		}

		sleep( min( 5, max( 1, $deadline - time() ) ) );
	} while ( true );

	if ( 'strict' === $mode ) {
		wp_html_api_benchmark_fail( 'Host is not stable enough for benchmarking. ' . implode( ' ', $warnings ) );
	}

	return array(
		'snapshot' => $snapshot,
		'warnings' => $warnings,
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
		'host'             => wp_html_api_benchmark_host_snapshot( false ),
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
