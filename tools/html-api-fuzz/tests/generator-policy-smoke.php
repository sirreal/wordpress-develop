#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_smoke_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function html_api_fuzz_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_smoke_fail( $message );
	}
}

function html_api_fuzz_smoke_valid_utf8( string $bytes ): bool {
	return 1 === preg_match( '//u', $bytes );
}

function html_api_fuzz_smoke_has_non_whitespace_c0_control( string $bytes ): bool {
	return 1 === preg_match( '/[\x01-\x08\x0b\x0e-\x1f]/', $bytes );
}

function html_api_fuzz_smoke_note_syntax_chars( string $bytes, array &$found ): void {
	foreach ( array_keys( $found ) as $char ) {
		if ( false !== strpos( $bytes, $char ) ) {
			$found[ $char ] = true;
		}
	}
}

function html_api_fuzz_smoke_expect_invalid_argument( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException $e ) {
		return;
	}

	html_api_fuzz_smoke_fail( $message );
}

function html_api_fuzz_smoke_has_active_formatting_shape( string $input, string $feature ): bool {
	$formatting_tags = array( 'b', 'big', 'code', 'em', 'font', 'i', 's', 'small', 'strike', 'strong', 'tt', 'u' );
	$attr_signatures = array( ' a b', ' class="af" data-x="1"', ' data-a="x" data-b="y"', ' title="same" data-af' );

	switch ( $feature ) {
		case 'active-formatting:same-tag-empty-attrs':
			foreach ( $formatting_tags as $tag ) {
				if ( false !== strpos( $input, '<p>' . str_repeat( '<' . $tag . '>', 4 ) ) ) {
					return true;
				}
			}
			return false;

		case 'active-formatting:same-tag-distinct-attrs':
			foreach ( $formatting_tags as $tag ) {
				if ( false !== strpos( $input, '<p><' . $tag . ' data-af="0"><' . $tag . ' data-af="1"><' . $tag . ' data-af="2"><' . $tag . ' data-af="3">' ) ) {
					return true;
				}
			}
			return false;

		case 'active-formatting:same-tag-matching-attrs':
			foreach ( $formatting_tags as $tag ) {
				foreach ( $attr_signatures as $attrs ) {
					if ( false !== strpos( $input, '<p>' . str_repeat( '<' . $tag . $attrs . '>', 4 ) ) ) {
						return true;
					}
				}
			}
			return false;

		case 'active-formatting:mixed-formatting':
			foreach ( $attr_signatures as $attrs ) {
				$quoted_attrs = preg_quote( $attrs, '~' );
				if ( 1 === preg_match( '~<p><em><i' . $quoted_attrs . '><strong><i' . $quoted_attrs . '>[^<>]*</em><i' . $quoted_attrs . '><strong><i' . $quoted_attrs . '>[^<>]*</p><p>~', $input ) ) {
					return true;
				}
			}
			return false;

		case 'active-formatting:marker-boundary':
			foreach ( $formatting_tags as $outer_tag ) {
				foreach ( $attr_signatures as $outer_attrs ) {
					$outer_cluster = preg_quote( str_repeat( '<' . $outer_tag . $outer_attrs . '>', 4 ), '~' );
					$outer_extra   = preg_quote( '<' . $outer_tag . $outer_attrs . '>', '~' );
					foreach ( $formatting_tags as $inner_tag ) {
						foreach ( $attr_signatures as $inner_attrs ) {
							$inner_cluster = preg_quote( str_repeat( '<' . $inner_tag . $inner_attrs . '>', 4 ), '~' );
							$inner_extra   = preg_quote( '<' . $inner_tag . $inner_attrs . '>', '~' );
							if ( 1 === preg_match( '~<p>' . $outer_cluster . '(?:' . $outer_extra . ')*[^<>]*</p><table><tr><td>[^<>]*<p>' . $inner_cluster . '(?:' . $inner_extra . ')*[^<>]*</p><p>[^<>]*</p></td></tr></table><p>[^<>]*</p>~', $input ) ) {
								return true;
							}
						}
					}
				}
			}
			return false;
	}

	return false;
}

function html_api_fuzz_smoke_rm_tree( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}
	if ( is_file( $path ) || is_link( $path ) ) {
		@unlink( $path );
		return;
	}
	foreach ( scandir( $path ) ?: array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		html_api_fuzz_smoke_rm_tree( $path . DIRECTORY_SEPARATOR . $item );
	}
	@rmdir( $path );
}

function html_api_fuzz_smoke_snapshot_tree( string $path ): array {
	if ( ! is_dir( $path ) ) {
		return array();
	}

	$root     = rtrim( $path, DIRECTORY_SEPARATOR );
	$snapshot = array();
	$walk     = static function ( string $directory ) use ( &$walk, &$snapshot, $root ): void {
		foreach ( scandir( $directory ) ?: array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full     = $directory . DIRECTORY_SEPARATOR . $item;
			$relative = substr( $full, strlen( $root ) + 1 );
			if ( is_dir( $full ) && ! is_link( $full ) ) {
				$snapshot[ 'dir:' . $relative ] = null;
				$walk( $full );
				continue;
			}
			$snapshot[ 'file:' . $relative ] = array(
				'size' => filesize( $full ),
				'sha1' => sha1_file( $full ),
			);
		}
	};
	$walk( $root );
	ksort( $snapshot );
	return $snapshot;
}

$valid = null;
for ( $truncation_seed = 1; $truncation_seed <= 64; $truncation_seed++ ) {
	$candidate = \HtmlApiFuzz\Generator::generate(
		$truncation_seed,
		'balanced',
		\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'valid-utf8',
		64
	);
	html_api_fuzz_smoke_assert( 'valid-utf8' === $candidate['payloadPolicy'], 'valid-utf8 policy should be resolved.' );
	html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $candidate['input'] ), 'valid-utf8 policy should produce valid UTF-8 bytes.' );
	html_api_fuzz_smoke_assert( strlen( $candidate['input'] ) <= 64, 'max-input-bytes should cap generated input.' );
	if ( true === $candidate['parameters']['truncated'] ) {
		$valid = $candidate;
		break;
	}
}
html_api_fuzz_smoke_assert( null !== $valid, 'max-input-bytes smoke should exercise truncation within the seed budget.' );
html_api_fuzz_smoke_assert( in_array( 'generator:truncated', $valid['parameters']['features'], true ), 'truncation should be recorded as a feature.' );
html_api_fuzz_smoke_assert( 'valid-utf8' === $valid['parameters']['payloadPolicy'], 'parameters should include payload policy.' );
html_api_fuzz_smoke_expect_invalid_argument(
	static function (): void {
		\HtmlApiFuzz\Generator::generate( 1, 'balanced', 'bogus-mode', 'valid-utf8' );
	},
	'invalid generator mode should throw.'
);
html_api_fuzz_smoke_assert( ! in_array( 'invalid-byte-heavy', \HtmlApiFuzz\Generator::payload_policies(), true ), 'invalid-byte-heavy should not be selectable for generated inputs.' );
html_api_fuzz_smoke_assert( in_array( 'invalid-byte-heavy', \HtmlApiFuzz\Generator::payload_policy_labels(), true ), 'invalid-byte-heavy should remain a recognized replay metadata label.' );
html_api_fuzz_smoke_expect_invalid_argument(
	static function (): void {
		\HtmlApiFuzz\Generator::generate( 1, 'attributes-entities', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'invalid-byte-heavy' );
	},
	'invalid-byte-heavy policy should be rejected for generated inputs.'
);

$body_override_a = \HtmlApiFuzz\Generator::generate( 12345, 'text-fragment', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'valid-utf8', 4096, 'body' );
$body_override_b = \HtmlApiFuzz\Generator::generate( 12345, 'text-fragment', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'valid-utf8', 4096, 'body' );
html_api_fuzz_smoke_assert( 'body' === $body_override_a['fragmentContext'], 'fragment context override should force body.' );
html_api_fuzz_smoke_assert( 'body' === $body_override_a['parameters']['requestedFragmentContext'], 'fragment context override should be recorded.' );
html_api_fuzz_smoke_assert( $body_override_a === $body_override_b, 'fragment context override should remain deterministic.' );
$body_auto_override = \HtmlApiFuzz\Generator::generate( 12345, 'balanced', 'auto', 'valid-utf8', 4096, 'body' );
html_api_fuzz_smoke_assert( 'body' === $body_auto_override['fragmentContext'], 'body context override should remain valid with auto mode.' );
$svg_override = \HtmlApiFuzz\Generator::generate( 12345, 'text-fragment', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'valid-utf8', 4096, 'svg' );
html_api_fuzz_smoke_assert( 'svg' === $svg_override['fragmentContext'], 'fragment context override should force SVG.' );
html_api_fuzz_smoke_assert( in_array( 'fragment-context:svg', $svg_override['parameters']['features'], true ), 'non-body override should be recorded as a feature.' );
html_api_fuzz_smoke_expect_invalid_argument(
	static function (): void {
		\HtmlApiFuzz\Generator::generate( 1, 'text-fragment', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'valid-utf8', 4096, 'bogus' );
	},
	'invalid fragment context override should throw.'
);
html_api_fuzz_smoke_expect_invalid_argument(
	static function (): void {
		\HtmlApiFuzz\Generator::generate( 1, 'text-fragment', 'auto', 'valid-utf8', 4096, 'svg' );
	},
	'non-body context override should require explicit fragment mode before auto resolution.'
);
html_api_fuzz_smoke_expect_invalid_argument(
	static function (): void {
		\HtmlApiFuzz\Generator::generate( 1, 'full-document', \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT, 'valid-utf8', 4096, 'svg' );
	},
	'non-body context override should be rejected for full documents.'
);

