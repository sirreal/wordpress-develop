<?php

/**
 * Tests for WP_CSS_Processor.
 *
 * @group css-api
 *
 * @coversDefaultClass WP_CSS_Processor
 */
class Tests_CssApi_WpCssProcessor extends WP_UnitTestCase {

	/**
	 * @ticket TBD
	 * @dataProvider data_valid_rules
	 * @covers ::parse_a_rule
	 */
	public function test_parse_a_rule_valid( string $css ): void {
		$this->assertTrue(
			WP_CSS_Processor::parse_a_rule( $css ),
			"Expected true for: {$css}"
		);
	}

	/**
	 * @ticket TBD
	 * @dataProvider data_invalid_rules
	 * @covers ::parse_a_rule
	 */
	public function test_parse_a_rule_invalid( string $css ): void {
		$this->assertFalse(
			WP_CSS_Processor::parse_a_rule( $css ),
			"Expected false for: {$css}"
		);
	}

	public static function data_valid_rules(): Generator {
		/*
		 * Qualified rules.
		 */
		yield 'Class selector' => array( '.a { color: red }' );
		yield 'Type selector' => array( 'div { }' );
		yield 'Universal selector' => array( '* { }' );
		yield 'ID selector' => array( '#main { }' );
		yield 'Descendant combinator' => array( 'div .a { }' );
		yield 'Child combinator' => array( 'div > .a + .b { }' );
		yield 'Attribute selector' => array( '[href] { }' );
		yield 'Attribute selector with value' => array( '[type="text"] { }' );
		yield 'Pseudo-class' => array( ':hover { }' );
		yield 'Pseudo-element' => array( '::before { }' );
		yield 'Compound selector' => array( 'div.class#id { }' );
		yield 'Selector list' => array( 'h1, h2, h3 { }' );
		yield 'Multiple declarations' => array( '.a { color: red; font-size: 16px }' );
		yield 'Empty block no space' => array( '.a{}' );
		yield 'Empty prelude' => array( '{ color: red }' );
		yield 'Empty prelude empty block' => array( '{}' );
		yield 'Declaration with !important' => array( '.a { color: red !important }' );

		/*
		 * CSS nesting.
		 */
		yield 'Nested rule' => array( '.parent { color: red; .child { color: blue } }' );
		yield 'Deep nesting' => array( '.a { .b { .c { } } }' );
		yield 'Nesting with declarations at multiple levels' => array(
			'.parent { color: red; .child { color: blue; .grandchild { color: green } } }',
		);

		/*
		 * At-rules: semicolon-terminated.
		 */
		yield '@charset' => array( '@charset "utf-8";' );
		yield '@import url()' => array( '@import url("a");' );
		yield '@import string' => array( '@import "styles.css";' );
		yield '@namespace' => array( '@namespace svg "http://www.w3.org/2000/svg";' );
		yield '@layer name semicolon' => array( '@layer name;' );

		/*
		 * At-rules: block-terminated.
		 */
		yield '@media with block' => array( '@media screen { }' );
		yield '@font-face' => array( '@font-face { }' );
		yield '@keyframes' => array( '@keyframes name { }' );
		yield '@supports' => array( '@supports (display: grid) { }' );
		yield '@layer with block' => array( '@layer { }' );
		yield '@media with content' => array( '@media screen { body { color: red } }' );

		/*
		 * At-rules: complex preludes.
		 */
		yield '@media complex prelude' => array(
			'@media (min-width: 600px) and (max-width: 900px) { }',
		);
		yield '@supports or' => array( '@supports (display: flex) or (display: grid) { }' );

		/*
		 * At-rules: EOF during at-rule (spec returns the at-rule despite parse error).
		 */
		yield '@charset no semicolon' => array( '@charset "utf-8"' );
		yield '@import no semicolon' => array( '@import url("a")' );
		yield '@layer name no semicolon' => array( '@layer name' );
		yield 'At-keyword only' => array( '@media' );

		/*
		 * Whitespace handling.
		 */
		yield 'Leading spaces' => array( '   .a { }' );
		yield 'Trailing spaces' => array( '.a { }   ' );
		yield 'Leading and trailing spaces' => array( '   .a { }   ' );
		yield 'Leading tab' => array( "\t.a { }" );
		yield 'Leading newline' => array( "\n.a { }" );
		yield 'Trailing newline' => array( ".a { }\n" );
		yield 'Leading CRLF' => array( "\r\n.a { }" );
		yield 'Mixed whitespace' => array( " \t\n .a { } \t\n " );

		/*
		 * Comment handling (spec assumes comments stripped; skip them like whitespace).
		 */
		yield 'Leading comment' => array( '/* comment */ .a { }' );
		yield 'Trailing comment' => array( '.a { } /* comment */' );
		yield 'Comments around at-rule' => array( '/* c1 */ @media screen { } /* c2 */' );

		/*
		 * Strings and URLs containing braces (tokenizer treats these as
		 * single tokens, so braces inside must not affect block matching).
		 */
		yield 'String with closing brace in block' => array( '.a { content: "}" }' );
		yield 'String with opening brace in block' => array( '.a { content: "{" }' );
		yield 'String with braces in block' => array( '.a { content: "{ }" }' );
		yield 'URL with closing brace in block' => array( ".a { background: url(a}) }" );

		/*
		 * Functional notation and paired tokens in preludes.
		 */
		yield 'Functional pseudo-class in prelude' => array( '.a:has(.b) { }' );
		yield 'Nested parens and brackets in qualified prelude' => array( '.a:not([b]) { }' );
		yield 'Parens with brace in at-rule prelude (string)' => array( '@supports (content: "{") { }' );
		yield 'Brackets in at-rule prelude' => array( '@foo [screen] { }' );
		yield 'Semicolon inside brackets in at-rule prelude' => array( '@foo [ ; ] { }' );
		yield 'Brace inside brackets in at-rule prelude' => array( '@foo [{] { }' );
		yield 'Brace inside function in at-rule prelude' => array( '@media func({) { }' );

		/*
		 * Escaped characters in preludes.
		 */
		yield 'Escaped open brace in prelude' => array( '.a\{ { }' );
		yield 'Escaped close brace in prelude' => array( '.a\} { }' );

		/*
		 * Nested at-rules inside blocks.
		 */
		yield 'Nested at-rule inside media' => array( '@media screen { @font-face { } }' );
		yield 'Multiple nested rules inside media' => array( '@media screen { .a { } .b { } }' );

		/*
		 * CDO/CDC tokens (<!-- -->) in prelude.
		 */
		yield 'CDO and CDC in qualified prelude' => array( '<!-- --> { }' );
		yield 'CDO and CDC in at-rule prelude' => array( '@foo <!-- --> { }' );

		/*
		 * Unclosed blocks (spec returns rule on EOF inside block).
		 */
		yield 'Unclosed qualified rule block' => array( '.a { color: red' );
		yield 'Unclosed at-rule block' => array( '@media screen { .a { color: red' );
		yield 'Unclosed nested block' => array( '.a { .b {' );

		/*
		 * Additional edge cases from CSS parsing test suites.
		 */
		yield 'At-rule with prelude and trailing comment' => array( '@foo bar; 	/* comment */' );
		yield 'At-rule with bracket and paren nesting' => array( ' /**/ @foo bar{[(4' );
		yield 'At-rule unclosed block with content' => array( '@foo { bar' );
		yield 'At-rule with unclosed bracket in prelude' => array( '@foo [ bar' );
		yield 'Qualified rule with surrounding comments' => array( ' /**/ div > p { color: #aaa;  } /**/ ' );
		yield 'Empty prelude unclosed block with comment' => array( ' /**/ { color: #aaa  ' );
		yield 'CDO CDC not special in prelude' => array( ' /* CDO/CDC are not special */ <!-- --> {' );
	}

