<?php
/**
 * Differential test: checks that a change to the HTML API parses every input the same way as a base checkout.
 *
 * Usage: php tests/performance/html-api/difftrace.php --base <checkout> --head <checkout> [options]
 *
 *   --mutations <n>  Mutated slices of the corpus to add to the inputs (default: 20000).
 *   --seed <n>       Seed for the synthetic documents and the mutations (default: 1).
 *   --tag-only       Only the Tag Processor check.
 *   --html-only      Only the HTML Processor check.
 *
 * Tag Processor: the `class-wp-html-tag-processor.php` of each checkout is loaded into
 * one process under a renamed class; every other HTML API file comes from base. Each
 * input is walked several ways (next_token(), next_tag() with and without closers and
 * with queries, with modifications, with bookmarks and seeks), the same action is
 * applied to both processors, and the return value, every public accessor and every
 * `_doing_it_wrong()` call are compared after every step.
 *
 * HTML Processor: each checkout runs in its own process and prints a hash of the token
 * stream of every input (token type and name, breadcrumbs, namespace, attributes,
 * modifiable text, and the error at the end). Inputs whose hashes differ are rerun to
 * show the first differing token.
 *
 * Inputs: the real corpus, the synthetic shapes at two sizes, hand-written edge cases and
 * every prefix of each, and mutated slices of the corpus. Exits 1 when anything differs.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

require_once __DIR__ . '/lib/class-benchmark-bootstrap.php';
require_once __DIR__ . '/lib/class-benchmark-synthetic.php';

$difftrace_log = array();

/**
 * Records the call, so that both processors can be checked for the same warnings.
 *
 * @param string $function_name Function name.
 * @param string $message       Message.
 * @param string $version       Version.
 */
function _doing_it_wrong( $function_name, $message, $version ) {
	$GLOBALS['difftrace_log'][] = "doing_it_wrong {$function_name}: {$message}";
}

/**
 * Records the call, so that both processors can be checked for the same warnings.
 *
 * @param string $function_name Function name.
 * @param string $message       Message.
 * @param int    $error_level   Level.
 */
function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {
	$GLOBALS['difftrace_log'][] = "trigger_error {$function_name}: {$message}";
}

/**
 * Parses options.
 *
 * @param string[] $argv Arguments.
 * @return array Options.
 */
function difftrace_options( array $argv ): array {
	$options = array(
		'mutations' => 20000,
		'seed'      => 1,
	);
	$count   = count( $argv );
	for ( $i = 1; $i < $count; $i++ ) {
		$name = substr( $argv[ $i ], 2 );
		if ( in_array( $name, array( 'tag-only', 'html-only' ), true ) ) {
			$options[ $name ] = true;
		} elseif ( in_array( $name, array( 'base', 'head', 'mutations', 'seed', 'emit-html', 'emit-html-detail' ), true ) && isset( $argv[ $i + 1 ] ) ) {
			$options[ $name ] = $argv[ ++$i ];
		} else {
			fwrite( STDERR, "difftrace.php: unexpected argument {$argv[ $i ]}\n" );
			exit( 2 );
		}
	}
	return $options;
}

/**
 * Hand-written inputs at the edges of the tokenizer: truncated syntax, special
 * elements, attribute syntax, comments, character references, and bytes that need care.
 *
 * @return string[] Inputs.
 */