foreach ( \HtmlApiFuzz\Generator::payload_policies() as $payload_policy ) {
	foreach ( \HtmlApiFuzz\Generator::profiles() as $profile ) {
		foreach ( \HtmlApiFuzz\Generator::modes() as $mode ) {
			for ( $seed = 1; $seed <= 8; ++$seed ) {
				$generated = \HtmlApiFuzz\Generator::generate( $seed, $profile, $mode, $payload_policy, 4096 );
				html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), "{$payload_policy}/{$profile}/{$mode}/{$seed} should produce valid UTF-8 bytes." );
				html_api_fuzz_smoke_assert( ! in_array( 'payload:invalid-byte', $generated['parameters']['features'], true ), "{$payload_policy}/{$profile}/{$mode}/{$seed} should not record invalid-byte generation." );
			}
		}
	}
}
$found_non_whitespace_c0_control = false;
for ( $seed = 1; $seed <= 512; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'balanced', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'mostly-valid', 4096 );
	if ( html_api_fuzz_smoke_has_non_whitespace_c0_control( $generated['input'] ) ) {
		$found_non_whitespace_c0_control = true;
		html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), 'non-whitespace C0 control sample should still be valid UTF-8.' );
		break;
	}
}
html_api_fuzz_smoke_assert( $found_non_whitespace_c0_control, 'generated valid UTF-8 payloads should retain non-whitespace C0 control coverage.' );

$required_generator_features = array(
	'charref:text',
	'charref:attr',
	'charref:rcdata',
	'charref:text:named-semicolon',
	'charref:text:named-missing-semicolon-legacy',
	'charref:text:named-missing-semicolon-invalid',
	'charref:text:numeric-valid',
	'charref:text:numeric-invalid',
	'charref:attr:named-semicolon',
	'charref:attr:named-missing-semicolon-legacy',
	'charref:attr:named-missing-semicolon-invalid',
	'charref:attr:numeric-valid',
	'charref:attr:numeric-invalid',
	'charref:rcdata:named-semicolon',
	'charref:rcdata:invalid',
	'charref:rcdata:numeric-valid',
	'charref:rcdata:numeric-invalid',
	'charref:leading-zero',
	'attr:weird-name',
	'attr:weird-spacing',
	'attr:malformed',
	'ascii:syntax-char',
	'ascii:syntax-ampersand',
	'ascii:syntax-less-than',
	'ascii:syntax-greater-than',
	'ascii:syntax-double-quote',
	'ascii:syntax-single-quote',
	'ascii:syntax-equals',
	'payload:short-ascii',
	'payload:empty-ascii',
	'payload:medium-ascii',
	'payload:ascii-length-0',
	'payload:ascii-length-1',
	'payload:ascii-length-2',
	'payload:ascii-length-3',
	'payload:ascii-length-4',
	'payload:ascii-length-5',
	'payload:ascii-length-6',
	'payload:ascii-length-7',
	'payload:ascii-length-8',
	'payload:ascii-length-9',
	'payload:ascii-length-10',
	'tag:unusual-name',
	'tag:invalid-name',
	'tag:alpha-invalid-name',
	'tag:alpha-weird-name',
	'tag:bogus-open-name',
	'tag:weird-spacing',
	'attr:duplicate',
	'select',
	'select:option',
	'select:optgroup',
	'select:breaker',
	'select:nested',
	'adoption-agency-pattern',
	'adoption:misnested-closers',
	'adoption:reconstruction',
	'adoption:noahs-ark',
	'active-formatting-reconstruction-pattern',
	'active-formatting:four-plus-same-tag',
	'active-formatting:four-plus-same-signature',
	'active-formatting:same-tag-empty-attrs',
	'active-formatting:same-tag-distinct-attrs',
	'active-formatting:same-tag-matching-attrs',
	'active-formatting:mixed-formatting',
	'active-formatting:marker-boundary',
	'auto-closing-chain',
	'special-closers',
	'foreign:breakout',
	'foreign:annotation-xml-encoding-variant',
	'foreign:cdata',
	'foreign:case-mangled-name',
	'plaintext',
);
$found_generator_features = array_fill_keys( $required_generator_features, false );
$all_generator_features_found = false;
foreach ( array( 'attributes-entities', 'rawtext-rcdata', 'text-fragment', 'incomplete-malformed', 'balanced', 'select', 'formatting-adoption', 'foreign-content' ) as $feature_profile ) {
	for ( $seed = 1; $seed <= 128; ++$seed ) {
		$generated = \HtmlApiFuzz\Generator::generate( $seed, $feature_profile, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'mostly-valid', null );
		html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), "{$feature_profile}/{$seed} feature-coverage sample should produce valid UTF-8 bytes." );
		$features = $generated['parameters']['features'];
		if ( in_array( 'generator:truncated', $features, true ) || in_array( 'generator:hard-truncated', $features, true ) ) {
			continue;
		}
		foreach ( $features as $feature ) {
			if ( array_key_exists( $feature, $found_generator_features ) ) {
				$found_generator_features[ $feature ] = true;
			}
		}
		if ( ! in_array( false, $found_generator_features, true ) ) {
			$all_generator_features_found = true;
			break 2;
		}
	}
}
html_api_fuzz_smoke_assert( $all_generator_features_found, 'generated samples should cover all required generator features before exhausting the smoke seed budget.' );
foreach ( $found_generator_features as $feature => $found ) {
	html_api_fuzz_smoke_assert( $found, "generated samples should cover {$feature}." );
}

$required_active_formatting_shapes = array(
	'active-formatting:same-tag-empty-attrs',
	'active-formatting:same-tag-distinct-attrs',
	'active-formatting:same-tag-matching-attrs',
	'active-formatting:mixed-formatting',
	'active-formatting:marker-boundary',
);
$found_active_formatting_shapes = array_fill_keys( $required_active_formatting_shapes, false );
for ( $seed = 1; $seed <= 512; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'formatting-adoption', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'mostly-valid', null );
	html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), "formatting-adoption/{$seed} active-formatting shape sample should produce valid UTF-8 bytes." );
	foreach ( $required_active_formatting_shapes as $feature ) {
		if ( ! in_array( $feature, $generated['parameters']['features'], true ) ) {
			continue;
		}
		html_api_fuzz_smoke_assert(
			html_api_fuzz_smoke_has_active_formatting_shape( $generated['input'], $feature ),
			"generated samples that record {$feature} should emit the corresponding active-formatting byte shape."
		);
		$found_active_formatting_shapes[ $feature ] = true;
	}
	if ( ! in_array( false, $found_active_formatting_shapes, true ) ) {
		break;
	}
}
foreach ( $found_active_formatting_shapes as $feature => $found ) {
	html_api_fuzz_smoke_assert( $found, "formatting-adoption generation should emit {$feature} byte shapes." );
}

$required_comment_forms = array(
	'comment:ordinary-simple'              => array(
		'example' => '<!--comment-->',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<!--comment-->' );
		},
	),
	'comment:empty'                        => array(
		'example' => '<!---->',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<!---->' );
		},
	),
	'comment:space'                        => array(
		'example' => '<!-- -->',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<!-- -->' );
		},
	),
	'comment:short-empty-end'              => array(
		'example' => '<!-->',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<!-->' );
		},
	),
	'comment:short-hyphen-end'             => array(
		'example' => '<!--->',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<!--->' );
		},
	),
	'comment:nested-hyphens'               => array(
		'example' => '<!--a<!--b--c-->',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<!--a<!--b--c-->' );
		},
	),
	'comment:malformed-bang-ending'        => array(
		'example' => '<!--x--!>',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<!--x--!>' );
		},
	),
	'comment:malformed-greater-than-ending' => array(
		'example' => '<!--x>',
		'matches' => static function ( string $input ): bool {
			return str_ends_with( $input, '<!--x>' );
		},
	),
	'comment:unterminated'                 => array(
		'example' => '<!--x',
		'matches' => static function ( string $input ): bool {
			return str_ends_with( $input, '<!--x' );
		},
	),
	'comment:bogus-pi'                     => array(
		'example' => '<?target?>',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<?target?>' );
		},
	),
	'comment:bogus-declaration'            => array(
		'example' => '<!not-a-comment>',
		'matches' => static function ( string $input ): bool {
			return false !== strpos( $input, '<!not-a-comment>' );
		},
	),
);
$found_comment_forms = array_fill_keys( array_keys( $required_comment_forms ), false );
for ( $seed = 1; $seed <= 1024; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'comments-doctype-bogus', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'mostly-valid', null );
	html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), "comments-doctype-bogus/{$seed} comment-form sample should produce valid UTF-8 bytes." );
	foreach ( $required_comment_forms as $feature => $form ) {
		if ( in_array( $feature, $generated['parameters']['features'], true ) && $form['matches']( $generated['input'] ) ) {
			$found_comment_forms[ $feature ] = true;
		}
	}
	if ( ! in_array( false, $found_comment_forms, true ) ) {
		break;
	}
}
foreach ( $found_comment_forms as $feature => $found ) {
	html_api_fuzz_smoke_assert( $found, "comments-doctype-bogus generation should cover {$feature}." );
}

