<?php
/**
 * Oracle check for WP_HTML_Processor::set_inner_html().
 *
 * Verifies the core invariant of set_inner_html(): for every replacement the
 * method ACCEPTS, parsing the original and the updated documents with
 * Dom\HTMLDocument (lexbor, browser-grade) must produce identical trees
 * outside of the context element. Rejections are allowed to be conservative
 * and are only counted.
 *
 * Requires PHP 8.4+ for Dom\HTMLDocument.
 *
 * Usage: php tools/html-api-fuzz/set-inner-html-oracle.php
 *
 * Exits 0 when no violations are found, 1 on violations, 2 when the
 * environment cannot run the check.
 */

error_reporting( E_ALL );

if ( ! class_exists( Dom\HTMLDocument::class ) ) {
	fwrite( STDERR, "Dom\\HTMLDocument unavailable (requires PHP 8.4+); cannot run oracle check.\n" );
	exit( 2 );
}

/*
 * Minimal WordPress shims so the HTML API sources load standalone.
 */
function _doing_it_wrong( $method, $message, $version ) {}
function __( $text ) {
	return $text;
}
function wp_trigger_error( $method, $message, $level = E_USER_NOTICE ) {}
function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function wp_kses_uri_attributes() {
	return array();
}
function wp_scrub_utf8( $text ) {
	return false !== mb_check_encoding( $text, 'UTF-8' ) && mb_check_encoding( $text, 'UTF-8' )
		? $text
		: mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
}
function wp_has_noncharacters( $text ) {
	return false;
}

$includes = dirname( __DIR__, 2 ) . '/src/wp-includes';
require "{$includes}/class-wp-token-map.php";
foreach ( array(
	'html5-named-character-references.php',
	'class-wp-html-attribute-token.php',
	'class-wp-html-span.php',
	'class-wp-html-text-replacement.php',
	'class-wp-html-decoder.php',
	'class-wp-html-doctype-info.php',
	'class-wp-html-tag-processor.php',
	'class-wp-html-unsupported-exception.php',
	'class-wp-html-token.php',
	'class-wp-html-stack-event.php',
	'class-wp-html-open-elements.php',
	'class-wp-html-active-formatting-elements.php',
	'class-wp-html-processor-state.php',
	'class-wp-html-processor.php',
) as $file ) {
	require "{$includes}/html-api/{$file}";
}

/**
 * Serializes a document with the target element replaced by a marker,
 * so two documents can be compared "outside of" that element.
 *
 * Replacing the whole element (rather than emptying it) also removes
 * TEMPLATE contents, which the PHP Dom API does not expose for editing.
 *
 * @param string $doc Body-fragment HTML containing one element with a `data-t` attribute.
 * @return string|null Serialization with the target replaced, or null if no target found.
 */
function outside_shape( string $doc ): ?string {
	$dom = Dom\HTMLDocument::createFromString(
		"<!DOCTYPE html><html><body>{$doc}</body></html>",
		LIBXML_NOERROR
	);
	$target = $dom->querySelector( '[data-t]' );
	if ( null === $target ) {
		return null;
	}
	$target->parentNode->replaceChild( $dom->createElement( 'x-marker' ), $target );
	return $dom->saveHtml();
}

// Context documents. Each must contain exactly one element carrying `data-t`.
$documents = array(
	'<div data-t>x</div>',
	'<div data-t>x</div>after',
	'<div data-t>a<span>b</span></div><p>tail</p>',
	'<span data-t>x</span> more <b>text</b>',
	'<a href="/" data-t>link</a> rest',
	'<b><a href="/" data-t>link</a> bold</b> plain',
	'<p data-t>one<p>two',
	'<p>one<p data-t>two',
	'<ul><li data-t>one<li>two</ul>',
	'<ul><li>one<li data-t>two</ul>',
	'<h1 data-t>title</h1><p>x</p>',
	'<button data-t>press</button> after',
	'<form data-t><input name="a"></form><p>x</p>',
	'<table><tbody><tr><td data-t>cell</td><td>next</td></tr></tbody></table>',
	'<table data-t><tbody><tr><td>cell</td></tr></tbody></table>rest',
	'<select data-t><option>a</option></select>text',
	'<select><option data-t>a</option><option>b</option></select>',
	'<div><p><b>bold<p data-t>reconstructed</p></div>tail',
	'<b>outer<div data-t>x</div>more</b>done',
	'<svg data-t><circle r="1"></circle></svg>text',
	'<div data-t><p>deep<em>nest</em></div><table><tbody><tr><td>x</td></tr></tbody></table>',
	'<template data-t><p>x</p></template><span>y</span>',
	'<div data-t>x',
	'<p data-t>unclosed paragraph',
	'<optgroup data-t><option>x</option></optgroup>',
	'<dl><dt data-t>term<dd>def</dl>',
	'<div data-t class="a">x</div><!-- comment -->text',
);