function difftrace_edge_cases(): array {
	$cases = array(
		'',
		'<',
		'<a',
		'<a ',
		'<a b',
		'<a b=',
		'<a b="',
		'<a b="c',
		'<a b="c"',
		'<a b="c">',
		"<a b='c",
		'<a b="c" d',
		'<a b= ',
		'<a b=c ',
		'<a/',
		'<a /',
		'<a b/',
		'</',
		'</a',
		'</a>',
		'</ a>',
		'</>',
		'</>x',
		'</ >',
		'<//a>',
		'</p foo="bar">',
		'</br>',
		'<a></a ',
		'<!',
		'<!-',
		'<!--',
		'<!-->',
		'<!--->',
		'<!---->',
		'<!-- a -- >',
		'<!-- a --!>',
		'<!--a--!-->',
		'<!--->-->',
		'<!-- --!> -->',
		'<!x>',
		'<!-->x-->',
		'<!--x--',
		'<?',
		'<?>',
		'<?x ?>',
		'<?xml version="1.0"?>',
		'<?php echo 1; ?>',
		'<!DOCTYPE html>',
		'<!doctype html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">',
		'<!DOCTYPE>',
		'<!DOCTYPE html',
		'<!DoCtYpE HtMl SYSTEM "about:legacy-compat">',
		'<![CDATA[x]]>',
		'<![CDATA[ ]>',
		'<svg><![CDATA[x]]></svg>',
		'<svg><![CDATA[x',
		'<math><mi>x</mi><mo>&lt;</mo></math>',
		'<svg><foreignObject><p>x</p></foreignObject></svg>',
		'<svg viewBox="0 0 1 1"><path d="M0"/></svg>',
		'<script>a</script>',
		'<script><!--<script></script>--></script>',
		'<script><!--<script>--></script>',
		'<script><!--</script>',
		'<script>a</scrip',
		'<script>',
		'<SCRIPT>x</SCRIPT >',
		'<script>x</script/>',
		'<script>x</script foo>',
		'<script type="text/template"><div></script>',
		'<script><!--<script></script></script>-->',
		'<style>a{}</style>',
		'<style></styl',
		'<style><!--</style>-->',
		'<STYLE>x</sTyLe>',
		"<textarea>\n<b></textarea>",
		'<textarea></textarea',
		'<textarea>&amp;</textarea>',
		"<textarea>\r\nx</textarea>",
		'<title>&amp;x</title>',
		'<title><b></title>',
		'<xmp><b></xmp>',
		'<iframe><b></iframe>',
		'<noembed><b></noembed>',
		'<noframes><b></noframes>',
		'<noscript><b></noscript>',
		'<plaintext><b>',
		'<plaintext>',
		'<a href=x>',
		"<a href='x'>",
		'<a href=x/>',
		'<a/b/c>',
		'<a =b>',
		'<a ==b>',
		'<a b c d>',
		'<a b=c b=d>',
		'<a B=c b=d>',
		"<a\tb\nc\fd\re>",
		'<a b="&amp;&lt;&#x41;&#65;&notin;&notit;&amp">',
		'<a b=&amp;c>',
		'<a class="x  y	z x">',
		'<a class>',
		'<a class="">',
		'<a CLASS="A a">',
		'<a class="x" class="y">',
		'<a title="a>b">',
		'<a title=a>b>',
		'<a b="c"d="e">',
		'<a b=">"c>',
		'<a "b"=c>',
		"<a 'b'>",
		'<a <b>',
		'<a<b>',
		'<a b<c>',
		'<div>text &amp; more</div>',
		"a\0b",
		"<a\0b>",
		"<\0>",
		"<a b=\"\0\">",
		'<1>',
		'<-a>',
		'< a>',
		'<a.b>',
		'<A-B:C>',
		'<br/>',
		'<br />',
		'<br/ >',
		'<img src=x/>',
		'<a b/>c',
		'<div/>',
		'<input disabled/>',
		'x&#0;y',
		'&#x110000;',
		'&',
		'&amp',
		'&;',
		'&#',
		'&#x',
		'&#x;',
		'&#99999999999;',
		'&notin',
		'&NotNestedLessLess;',
		'&AMP;',
		'&amp;amp;',
		'<p>a<p>b',
		"\xEF\xBB\xBF<p>",
		"<p>\xC3\xA9\xF0\x9F\x98\x80</p>",
		"<p>\xFF\xFE</p>",
		"<p title=\"\xC3\">x</p>",
		"<\xC3\xA9>",
		'<template><td>x</template>',
		'<table><tr><td>x</table>',
		'<table>x<tr>',
		'<select><option>x</select>',
		'<ul><li>a<li>b</ul>',
		'<b><i>x</b></i>',
		'<a><a>x',
		'<p><div>x</p></div>',
		'<html><head><title>t</title></head><body><p class="x" id=y>Hello<br>world</p></body></html>',
		str_repeat( '<div a=b>', 50 ) . str_repeat( '</div>', 50 ),
		'<a ' . str_repeat( 'b ', 200 ) . '>',
		'<p>' . str_repeat( ' ', 300 ) . '</p>',
	);

	// Special elements: case, near-miss names, NUL bytes, and leading newlines.
	array_push(
		$cases,
		'<TITLE>a</title>',
		'<titlex>y</titlex>',
		"<listing>\nx</listing>",
		"<LISTING>\r\nx",
		'<svg><title>a</title></svg>',
		'<textarea/>a</textarea>',
		'</script>',
		"<scri\0pt>x</script>",
		"<title\0>x</title>",
		"<t\xC4\xB1tle>x</title>",
		'<IFRAME>x</iframe>',
		'<NoEmBeD>x</noembed>',
		'<NOFRAMES>x</noframes>',
		'<Xmp>x</xmp>',
		'<scripts>x</scripts>',
		'<styles>x',
		"<pre>\n\nx</pre>",
		"<pre a=b>\nx</pre>",
		"<textarea>\n\nx</textarea>",
		'<p><xmp>b</xmp>c',
		'<p><title>b</title>c',
		'<table data-a=1><textarea>x</textarea>',
		"<table><tr><td><pre><br><td>\ny</table>",
		"<pre>\nx</pre><listing>\ny</listing>"
	);

	// Every whitespace byte at every place the tokenizer skips or ends on whitespace.
	foreach ( array( ' ', "\t", "\f", "\r", "\n", "\r\n" ) as $ws ) {
		$cases[] = "<a{$ws}b{$ws}={$ws}c{$ws}d{$ws}={$ws}\"e\"{$ws}f{$ws}={$ws}'g'{$ws}h{$ws}/{$ws}>";
		$cases[] = "<a b{$ws}>x</a{$ws}>";
		$cases[] = "<a{$ws}/{$ws}>";
		$cases[] = "<a b={$ws}>";
		$cases[] = "<a b{$ws}={$ws}";
		$cases[] = "<!DOCTYPE{$ws}html{$ws}PUBLIC{$ws}\"x\"{$ws}\"y\"{$ws}>";
		$cases[] = "<script>x</script{$ws}>y";
		$cases[] = "<textarea>{$ws}x</textarea{$ws}>";
		$cases[] = "<pre>{$ws}x</pre>";
		$cases[] = "<title{$ws}a=b>x</title{$ws}>";
	}
	return $cases;
}