$syntax_chars = array( '&', '<', '>', '"', "'", '=' );
$found_syntax_contexts = array(
	'standalone input'        => array_fill_keys( $syntax_chars, false ),
	'quoted attribute value'  => array_fill_keys( $syntax_chars, false ),
	'rawtext element content' => array_fill_keys( $syntax_chars, false ),
	'comment content'         => array_fill_keys( $syntax_chars, false ),
);
$found_text_fragment_lengths = array_fill_keys( range( 0, 10 ), false );
$found_medium_text_fragment  = false;
for ( $seed = 1; $seed <= 512; ++$seed ) {
	$text_fragment = \HtmlApiFuzz\Generator::generate( $seed, 'text-fragment', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'mostly-valid', null );
	$input_length  = strlen( $text_fragment['input'] );
	if ( $input_length <= 10 ) {
		$found_text_fragment_lengths[ $input_length ] = true;
	} else {
		$found_medium_text_fragment = true;
	}
	html_api_fuzz_smoke_note_syntax_chars( $text_fragment['input'], $found_syntax_contexts['standalone input'] );

	foreach ( array( 'rawtext-rcdata', 'attributes-entities', 'comments-doctype-bogus' ) as $profile ) {
		$generated = \HtmlApiFuzz\Generator::generate( $seed, $profile, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'mostly-valid', null );
		if ( preg_match_all( '~<(script|style|iframe|noembed|noframes|xmp|noscript)\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>(.*?)</\1>~is', $generated['input'], $matches ) ) {
			foreach ( $matches[2] as $rawtext ) {
				html_api_fuzz_smoke_note_syntax_chars( $rawtext, $found_syntax_contexts['rawtext element content'] );
			}
		}
		if ( preg_match_all( '/<!--(.*?)-->/s', $generated['input'], $matches ) ) {
			foreach ( $matches[1] as $comment ) {
				html_api_fuzz_smoke_note_syntax_chars( $comment, $found_syntax_contexts['comment content'] );
			}
		}
		if ( preg_match_all( '~\s[-A-Za-z0-9_:.]+\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~', $generated['input'], $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $attribute ) {
				html_api_fuzz_smoke_note_syntax_chars( ( $attribute[1] ?? '' ) . ( $attribute[2] ?? '' ), $found_syntax_contexts['quoted attribute value'] );
			}
		}
	}
}
foreach ( $found_text_fragment_lengths as $length => $found ) {
	html_api_fuzz_smoke_assert( $found, "text-fragment generation should cover exact {$length}-byte inputs." );
}
html_api_fuzz_smoke_assert( $found_medium_text_fragment, 'text-fragment generation should cover medium-sized inputs.' );
for ( $seed = 1; $seed <= 16; ++$seed ) {
	$stress_text_fragment = \HtmlApiFuzz\Generator::generate( $seed, 'text-fragment', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'stress-long', null );
	html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $stress_text_fragment['input'] ), 'text-fragment stress-long generation should produce valid UTF-8 bytes.' );
	html_api_fuzz_smoke_assert( strlen( $stress_text_fragment['input'] ) >= 64, 'text-fragment stress-long generation should honor the lower long-input bound.' );
	html_api_fuzz_smoke_assert( strlen( $stress_text_fragment['input'] ) <= 1024, 'text-fragment stress-long generation should honor the upper long-input bound.' );
	html_api_fuzz_smoke_assert( in_array( 'input:long', $stress_text_fragment['parameters']['features'], true ), 'text-fragment stress-long generation should record the long-input feature.' );
}
foreach ( $found_syntax_contexts as $context => $found_chars ) {
	foreach ( $found_chars as $char => $found ) {
		html_api_fuzz_smoke_assert( $found, "{$context} should expose syntax character {$char} in final generated HTML." );
	}
}

$found_resource_stress = false;
$found_resource_stress_long = false;
for ( $seed = 1; $seed <= 512; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'auto', 'auto', 'auto', 4096 );
	html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), 'auto generation should produce valid UTF-8 bytes.' );
	html_api_fuzz_smoke_assert( ! in_array( 'payload:invalid-byte', $generated['parameters']['features'], true ), 'auto generation should not record invalid-byte payload features.' );
	if ( ! in_array( $generated['profile'], array( 'attributes-entities', 'incomplete-malformed' ), true ) ) {
		html_api_fuzz_smoke_assert( ! in_array( 'attr:malformed', $generated['parameters']['features'], true ), 'auto generation should keep malformed attributes in targeted profiles.' );
		html_api_fuzz_smoke_assert( ! in_array( 'tag:weird-syntax', $generated['parameters']['features'], true ), 'auto generation should keep weird tag syntax in targeted profiles.' );
	}
	if ( 'resource-stress' === $generated['profile'] ) {
		$found_resource_stress = true;
	}
	if ( 'stress-long' === $generated['payloadPolicy'] ) {
		html_api_fuzz_smoke_assert( 'resource-stress' === $generated['profile'], 'auto stress-long payloads should stay in the resource-stress profile.' );
		$found_resource_stress_long = true;
	}
}
html_api_fuzz_smoke_assert( $found_resource_stress, 'auto generation should retain the resource-stress bucket.' );
html_api_fuzz_smoke_assert( $found_resource_stress_long, 'resource-stress auto generation should retain stress-long payload coverage.' );
for ( $seed = 1; $seed <= 64; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'balanced', 'auto', 'auto', 4096 );
	html_api_fuzz_smoke_assert( 'stress-long' !== $generated['payloadPolicy'], 'non-resource explicit profiles should not auto-resolve stress-long.' );
}

$tmp = tempnam( sys_get_temp_dir(), 'html-api-fuzz-policy-smoke-' );
if ( false === $tmp ) {
	html_api_fuzz_smoke_fail( 'Could not create temp path.' );
}
@unlink( $tmp );
\HtmlApiFuzz\ensure_dir( $tmp );
register_shutdown_function( 'html_api_fuzz_smoke_rm_tree', $tmp );
html_api_fuzz_smoke_expect_invalid_argument(
	static function () use ( $tmp ): void {
		\HtmlApiFuzz\Worker::run(
			array(
				'input-base64'   => base64_encode( '<p>x</p>' ),
				'payload-policy' => 'valid-ut8',
				'output-dir'     => $tmp . '/invalid-policy',
			)
		);
	},
	'invalid direct-input payload policy should throw.'
);
html_api_fuzz_smoke_expect_invalid_argument(
	static function () use ( $tmp ): void {
		\HtmlApiFuzz\Worker::run(
			array(
				'seed'           => '1',
				'profile'        => 'balanced',
				'mode'           => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
				'payload-policy' => 'invalid-byte-heavy',
				'output-dir'     => $tmp . '/generated-invalid-heavy',
			)
		);
	},
	'generated worker inputs should reject legacy invalid-byte-heavy policy.'
);
html_api_fuzz_smoke_expect_invalid_argument(
	static function () use ( $tmp ): void {
		\HtmlApiFuzz\Worker::run(
			array(
				'seed'             => '1',
				'mode'             => 'auto',
				'fragment-context' => 'svg',
				'output-dir'       => $tmp . '/generated-auto-svg',
			)
		);
	},
	'generated worker inputs should reject non-body context with auto mode.'
);
html_api_fuzz_smoke_expect_invalid_argument(
	static function () use ( $tmp ): void {
		\HtmlApiFuzz\Worker::run(
			array(
				'seed'                  => '1',
				'mode'                  => 'auto',
				'fragment-context'      => 'svg',
				'corpus-mutate-percent' => '100',
				'output-dir'            => $tmp . '/corpus-auto-svg',
			)
		);
	},
	'corpus worker inputs should reject non-body context with auto mode.'
);

$direct_svg_dir = $tmp . '/direct-default-fragment-svg';
$direct_svg_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'     => base64_encode( '<circle></circle>' ),
		'fragment-context' => 'svg',
		'output-dir'       => $direct_svg_dir,
	)
);
html_api_fuzz_smoke_assert( \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY === ( $direct_svg_result['mode'] ?? null ), 'direct input should retain its fragment-body default mode.' );
html_api_fuzz_smoke_assert( 'svg' === ( $direct_svg_result['fragmentContext'] ?? null ), 'direct input should accept a non-body fragment context with its fragment default.' );

