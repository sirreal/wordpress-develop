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
