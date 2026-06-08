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
			'invalid-byte-heavy',
			'ascii-structural',
			'stress-long',
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
						'valid-utf8'         => 48,
						'mostly-valid'       => 30,
						'ascii-structural'   => 15,
						'invalid-byte-heavy' => 7,
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
			$html      = self::trim_to_max_bytes( $html, $max_input_bytes, $payload_policy );
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

	private static function trim_to_max_bytes( string $html, int $max_input_bytes, string $payload_policy ): string {
		$trimmed = substr( $html, 0, $max_input_bytes );
		if ( in_array( $payload_policy, array( 'valid-utf8', 'ascii-structural' ), true ) ) {
			while ( '' !== $trimmed && 1 !== preg_match( '//u', $trimmed ) ) {
				$trimmed = substr( $trimmed, 0, -1 );
			}
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
				return self::trim_to_max_bytes( $out, 131072, $this->payload_policy );
			}
		}
		return $out;
	}

	private function node( int $depth, string $context ): string {
		if ( $depth <= 0 ) {
			return $this->leaf();
		}

		$weights = array(
			'element'  => 35,
			'text'     => 16,
			'comment'  => 7,
			'void'     => 8,
			'raw'      => 5,
			'template' => 5,
			'table'    => 5,
			'foreign'  => 5,
			'doctype'  => 2,
			'bogus'    => 2,
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
		} elseif ( 'formatting-adoption' === $this->profile ) {
			$weights['element'] = 55;
		} elseif ( 'incomplete-malformed' === $this->profile ) {
			$weights['bogus'] = 25;
			$weights['element'] = 30;
		}

		switch ( $this->rng->weighted( $weights ) ) {
			case 'text':
				$this->mark_feature( 'text' );
				return $this->terminal_text();
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
		$tag = $this->tag_name();
		if ( 'formatting-adoption' === $this->profile ) {
			$tag = $this->rng->choice( array( 'a', 'b', 'big', 'button', 'em', 'font', 'i', 'nobr', 'p', 'span', 'strong' ) );
			$this->mark_feature( 'formatting-adoption-candidate' );
		}

		$close = $this->rng->chance( 'incomplete-malformed' === $this->profile ? 55 : 85 );
		$end   = $close ? '</' . ( $this->rng->chance( 88 ) ? $tag : $this->tag_name() ) . '>' : '';
		return '<' . $tag . $this->attrs() . '>' . $this->nodes( $depth, 'body' ) . $end;
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
		$tag = $this->rng->chance( 45 ) ? $this->rng->choice( $this->rcdata_tags ) : $this->rng->choice( $this->raw_tags );
		$this->mark_feature( in_array( $tag, $this->rcdata_tags, true ) ? 'rcdata' : 'rawtext' );
		return '<' . $tag . $this->attrs() . '>' . $this->terminal_text( true ) . ( $this->rng->chance( 82 ) ? '</' . $tag . '>' : '' );
	}

	private function leaf(): string {
		return $this->rng->chance( 70 ) ? $this->terminal_text() : '<!--' . $this->terminal_comment() . '-->';
	}

	private function tag_name(): string {
		if ( $this->rng->chance( 12 ) ) {
			return $this->custom_name();
		}
		return $this->rng->choice( $this->normal_tags );
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
		for ( $i = 0; $i < $count; ++$i ) {
			$name = $this->attr_name();
			if ( '' === $name ) {
				continue;
			}
			if ( $this->rng->chance( 18 ) ) {
				$out .= ' ' . $name;
				continue;
			}
			$value = $this->terminal_attr_value();
			$quote = $this->rng->choice( array( '"', "'", '', '"' ) );
			if ( '' === $quote ) {
				$this->mark_feature( 'attr:unquoted' );
				$value = preg_replace( '/[\x00-\x20"\'<>`=]+/', '_', $value );
				$out .= ' ' . $name . '=' . $value;
			} else {
				$this->mark_feature( 'attr:quoted' );
				$out .= ' ' . $name . '=' . $quote . $value . $quote;
			}
		}
		return $out;
	}

	private function attr_name(): string {
		if ( $this->rng->chance( 72 ) ) {
			return $this->rng->choice( array( 'id', 'class', 'href', 'src', 'alt', 'title', 'data-x', 'data-Foo', 'xlink:href', 'xml:lang', 'checked', 'disabled', 'style' ) );
		}

		$name = $this->rng->chance( 45 )
			? $this->terminal_payload()
			: $this->terminal_ascii( $this->rng->int( 1, 14 ) );
		$name = preg_replace( '/[\x09\x0a\x0c\x0d\x20"\'<>\/=]+/', '-', $name );
		$name = trim( (string) $name, '-' );
		return '' === $name ? 'data-empty' : $name;
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
			case 'other-bytes':
				$this->mark_feature( 'payload:invalid-byte' );
				return $this->rng->choice( array( "\x80", "\x81\x40", "\xC0\xAF", "\xE9", "\xFE\xFF", "\xF5\x80\x80\x80" ) );
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
				return array( 'ascii' => 58, 'utf8' => 32, 'controls' => 4, 'repeat' => 6 );
			case 'ascii-structural':
				return array( 'ascii' => 78, 'controls' => 6, 'repeat' => 16 );
			case 'invalid-byte-heavy':
				return array( 'ascii' => 25, 'utf8' => 15, 'other-bytes' => 35, 'nulls' => 10, 'controls' => 7, 'repeat' => 8 );
			case 'stress-long':
				return array( 'ascii' => 20, 'utf8' => 8, 'other-bytes' => 5, 'nulls' => 5, 'controls' => 5, 'repeat' => 37, 'long-ascii' => 20 );
			case 'mostly-valid':
			default:
				return array( 'ascii' => 50, 'utf8' => 25, 'other-bytes' => 5, 'nulls' => 2, 'controls' => 8, 'repeat' => 10 );
		}
	}

	private function entity_or_markup_text(): string {
		if ( in_array( $this->payload_policy, array( 'valid-utf8', 'ascii-structural' ), true ) ) {
			$value = $this->rng->choice( array( '&amp;', '&notin;', '&#xfffd;', '&bogus', '<', '>' ) );
			$this->mark_entity_feature( $value );
			return $value;
		}

		$value = $this->rng->choice( array( '&amp;', '&notin;', '&#x00;', '&#xfffd;', '&bogus', '<', '>' ) );
		$this->mark_entity_feature( $value );
		return $value;
	}

	private function entity_or_markup_attr(): string {
		if ( in_array( $this->payload_policy, array( 'valid-utf8', 'ascii-structural' ), true ) ) {
			$value = $this->rng->choice( array( '&amp;', '&quot;', '&notin;', '<tag>' ) );
			$this->mark_entity_feature( $value );
			return $value;
		}

		$value = $this->rng->choice( array( '&amp;', '&quot;', '&#0;', '&notin;', '<tag>' ) );
		$this->mark_entity_feature( $value );
		return $value;
	}

	private function mark_entity_feature( string $value ): void {
		if ( '&#x00;' === $value || '&#0;' === $value ) {
			$this->mark_feature( 'entity:numeric-zero' );
		} elseif ( '&#xfffd;' === $value ) {
			$this->mark_feature( 'entity:fffd' );
		} elseif ( '<' === $value || '>' === $value || '<tag>' === $value ) {
			$this->mark_feature( 'entity:markup-like' );
		} elseif ( 0 === strpos( $value, '&' ) ) {
			$this->mark_feature( 'entity:named' );
		}
	}

	private function terminal_control(): string {
		$controls = in_array( $this->payload_policy, array( 'valid-utf8', 'ascii-structural' ), true )
			? array( "\r", "\n", "\t", "\f" )
			: array( "\r", "\n", "\t", "\f", "\x01", "\x1f" );

		return $this->rng->choice( $controls ) . $this->terminal_ascii( 4 );
	}

	private function terminal_repeat(): string {
		$chars  = in_array( $this->payload_policy, array( 'valid-utf8', 'ascii-structural' ), true )
			? array( 'a', '<', '&', ' ' )
			: array( 'a', '<', '&', "\0", ' ' );
		$length = 'stress-long' === $this->payload_policy ? $this->rng->int( 64, 1024 ) : $this->rng->int( 4, 64 );
		if ( $length > 64 ) {
			$this->mark_feature( 'payload:long-repeat' );
		}
		$char = $this->rng->choice( $chars );
		if ( "\0" === $char ) {
			$this->mark_feature( 'payload:nul' );
		}

		return str_repeat( $char, $length );
	}

	private function terminal_ascii( int $length ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 -_:/.,;#[](){}';
		$out      = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$out .= $alphabet[ $this->rng->int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}
}