$worker_dir = $tmp . '/worker';
\HtmlApiFuzz\Worker::run(
	array(
		'seed'            => '17',
		'profile'         => 'balanced',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'valid-utf8',
		'max-input-bytes' => '2048',
		'output-dir'      => $worker_dir,
	)
);
$worker_replay = \HtmlApiFuzz\read_json_file( $worker_dir . '/replay.json' );
$worker_result = \HtmlApiFuzz\read_json_file( $worker_dir . '/result.json' );
html_api_fuzz_smoke_assert( 'valid-utf8' === ( $worker_replay['payloadPolicy'] ?? null ), 'replay should persist top-level payload policy.' );
html_api_fuzz_smoke_assert( 'valid-utf8' === ( $worker_replay['generator']['payloadPolicy'] ?? null ), 'replay generator parameters should persist payload policy.' );
html_api_fuzz_smoke_assert( 'generated' === ( $worker_replay['inputSource'] ?? null ), 'generated replay should record generated input source.' );
html_api_fuzz_smoke_assert( 'valid-utf8' === ( $worker_result['payloadPolicy'] ?? null ), 'result should persist payload policy.' );
html_api_fuzz_smoke_assert( ! empty( $worker_result['generator']['features'] ?? array() ), 'result should persist non-empty generator features.' );
html_api_fuzz_smoke_assert( ( $worker_replay['generator']['features'] ?? null ) === ( $worker_result['generator']['features'] ?? null ), 'result and replay should persist the same generator features.' );
$git_metadata = \HtmlApiFuzz\git_metadata();
html_api_fuzz_smoke_assert( array_key_exists( 'available', $git_metadata ), 'git metadata should report availability.' );
html_api_fuzz_smoke_assert( array_key_exists( 'dirty', $git_metadata ), 'git metadata should report dirty state.' );
if ( $git_metadata['available'] ?? false ) {
	html_api_fuzz_smoke_assert( 1 === preg_match( '/^[0-9a-f]{7,}$/', $git_metadata['commit'] ?? '' ), 'git metadata should include a full hex commit hash.' );
	html_api_fuzz_smoke_assert( ( $git_metadata['commit'] ?? null ) === ( $worker_replay['repoCommit'] ?? null ), 'replay should persist the current commit hash.' );
	html_api_fuzz_smoke_assert( ( $git_metadata['dirty'] ?? null ) === ( $worker_replay['repoDirty'] ?? null ), 'replay should persist the tracked-file dirty flag.' );
}
$ancestor_repo = $tmp . '/ancestor-repo';
$ancestor_child = $ancestor_repo . '/child';
\HtmlApiFuzz\ensure_dir( $ancestor_child );
$ancestor_init = \HtmlApiFuzz\run_git_command( array( 'init' ), 1000, $ancestor_repo );
if ( 0 === $ancestor_init['code'] ) {
	$ancestor_child_metadata = \HtmlApiFuzz\git_metadata( 1000, $ancestor_child );
	html_api_fuzz_smoke_assert( false === ( $ancestor_child_metadata['available'] ?? null ), 'git metadata should not report an ancestor repository as the current repo.' );
}
$dirty_repo = $tmp . '/dirty-repo';
\HtmlApiFuzz\ensure_dir( $dirty_repo );
$dirty_init = \HtmlApiFuzz\run_git_command( array( 'init' ), 1000, $dirty_repo );
if ( 0 === $dirty_init['code'] ) {
	file_put_contents( $dirty_repo . '/tracked.txt', "clean\n" );
	$dirty_add = \HtmlApiFuzz\run_git_command( array( 'add', 'tracked.txt' ), 1000, $dirty_repo );
	$dirty_commit = \HtmlApiFuzz\run_git_command(
		array(
			'-c',
			'user.email=html-api-fuzz@example.invalid',
			'-c',
			'user.name=HTML API Fuzz',
			'-c',
			'commit.gpgsign=false',
			'commit',
			'--no-gpg-sign',
			'--no-verify',
			'-m',
			'initial',
		),
		1000,
		$dirty_repo
	);
	html_api_fuzz_smoke_assert( 0 === $dirty_add['code'], 'temp git repo should stage the tracked dirty fixture.' );
	html_api_fuzz_smoke_assert( 0 === $dirty_commit['code'], 'temp git repo should commit the tracked dirty fixture.' );
	$clean_repo_metadata = \HtmlApiFuzz\git_metadata( 1000, $dirty_repo, false );
	html_api_fuzz_smoke_assert( true === ( $clean_repo_metadata['available'] ?? null ), 'temp git repo metadata should be available.' );
	html_api_fuzz_smoke_assert( false === ( $clean_repo_metadata['dirty'] ?? null ), 'clean tracked temp git repo should report dirty false.' );
	file_put_contents( $dirty_repo . '/tracked.txt', "dirty\n" );
	$dirty_repo_metadata = \HtmlApiFuzz\git_metadata( 1000, $dirty_repo, false );
	html_api_fuzz_smoke_assert( true === ( $dirty_repo_metadata['dirty'] ?? null ), 'modified tracked temp git repo should report dirty true.' );
}
$fake_git_root = $tmp . '/fake-git-root';
$fake_git_bin = $tmp . '/fake-git-bin';
\HtmlApiFuzz\ensure_dir( $fake_git_root );
\HtmlApiFuzz\ensure_dir( $fake_git_bin );
$fake_git = $fake_git_bin . '/git';
file_put_contents(
	$fake_git,
	"#!/bin/sh\n" .
	"if [ \"\$1\" = \"-C\" ]; then root=\"\$2\"; shift 2; else root=\"\$PWD\"; fi\n" .
	"if [ \"\$1\" = \"rev-parse\" ] && [ \"\$2\" = \"--show-toplevel\" ]; then printf '%s\\n' \"\$root\"; exit 0; fi\n" .
	"if [ \"\$1\" = \"rev-parse\" ] && [ \"\$2\" = \"HEAD\" ]; then printf '%s\\n' abcdef1234567890abcdef1234567890abcdef12; exit 0; fi\n" .
	"if [ \"\$1\" = \"rev-parse\" ] && [ \"\$2\" = \"--short=12\" ]; then printf '%s\\n' abcdef123456; exit 0; fi\n" .
	"if [ \"\$1\" = \"branch\" ] && [ \"\$2\" = \"--show-current\" ]; then printf '%s\\n' main; exit 0; fi\n" .
	"if [ \"\$1\" = \"show\" ]; then printf '%s\\n' 2026-01-01T00:00:00+00:00; exit 0; fi\n" .
	"if [ \"\$1\" = \"diff\" ]; then exit 2; fi\n" .
	"exit 1\n"
);
chmod( $fake_git, 0755 );
$old_path = getenv( 'PATH' );
putenv( 'PATH=' . $fake_git_bin . PATH_SEPARATOR . ( false === $old_path ? '' : $old_path ) );
$unknown_dirty_metadata = \HtmlApiFuzz\git_metadata( 1000, $fake_git_root, false );
if ( false === $old_path ) {
	putenv( 'PATH' );
} else {
	putenv( 'PATH=' . $old_path );
}
html_api_fuzz_smoke_assert( true === ( $unknown_dirty_metadata['available'] ?? null ), 'git metadata should remain available when only dirty detection fails.' );
html_api_fuzz_smoke_assert( array_key_exists( 'dirty', $unknown_dirty_metadata ) && null === $unknown_dirty_metadata['dirty'], 'dirty detection failures should report dirty null.' );

$replay_cli_dir = $tmp . '/replay-cli';
$replay_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$worker_dir . '/replay.json',
		'--output-dir',
		$replay_cli_dir,
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/replay-cli.log'
);
html_api_fuzz_smoke_assert( ! $replay_proc['timedOut'] && in_array( $replay_proc['code'], array( 0, 2 ), true ), 'replay CLI should complete.' );
$replay_cli_replay = \HtmlApiFuzz\read_json_file( $replay_cli_dir . '/replay.json' );
html_api_fuzz_smoke_assert( null === ( $replay_cli_replay['generator'] ?? null ), 'replay CLI output should not invent immediate generator metadata.' );
html_api_fuzz_smoke_assert( 'input-file' === ( $replay_cli_replay['inputSource'] ?? null ), 'replay CLI output should record immediate input source.' );
html_api_fuzz_smoke_assert( ( $worker_replay['generator'] ?? null ) === ( $replay_cli_replay['originalGenerator'] ?? null ), 'replay CLI output should preserve original generator metadata.' );
html_api_fuzz_smoke_assert( ( $worker_replay['repoCommit'] ?? null ) === ( $replay_cli_replay['sourceReplay']['repoCommit'] ?? null ), 'replay CLI output should preserve source replay commit metadata.' );
html_api_fuzz_smoke_assert( ( $worker_replay['repoDirty'] ?? null ) === ( $replay_cli_replay['sourceReplay']['repoDirty'] ?? null ), 'replay CLI output should preserve source replay dirty metadata.' );

$legacy_replay = $worker_replay;
$legacy_replay['payloadPolicy'] = 'replay';
if ( isset( $legacy_replay['generator']['payloadPolicy'] ) ) {
	$legacy_replay['generator']['payloadPolicy'] = 'replay';
}
$legacy_replay_path = $tmp . '/legacy-payload-policy-replay.json';
\HtmlApiFuzz\write_json_file( $legacy_replay_path, $legacy_replay );
$legacy_replay_dir = $tmp . '/legacy-replay-cli';
$legacy_replay_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$legacy_replay_path,
		'--output-dir',
		$legacy_replay_dir,
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/legacy-replay-cli.log'
);
html_api_fuzz_smoke_assert( ! $legacy_replay_proc['timedOut'] && in_array( $legacy_replay_proc['code'], array( 0, 2 ), true ), 'legacy replay payload policy labels should not make replay fatal.' );
$legacy_replay_cli_replay = \HtmlApiFuzz\read_json_file( $legacy_replay_dir . '/replay.json' );
html_api_fuzz_smoke_assert( null === ( $legacy_replay_cli_replay['payloadPolicy'] ?? null ), 'legacy replay payload policy labels should be treated as unlabeled direct input.' );
html_api_fuzz_smoke_assert( 'replay' === ( $legacy_replay_cli_replay['originalGenerator']['payloadPolicy'] ?? null ), 'legacy replay should preserve original generator metadata.' );

