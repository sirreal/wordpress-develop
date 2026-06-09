<?php
namespace HtmlApiFuzz;

class Generator {
	const MODE_FRAGMENT_BODY = 'fragment-body';
	const MODE_FULL_DOCUMENT = 'full-document';

	private $rng;
	private $profile;
	private $payload_policy;
	private $features = array();

	private $normal_tags = array( 'div', 'p', 'span', 'section', 'article', 'main', 'header', 'footer', 'a', 'b', 'i', 'em', 'strong', 'small', 'mark', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'button', 'form', 'label', 'select', 'option' );
	private $void_tags   = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
	private $raw_tags    = array( 'script', 'style', 'iframe', 'noembed', 'noframes', 'xmp' );
	private $rcdata_tags = array( 'title', 'textarea' );
	private $named_character_references = array( 'amp', 'AMP', 'lt', 'LT', 'gt', 'GT', 'quot', 'QUOT', 'apos', 'nbsp', 'copy', 'COPY', 'reg', 'not', 'notin', 'AElig', 'NotEqualTilde', 'CounterClockwiseContourIntegral' );
	private $legacy_semicolonless_named_character_references = array( 'amp', 'AMP', 'lt', 'LT', 'gt', 'GT', 'quot', 'QUOT', 'nbsp', 'copy', 'COPY', 'reg', 'not', 'AElig' );
	private $invalid_semicolonless_named_character_references = array( 'apos', 'notin', 'NotEqualTilde', 'CounterClockwiseContourIntegral' );
	private $unusual_attr_names         = array( 'aria-label', 'data-id', 'data--x', '_', ':colon', '@click', '[data-x]', 'xml:space', 'xmlns:xlink', 'xlink:href', 'on:click', 'data.thing', 'data🙂' );
	private $unusual_tag_names          = array( 'x-widget', 'x-0', 'a-b-c', 'foo:bar', 'foo.bar', 'foo_bar', 'x🙂', 'MiXeD-Custom' );

	public static function profiles(): array {
		return array(
			'balanced',
			'full-document',
			'body-fragment',
			'tables',
			'template',
			'foreign-content',
			'rawtext-rcdata',
			'formatting-adoption',
			'attributes-entities',
			'comments-doctype-bogus',
			'deep-nesting',
			'resource-stress',
			'incomplete-malformed',
		);
	}

	public static function payload_policies(): array {
		return array(
			'valid-utf8',
			'mostly-valid',
			'ascii-structural',
			'stress-long',
		);
	}

	public static function payload_policy_labels(): array {
		return array_merge(
			self::payload_policies(),
			array(
				'invalid-byte-heavy',
			)
		);
	}

	public static function modes(): array {
		return array(
			self::MODE_FRAGMENT_BODY,
			self::MODE_FULL_DOCUMENT,
		);
	}