/**
 * Yields every input, as id => HTML, in the same order on every run with the same seed.
 *
 * @param int $seed      Seed.
 * @param int $mutations Number of mutated slices.
 * @return Generator
 */
function difftrace_inputs( int $seed, int $mutations ): Generator {
	$corpus = glob( __DIR__ . '/corpus/real/*.{html,htm}', GLOB_BRACE );
	sort( $corpus );
	$documents = array();
	foreach ( $corpus as $path ) {
		$documents[ basename( $path ) ] = file_get_contents( $path );
	}
	foreach ( $documents as $id => $html ) {
		yield "corpus {$id}" => $html;
	}

	foreach ( Benchmark_Synthetic::shapes() as $shape ) {
		foreach ( array( 5000, 100000 ) as $size ) {
			yield "synthetic {$shape} {$size}" => Benchmark_Synthetic::generate( $shape, $size, $seed );
		}
	}

	foreach ( difftrace_edge_cases() as $n => $html ) {
		$length = strlen( $html );
		for ( $i = 0; $i <= $length; $i++ ) {
			yield "edge {$n} prefix {$i}" => substr( $html, 0, $i );
		}
	}

	if ( empty( $documents ) || $mutations <= 0 ) {
		return;
	}
	$sources  = array_values( $documents );
	$alphabet = array( '<', '>', '/', '!', '-', '-', '=', '"', "'", ' ', "\t", "\n", "\0", '&', ';', '#', '?', '[', ']', 'a', 'x', 'S', 'T', '<!--', '-->', '</', '/>', '<script>', '</script>', '<textarea>', '<style>', '<title>', '<svg>', '<math>', '<![CDATA[', ']]>', '<!DOCTYPE html>', '&amp;', '&lt', "\xC3", "\xC3\xA9", "\r", "\f", "\r\n", ' = ', "\t" );
	$prefixes = array( '', '', '', '<script>', '<textarea>', '<style>', '<title>', '<!--', '<svg>', '<plaintext>', '<xmp>', '<a b="' );
	mt_srand( crc32( "mutations {$seed}" ), MT_RAND_MT19937 );
	for ( $m = 0; $m < $mutations; $m++ ) {
		$source = $sources[ mt_rand( 0, count( $sources ) - 1 ) ];
		$length = mt_rand( 20, 600 );
		$start  = mt_rand( 0, max( 0, strlen( $source ) - $length ) );
		$html   = $prefixes[ mt_rand( 0, count( $prefixes ) - 1 ) ] . substr( $source, $start, $length );
		$edits  = mt_rand( 1, 4 );
		for ( $e = 0; $e < $edits; $e++ ) {
			$at = mt_rand( 0, strlen( $html ) );
			switch ( mt_rand( 0, 4 ) ) {
				case 0: // Insert.
					$html = substr( $html, 0, $at ) . $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ] . substr( $html, $at );
					break;
				case 1: // Replace one byte.
					$html = substr( $html, 0, $at ) . $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ] . substr( $html, $at + 1 );
					break;
				case 2: // Delete a few bytes.
					$html = substr( $html, 0, $at ) . substr( $html, $at + mt_rand( 1, 8 ) );
					break;
				case 3: // Truncate.
					$html = substr( $html, 0, $at );
					break;
				default: // Whitespace beside an equals sign.
					$equals = strpos( $html, '=', $at );
					if ( false !== $equals ) {
						$where = $equals + mt_rand( 0, 1 );
						$html  = substr( $html, 0, $where ) . substr( " \t\f\r\n", mt_rand( 0, 4 ), 1 ) . substr( $html, $where );
					}
			}
		}
		yield "mutation {$m}" => $html;
	}
}