$legacy_invalid_replay = $worker_replay;
$legacy_invalid_replay['payloadPolicy'] = 'invalid-byte-heavy';
if ( isset( $legacy_invalid_replay['generator']['payloadPolicy'] ) ) {
	$legacy_invalid_replay['generator']['payloadPolicy'] = 'invalid-byte-heavy';
}
$legacy_invalid_replay_path = $tmp . '/legacy-invalid-payload-policy-replay.json';
\HtmlApiFuzz\write_json_file( $legacy_invalid_replay_path, $legacy_invalid_replay );
$legacy_invalid_replay_dir = $tmp . '/legacy-invalid-replay-cli';
$legacy_invalid_replay_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$legacy_invalid_replay_path,
		'--output-dir',
		$legacy_invalid_replay_dir,
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/legacy-invalid-replay-cli.log'
);
html_api_fuzz_smoke_assert( ! $legacy_invalid_replay_proc['timedOut'] && in_array( $legacy_invalid_replay_proc['code'], array( 0, 2 ), true ), 'legacy invalid-byte-heavy replay payload policy label should not make replay fatal.' );
$legacy_invalid_replay_cli_replay = \HtmlApiFuzz\read_json_file( $legacy_invalid_replay_dir . '/replay.json' );
html_api_fuzz_smoke_assert( 'invalid-byte-heavy' === ( $legacy_invalid_replay_cli_replay['payloadPolicy'] ?? null ), 'legacy invalid-byte-heavy replay payload policy label should be preserved as direct-input metadata.' );
html_api_fuzz_smoke_assert( 'invalid-byte-heavy' === ( $legacy_invalid_replay_cli_replay['originalGenerator']['payloadPolicy'] ?? null ), 'legacy invalid-byte-heavy replay should preserve original generator metadata.' );

$invalid_byte_replay_source_dir = $tmp . '/invalid-byte-replay-source';
$invalid_byte_replay_source = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<p>' . str_repeat( 'a', 220 ) . "\xC0" . '</p>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'invalid-byte-heavy',
		'output-dir'      => $invalid_byte_replay_source_dir,
		'max-tokens'      => '2000',
		'max-nodes'       => '3000',
	)
);
html_api_fuzz_smoke_assert( 'normalize-tree-changed' === ( $invalid_byte_replay_source['failureClass'] ?? null ), 'invalid-byte replay fixture should be a real normalization-preservation failure.' );

$invalid_byte_replay_cli_dir = $tmp . '/invalid-byte-replay-cli';
$invalid_byte_replay_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$invalid_byte_replay_source_dir . '/replay.json',
		'--output-dir',
		$invalid_byte_replay_cli_dir,
		'--timeout-ms',
		'10000',
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/invalid-byte-replay-cli.log'
);
html_api_fuzz_smoke_assert( ! $invalid_byte_replay_proc['timedOut'] && 2 === $invalid_byte_replay_proc['code'], 'real invalid-byte replay should complete as a replayed failure.' );
$invalid_byte_replay_cli_replay = \HtmlApiFuzz\read_json_file( $invalid_byte_replay_cli_dir . '/replay.json' );
html_api_fuzz_smoke_assert( 'invalid-byte-heavy' === ( $invalid_byte_replay_cli_replay['payloadPolicy'] ?? null ), 'real invalid-byte replay should preserve legacy payload policy metadata.' );

$invalid_byte_exact_minimize_dir = $tmp . '/invalid-byte-exact-minimize';
$invalid_byte_exact_minimize_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/minimize.php',
		'--replay',
		$invalid_byte_replay_source_dir . '/replay.json',
		'--output-dir',
		$invalid_byte_exact_minimize_dir,
		'--max-attempts',
		'1',
		'--timeout-ms',
		'10000',
	),
	\HtmlApiFuzz\repo_root(),
	20000,
	$tmp . '/invalid-byte-exact-minimize.log'
);
html_api_fuzz_smoke_assert( ! $invalid_byte_exact_minimize_proc['timedOut'] && 0 === $invalid_byte_exact_minimize_proc['code'], 'exact-signature invalid-byte replay should minimize.' );
$invalid_byte_exact_minimize_result = \HtmlApiFuzz\read_json_file( $invalid_byte_exact_minimize_dir . '/minimize-result.json' );
html_api_fuzz_smoke_assert( true === ( $invalid_byte_exact_minimize_result['ok'] ?? null ), 'exact-signature invalid-byte minimization should preserve the target signature.' );
html_api_fuzz_smoke_assert( 'process' === ( $invalid_byte_exact_minimize_result['probeMode'] ?? null ), 'auto exact-signature minimization should use timeout-enforced process probes by default.' );
html_api_fuzz_smoke_assert( true === ( $invalid_byte_exact_minimize_result['candidateArtifactsRetained'] ?? null ), 'auto process minimization should report retained candidate artifacts.' );
html_api_fuzz_smoke_assert( 1 === ( $invalid_byte_exact_minimize_result['attempts'] ?? null ), 'exact-signature smoke minimization should run the requested single probe.' );
html_api_fuzz_smoke_assert( is_array( $invalid_byte_exact_minimize_result['probeTiming'] ?? null ), 'exact-signature minimization should report probe timing.' );
html_api_fuzz_smoke_assert( is_dir( $invalid_byte_exact_minimize_dir . '/candidates' ), 'auto process minimization should retain per-candidate artifact directories.' );

$invalid_byte_in_process_minimize_dir = $tmp . '/invalid-byte-in-process-minimize';
$invalid_byte_in_process_minimize_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/minimize.php',
		'--replay',
		$invalid_byte_replay_source_dir . '/replay.json',
		'--output-dir',
		$invalid_byte_in_process_minimize_dir,
		'--probe-mode',
		'in-process',
		'--max-attempts',
		'1',
		'--timeout-ms',
		'10000',
	),
	\HtmlApiFuzz\repo_root(),
	20000,
	$tmp . '/invalid-byte-in-process-minimize.log'
);
html_api_fuzz_smoke_assert( ! $invalid_byte_in_process_minimize_proc['timedOut'] && 0 === $invalid_byte_in_process_minimize_proc['code'], 'explicit in-process invalid-byte replay should minimize.' );
$invalid_byte_in_process_minimize_result = \HtmlApiFuzz\read_json_file( $invalid_byte_in_process_minimize_dir . '/minimize-result.json' );
html_api_fuzz_smoke_assert( true === ( $invalid_byte_in_process_minimize_result['ok'] ?? null ), 'explicit in-process minimization should preserve the target signature.' );
html_api_fuzz_smoke_assert( 'in-process' === ( $invalid_byte_in_process_minimize_result['probeMode'] ?? null ), 'explicit in-process minimization should use in-process probes.' );
html_api_fuzz_smoke_assert( false === ( $invalid_byte_in_process_minimize_result['candidateArtifactsRetained'] ?? null ), 'in-process minimization should not retain candidate artifacts by default.' );
html_api_fuzz_smoke_assert( ! is_dir( $invalid_byte_in_process_minimize_dir . '/candidates' ), 'in-process minimization should avoid per-candidate artifact directories by default.' );

$invalid_byte_minimize_dir = $tmp . '/invalid-byte-minimize';
$invalid_byte_minimize_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/minimize.php',
		'--replay',
		$invalid_byte_replay_source_dir . '/replay.json',
		'--output-dir',
		$invalid_byte_minimize_dir,
		'--any-failure',
		'--max-attempts',
		'1',
		'--timeout-ms',
		'10000',
	),
	\HtmlApiFuzz\repo_root(),
	20000,
	$tmp . '/invalid-byte-minimize.log'
);
html_api_fuzz_smoke_assert( ! $invalid_byte_minimize_proc['timedOut'] && 0 === $invalid_byte_minimize_proc['code'], 'real invalid-byte replay should remain minimizable.' );
$invalid_byte_minimize_result = \HtmlApiFuzz\read_json_file( $invalid_byte_minimize_dir . '/minimize-result.json' );
$invalid_byte_minimize_replay = \HtmlApiFuzz\read_json_file( $invalid_byte_minimize_result['minimizedReplay'] ?? '' );
$invalid_byte_source_replay = \HtmlApiFuzz\read_json_file( $invalid_byte_replay_source_dir . '/replay.json' );
html_api_fuzz_smoke_assert( 'process' === ( $invalid_byte_minimize_result['probeMode'] ?? null ), 'any-failure minimization should use process probes by default.' );
html_api_fuzz_smoke_assert( true === ( $invalid_byte_minimize_result['candidateArtifactsRetained'] ?? null ), 'process-mode minimization should report retained candidate artifacts.' );
html_api_fuzz_smoke_assert( is_dir( $invalid_byte_minimize_dir . '/candidates' ), 'process-mode minimization should retain per-candidate artifact directories.' );
html_api_fuzz_smoke_assert( 'invalid-byte-heavy' === ( $invalid_byte_minimize_result['payloadPolicy'] ?? null ), 'invalid-byte minimization should preserve legacy payload policy metadata.' );
html_api_fuzz_smoke_assert( ( $invalid_byte_source_replay['repoCommit'] ?? null ) === ( $invalid_byte_minimize_result['sourceReplay']['repoCommit'] ?? null ), 'minimize result should preserve source replay commit metadata.' );
html_api_fuzz_smoke_assert( ( $invalid_byte_source_replay['repoDirty'] ?? null ) === ( $invalid_byte_minimize_result['sourceReplay']['repoDirty'] ?? null ), 'minimize result should preserve source replay dirty metadata.' );
html_api_fuzz_smoke_assert( ( $invalid_byte_source_replay['repoCommit'] ?? null ) === ( $invalid_byte_minimize_replay['sourceReplay']['repoCommit'] ?? null ), 'minimized replay should preserve source replay commit metadata.' );
html_api_fuzz_smoke_assert( ( $invalid_byte_source_replay['repoDirty'] ?? null ) === ( $invalid_byte_minimize_replay['sourceReplay']['repoDirty'] ?? null ), 'minimized replay should preserve source replay dirty metadata.' );

$bad_runner_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/runner.php',
		'--payload-policy',
		'valid-ut8',
		'--max-seeds',
		'1',
		'--output-dir',
		$tmp . '/bad-runner',
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/bad-runner.log'
);
html_api_fuzz_smoke_assert( 0 !== $bad_runner_proc['code'], 'runner CLI should reject invalid payload policy before starting workers.' );