	public static function generate( int $seed, string $profile = 'auto', string $mode = 'auto', string $payload_policy = 'auto', ?int $max_input_bytes = null ): array {
		$rng = new Prng( $seed );
		$requested_profile        = $profile;
		$requested_mode           = $mode;
		$requested_payload_policy = $payload_policy;
		if ( 'auto' === $profile ) {
			$profile = $rng->weighted(
				array(
					'balanced'                => 24,
					'full-document'           => 8,
					'body-fragment'           => 8,
					'tables'                  => 12,
					'template'                => 9,
					'foreign-content'         => 11,
					'rawtext-rcdata'          => 9,
					'formatting-adoption'     => 10,
						'attributes-entities'     => 7,
						'comments-doctype-bogus'  => 5,
						'deep-nesting'            => 2,
						'resource-stress'         => 1,
						'incomplete-malformed'    => 2,
					)
			);
		} elseif ( ! in_array( $profile, self::profiles(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator profile: ' . $profile );
		}

		if ( 'auto' === $payload_policy ) {
			$payload_policy = 'resource-stress' === $profile
				? $rng->weighted(
					array(
						'stress-long'  => 75,
						'mostly-valid' => 15,
						'valid-utf8'   => 10,
					)
				)
				: $rng->weighted(
					array(
						'valid-utf8'       => 52,
						'mostly-valid'     => 33,
						'ascii-structural' => 15,
					)
				);
		} elseif ( ! in_array( $payload_policy, self::payload_policies(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator payload policy: ' . $payload_policy );
		}

		if ( 'auto' === $mode ) {
			$mode = 'full-document' === $profile ? self::MODE_FULL_DOCUMENT : ( 'body-fragment' === $profile ? self::MODE_FRAGMENT_BODY : $rng->weighted( array( self::MODE_FRAGMENT_BODY => 70, self::MODE_FULL_DOCUMENT => 30 ) ) );
		} elseif ( ! in_array( $mode, self::modes(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator mode: ' . $mode );
		}

		$generator = new self( $rng, $profile, $payload_policy );
		$max_depth = $generator->depth_for_profile();
		$body      = $generator->nodes( $max_depth, 'body' );

		if ( self::MODE_FULL_DOCUMENT === $mode ) {
			$html = $generator->full_document( $body );
		} else {
			$html = $body;
		}
		$truncated = false;
		if ( null !== $max_input_bytes && $max_input_bytes > 0 && strlen( $html ) > $max_input_bytes ) {
			$html      = self::trim_to_max_bytes( $html, $max_input_bytes );
			$truncated = true;
			$generator->mark_feature( 'generator:truncated' );
		}

		return array(
			'input'         => $html,
			'mode'          => $mode,
			'profile'       => $profile,
			'payloadPolicy' => $payload_policy,
			'parameters'    => array(
				'seed'                   => $seed,
				'requestedProfile'       => $requested_profile,
				'requestedMode'          => $requested_mode,
				'requestedPayloadPolicy' => $requested_payload_policy,
				'profile'                => $profile,
				'mode'                   => $mode,
				'payloadPolicy'          => $payload_policy,
				'maxDepth'               => $max_depth,
				'maxInputBytes'          => $max_input_bytes,
				'truncated'              => $truncated,
				'byteLength'             => strlen( $html ),
				'features'               => $generator->features(),
			),
		);
	}

	private function __construct( Prng $rng, string $profile, string $payload_policy ) {
		$this->rng            = $rng;
		$this->profile        = $profile;
		$this->payload_policy = $payload_policy;
	}

	private static function trim_to_max_bytes( string $html, int $max_input_bytes ): string {
		$trimmed = substr( $html, 0, $max_input_bytes );
		while ( '' !== $trimmed && 1 !== preg_match( '//u', $trimmed ) ) {
			$trimmed = substr( $trimmed, 0, -1 );
		}

		return $trimmed;
	}

	private function mark_feature( string $feature ): void {
		$this->features[ $feature ] = true;
	}

	private function features(): array {
		$features = array_keys( $this->features );
		sort( $features );
		return $features;
	}

	private function depth_for_profile(): int {
		if ( 'resource-stress' === $this->profile ) {
			return $this->rng->int( 10, 20 );
		}
		if ( 'deep-nesting' === $this->profile ) {
			return $this->rng->int( 8, 18 );
		}
		if ( 'balanced' === $this->profile ) {
			return $this->rng->int( 3, 5 );
		}
		return $this->rng->int( 3, 6 );
	}

	private function full_document( string $body ): string {
		$doctype = $this->rng->chance( 70 ) ? $this->doctype() : '';
		$head    = $this->rng->chance( 65 ) ? '<head>' . $this->head_nodes() . '</head>' : $this->head_nodes();
		$attrs   = $this->attrs();
		$body_at = $this->attrs();

		if ( $this->rng->chance( 20 ) ) {
			return $doctype . $head . $body;
		}

		return $doctype . '<html' . $attrs . '>' . $head . '<body' . $body_at . '>' . $body . ( $this->rng->chance( 75 ) ? '</body></html>' : '' );
	}

	private function head_nodes(): string {
		$out = '';
		if ( $this->rng->chance( 45 ) ) {
			$out .= '<title>' . $this->terminal_text() . '</title>';
		}
		if ( $this->rng->chance( 35 ) ) {
			$out .= '<meta' . $this->attrs() . '>';
		}
		if ( $this->rng->chance( 25 ) ) {
			$out .= '<template>' . $this->nodes( 2, 'body' ) . '</template>';
		}
		return $out;
	}

	private function nodes( int $depth, string $context ): string {
		if ( 'deep-nesting' === $this->profile ) {
			$count = $this->rng->int( 1, 2 );
		} elseif ( $depth > 4 ) {
			$count = $this->rng->int( 1, 3 );
		} elseif ( $depth > 2 ) {
			$count = $this->rng->int( 1, 5 );
		} else {
			$count = $this->rng->int( 1, 7 );
		}

		$out = '';
		for ( $i = 0; $i < $count; ++$i ) {
			$out .= $this->node( $depth, $context );
			if ( strlen( $out ) > 131072 ) {
				$this->mark_feature( 'generator:hard-truncated' );
				return self::trim_to_max_bytes( $out, 131072 );
			}
		}
		return $out;
	}

	private function node( int $depth, string $context ): string {
		if ( $depth <= 0 ) {
			return $this->leaf();
		}

		$weights = array(
			'element'   => 35,
			'text'      => 16,
			'charref'   => 1,
			'comment'   => 7,
			'void'      => 8,
			'raw'       => 5,
			'template'  => 5,
			'table'     => 5,
			'foreign'   => 5,
			'weird-tag' => 0,
			'doctype'   => 2,
			'bogus'     => 2,
		);

		if ( 'tables' === $this->profile ) {
			$weights['table'] = 35;
			$weights['element'] = 15;
		} elseif ( 'template' === $this->profile ) {
			$weights['template'] = 35;
		} elseif ( 'foreign-content' === $this->profile ) {
			$weights['foreign'] = 35;
		} elseif ( 'rawtext-rcdata' === $this->profile ) {
			$weights['raw'] = 35;
		} elseif ( 'comments-doctype-bogus' === $this->profile ) {
			$weights['comment'] = 25;
			$weights['doctype'] = 12;
			$weights['bogus'] = 15;
		} elseif ( 'attributes-entities' === $this->profile ) {
			$weights['element'] = 50;
			$weights['text'] = 22;
			$weights['charref'] = 12;
			$weights['weird-tag'] = 8;
		} elseif ( 'formatting-adoption' === $this->profile ) {
			$weights['element'] = 55;
		} elseif ( 'incomplete-malformed' === $this->profile ) {
			$weights['bogus'] = 25;
			$weights['element'] = 30;
			$weights['weird-tag'] = 15;
		}

		switch ( $this->rng->weighted( $weights ) ) {
			case 'text':
				$this->mark_feature( 'text' );
				return $this->terminal_text();
			case 'charref':
				$this->mark_feature( 'text' );
				return $this->character_reference( 'text' ) . $this->terminal_payload();
			case 'comment':
				$this->mark_feature( 'comment' );
				return '<!--' . $this->terminal_comment() . ( $this->rng->chance( 85 ) ? '-->' : $this->rng->choice( array( '--!>', '', '>' ) ) );
			case 'void':
				$this->mark_feature( 'void-element' );
				return '<' . $this->rng->choice( $this->void_tags ) . $this->attrs() . ( $this->rng->chance( 25 ) ? '/>' : '>' );
			case 'raw':
				return $this->raw_element();
			case 'template':
				$this->mark_feature( 'template' );
				return '<template' . $this->attrs() . '>' . $this->nodes( $depth - 1, 'body' ) . ( $this->rng->chance( 80 ) ? '</template>' : '' );
			case 'table':
				return $this->table( $depth - 1 );
			case 'foreign':
				return $this->foreign( $depth - 1 );
			case 'weird-tag':
				return $this->weird_element( $depth - 1 );
			case 'doctype':
				$this->mark_feature( 'doctype' );
				return $this->doctype();
			case 'bogus':
				$this->mark_feature( 'bogus-markup' );
				return $this->bogus();
			case 'element':
			default:
				return $this->element( $depth - 1 );
		}
	}

	private function element( int $depth ): string {
		if ( 'attributes-entities' === $this->profile && $this->rng->chance( 16 ) ) {
			return $this->weird_element( $depth );
		}
		if ( 'incomplete-malformed' === $this->profile && $this->rng->chance( 12 ) ) {
			return $this->weird_element( $depth );
		}

		$tag = $this->tag_name();
		if ( 'formatting-adoption' === $this->profile ) {
			$tag = $this->rng->choice( array( 'a', 'b', 'big', 'button', 'em', 'font', 'i', 'nobr', 'p', 'span', 'strong' ) );
			$this->mark_feature( 'formatting-adoption-candidate' );
		}

		$close = $this->rng->chance( 'incomplete-malformed' === $this->profile ? 55 : 85 );
		$end   = $close ? '</' . ( $this->rng->chance( 88 ) ? $tag : $this->tag_name() ) . '>' : '';
		return '<' . $tag . $this->attrs() . '>' . $this->nodes( $depth, 'body' ) . $end;
	}

	private function weird_element( int $depth ): string {
		$this->mark_feature( 'tag:weird-syntax' );
		$case = $this->rng->weighted(
			array(
				'unusual-name'      => 36,
				'invalid-name'      => 30,
				'boundary-spacing'  => 18,
				'malformed-closer'  => 16,
			)
		);

		if ( 'invalid-name' === $case ) {
			$tag = $this->invalid_tag_name();
			return '<' . $tag . $this->attrs() . '>' . $this->nodes( $depth, 'body' ) . ( $this->rng->chance( 40 ) ? '</' . $tag . '>' : '' );
		}

		$tag = 'unusual-name' === $case ? $this->unusual_tag_name() : $this->tag_name();
		if ( 'boundary-spacing' === $case ) {
			$this->mark_feature( 'tag:weird-spacing' );
			return '<' . $tag . $this->tag_gap() . $this->attrs() . $this->tag_gap() . ( $this->rng->chance( 25 ) ? '/' . $this->tag_gap() : '' ) . '>' . $this->nodes( $depth, 'body' ) . ( $this->rng->chance( 70 ) ? '</' . $tag . $this->tag_gap() . '>' : '' );
		}

		if ( 'malformed-closer' === $case ) {
			return '<' . $tag . $this->attrs() . '>' . $this->nodes( $depth, 'body' ) . '</' . $this->invalid_tag_name() . $this->tag_gap() . '>';
		}

		return '<' . $tag . $this->attrs() . '>' . $this->nodes( $depth, 'body' ) . ( $this->rng->chance( 75 ) ? '</' . $tag . '>' : '' );
	}

	private function table( int $depth ): string {
		$this->mark_feature( 'table' );
		$cells = '';
		for ( $r = 0; $r < $this->rng->int( 1, 4 ); ++$r ) {
			$row = '';
			for ( $c = 0; $c < $this->rng->int( 1, 4 ); ++$c ) {
				$cell_tag = $this->rng->choice( array( 'td', 'th' ) );
				$row .= '<' . $cell_tag . $this->attrs() . '>' . $this->nodes( max( 0, $depth - 1 ), 'body' ) . ( $this->rng->chance( 80 ) ? '</' . $cell_tag . '>' : '' );
			}
			$cells .= '<tr' . $this->attrs() . '>' . $row . ( $this->rng->chance( 80 ) ? '</tr>' : '' );
		}

		$section = $this->rng->chance( 50 ) ? '<' . $this->rng->choice( array( 'tbody', 'thead', 'tfoot' ) ) . '>' . $cells . '</' . $this->rng->choice( array( 'tbody', 'thead', 'tfoot' ) ) . '>' : $cells;
		$noise   = $this->rng->chance( 45 ) ? $this->terminal_text() . $this->element( max( 0, $depth - 1 ) ) : '';
		if ( '' !== $noise ) {
			$this->mark_feature( 'foster-parenting-candidate' );
		}
		return '<table' . $this->attrs() . '>' . $noise . $section . ( $this->rng->chance( 82 ) ? '</table>' : '' );
	}

	private function foreign( int $depth ): string {
		$this->mark_feature( 'foreign-content' );
		if ( $this->rng->chance( 50 ) ) {
			$this->mark_feature( 'mathml-html-integration-point' );
			$inner = '<mi' . $this->attrs() . '>' . $this->terminal_text() . '</mi><annotation-xml encoding="text/html">' . $this->nodes( max( 0, $depth - 1 ), 'body' ) . '</annotation-xml>';
			return '<math' . $this->attrs() . '>' . $inner . ( $this->rng->chance( 85 ) ? '</math>' : '' );
		}

		$this->mark_feature( 'svg-foreignobject' );
		$inner = '<g><title>' . $this->terminal_text() . '</title><foreignObject>' . $this->nodes( max( 0, $depth - 1 ), 'body' ) . '</foreignObject></g>';
		return '<svg' . $this->attrs() . ' viewBox="0 0 10 10">' . $inner . ( $this->rng->chance( 85 ) ? '</svg>' : '' );
	}

	private function raw_element(): string {
		if ( $this->rng->chance( 45 ) ) {
			$tag = $this->rng->choice( $this->rcdata_tags );
			$this->mark_feature( 'rcdata' );
			return '<' . $tag . $this->attrs() . '>' . $this->terminal_rcdata() . ( $this->rng->chance( 82 ) ? '</' . $tag . '>' : '' );
		}

		$tag = $this->rng->choice( $this->raw_tags );
		$this->mark_feature( 'rawtext' );
		return '<' . $tag . $this->attrs() . '>' . $this->terminal_text( true ) . ( $this->rng->chance( 82 ) ? '</' . $tag . '>' : '' );
	}

	private function leaf(): string {
		return $this->rng->chance( 70 ) ? $this->terminal_text() : '<!--' . $this->terminal_comment() . '-->';
	}

	private function tag_name(): string {
		if ( $this->rng->chance( 8 ) ) {
			return $this->unusual_tag_name();
		}
		if ( $this->rng->chance( 12 ) ) {
			return $this->custom_name();
		}
		return $this->rng->choice( $this->normal_tags );
	}

	private function unusual_tag_name(): string {
		$this->mark_feature( 'tag:unusual-name' );
		return $this->rng->choice( $this->unusual_tag_names );
	}

	private function invalid_tag_name(): string {
		switch ( $this->rng->int( 1, 12 ) ) {
			case 1:
				$this->mark_feature( 'tag:invalid-name' );
				$this->mark_feature( 'tag:alpha-invalid-name' );
				return 'x' . "\0" . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			case 2:
				$this->mark_feature( 'tag:alpha-weird-name' );
				return 'x🙂' . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			case 3:
				$this->mark_feature( 'tag:alpha-weird-name' );
				return 'x<' . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			case 4:
				$this->mark_feature( 'tag:alpha-weird-name' );
				return 'x"' . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			case 5:
				$this->mark_feature( 'tag:alpha-weird-name' );
				return 'x=' . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			case 6:
				$this->mark_feature( 'tag:invalid-name' );
				$this->mark_feature( 'tag:bogus-open-name' );
				return '1' . $this->terminal_special_ascii( $this->rng->int( 1, 5 ) );
			case 7:
				$this->mark_feature( 'tag:invalid-name' );
				$this->mark_feature( 'tag:bogus-open-name' );
				return ':' . $this->terminal_special_ascii( $this->rng->int( 1, 5 ) );
			case 8:
				$this->mark_feature( 'tag:invalid-name' );
				$this->mark_feature( 'tag:bogus-open-name' );
				return '?' . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			case 9:
				$this->mark_feature( 'tag:invalid-name' );
				$this->mark_feature( 'tag:bogus-open-name' );
				return '!' . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			case 10:
				$this->mark_feature( 'tag:invalid-name' );
				$this->mark_feature( 'tag:bogus-open-name' );
				return '=' . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			case 11:
				$this->mark_feature( 'tag:invalid-name' );
				$this->mark_feature( 'tag:bogus-open-name' );
				return '"' . $this->terminal_ascii( $this->rng->int( 1, 6 ) );
			default:
				$this->mark_feature( 'tag:invalid-name' );
				$this->mark_feature( 'tag:bogus-open-name' );
				return $this->rng->choice( array( '#', '@', '$', '%', '|', '~' ) ) . $this->terminal_special_ascii( $this->rng->int( 1, 7 ) );
		}
	}

	private function custom_name(): string {
		$start = $this->rng->choice( array( 'x', 'wp', 'custom', 'a', 'z' ) );
		$payload = $this->rng->chance( 45 )
			? $this->terminal_payload()
			: $this->terminal_ascii( $this->rng->int( 1, 12 ) );
		$name = preg_replace( '/[\x09\x0a\x0c\x0d\x20<>\/]+/', '-', $payload );
		$name = trim( (string) $name, '-' );
		if ( '' === $name ) {
			$name = 'x';
		}

		return $start . '-' . $name;
	}

	private function attrs(): string {
		$count = 'attributes-entities' === $this->profile ? $this->rng->int( 1, 8 ) : $this->rng->int( 0, 4 );
		$out   = '';
		$weird_attr_chance = 'attributes-entities' === $this->profile ? 28 : ( 'incomplete-malformed' === $this->profile ? 18 : 0 );
		for ( $i = 0; $i < $count; ++$i ) {
			if ( $this->rng->chance( $weird_attr_chance ) ) {
				$out .= $this->weird_attr_chunk();
				continue;
			}

			$name = $this->attr_name();
			if ( '' === $name ) {
				continue;
			}
			$gap = $this->attribute_gap();
			if ( $this->rng->chance( 18 ) ) {
				$out .= $gap . $name;
				continue;
			}
			$value = $this->terminal_attr_value();
			$quote = $this->rng->choice( array( '"', "'", '', '"' ) );
			if ( '' === $quote ) {
				$this->mark_feature( 'attr:unquoted' );
				$value = $this->unquoted_attr_value( $value );
				$out .= $gap . $name . $this->attribute_equals() . $value;
			} else {
				$this->mark_feature( 'attr:quoted' );
				$out .= $gap . $name . $this->attribute_equals() . $quote . $value . $quote;
			}
		}
		return $out;
	}

	private function attr_name(): string {
		switch ( $this->rng->weighted( array( 'common' => 62, 'unusual' => 18, 'generated' => 20 ) ) ) {
			case 'common':
				return $this->rng->choice( array( 'id', 'class', 'href', 'src', 'alt', 'title', 'data-x', 'data-Foo', 'xlink:href', 'xml:lang', 'checked', 'disabled', 'style' ) );
			case 'unusual':
				$this->mark_feature( 'attr:weird-name' );
				return $this->rng->choice( $this->unusual_attr_names );
		}

		$name = $this->rng->chance( 45 )
			? $this->terminal_payload()
			: $this->terminal_special_ascii( $this->rng->int( 1, 14 ) );
		$name = preg_replace( '/[\x09\x0a\x0c\x0d\x20"\'<>\/=]+/', '-', $name );
		$name = trim( (string) $name, '-' );
		if ( '' === $name ) {
			return 'data-empty';
		}
		if ( 1 === preg_match( '/[^A-Za-z0-9_:\.-]/', $name ) ) {
			$this->mark_feature( 'attr:weird-name' );
		}
		return $name;
	}

	private function weird_attr_chunk(): string {
		$this->mark_feature( 'attr:malformed' );
		$this->mark_feature( 'attr:weird-name' );
		$name  = $this->terminal_attr_special_name();
		$value = $this->terminal_attr_value();
		$gap   = $this->attribute_gap();

		switch ( $this->rng->int( 1, 8 ) ) {
			case 1:
				return $gap . '@' . $name . $this->attribute_equals() . '"' . $value . '"';
			case 2:
				return $gap . '<' . $name . $this->attribute_equals() . "'" . $value . "'";
			case 3:
				return $gap . $name . '/' . $this->attribute_equals() . '"' . $value . '"';
			case 4:
				return $gap . '"' . $name . '"' . $this->attribute_equals() . "'" . $value . "'";
			case 5:
				return $gap . '=' . '"' . $value . '"';
			case 6:
				return $gap . $name . $this->attribute_gap() . $this->attribute_equals() . $this->attribute_gap() . $this->unquoted_attr_value( $value );
			case 7:
				return $gap . $name . '<' . $this->terminal_attr_special_name();
			default:
				return $gap . $name . $this->attribute_equals() . $this->character_reference( 'attr' ) . $this->terminal_ascii( 2 );
		}
	}

	private function terminal_attr_special_name(): string {
		switch ( $this->rng->int( 1, 8 ) ) {
			case 1:
				return 'data-' . $this->terminal_special_ascii( $this->rng->int( 1, 5 ) );
			case 2:
				return '[' . $this->terminal_ascii( $this->rng->int( 1, 4 ) ) . ']';
			case 3:
				return ':' . $this->terminal_ascii( $this->rng->int( 1, 5 ) );
			case 4:
				return '.' . $this->terminal_ascii( $this->rng->int( 1, 5 ) );
			case 5:
				return '#' . $this->terminal_ascii( $this->rng->int( 1, 5 ) );
			case 6:
				return "'" . $this->terminal_ascii( $this->rng->int( 1, 5 ) );
			case 7:
				return '"' . $this->terminal_ascii( $this->rng->int( 1, 5 ) );
			default:
				return 'x' . "\0" . $this->terminal_ascii( $this->rng->int( 1, 4 ) );
		}
	}

	private function attribute_gap(): string {
		$gap = $this->rng->choice( array( ' ', ' ', ' ', "\t", "\n", "\f", "\r\n", '  ', " \t " ) );
		if ( ' ' !== $gap ) {
			$this->mark_feature( 'attr:weird-spacing' );
		}
		return $gap;
	}

	private function attribute_equals(): string {
		$equals = $this->rng->choice( array( '=', '=', '=', ' = ', "\t=\n", "\f= ", " =\t" ) );
		if ( '=' !== $equals ) {
			$this->mark_feature( 'attr:weird-spacing' );
		}
		return $equals;
	}

	private function unquoted_attr_value( string $value ): string {
		return (string) preg_replace( '/[\x00-\x20"\'<>`=]+/', '_', $value );
	}

	private function tag_gap(): string {
		$gap = $this->rng->choice( array( ' ', ' ', "\t", "\n", "\f", "\r\n", '  ', " \t " ) );
		if ( ' ' !== $gap ) {
			$this->mark_feature( 'tag:weird-spacing' );
		}
		return $gap;
	}

	private function doctype(): string {
		if ( $this->rng->chance( 70 ) ) {
			return '<!DOCTYPE html>';
		}
		return '<!DOCTYPE ' . $this->rng->choice( array( 'html', 'HTML', 'svg', 'bogus' ) ) . ' "' . $this->terminal_ascii( 8 ) . '">';
	}

	private function bogus(): string {
		return $this->rng->choice(
			array(
				'<![CDATA[' . $this->terminal_text() . ']]>',
				'<?' . $this->terminal_ascii( 8 ) . '?>',
				'</ ' . $this->terminal_ascii( 5 ),
				'<' . $this->terminal_ascii( $this->rng->int( 0, 12 ) ),
				'<//' . $this->terminal_ascii( 10 ) . '>',
			)
		);
	}

	private function terminal_text( bool $raw = false ): string {
		$parts = array();
		$count = $this->rng->int( 1, 5 );
		for ( $i = 0; $i < $count; ++$i ) {
			$parts[] = $this->terminal_payload();
			if ( ! $raw && $this->rng->chance( 35 ) ) {
				$parts[] = $this->entity_or_markup_text();
			}
		}
		return implode( '', $parts );
	}

	private function terminal_rcdata(): string {
		$parts = array();
		$count = $this->rng->int( 1, 5 );
		for ( $i = 0; $i < $count; ++$i ) {
			$parts[] = $this->terminal_payload();
			if ( $this->rng->chance( 55 ) ) {
				$parts[] = $this->character_reference( 'rcdata' );
			}
		}
		return implode( '', $parts );
	}

	private function terminal_comment(): string {
		return str_replace( '-->', '-- >', $this->terminal_text( true ) );
	}

	private function terminal_attr_value(): string {
		return $this->terminal_payload() . ( $this->rng->chance( 45 ) ? $this->entity_or_markup_attr() : '' );
	}

	private function terminal_payload(): string {
		switch ( $this->rng->weighted( $this->payload_weights() ) ) {
			case 'utf8':
				$this->mark_feature( 'payload:utf8' );
				return $this->rng->choice( array( 'é', '雪', '🙂', 'β', 'עברית', 'مرحبا', 'नमस्ते' ) );
			case 'nulls':
				$this->mark_feature( 'payload:nul' );
				return $this->terminal_ascii( 3 ) . "\0" . $this->terminal_ascii( 3 );
			case 'controls':
				$this->mark_feature( 'payload:control' );
				return $this->terminal_control();
			case 'repeat':
				$this->mark_feature( 'payload:repeat' );
				return $this->terminal_repeat();
			case 'long-ascii':
				$this->mark_feature( 'payload:long-ascii' );
				return $this->terminal_ascii( $this->rng->int( 64, 512 ) );
			case 'ascii':
			default:
				$this->mark_feature( 'payload:ascii' );
				return $this->terminal_ascii( $this->rng->int( 1, 24 ) );
		}
	}

	private function payload_weights(): array {
		switch ( $this->payload_policy ) {
			case 'valid-utf8':
				return array( 'ascii' => 54, 'utf8' => 32, 'nulls' => 4, 'controls' => 4, 'repeat' => 6 );
			case 'ascii-structural':
				return array( 'ascii' => 74, 'nulls' => 4, 'controls' => 6, 'repeat' => 16 );
			case 'stress-long':
				return array( 'ascii' => 20, 'utf8' => 8, 'nulls' => 5, 'controls' => 5, 'repeat' => 42, 'long-ascii' => 20 );
			case 'mostly-valid':
			default:
				return array( 'ascii' => 50, 'utf8' => 25, 'nulls' => 7, 'controls' => 8, 'repeat' => 10 );
		}
	}

	private function entity_or_markup_text(): string {
		if ( $this->rng->chance( 78 ) ) {
			return $this->character_reference( 'text' );
		}

		$value = $this->rng->choice( array( '<', '>' ) );
		$this->mark_entity_feature( $value );
		return $value;
	}

	private function entity_or_markup_attr(): string {
		if ( $this->rng->chance( 82 ) ) {
			return $this->character_reference( 'attr' );
		}

		$value = $this->rng->choice( array( '<tag>', '<', '>' ) );
		$this->mark_entity_feature( $value );
		return $value;
	}

	private function character_reference( string $context ): string {
		$this->mark_feature( 'charref:' . $context );
		switch ( $this->rng->weighted( array( 'named-semicolon' => 28, 'named-missing-semicolon' => 24, 'decimal' => 18, 'hex' => 18, 'invalid' => 12 ) ) ) {
			case 'named-semicolon':
				$this->mark_character_reference_feature( $context, 'named' );
				$this->mark_character_reference_feature( $context, 'named-semicolon' );
				$name = $this->known_named_character_reference( $context );
				return '&' . $name . ';';

			case 'named-missing-semicolon':
				$this->mark_character_reference_feature( $context, 'named' );
				$this->mark_character_reference_feature( $context, 'named-missing-semicolon' );
				if ( $this->rng->chance( 65 ) ) {
					$this->mark_character_reference_feature( $context, 'named-missing-semicolon-legacy' );
					$name = $this->rng->choice( $this->legacy_semicolonless_named_character_references );
				} else {
					$this->mark_character_reference_feature( $context, 'named-missing-semicolon-invalid' );
					$name = $this->rng->choice( $this->invalid_semicolonless_named_character_references );
				}
				if ( strtolower( $name ) !== $name ) {
					$this->mark_character_reference_feature( $context, 'casing' );
				}
				return '&' . $name;

			case 'decimal':
				$this->mark_character_reference_feature( $context, 'numeric-decimal' );
				$this->mark_character_reference_feature( $context, 'numeric-valid' );
				return '&#' . $this->decimal_character_reference_digits( $context ) . ';';

			case 'hex':
				$this->mark_character_reference_feature( $context, 'numeric-hex' );
				$this->mark_character_reference_feature( $context, 'numeric-valid' );
				return '&#' . ( $this->rng->chance( 50 ) ? 'x' : 'X' ) . $this->hex_character_reference_digits( $context ) . ';';

			case 'invalid':
			default:
				return $this->invalid_character_reference( $context );
		}
	}

	private function mark_character_reference_feature( string $context, string $feature ): void {
		$this->mark_feature( 'charref:' . $feature );
		$this->mark_feature( 'charref:' . $context . ':' . $feature );
	}

	private function known_named_character_reference( string $context ): string {
		$name = $this->rng->choice( $this->named_character_references );
		if ( strtolower( $name ) !== $name ) {
			$this->mark_character_reference_feature( $context, 'casing' );
		}
		return $name;
	}

	private function decimal_character_reference_digits( string $context ): string {
		$digits = $this->rng->choice( array( '34', '38', '60', '62', '65', '160', '169', '65533', '128578' ) );
		if ( $this->rng->chance( 45 ) ) {
			$this->mark_character_reference_feature( $context, 'leading-zero' );
			$digits = str_repeat( '0', $this->rng->int( 1, 8 ) ) . $digits;
		}
		return $digits;
	}

	private function hex_character_reference_digits( string $context ): string {
		$digits = $this->rng->choice( array( '22', '26', '3C', '3e', '41', 'a0', '00A9', '1F642', 'FFFD' ) );
		if ( $this->rng->chance( 45 ) ) {
			$this->mark_character_reference_feature( $context, 'leading-zero' );
			$digits = str_repeat( '0', $this->rng->int( 1, 8 ) ) . $digits;
		}
		$out = '';
		foreach ( str_split( $digits ) as $char ) {
			if ( ctype_alpha( $char ) ) {
				$this->mark_character_reference_feature( $context, 'casing' );
				$out .= $this->rng->chance( 50 ) ? strtolower( $char ) : strtoupper( $char );
			} else {
				$out .= $char;
			}
		}
		return $out;
	}

	private function invalid_character_reference( string $context ): string {
		$this->mark_character_reference_feature( $context, 'invalid' );
		switch ( $this->rng->weighted( array( 'named' => 55, 'decimal' => 20, 'hex' => 25 ) ) ) {
			case 'decimal':
				$this->mark_character_reference_feature( $context, 'numeric-decimal' );
				$this->mark_character_reference_feature( $context, 'numeric-invalid' );
				return $this->rng->choice( array( '&#;', '&#0;', '&#00000000;', '&#13;', '&#99999999;', '&#-1;' ) );

			case 'hex':
				$this->mark_character_reference_feature( $context, 'numeric-hex' );
				$this->mark_character_reference_feature( $context, 'numeric-invalid' );
				return $this->rng->choice( array( '&#x;', '&#x0;', '&#X0000;', '&#xD800;', '&#x110000;' ) );

			case 'named':
			default:
				$this->mark_character_reference_feature( $context, 'named' );
				return $this->rng->choice( array( '&bogus;', '&NoSuchEntity', '&;', '&amp ;', '&noti;' ) );
		}
	}

	private function mark_entity_feature( string $value ): void {
		if ( 1 === preg_match( '/^&#(?:0+)?0;$/', $value ) || 1 === preg_match( '/^&#[xX](?:0+)?0;$/', $value ) ) {
			$this->mark_feature( 'entity:numeric-zero' );
		} elseif ( 1 === preg_match( '/^&#[xX](?:0+)?[fF]{3}[dD];$/', $value ) || '&#65533;' === $value ) {
			$this->mark_feature( 'entity:fffd' );
		} elseif ( '<' === $value || '>' === $value || '<tag>' === $value ) {
			$this->mark_feature( 'entity:markup-like' );
		} elseif ( 0 === strpos( $value, '&' ) ) {
			$this->mark_feature( 'entity:named' );
		}
	}

	private function terminal_control(): string {
		return $this->rng->choice( array( "\r", "\n", "\r\n", "\t", "\f", "\x01", "\x1f" ) ) . $this->terminal_ascii( 4 );
	}

	private function terminal_repeat(): string {
		$chars  = array( 'a', '<', '&', ' ' );
		$length = 'stress-long' === $this->payload_policy ? $this->rng->int( 64, 1024 ) : $this->rng->int( 4, 64 );
		if ( $length > 64 ) {
			$this->mark_feature( 'payload:long-repeat' );
		}
		return str_repeat( $this->rng->choice( $chars ), $length );
	}

	private function terminal_ascii( int $length ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 -_:/.,;#[](){}';
		$out      = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$out .= $alphabet[ $this->rng->int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}

	private function terminal_special_ascii( int $length ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_:.@#[](){}!?$%^*+~,|';
		$out      = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$out .= $alphabet[ $this->rng->int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}
}