// Content snippets, from benign to hostile.
$contents = array(
	'',
	'plain text',
	'<p>simple</p>',
	'<p>one<p>two',
	'<div>nested</div>',
	'<a href="#">link</a>',
	'<a>unclosed link',
	'<b>unclosed bold',
	'<b><i>nested unclosed',
	'<em>closed</em> trailing',
	'</a>stray a closer',
	'</div>stray div closer',
	'</p>stray p closer',
	'</li>stray li closer',
	'</b>stray b closer',
	'</table>stray table closer',
	'</svg>stray svg closer',
	'<li>list item',
	'<td>cell',
	'<tr><td>row',
	'<option>opt',
	'<button>btn</button>',
	'<form><input></form>',
	'<form>unclosed form',
	'<table><tr><td>t</td></tr></table>',
	'<table>text<td>fostered',
	'<h2>heading</h2>',
	'<!-- comment -->',
	'<!doctype html>',
	'<![CDATA[not cdata]]>',
	'<script>let a = "</div>";</script>',
	'<script>unclosed script',
	'<style>p { color: red }</style>',
	'<textarea></div>not markup</textarea>',
	'<textarea>unclosed textarea',
	'<pre>' . "\n" . 'newline</pre>',
	'<svg><rect/></svg>',
	'<svg><foreignObject><div>x</div></foreignObject></svg>',
	'<math><mi>x</mi></math>',
	'<select><option>x</select>',
	'<p att="unclosed attribute',
	'<div',
	'<',
	'&amp; &lt; &notanentity;',
	"nulls \x00 inside",
	'<span>ok</span></span></span>',
	'<template><p>tpl</p></template>',
	'<body class="boo">y',
	'<html lang="x">y',
	'<head><meta></head>',
	'text</body>more',
	'text</html>more',
	'<b>closed</b><i>closed</i>',
	'<p style="a>b">attr with gt</p>',
	'deeply <span><span><span>nested</span></span></span>',
);

// Randomized phase: combine atoms into content snippets.
$atoms = array();
foreach ( array( 'a', 'b', 'i', 'em', 'div', 'p', 'span', 'li', 'ul', 'td', 'tr', 'table', 'tbody', 'select', 'option', 'optgroup', 'form', 'button', 'h1', 'h2', 'svg', 'math', 'template', 'pre', 'code', 'nobr', 'ruby', 'rt', 'dd', 'dt', 'caption', 'colgroup', 'hr', 'br', 'img', 'blockquote', 'article', 'header', 'marquee', 'object', 'output', 'label', 'u', 's', 'small', 'big', 'font', 'strike', 'tt' ) as $t ) {
	$atoms[] = "<{$t}>";
	$atoms[] = "</{$t}>";
}
$atoms = array_merge(
	$atoms,
	array( 'text ', 'more words ', '<!-- c -->', '&amp;', '&notanentity;', '<', '</', '<x', '<!doctype html>', "\n", '<a href="x">', '<b class="y">', '<td rowspan="2">', '<input>', '<template shadowrootmode="open">' )
);

mt_srand( 42 );
$random_contents = array();
for ( $i = 0; $i < 400; $i++ ) {
	$n     = mt_rand( 1, 6 );
	$parts = array();
	for ( $j = 0; $j < $n; $j++ ) {
		$parts[] = $atoms[ mt_rand( 0, count( $atoms ) - 1 ) ];
	}
	$random_contents[] = implode( '', $parts );
}
$contents = array_merge( $contents, $random_contents );

$accepted   = 0;
$rejected   = 0;
$violations = 0;
$errors     = 0;

foreach ( $documents as $doc ) {
	foreach ( $contents as $content ) {
		$processor = WP_HTML_Processor::create_fragment( $doc );
		$found     = false;
		while ( $processor->next_tag() ) {
			if ( null !== $processor->get_attribute( 'data-t' ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			++$errors;
			echo "SETUP: could not find target in: {$doc}\n";
			continue;
		}

		$result = $processor->set_inner_html( $content );

		if ( ! $result ) {
			++$rejected;
			continue;
		}

		++$accepted;
		$updated = $processor->get_updated_html();

		// Oracle: outside shape must be identical.
		$before = outside_shape( $doc );
		$after  = outside_shape( $updated );
		if ( null === $before || null === $after ) {
			++$errors;
			echo "ORACLE SETUP FAILURE for doc: {$doc} content: {$content}\n";
			continue;
		}
		if ( $before !== $after ) {
			++$violations;
			echo 'VIOLATION:' . "\n" .
				'  doc:      ' . var_export( $doc, true ) . "\n" .
				'  content:  ' . var_export( $content, true ) . "\n" .
				'  updated:  ' . var_export( $updated, true ) . "\n" .
				"  before:   {$before}\n" .
				"  after:    {$after}\n";
		}

		// The processor itself must remain healthy through the end of the document.
		while ( $processor->next_token() ) {
			continue;
		}
		if ( null !== $processor->get_last_error() ) {
			++$violations;
			echo "PROCESSOR FAILED after accepting:\n  doc: {$doc}\n  content: {$content}\n  error: {$processor->get_last_error()}\n";
		}
	}
}

$total = $accepted + $rejected;
echo "\n{$total} cases: {$accepted} accepted, {$rejected} rejected, {$violations} violations, {$errors} setup errors\n";
exit( ( $violations > 0 || $errors > 0 ) ? 1 : 0 );