/**
 * Loads a checkout's Tag Processor source under another class name.
 *
 * @param string $checkout   Checkout root.
 * @param string $class_name New class name.
 */
function difftrace_load_renamed( string $checkout, string $class_name ): void {
	$source = file_get_contents( "{$checkout}/src/wp-includes/html-api/class-wp-html-tag-processor.php" );
	if ( false === $source ) {
		fwrite( STDERR, "difftrace.php: no Tag Processor in {$checkout}\n" );
		exit( 2 );
	}
	$source = preg_replace( '~\bWP_HTML_Tag_Processor\b~', $class_name, $source );
	$path   = sys_get_temp_dir() . "/difftrace-{$class_name}-" . getmypid() . '.php';
	file_put_contents( $path, $source );
	require $path;
	unlink( $path );
}

/**
 * Everything observable about the current token through the public API.
 *
 * @param WP_HTML_Tag_Processor $p Processor.
 * @return array Snapshot.
 */
function difftrace_snapshot( $p ): array {
	$doctype  = $p->get_doctype_info();
	$snapshot = array(
		'type'         => $p->get_token_type(),
		'name'         => $p->get_token_name(),
		'tag'          => $p->get_tag(),
		'qualified'    => $p->get_qualified_tag_name(),
		'namespace'    => $p->get_namespace(),
		'closer'       => $p->is_tag_closer(),
		'self_closing' => $p->has_self_closing_flag(),
		'comment_type' => $p->get_comment_type(),
		'comment_text' => $p->get_full_comment_text(),
		'text'         => $p->get_modifiable_text(),
		'incomplete'   => $p->paused_at_incomplete_token(),
		'doctype'      => null === $doctype ? null : get_object_vars( $doctype ),
		'attributes'   => null,
	);
	$names    = $p->get_attribute_names_with_prefix( '' );
	if ( null !== $names ) {
		$snapshot['attributes'] = array();
		foreach ( $names as $name ) {
			$snapshot['attributes'][ $name ] = array( $p->get_attribute( $name ), $p->get_qualified_attribute_name( $name ) );
		}
		$snapshot['data_prefix'] = $p->get_attribute_names_with_prefix( 'data-' );
		$snapshot['probes']      = array( $p->get_attribute( 'CLASS' ), $p->get_attribute( 'missing' ), $p->has_class( 'x' ), $p->has_class( 'X' ) );
		$snapshot['classes']     = array();
		foreach ( $p->class_list() as $class_name ) {
			$snapshot['classes'][] = $class_name;
		}
	}
	return $snapshot;
}