$bad_context_runner_dir = $tmp . '/bad-context-runner';
$bad_context_runner_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/runner.php',
		'--mode',
		'auto',
		'--fragment-context',
		'svg',
		'--max-seeds',
		'1',
		'--output-dir',
		$bad_context_runner_dir,
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/bad-context-runner.log'
);
html_api_fuzz_smoke_assert( 0 !== $bad_context_runner_proc['code'], 'runner CLI should reject non-body context with auto mode.' );
html_api_fuzz_smoke_assert( ! is_dir( $bad_context_runner_dir ), 'runner should reject invalid mode/context configuration before creating output.' );

$legacy_invalid_runner_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/runner.php',
		'--payload-policy',
		'invalid-byte-heavy',
		'--max-seeds',
		'1',
		'--output-dir',
		$tmp . '/legacy-invalid-runner',
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/legacy-invalid-runner.log'
);
html_api_fuzz_smoke_assert( 0 !== $legacy_invalid_runner_proc['code'], 'runner CLI should reject legacy invalid-byte-heavy generation policy.' );

$legacy_invalid_launcher_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/launcher.php',
		'--payload-policy',
		'invalid-byte-heavy',
		'--max-seeds',
		'1',
		'--duration-seconds',
		'0',
		'--output-dir',
		$tmp . '/legacy-invalid-launcher',
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/legacy-invalid-launcher.log'
);
html_api_fuzz_smoke_assert( 0 !== $legacy_invalid_launcher_proc['code'], 'launcher CLI should reject legacy invalid-byte-heavy generation policy.' );

$metadata_runner_dir = $tmp . '/metadata-runner';
$metadata_runner_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/runner.php',
		'--max-seeds',
		'1',
		'--duration-seconds',
		'0',
		// Passing seed directories are pruned by default; this run asserts on
		// the on-disk replay document.
		'--keep-all-artifacts',
		'--output-dir',
		$metadata_runner_dir,
	),
	\HtmlApiFuzz\repo_root(),
	15000,
	$tmp . '/metadata-runner.log'
);
html_api_fuzz_smoke_assert( ! $metadata_runner_proc['timedOut'] && 0 === $metadata_runner_proc['code'], 'runner metadata smoke run should complete.' );
$metadata_runner_state = \HtmlApiFuzz\read_json_file( $metadata_runner_dir . '/state.json' );
html_api_fuzz_smoke_assert( 'html-api-fuzz-runner-state' === ( $metadata_runner_state['kind'] ?? null ), 'runner state should be written.' );
html_api_fuzz_smoke_assert( is_array( $metadata_runner_state['git'] ?? null ), 'runner state should include compact git metadata.' );
$metadata_runner_events = \HtmlApiFuzz\read_ndjson_records( $metadata_runner_dir . '/events.ndjson' );
html_api_fuzz_smoke_assert( is_array( $metadata_runner_events[0]['git'] ?? null ), 'runner start event should include compact git metadata.' );
$metadata_runner_replay = \HtmlApiFuzz\read_json_file( $metadata_runner_dir . '/seed-1/primary/replay.json' );
if ( $git_metadata['available'] ?? false ) {
	html_api_fuzz_smoke_assert( $git_metadata['commit'] === ( $metadata_runner_state['git']['commit'] ?? null ), 'runner state git metadata should match the current commit.' );
	html_api_fuzz_smoke_assert( $git_metadata['commit'] === ( $metadata_runner_events[0]['git']['commit'] ?? null ), 'runner start event git metadata should match the current commit.' );
	html_api_fuzz_smoke_assert( $metadata_runner_state['git']['commit'] === ( $metadata_runner_replay['repoCommit'] ?? null ), 'runner worker replay should use runner-provided git metadata.' );
	html_api_fuzz_smoke_assert( $metadata_runner_state['git']['dirty'] === ( $metadata_runner_replay['repoDirty'] ?? null ), 'runner worker replay should use runner-provided dirty metadata.' );
}

$metadata_launcher_dir = $tmp . '/metadata-launcher';
$metadata_launcher_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/launcher.php',
		'--lanes',
		'1',
		'--max-seeds',
		'1',
		'--duration-seconds',
		'0',
		// Passing seed directories are pruned by default; this run asserts on
		// the on-disk replay document.
		'--keep-all-artifacts',
		// Non-default value pins the launcher-to-lane flag passthrough.
		'--max-keep-per-signature',
		'3',
		'--output-dir',
		$metadata_launcher_dir,
	),
	\HtmlApiFuzz\repo_root(),
	20000,
	$tmp . '/metadata-launcher.log'
);
html_api_fuzz_smoke_assert( ! $metadata_launcher_proc['timedOut'] && 0 === $metadata_launcher_proc['code'], 'launcher metadata smoke run should complete.' );
$metadata_launcher_state = \HtmlApiFuzz\read_json_file( $metadata_launcher_dir . '/launcher-state.json' );
html_api_fuzz_smoke_assert( 'html-api-fuzz-launcher-state' === ( $metadata_launcher_state['kind'] ?? null ), 'launcher state should be written.' );
html_api_fuzz_smoke_assert( is_array( $metadata_launcher_state['git'] ?? null ), 'launcher state should include compact git metadata.' );
html_api_fuzz_smoke_assert( true === ( $metadata_launcher_state['finished'] ?? null ), 'no-watcher launcher should be terminal.' );
html_api_fuzz_smoke_assert( true === ( $metadata_launcher_state['campaignFinished'] ?? null ), 'no-watcher launcher should record campaign completion.' );
html_api_fuzz_smoke_assert( true === ( $metadata_launcher_state['campaignOk'] ?? null ), 'no-watcher launcher should record successful lane processes.' );
html_api_fuzz_smoke_assert( true === ( $metadata_launcher_state['workflowCompleted'] ?? null ), 'no-watcher launcher should complete its requested workflow.' );
html_api_fuzz_smoke_assert( false === ( $metadata_launcher_state['watcherRequested'] ?? null ), 'no-watcher launcher should record watcher intent.' );
html_api_fuzz_smoke_assert( null === ( $metadata_launcher_state['watcherResult'] ?? null ), 'no-watcher launcher should not invent a watcher result.' );
html_api_fuzz_smoke_assert( 'completed' === ( $metadata_launcher_state['status'] ?? null ), 'no-watcher launcher should report completed status.' );
$metadata_launcher_events = \HtmlApiFuzz\read_ndjson_records( $metadata_launcher_dir . '/events.ndjson' );
html_api_fuzz_smoke_assert( is_array( $metadata_launcher_events[0]['git'] ?? null ), 'launcher start event should include compact git metadata.' );
$metadata_launcher_event_kinds = array_column( $metadata_launcher_events, 'kind' );
html_api_fuzz_smoke_assert( 'launcher-stop' === end( $metadata_launcher_event_kinds ), 'no-watcher launcher should finish with launcher-stop.' );
html_api_fuzz_smoke_assert( false !== array_search( 'campaign-stop', $metadata_launcher_event_kinds, true ), 'no-watcher launcher should record campaign-stop.' );
html_api_fuzz_smoke_assert( false === array_search( 'watcher-start', $metadata_launcher_event_kinds, true ), 'no-watcher launcher should not record watcher-start.' );
$metadata_launcher_lane_state = \HtmlApiFuzz\read_json_file( $metadata_launcher_dir . '/lane-00/state.json' );
html_api_fuzz_smoke_assert( 3 === ( $metadata_launcher_lane_state['maxKeepPerSignature'] ?? null ), 'launcher should pass --max-keep-per-signature through to lanes.' );
$metadata_launcher_replay = \HtmlApiFuzz\read_json_file( $metadata_launcher_dir . '/lane-00/seed-1/primary/replay.json' );
if ( $git_metadata['available'] ?? false ) {
	html_api_fuzz_smoke_assert( $git_metadata['commit'] === ( $metadata_launcher_state['git']['commit'] ?? null ), 'launcher state git metadata should match the current commit.' );
	html_api_fuzz_smoke_assert( $git_metadata['commit'] === ( $metadata_launcher_events[0]['git']['commit'] ?? null ), 'launcher start event git metadata should match the current commit.' );
	html_api_fuzz_smoke_assert( $metadata_launcher_state['git']['commit'] === ( $metadata_launcher_replay['repoCommit'] ?? null ), 'launcher worker replay should use launcher-provided git metadata.' );
	html_api_fuzz_smoke_assert( $metadata_launcher_state['git']['dirty'] === ( $metadata_launcher_replay['repoDirty'] ?? null ), 'launcher worker replay should use launcher-provided dirty metadata.' );
}