	public static function data_invalid_rules(): Generator {
		/*
		 * Empty / whitespace-only (step 3: EOF after skipping whitespace).
		 */
		yield 'Empty string' => array( '' );
		yield 'Whitespace only spaces' => array( '   ' );
		yield 'Whitespace only tab' => array( "\t" );
		yield 'Whitespace only newline' => array( "\n" );
		yield 'Whitespace only mixed' => array( " \t\n\r\n " );
		yield 'Only a comment' => array( '/* comment */' );
		yield 'Only comments' => array( '/* c1 */ /* c2 */' );

		/*
		 * Multiple rules (step 7: non-EOF after consuming rule).
		 */
		yield 'Two qualified rules' => array( '.a {} .b {}' );
		yield 'Two at-rules' => array( '@charset "utf-8"; @import url("a");' );
		yield 'Qualified then at-rule' => array( '.a {} @media {}' );
		yield 'At-rule then qualified' => array( '@media {} .a {}' );
		yield 'At-rule semicolon then qualified' => array( '@charset "utf-8"; .a {}' );

		/*
		 * Trailing non-whitespace after valid rule (step 7).
		 */
		yield 'Trailing ident after qualified rule' => array( '.a {} foo' );
		yield 'Trailing selector after at-rule' => array( '@media screen { } .extra' );
		yield 'Trailing semicolon after qualified rule' => array( '.a {} ;' );
		yield 'Trailing brace after qualified rule' => array( '.a {} }' );

		/*
		 * Qualified rule with no block (EOF during consume qualified rule).
		 */
		yield 'Selector without block' => array( '.a' );
		yield 'Type selector without block' => array( 'div' );
		yield 'Complex selector without block' => array( 'div > .a + .b' );

		/*
		 * Lone punctuation (consumed as prelude, no block found -> EOF -> nothing).
		 */
		yield 'Just a semicolon' => array( ';' );
		yield 'Just a closing brace' => array( '}' );
		yield 'Just a colon' => array( ':' );
		yield 'Just a comma' => array( ',' );

		/*
		 * @ sign not followed by ident (tokenizer produces DELIM, not AT_KEYWORD).
		 * Treated as qualified rule prelude — no block → syntax error.
		 */
		yield 'Bare @ sign' => array( '@' );
		yield '@ then semicolon' => array( '@;' );

		/*
		 * Braces inside () or [] in qualified rule prelude are consumed as
		 * component values, not as the rule's block. The qualified rule never
		 * finds its block → EOF → nothing.
		 */
		yield 'Brace inside parens in qualified prelude' => array( '.a:has({) { }' );
		yield 'Brace inside brackets in qualified prelude' => array( '[x={] { }' );

		/*
		 * Additional edge cases from CSS parsing test suites.
		 */
		yield 'Two qualified rules with declarations' => array( 'div { color: #aaa; } p{}' );
		yield 'Qualified rule then CDC' => array( 'div {} -->' );
		yield 'Empty block then ident' => array( '{}a' );
	}
}