/**
 * Applies one action to a processor and returns what it returned, any exception, and the warnings it raised.
 *
 * @param WP_HTML_Tag_Processor $p      Processor.
 * @param array                 $action Method name and arguments.
 * @return array Outcome.
 */
function difftrace_apply( $p, array $action ): array {
	$GLOBALS['difftrace_log'] = array();
	try {
		$result = $p->{$action[0]}( ...array_slice( $action, 1 ) );
	} catch ( Throwable $e ) {
		$result = 'threw ' . get_class( $e ) . ': ' . $e->getMessage();
	}

	// Messages name the renamed class through __METHOD__, and errors name a file and line; compare without them.
	$renamed = array( 'WP_HTML_Tag_Processor_Base', 'WP_HTML_Tag_Processor_Head' );
	if ( is_string( $result ) && 0 === strpos( $result, 'threw ' ) ) {
		$result = preg_replace( '~,? (?:called )?in \S+ on line \d+~', '', str_replace( $renamed, 'WP_HTML_Tag_Processor', $result ) );
	}
	return array(
		'return' => $result,
		'log'    => str_replace( $renamed, 'WP_HTML_Tag_Processor', $GLOBALS['difftrace_log'] ),
	);
}

/**
 * Walks one input with both Tag Processors in lockstep and returns the first difference, or null.
 *
 * @param string $id   Input id.
 * @param string $html Input.
 * @param string $walk Walk name.
 * @param int    $seed Seed.
 * @param int    $steps Steps taken, incremented.
 * @return array|null Difference.
 */