$launcher_oracle_watcher_dir = $tmp . '/launcher-oracle-watcher';
$launcher_oracle_watcher_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/launcher.php',
		'--lanes',
		'1',
		'--max-seeds',
		'1',
		'--duration-seconds',
		'0',
		'--watcher',
		'--no-minimize',
		'--triage-oracle-findings',
		'--output-dir',
		$launcher_oracle_watcher_dir,
	),
	\HtmlApiFuzz\repo_root(),
	30000,
	$tmp . '/launcher-oracle-watcher.log'
);
html_api_fuzz_smoke_assert( ! $launcher_oracle_watcher_proc['timedOut'] && 0 === $launcher_oracle_watcher_proc['code'], 'launcher oracle watcher passthrough run should complete.' );
$launcher_oracle_watcher = json_decode( $launcher_oracle_watcher_proc['stdout'], true );
html_api_fuzz_smoke_assert( 0 === ( $launcher_oracle_watcher['watcherResult']['code'] ?? null ), 'launcher oracle watcher should exit cleanly.' );
$launcher_oracle_watcher_log = trim( (string) file_get_contents( $launcher_oracle_watcher['watcherResult']['logPath'] ?? '' ) );
$launcher_oracle_watcher_scan = json_decode( $launcher_oracle_watcher_log, true );
html_api_fuzz_smoke_assert( true === ( $launcher_oracle_watcher_scan['triageOracleFindings'] ?? null ), 'launcher should pass --triage-oracle-findings through to watcher.' );
$launcher_oracle_watcher_state = \HtmlApiFuzz\read_json_file( $launcher_oracle_watcher_dir . '/launcher-state.json' );
html_api_fuzz_smoke_assert( true === ( $launcher_oracle_watcher_state['finished'] ?? null ), 'successful watcher launcher should be terminal.' );
html_api_fuzz_smoke_assert( true === ( $launcher_oracle_watcher_state['campaignFinished'] ?? null ), 'successful watcher launcher should record campaign completion.' );
html_api_fuzz_smoke_assert( true === ( $launcher_oracle_watcher_state['campaignOk'] ?? null ), 'successful watcher launcher should record successful lane processes.' );
html_api_fuzz_smoke_assert( true === ( $launcher_oracle_watcher_state['workflowCompleted'] ?? null ), 'successful watcher launcher should complete its requested workflow.' );
html_api_fuzz_smoke_assert( true === ( $launcher_oracle_watcher_state['watcherRequested'] ?? null ), 'successful watcher launcher should record watcher intent.' );
html_api_fuzz_smoke_assert( 'completed' === ( $launcher_oracle_watcher_state['status'] ?? null ), 'successful watcher launcher should report completed status.' );
html_api_fuzz_smoke_assert( $launcher_oracle_watcher['watcherResult'] === ( $launcher_oracle_watcher_state['watcherResult'] ?? null ), 'stdout and durable watcher results should match.' );
$launcher_oracle_watcher_events = \HtmlApiFuzz\read_ndjson_records( $launcher_oracle_watcher_dir . '/events.ndjson' );
$launcher_oracle_watcher_kinds  = array_column( $launcher_oracle_watcher_events, 'kind' );
$campaign_stop_index = array_search( 'campaign-stop', $launcher_oracle_watcher_kinds, true );
$watcher_start_index = array_search( 'watcher-start', $launcher_oracle_watcher_kinds, true );
$watcher_stop_index  = array_search( 'watcher-stop', $launcher_oracle_watcher_kinds, true );
$launcher_stop_index = array_search( 'launcher-stop', $launcher_oracle_watcher_kinds, true );
html_api_fuzz_smoke_assert( false !== $campaign_stop_index && false !== $watcher_start_index && false !== $watcher_stop_index && false !== $launcher_stop_index, 'successful watcher lifecycle events should be durable.' );
html_api_fuzz_smoke_assert( $campaign_stop_index < $watcher_start_index && $watcher_start_index < $watcher_stop_index && $watcher_stop_index < $launcher_stop_index, 'successful watcher lifecycle events should be ordered.' );
html_api_fuzz_smoke_assert( $launcher_stop_index === count( $launcher_oracle_watcher_kinds ) - 1, 'successful watcher launcher-stop should be final.' );

$launcher_watcher_timeout_dir = $tmp . '/launcher-watcher-timeout';
$launcher_watcher_timeout_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/launcher.php',
		'--lanes',
		'1',
		'--max-seeds',
		'1',
		'--duration-seconds',
		'0',
		'--watcher',
		'--no-minimize',
		'--watcher-timeout-ms',
		'0',
		'--output-dir',
		$launcher_watcher_timeout_dir,
	),
	\HtmlApiFuzz\repo_root(),
	30000,
	$tmp . '/launcher-watcher-timeout.log'
);
html_api_fuzz_smoke_assert( ! $launcher_watcher_timeout_proc['timedOut'], 'forced watcher-timeout launcher should terminate itself.' );
html_api_fuzz_smoke_assert( 0 !== $launcher_watcher_timeout_proc['code'], 'forced watcher timeout should make the launcher fail.' );
$launcher_watcher_timeout = json_decode( $launcher_watcher_timeout_proc['stdout'], true );
html_api_fuzz_smoke_assert( false === ( $launcher_watcher_timeout['ok'] ?? null ), 'forced watcher timeout should report ok=false.' );
html_api_fuzz_smoke_assert( true === ( $launcher_watcher_timeout['watcherResult']['timedOut'] ?? null ), 'forced timeout smoke must actually time out a started watcher process.' );
html_api_fuzz_smoke_assert( null === ( $launcher_watcher_timeout['watcherResult']['code'] ?? null ), 'timed-out watcher should have no exit code.' );
html_api_fuzz_smoke_assert( 'timed-out' === ( $launcher_watcher_timeout['watcherResult']['status'] ?? null ), 'timed-out watcher should report timed-out status.' );
html_api_fuzz_smoke_assert( 0 < ( $launcher_watcher_timeout['watcherResult']['durationMs'] ?? 0 ), 'timed-out watcher should record a positive lifetime.' );
html_api_fuzz_smoke_assert( is_string( $launcher_watcher_timeout['watcherResult']['startedAt'] ?? null ), 'timed-out watcher should record that it started.' );
$launcher_watcher_timeout_state = \HtmlApiFuzz\read_json_file( $launcher_watcher_timeout_dir . '/launcher-state.json' );
html_api_fuzz_smoke_assert( true === ( $launcher_watcher_timeout_state['finished'] ?? null ), 'timed-out watcher launcher should be terminal.' );
html_api_fuzz_smoke_assert( true === ( $launcher_watcher_timeout_state['campaignFinished'] ?? null ), 'timed-out watcher launcher should preserve campaign completion.' );
html_api_fuzz_smoke_assert( true === ( $launcher_watcher_timeout_state['campaignOk'] ?? null ), 'timed-out watcher launcher should preserve successful lane status.' );
html_api_fuzz_smoke_assert( false === ( $launcher_watcher_timeout_state['workflowCompleted'] ?? null ), 'timed-out watcher should leave the requested workflow incomplete.' );
html_api_fuzz_smoke_assert( 'watcher-timed-out' === ( $launcher_watcher_timeout_state['status'] ?? null ), 'timed-out watcher launcher should report watcher-timed-out.' );
html_api_fuzz_smoke_assert( $launcher_watcher_timeout['watcherResult'] === ( $launcher_watcher_timeout_state['watcherResult'] ?? null ), 'timed-out stdout and durable watcher results should match.' );
$launcher_watcher_timeout_events = \HtmlApiFuzz\read_ndjson_records( $launcher_watcher_timeout_dir . '/events.ndjson' );
$launcher_watcher_timeout_kinds  = array_column( $launcher_watcher_timeout_events, 'kind' );
$campaign_stop_index = array_search( 'campaign-stop', $launcher_watcher_timeout_kinds, true );
$watcher_start_index = array_search( 'watcher-start', $launcher_watcher_timeout_kinds, true );
$watcher_timeout_index = array_search( 'watcher-timeout', $launcher_watcher_timeout_kinds, true );
$launcher_stop_index = array_search( 'launcher-stop', $launcher_watcher_timeout_kinds, true );
html_api_fuzz_smoke_assert( false !== $campaign_stop_index && false !== $watcher_start_index && false !== $watcher_timeout_index && false !== $launcher_stop_index, 'timed-out watcher lifecycle events should be durable.' );
html_api_fuzz_smoke_assert( $campaign_stop_index < $watcher_start_index && $watcher_start_index < $watcher_timeout_index && $watcher_timeout_index < $launcher_stop_index, 'timed-out watcher lifecycle events should be ordered.' );
html_api_fuzz_smoke_assert( false === ( $launcher_watcher_timeout_events[ $launcher_stop_index ]['ok'] ?? null ), 'timed-out final launcher-stop should report failure.' );
$timeout_snapshot = html_api_fuzz_smoke_snapshot_tree( $launcher_watcher_timeout_dir );
usleep( 500000 );
html_api_fuzz_smoke_assert( $timeout_snapshot === html_api_fuzz_smoke_snapshot_tree( $launcher_watcher_timeout_dir ), 'timed-out watcher must not survive the launcher and mutate artifacts later.' );

$bad_stride_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/runner.php',
		'--seed-stride',
		'0',
		'--max-seeds',
		'1',
		'--output-dir',
		$tmp . '/bad-stride-runner',
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/bad-stride-runner.log'
);
html_api_fuzz_smoke_assert( 0 !== $bad_stride_proc['code'], 'runner CLI should reject non-positive seed strides before starting workers.' );

$unlabeled_dir = $tmp . '/unlabeled-direct';
$unlabeled_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64' => base64_encode( '<p>x</p>' ),
		'profile'      => 'replay',
		'mode'         => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'output-dir'   => $unlabeled_dir,
	)
);
$unlabeled_replay = \HtmlApiFuzz\read_json_file( $unlabeled_dir . '/replay.json' );
html_api_fuzz_smoke_assert( null === ( $unlabeled_result['payloadPolicy'] ?? null ), 'unlabeled direct input result should leave payloadPolicy null.' );
html_api_fuzz_smoke_assert( null === ( $unlabeled_replay['payloadPolicy'] ?? null ), 'unlabeled direct input replay should leave payloadPolicy null.' );
html_api_fuzz_smoke_assert( null === ( $unlabeled_result['generator'] ?? null ), 'unlabeled direct input should not invent generator metadata.' );
html_api_fuzz_smoke_assert( 'input-base64' === ( $unlabeled_replay['inputSource'] ?? null ), 'unlabeled direct input replay should record inputSource.' );
html_api_fuzz_smoke_assert( 'idempotent' === ( $unlabeled_result['tagProcessor']['normalize']['status'] ?? null ), 'worker result should persist normalize() idempotence metadata.' );

