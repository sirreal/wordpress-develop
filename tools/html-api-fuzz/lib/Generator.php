<?php
namespace HtmlApiFuzz;

class Generator {
	const MODE_FRAGMENT_BODY = 'fragment-body';
	const MODE_FULL_DOCUMENT = 'full-document';

	private $rng;
	private $profile;

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
			'incomplete-malformed',
		);
	}

	public static function generate( int $seed, string $profile = 'auto', string $mode = 'auto' ): array {
		$rng = new Prng( $seed );
		if ( 'auto' === $profile ) {
			$profile = $rng->weighted(
				array(
					'balanced'                => 20,
					'full-document'           => 8,
					'body-fragment'           => 8,
					'tables'                  => 10,
					'template'                => 8,
					'foreign-content'         => 10,
					'rawtext-rcdata'          => 9,
					'formatting-adoption'     => 9,
					'attributes-entities'     => 7,
					'comments-doctype-bogus'  => 6,
					'deep-nesting'            => 3,
					'incomplete-malformed'    => 2,
				)
			);
		}

		if ( 'auto' === $mode ) {
			$mode = 'full-document' === $profile ? self::MODE_FULL_DOCUMENT : ( 'body-fragment' === $profile ? self::MODE_FRAGMENT_BODY : $rng->weighted( array( self::MODE_FRAGMENT_BODY => 70, self::MODE_FULL_DOCUMENT => 30 ) ) );
		}

		$generator = new self( $rng, $profile );
		$max_depth = $generator->depth_for_profile();
		$body      = $generator->nodes( $max_depth, 'body' );

		if ( self::MODE_FULL_DOCUMENT === $mode ) {
			$html = $generator->full_document( $body );
		} else {
			$html = $body;
		}

		return array(
			'input'      => $html,
			'mode'       => $mode,
			'profile'    => $profile,
			'parameters' => array(
				'seed'       => $seed,
				'maxDepth'   => $max_depth,
				'byteLength' => strlen( $html ),
			),
		);
	}

	private function __construct( Prng $rng, string $profile ) {
		$this->rng     = $rng;
		$this->profile = $profile;
	}

	private function depth_for_profile(): int {
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
				return substr( $out, 0, 131072 );
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
				return $this->terminal_text();
			case 'comment':
				return '<!--' . $this->terminal_comment() . ( $this->rng->chance( 85 ) ? '-->' : $this->rng->choice( array( '--!>', '', '>' ) ) );
			case 'void':
				return '<' . $this->rng->choice( $this->void_tags ) . $this->attrs() . ( $this->rng->chance( 25 ) ? '/>' : '>' );
			case 'raw':
				return $this->raw_element();
			case 'template':
				return '<template' . $this->attrs() . '>' . $this->nodes( $depth - 1, 'body' ) . ( $this->rng->chance( 80 ) ? '</template>' : '' );
			case 'table':
				return $this->table( $depth - 1 );
			case 'foreign':
				return $this->foreign( $depth - 1 );
			case 'doctype':
				return $this->doctype();
			case 'bogus':
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
		}

		$close = $this->rng->chance( 'incomplete-malformed' === $this->profile ? 55 : 85 );
		$end   = $close ? '</' . ( $this->rng->chance( 88 ) ? $tag : $this->tag_name() ) . '>' : '';
		return '<' . $tag . $this->attrs() . '>' . $this->nodes( $depth, 'body' ) . $end;
	}

	private function table( int $depth ): string {
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
		return '<table' . $this->attrs() . '>' . $noise . $section . ( $this->rng->chance( 82 ) ? '</table>' : '' );
	}

	private function foreign( int $depth ): string {
		if ( $this->rng->chance( 50 ) ) {
			$inner = '<mi' . $this->attrs() . '>' . $this->terminal_text() . '</mi><annotation-xml encoding="text/html">' . $this->nodes( max( 0, $depth - 1 ), 'body' ) . '</annotation-xml>';
			return '<math' . $this->attrs() . '>' . $inner . ( $this->rng->chance( 85 ) ? '</math>' : '' );
		}

		$inner = '<g><title>' . $this->terminal_text() . '</title><foreignObject>' . $this->nodes( max( 0, $depth - 1 ), 'body' ) . '</foreignObject></g>';
		return '<svg' . $this->attrs() . ' viewBox="0 0 10 10">' . $inner . ( $this->rng->chance( 85 ) ? '</svg>' : '' );
	}

	private function raw_element(): string {
		$tag = $this->rng->chance( 45 ) ? $this->rng->choice( $this->rcdata_tags ) : $this->rng->choice( $this->raw_tags );
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
				$value = preg_replace( '/[\x00-\x20"\'<>`=]+/', '_', $value );
				$out .= ' ' . $name . '=' . $value;
			} else {
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
				$parts[] = $this->rng->choice( array( '&amp;', '&notin;', '&#x00;', '&#xfffd;', '&bogus', '<', '>' ) );
			}
		}
		return implode( '', $parts );
	}

	private function terminal_comment(): string {
		return str_replace( '-->', '-- >', $this->terminal_text( true ) );
	}

	private function terminal_attr_value(): string {
		return $this->terminal_payload() . ( $this->rng->chance( 45 ) ? $this->rng->choice( array( '&amp;', '&quot;', '&#0;', '&notin;', '<tag>' ) ) : '' );
	}

	private function terminal_payload(): string {
		switch ( $this->rng->weighted( array( 'ascii' => 45, 'utf8' => 20, 'other-bytes' => 12, 'nulls' => 8, 'controls' => 8, 'repeat' => 7 ) ) ) {
			case 'utf8':
				return $this->rng->choice( array( 'é', '雪', '🙂', 'β', 'עברית', 'مرحبا', 'नमस्ते' ) );
			case 'other-bytes':
				return $this->rng->choice( array( "\x80", "\x81\x40", "\xC0\xAF", "\xE9", "\xFE\xFF", "\xF5\x80\x80\x80" ) );
			case 'nulls':
				return $this->terminal_ascii( 3 ) . "\0" . $this->terminal_ascii( 3 );
			case 'controls':
				return $this->rng->choice( array( "\r", "\n", "\t", "\f", "\x01", "\x1f" ) ) . $this->terminal_ascii( 4 );
			case 'repeat':
				return str_repeat( $this->rng->choice( array( 'a', '<', '&', "\0", ' ' ) ), $this->rng->int( 4, 64 ) );
			case 'ascii':
			default:
				return $this->terminal_ascii( $this->rng->int( 1, 24 ) );
		}
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