function difftrace_tag_walk( string $id, string $html, string $walk, int $seed, int &$steps ): ?array {
	$a = new WP_HTML_Tag_Processor_Base( $html );
	$b = new WP_HTML_Tag_Processor_Head( $html );
	mt_srand( crc32( "{$seed} {$id} {$walk}" ), MT_RAND_MT19937 );

	$limit     = in_array( $walk, array( 'modify', 'bookmarks', 'mixed' ), true ) ? 20000 : PHP_INT_MAX;
	$snapshot  = null;
	$bookmarks = array();
	$seeks     = 0;
	for ( $step = 0; $step < $limit; $step++ ) {
		$action = array( 'next_token' );
		$kind   = $walk;
		if ( 'mixed' === $walk ) {
			$kind = mt_rand( 0, 1 ) ? 'modify' : 'bookmarks';
		}
		switch ( $kind ) {
			case 'tag':
				$action = array( 'next_tag' );
				break;
			case 'closers':
				$action = array( 'next_tag', array( 'tag_closers' => 'visit' ) );
				break;
			case 'query':
				$queries = array(
					'div',
					'A',
					array( 'class_name' => 'x' ),
					array(
						'tag_name'     => 'p',
						'match_offset' => 2,
					),
					array(
						'tag_name'    => 'script',
						'tag_closers' => 'visit',
					),
				);
				$action  = array( 'next_tag', $queries[ mt_rand( 0, count( $queries ) - 1 ) ] );
				break;
			case 'modify':
				$roll = mt_rand( 0, 99 );
				if ( null !== $snapshot && '#tag' === $snapshot['type'] && $roll < 30 ) {
					$names = array_keys( (array) $snapshot['attributes'] );
					switch ( $roll % 5 ) {
						case 0:
							$action = array( 'set_attribute', 'data-x', mt_rand( 0, 1 ) ? 'a"b&c<d' : true );
							break;
						case 1:
							$action = array( 'remove_attribute', empty( $names ) ? 'id' : $names[ mt_rand( 0, count( $names ) - 1 ) ] );
							break;
						case 2:
							$action = array( 'add_class', 'x' );
							break;
						case 3:
							$action = array( 'remove_class', empty( $snapshot['classes'] ) ? 'x' : $snapshot['classes'][0] );
							break;
						default:
							$action = array( 'set_modifiable_text', 'new </script> & <!-- text' );
					}
				} elseif ( null !== $snapshot && $roll < 40 && '#tag' !== $snapshot['type'] ) {
					$action = array( 'set_modifiable_text', 'x & y' );
				} elseif ( $roll < 43 ) {
					$action = array( 'get_updated_html' );
				}
				break;
			case 'bookmarks':
				$roll = mt_rand( 0, 99 );
				if ( $roll < 10 ) {
					$name               = 'b' . mt_rand( 0, 4 );
					$action             = array( 'set_bookmark', $name );
					$bookmarks[ $name ] = true;
				} elseif ( $roll < 13 && ! empty( $bookmarks ) && $seeks < 100 ) {
					$names  = array_keys( $bookmarks );
					$action = array( 'seek', $names[ mt_rand( 0, count( $names ) - 1 ) ] );
					++$seeks;
				} elseif ( $roll < 15 && ! empty( $bookmarks ) ) {
					$names  = array_keys( $bookmarks );
					$name   = $names[ mt_rand( 0, count( $names ) - 1 ) ];
					$action = array( 'release_bookmark', $name );
					unset( $bookmarks[ $name ] );
				}
				break;
		}

		$outcome_a = difftrace_apply( $a, $action );
		$outcome_b = difftrace_apply( $b, $action );
		$snapshot  = difftrace_snapshot( $a );
		$state_a   = array( $outcome_a, $snapshot );
		$state_b   = array( $outcome_b, difftrace_snapshot( $b ) );
		++$steps;
		if ( $state_a !== $state_b ) {
			return array(
				'walk'   => $walk,
				'step'   => $step,
				'action' => $action,
				'base'   => $state_a,
				'head'   => $state_b,
			);
		}
		if ( 'next_token' === $action[0] || 'next_tag' === $action[0] ) {
			if ( false === $outcome_a['return'] ) {
				break;
			}
		}
	}

	$final_a = difftrace_apply( $a, array( 'get_updated_html' ) );
	$final_b = difftrace_apply( $b, array( 'get_updated_html' ) );
	if ( $final_a !== $final_b ) {
		return array(
			'walk'   => $walk,
			'step'   => 'final get_updated_html()',
			'action' => array( 'get_updated_html' ),
			'base'   => $final_a,
			'head'   => $final_b,
		);
	}
	return null;
}

/**
 * Serializes the HTML Processor's token stream for one input.
 *
 * With `$modify`, it also sets an attribute on some opening tags and calls
 * get_updated_html() on some tokens (on every token of a small input, at most
 * 20 times per input), which makes the processor parse the current token
 * again, and records what those calls return.
 *
 * @param string $html   Input.
 * @param bool   $modify Whether to modify while walking.
 * @return string[] One line per token, then the final state.
 */