$normalize_idempotent = \HtmlApiFuzz\TagInvariants::check( '<p a=1 a=2>One&nbsp</p>', array( 'maxTokens' => 2000 ) );
html_api_fuzz_smoke_assert( true === ( $normalize_idempotent['ok'] ?? null ), 'normalizable input should pass tag invariants.' );
html_api_fuzz_smoke_assert( 'idempotent' === ( $normalize_idempotent['normalize']['status'] ?? null ), 'normalizable input should record an idempotent normalize() status.' );
html_api_fuzz_smoke_assert( is_string( $normalize_idempotent['normalize']['normalizedSha1'] ?? null ), 'idempotent normalize() metadata should include the normalized hash.' );

$normalize_unsupported = \HtmlApiFuzz\TagInvariants::check( '<A><I><A>', array( 'maxTokens' => 2000 ) );
html_api_fuzz_smoke_assert( true === ( $normalize_unsupported['ok'] ?? null ), 'unsupported normalize() input should not fail unrelated tag invariants.' );
html_api_fuzz_smoke_assert( 'unsupported' === ( $normalize_unsupported['normalize']['status'] ?? null ), 'unsupported normalize() input should be recorded without an idempotence failure.' );

$normalize_not_idempotent = \HtmlApiFuzz\TagInvariants::check( '<svg xlink:href href></svg>', array( 'maxTokens' => 2000 ) );
html_api_fuzz_smoke_assert( true === ( $normalize_not_idempotent['ok'] ?? null ), 'normalize() metadata should not fail unrelated tag invariants directly.' );
if ( false === ( $normalize_not_idempotent['normalize']['ok'] ?? true ) ) {
	html_api_fuzz_smoke_assert( 'normalize-not-idempotent' === ( $normalize_not_idempotent['normalize']['failure']['name'] ?? null ), 'non-idempotent normalize() input should report the normalize-not-idempotent invariant.' );
	html_api_fuzz_smoke_assert( 'failed' === ( $normalize_not_idempotent['normalize']['status'] ?? null ), 'non-idempotent normalize() input should record failed normalize() metadata.' );
	html_api_fuzz_smoke_assert( is_int( $normalize_not_idempotent['normalize']['firstDifference']['firstByteOffset'] ?? null ), 'non-idempotent normalize() metadata should include a first byte difference.' );
}

$resource_dir = $tmp . '/resource-limit';
$resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( str_repeat( '<span>', 12 ) ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $resource_dir,
		'max-tokens'      => '1',
		'max-nodes'       => '100',
	)
);
html_api_fuzz_smoke_assert( 'resource-limit' === ( $resource_result['failureClass'] ?? null ), 'token ceilings should be bucketed as resource-limit.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $resource_result['status'] ?? null ), 'token ceilings should use resource-limit status.' );
html_api_fuzz_smoke_assert( in_array( 'tag-token-limit-exceeded', $resource_result['signature']['facts']['limitFailures'] ?? array(), true ), 'resource-limit signature should include concrete limit failure names.' );
html_api_fuzz_smoke_assert( 'input-base64' === ( $resource_result['inputSource'] ?? null ), 'provided base64 input should record inputSource.' );
html_api_fuzz_smoke_assert( null === ( $resource_result['generator'] ?? null ), 'provided input should not invent generator metadata.' );

$normalize_resource_dir = $tmp . '/normalize-resource-limit';
$normalize_resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<svg xlink:href href></svg>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $normalize_resource_dir,
		'max-tokens'      => '1',
		'max-nodes'       => '100',
	)
);
html_api_fuzz_smoke_assert( 'resource-limit' === ( $normalize_resource_result['failureClass'] ?? null ), 'tag token ceilings should not be masked by normalize() failures.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $normalize_resource_result['status'] ?? null ), 'tag token ceilings should retain resource-limit status when normalize() would otherwise fail.' );
html_api_fuzz_smoke_assert( 'skipped-resource-limit' === ( $normalize_resource_result['tagProcessor']['normalize']['status'] ?? null ), 'normalize() idempotence should be skipped after tag resource limits.' );
html_api_fuzz_smoke_assert( in_array( 'tag-token-limit-exceeded', $normalize_resource_result['signature']['facts']['limitFailures'] ?? array(), true ), 'resource-limit signature should still include tag token limit failures when normalize() is skipped.' );

$dom_resource_dir = $tmp . '/dom-resource-limit';
$dom_resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<p>x</p>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $dom_resource_dir,
		'max-tokens'      => '100',
		'max-nodes'       => '1',
	)
);
html_api_fuzz_smoke_assert( 'resource-limit' === ( $dom_resource_result['failureClass'] ?? null ), 'DOM node ceilings should be bucketed as resource-limit.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $dom_resource_result['status'] ?? null ), 'DOM node ceilings should use resource-limit status.' );
html_api_fuzz_smoke_assert( 'node-limit-exceeded' === ( $dom_resource_result['dom']['failureClass'] ?? null ), 'DOM result should preserve the concrete node limit failure.' );
html_api_fuzz_smoke_assert( in_array( 'dom-node-limit-exceeded', $dom_resource_result['signature']['facts']['limitFailures'] ?? array(), true ), 'resource-limit signature should include DOM node limit failures.' );


$wp_resource_dir = $tmp . '/wordpress-resource-limit';
$wp_resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<p>x</p>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $wp_resource_dir,
		'max-tokens'      => '3',
		'max-nodes'       => '100',
	)
);
html_api_fuzz_smoke_assert( 'resource-limit' === ( $wp_resource_result['failureClass'] ?? null ), 'WordPress tree token ceilings should be bucketed as resource-limit.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $wp_resource_result['status'] ?? null ), 'WordPress tree token ceilings should use resource-limit status.' );
html_api_fuzz_smoke_assert( 'token-limit-exceeded' === ( $wp_resource_result['wordpress']['failureClass'] ?? null ), 'WordPress result should preserve the concrete token limit failure.' );
html_api_fuzz_smoke_assert( in_array( 'wordpress-token-limit-exceeded', $wp_resource_result['signature']['facts']['limitFailures'] ?? array(), true ), 'resource-limit signature should include WordPress token limit failures.' );

$resource_watcher_run_dir = $tmp . '/resource-watcher-run';
\HtmlApiFuzz\ensure_dir( $resource_watcher_run_dir );
\HtmlApiFuzz\append_ndjson(
	$resource_watcher_run_dir . '/summary.ndjson',
	array(
		'ok'            => false,
		'status'        => $resource_result['status'] ?? null,
		'failureClass'  => $resource_result['failureClass'] ?? null,
		'profile'       => $resource_result['profile'] ?? null,
		'mode'          => $resource_result['mode'] ?? null,
		'payloadPolicy' => $resource_result['payloadPolicy'] ?? null,
		'generator'     => $resource_result['generator'] ?? null,
		'inputSource'   => $resource_result['inputSource'] ?? null,
		'inputSha1'     => $resource_result['inputSha1'] ?? null,
		'inputLength'   => $resource_result['inputLength'] ?? null,
		'signature'     => $resource_result['signature'] ?? null,
		'resultPath'    => $resource_dir . '/result.json',
		'replayPath'    => $resource_dir . '/replay.json',
	)
);
$resource_watcher_state_dir = $tmp . '/resource-watcher-state';
$resource_watcher_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/watcher.php',
		'--run-dir',
		$resource_watcher_run_dir,
		'--state-dir',
		$resource_watcher_state_dir,
		'--once',
		'--no-minimize',
		'--max-minimize',
		'1',
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/resource-watcher.log'
);
html_api_fuzz_smoke_assert( ! $resource_watcher_proc['timedOut'] && 0 === $resource_watcher_proc['code'], 'watcher should process resource-limit summaries.' );
$resource_watcher_state = \HtmlApiFuzz\read_json_file( $resource_watcher_state_dir . '/state.json' );
$resource_watcher_hash = $resource_result['signature']['hash'] ?? null;
$resource_watcher_record = is_string( $resource_watcher_hash ) ? ( $resource_watcher_state['signatures'][ $resource_watcher_hash ] ?? array() ) : array();
html_api_fuzz_smoke_assert( 'queued' === ( $resource_watcher_record['status'] ?? null ), 'watcher should queue resource-limit signatures for minimization.' );
html_api_fuzz_smoke_assert( ! isset( $resource_watcher_record['minimizeResult'] ), 'watcher --no-minimize should not start resource-limit minimization.' );
$resource_watcher_second_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/watcher.php',
		'--run-dir',
		$resource_watcher_run_dir,
		'--state-dir',
		$resource_watcher_state_dir,
		'--once',
		'--no-minimize',
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/resource-watcher-second.log'
);
$resource_watcher_second = json_decode( $resource_watcher_second_proc['stdout'], true );
html_api_fuzz_smoke_assert( ! $resource_watcher_second_proc['timedOut'] && 0 === $resource_watcher_second_proc['code'], 'watcher should process a second scan.' );
html_api_fuzz_smoke_assert( 0 === ( $resource_watcher_second['failuresSeen'] ?? null ), 'watcher should not reread already-scanned summary records.' );


echo "OK\n";