function difftrace_html_stream( string $html, bool $modify = false ): array {
	$lines = array();
	$p     = WP_HTML_Processor::create_full_parser( $html );
	if ( null === $p ) {
		return array( 'create_full_parser returned null' );
	}
	mt_srand( crc32( $html ), MT_RAND_MT19937 );
	$flushes = 0;
	// Small inputs set an attribute on half the opening tags and flush on every token, so that a flush
	// lands on virtual tokens and after errors; large inputs do both rarely.
	$small     = strlen( $html ) < 2000;
	$set_below = $small ? 50 : 20;
	while ( $p->next_token() ) {
		if ( $modify ) {
			// Flush before modifying, so that an update set on one token is still pending at the next.
			$roll = mt_rand( 0, 99 );
			if ( ( $small || ( $roll >= 20 && $roll < 25 ) ) && ++$flushes <= 20 ) {
				// Each call copies and reparses the whole document; a cap keeps large inputs linear.
				$lines[] = 'get_updated_html ' . sha1( $p->get_updated_html() );
			}
			if ( $roll < $set_below && '#tag' === $p->get_token_type() && ! $p->is_tag_closer() ) {
				$lines[] = 'set_attribute ' . var_export( $p->set_attribute( 'data-x', 'y' ), true );
			}
		}
		$attributes = array();
		foreach ( (array) $p->get_attribute_names_with_prefix( '' ) as $name ) {
			$attributes[] = $name . '=' . var_export( $p->get_attribute( $name ), true );
		}
		$lines[] = json_encode(
			array(
				$p->get_token_type(),
				$p->get_token_name(),
				$p->is_tag_closer(),
				$p->get_namespace(),
				implode( ' ', (array) $p->get_breadcrumbs() ),
				$attributes,
				$p->get_modifiable_text(),
			),
			JSON_INVALID_UTF8_SUBSTITUTE
		);
	}
	$exception = $p->get_unsupported_exception();
	$lines[]   = 'end ' . var_export( $p->get_last_error(), true ) . ' ' . ( null === $exception ? '' : $exception->getMessage() ) . ' ' . var_export( $p->paused_at_incomplete_token(), true );
	return $lines;
}

/**
 * Runs this script in a child process for one checkout and returns its stdout lines.
 *
 * @param string   $checkout Checkout root.
 * @param array    $options  Options.
 * @param string[] $extra    Extra arguments.
 * @return string[] Lines.
 */
function difftrace_child( string $checkout, array $options, array $extra ): array {
	$command = array_merge(
		array( PHP_BINARY, '-d', 'memory_limit=-1', __FILE__, '--seed', (string) $options['seed'], '--mutations', (string) $options['mutations'] ),
		$extra
	);
	// Stderr is left out of the spec so the child inherits it: passing STDERR makes PHP seek the shared offset.
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ) ), $pipes );
	$output  = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	if ( 0 !== proc_close( $process ) ) {
		fwrite( STDERR, "difftrace.php: the child for {$checkout} failed\n" );
		exit( 2 );
	}
	return explode( "\n", rtrim( $output, "\n" ) );
}

$options = difftrace_options( $argv );
$seed    = (int) $options['seed'];

// Child modes: load one checkout and print the HTML Processor stream.
if ( isset( $options['emit-html'] ) ) {
	Benchmark_Bootstrap::load( $options['emit-html'] );
	foreach ( difftrace_inputs( $seed, (int) $options['mutations'] ) as $id => $html ) {
		echo $id, "\t", sha1( implode( "\n", array_merge( difftrace_html_stream( $html ), array( 'modified' ), difftrace_html_stream( $html, true ) ) ) ), "\n";
	}
	exit( 0 );
}
if ( isset( $options['emit-html-detail'] ) ) {
	list( $checkout, $wanted ) = explode( "\t", $options['emit-html-detail'], 2 );
	Benchmark_Bootstrap::load( $checkout );
	foreach ( difftrace_inputs( $seed, (int) $options['mutations'] ) as $id => $html ) {
		if ( $id === $wanted ) {
			echo implode( "\n", array_merge( difftrace_html_stream( $html ), array( 'modified' ), difftrace_html_stream( $html, true ) ) ), "\n";
			exit( 0 );
		}
	}
	exit( 1 );
}

if ( ! isset( $options['base'], $options['head'] ) ) {
	fwrite( STDERR, "usage: php difftrace.php --base <checkout> --head <checkout> [--mutations <n>] [--seed <n>] [--tag-only|--html-only]\n" );
	exit( 2 );
}
$base = rtrim( $options['base'], '/' );
$head = rtrim( $options['head'], '/' );

$failures = 0;
$started  = microtime( true );

$other_changes = array();
foreach ( glob( "{$base}/src/wp-includes/html-api/*.php" ) as $path ) {
	$file = basename( $path );
	if ( 'class-wp-html-tag-processor.php' !== $file && file_get_contents( $path ) !== @file_get_contents( "{$head}/src/wp-includes/html-api/{$file}" ) ) {
		$other_changes[] = $file;
	}
}
if ( ! empty( $other_changes ) ) {
	fwrite( STDERR, 'Note: head also changes ' . implode( ', ', $other_changes ) . "; the Tag Processor check loads those files from base, the HTML Processor check covers them.\n" );
}

if ( ! isset( $options['html-only'] ) ) {
	Benchmark_Bootstrap::load( $base );
	difftrace_load_renamed( $base, 'WP_HTML_Tag_Processor_Base' );
	difftrace_load_renamed( $head, 'WP_HTML_Tag_Processor_Head' );

	$inputs = 0;
	$steps  = 0;
	$shown  = 0;
	foreach ( difftrace_inputs( $seed, (int) $options['mutations'] ) as $id => $html ) {
		++$inputs;
		foreach ( array( 'token', 'tag', 'closers', 'query', 'modify', 'bookmarks', 'mixed' ) as $walk ) {
			$difference = difftrace_tag_walk( $id, $html, $walk, $seed, $steps );
			if ( null === $difference ) {
				continue;
			}
			++$failures;
			if ( $shown++ < 5 ) {
				echo "Tag Processor differs: {$id}, walk {$difference['walk']}, step {$difference['step']}\n";
				if ( strlen( $html ) <= 300 ) {
					echo '  input: ', var_export( $html, true ), "\n";
				}
				echo '  action: ', json_encode( $difference['action'], JSON_INVALID_UTF8_SUBSTITUTE ), "\n";
				echo '  base: ', json_encode( $difference['base'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR ), "\n";
				echo '  head: ', json_encode( $difference['head'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR ), "\n";
			}
		}
	}
	printf( "Tag Processor: %d inputs, 7 walks each, %d steps compared, %d walks differ.\n", $inputs, $steps, $failures );
}

if ( ! isset( $options['tag-only'] ) ) {
	$base_lines = difftrace_child( $base, $options, array( '--emit-html', $base ) );
	$head_lines = difftrace_child( $head, $options, array( '--emit-html', $head ) );
	$differing  = array();
	foreach ( $base_lines as $n => $line ) {
		if ( ( $head_lines[ $n ] ?? null ) !== $line ) {
			$differing[] = explode( "\t", $line )[0];
		}
	}
	foreach ( array_slice( $differing, 0, 5 ) as $id ) {
		$a = difftrace_child( $base, $options, array( '--emit-html-detail', "{$base}\t{$id}" ) );
		$b = difftrace_child( $head, $options, array( '--emit-html-detail', "{$head}\t{$id}" ) );
		$n = 0;
		while ( $n < max( count( $a ), count( $b ) ) && ( $a[ $n ] ?? null ) === ( $b[ $n ] ?? null ) ) {
			++$n;
		}
		echo "HTML Processor differs: {$id}, token {$n}\n";
		echo '  base: ', $a[ $n ] ?? '(none)', "\n";
		echo '  head: ', $b[ $n ] ?? '(none)', "\n";
	}
	$failures += count( $differing );
	printf( "HTML Processor: %d inputs, %d differ.\n", count( $base_lines ), count( $differing ) );
}

printf( "%s in %.0f s.\n", 0 === $failures ? 'No differences' : 'DIFFERENCES FOUND', microtime( true ) - $started );
exit( 0 === $failures ? 0 : 1 );
