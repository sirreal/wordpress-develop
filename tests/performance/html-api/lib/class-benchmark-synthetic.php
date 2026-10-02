<?php
/**
 * Seeded synthetic document generator for the HTML API parsing benchmark.
 *
 * Every shape produces a full HTML document (doctype, html, head, body) whose
 * body is built from generated chunks until the byte target is reached. The
 * last chunk is trimmed at an element boundary, never mid-token, so the output
 * is always well-formed for its shape and lands within 2% of the target size.
 *
 * Generation is deterministic: the same (shape, target bytes, seed) produces
 * identical bytes on every run and on every PHP version, because all choices
 * come from `mt_rand()` after `mt_srand( $seed, MT_RAND_MT19937 )`.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

/**
 * Generates synthetic HTML documents of a named shape at a byte target.
 */
class Benchmark_Synthetic {
	/**
	 * Shape ids in report order, each with a one-sentence description.
	 *
	 * @var array<string, string>
	 */
	const SHAPES = array(
		'tags-dense'          => 'Short elements with no attributes and almost no text, nested up to eight deep.',
		'text-long'           => 'A few P elements holding long runs of plain ASCII prose with no entities, no "<" and no "&".',
		'text-entities'       => 'Prose with frequent named and numeric character references, including invalid and unterminated ones.',
		'text-utf8'           => 'Prose of multi-byte UTF-8 text in mixed scripts, with no character references.',
		'comments-many'       => 'Thousands of short comments between tiny elements, some containing "--", plus bogus comments like "<!x>" and "<?pi?>".',
		'attributes-heavy'    => 'Elements with 10 to 30 attributes each: quoted and unquoted values, duplicates, booleans, long class and style values, data-* names.',
		'nesting-deep'        => 'Elements nested 200 deep and then unwound, repeated, to load the HTML Processor stack of open elements.',
		'script-style'        => 'Large SCRIPT and STYLE bodies containing "<" and "</" that are not closers, plus TEXTAREA and TITLE RCDATA.',
		'whitespace-runs'     => 'Long runs of spaces, tabs and newlines between tags and inside text.',
		'foreign-content'     => 'Inline SVG and MathML subtrees with namespaced attributes, self-closing tags and foreignObject holding HTML.',
		'formatting-adoption' => 'Misnested formatting elements (B and I crossing, A inside A, P closed by DIV) that run the adoption agency on the HTML Processor, limited to the cases it supports: no block element between a formatting element and its closer, and no text before the inner closers.',
		'tables'              => 'Tables with and without TBODY, implied rows and cells, nested tables, comments and hidden inputs between rows; no foster-parented content, because the HTML Processor bails on it.',
		'wordpress-post'      => 'A block-themed WordPress page: block comments, paragraphs, headings, figures with srcset, lists, nested group DIVs with class and style, inline SVG icons and JSON scripts.',
	);

	/**
	 * Plain ASCII words used for prose.
	 *
	 * @var string[]
	 */
	const ASCII_WORDS = array(
		'the',
		'of',
		'and',
		'to',
		'in',
		'that',
		'is',
		'was',
		'for',
		'it',
		'with',
		'as',
		'his',
		'on',
		'be',
		'at',
		'by',
		'this',
		'had',
		'not',
		'are',
		'but',
		'from',
		'or',
		'have',
		'an',
		'they',
		'which',
		'one',
		'you',
		'were',
		'her',
		'all',
		'she',
		'there',
		'would',
		'their',
		'we',
		'him',
		'been',
		'has',
		'when',
		'who',
		'will',
		'more',
		'no',
		'if',
		'out',
		'so',
		'said',
		'what',
		'up',
		'its',
		'about',
		'into',
		'than',
		'them',
		'can',
		'only',
		'other',
		'new',
		'some',
		'could',
		'time',
		'these',
		'two',
		'may',
		'then',
		'do',
		'first',
		'any',
		'my',
		'now',
		'such',
		'like',
		'our',
		'over',
		'man',
		'me',
		'even',
		'most',
		'made',
		'after',
		'also',
		'did',
		'many',
		'before',
		'must',
		'through',
		'back',
		'years',
		'where',
		'much',
		'your',
		'way',
		'well',
		'down',
		'should',
		'because',
		'each',
		'just',
		'those',
		'people',
		'how',
		'too',
		'little',
		'state',
		'good',
		'very',
		'make',
		'world',
		'still',
		'own',
		'see',
		'men',
		'work',
		'long',
		'get',
		'here',
		'between',
		'both',
		'life',
		'being',
		'under',
		'never',
		'day',
		'same',
		'another',
		'know',
		'while',
		'last',
		'might',
		'great',
		'old',
		'year',
		'off',
		'come',
		'since',
		'against',
		'go',
		'came',
		'right',
		'used',
		'take',
		'three',
		'parser',
		'document',
		'element',
		'attribute',
		'token',
		'markup',
		'string',
		'buffer',
		'processor',
		'benchmark',
	);

	/**
	 * Multi-byte UTF-8 words in mixed scripts.
	 *
	 * @var string[]
	 */
	const UTF8_WORDS = array(
		// Japanese.
		'文書',
		'解析',
		'要素',
		'属性',
		'トークン',
		'プロセッサ',
		'ベンチマーク',
		'日本語',
		'これは',
		'テストです',
		// Chinese.
		'文档',
		'解析器',
		'元素',
		'属性值',
		'标记语言',
		'性能测试',
		// Korean.
		'문서',
		'파서',
		'요소',
		'속성',
		'토큰',
		'벤치마크',
		// Arabic.
		'المستند',
		'المحلل',
		'عنصر',
		'سمة',
		'رمز',
		'اختبار',
		// Hebrew.
		'מסמך',
		'מנתח',
		'אלמנט',
		// Cyrillic.
		'документ',
		'парсер',
		'элемент',
		'атрибут',
		'токен',
		'тест',
		'производительность',
		// Greek.
		'έγγραφο',
		'αναλυτής',
		'στοιχείο',
		'χαρακτηριστικό',
		// Devanagari.
		'दस्तावेज़',
		'पार्सर',
		'तत्व',
		'विशेषता',
		// Thai.
		'เอกสาร',
		'ตัวแยกวิเคราะห์',
		'องค์ประกอบ',
		// Latin with diacritics.
		'déjà',
		'naïve',
		'Straße',
		'çocuk',
		'năm',
		'Ōtsuka',
		'ñandú',
		'łódź',
		// Symbols and emoji (four-byte sequences).
		'→',
		'…',
		'«»',
		'🙂',
		'🚀',
		'🧪',
		'✓',
	);

	/**
	 * Returns the shape ids in report order.
	 *
	 * @return string[] Shape ids.
	 */
	public static function shapes(): array {
		return array_keys( self::SHAPES );
	}

	/**
	 * Returns a one-sentence description of a shape.
	 *
	 * @throws InvalidArgumentException When the shape is unknown.
	 *
	 * @param string $shape Shape id.
	 * @return string Description.
	 */
	public static function describe( string $shape ): string {
		if ( ! isset( self::SHAPES[ $shape ] ) ) {
			throw new InvalidArgumentException( "Unknown synthetic shape '{$shape}'." );
		}

		return self::SHAPES[ $shape ];
	}

	/**
	 * Generates a document of the given shape, within 2% of the byte target.
	 *
	 * @throws InvalidArgumentException When the shape is unknown or the target is too small.
	 *
	 * @param string $shape        Shape id.
	 * @param int    $target_bytes Target document size in bytes.
	 * @param int    $seed         Seed for `mt_srand()`.
	 * @return string The HTML document.
	 */
	public static function generate( string $shape, int $target_bytes, int $seed ): string {
		if ( ! isset( self::SHAPES[ $shape ] ) ) {
			throw new InvalidArgumentException( "Unknown synthetic shape '{$shape}'." );
		}

		mt_srand( $seed, MT_RAND_MT19937 );

		$head = self::head( $shape );
		$tail = "\n</body>\n</html>\n";

		$budget = $target_bytes - strlen( $head ) - strlen( $tail );
		if ( $budget < 64 ) {
			throw new InvalidArgumentException( "Target of {$target_bytes} bytes is too small for shape '{$shape}'." );
		}

		$generator = 'chunk_' . str_replace( '-', '_', $shape );
		$body      = '';
		$remaining = $budget;

		while ( $remaining > 0 ) {
			$natural = mt_rand( 1500, 4000 );
			$chunk   = self::$generator( min( $natural, $remaining ) );
			if ( '' === $chunk ) {
				break;
			}
			$body      .= $chunk;
			$remaining -= strlen( $chunk );
		}

		return $head . $body . $tail;
	}

	/**
	 * Builds the document prologue through the BODY start tag.
	 *
	 * @param string $shape Shape id.
	 * @return string Document head.
	 */
	private static function head( string $shape ): string {
		if ( 'wordpress-post' === $shape ) {
			return "<!DOCTYPE html>\n<html lang=\"en-US\">\n<head>\n" .
				"<meta charset=\"UTF-8\">\n" .
				"<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n" .
				"<title>Synthetic post &#8211; Benchmark</title>\n" .
				"<meta name=\"generator\" content=\"WordPress 7.2\">\n" .
				"<link rel=\"stylesheet\" id=\"wp-block-library-css\" href=\"/wp-includes/css/dist/block-library/style.min.css\" media=\"all\">\n" .
				"<style id=\"global-styles-inline-css\">:root{--wp--preset--color--base:#fff;--wp--preset--color--contrast:#111}body{margin:0}</style>\n" .
				"</head>\n<body class=\"post-template-default single single-post wp-embed-responsive\">\n";
		}

		return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n" .
			"<meta charset=\"utf-8\">\n" .
			"<title>Synthetic {$shape}</title>\n" .
			"</head>\n<body>\n";
	}

	/*
	 * Shared helpers.
	 */

	/**
	 * Picks one entry of a list.
	 *
	 * @param array $items Non-empty list.
	 * @return mixed One entry.
	 */
	private static function pick( array $items ) {
		return $items[ mt_rand( 0, count( $items ) - 1 ) ];
	}

	/**
	 * Returns true with the given probability in percent.
	 *
	 * @param int $percent Probability, 0 to 100.
	 * @return bool Whether the event fired.
	 */
	private static function chance( int $percent ): bool {
		return mt_rand( 1, 100 ) <= $percent;
	}

	/**
	 * Builds prose from a word list, never exceeding `$max` bytes.
	 *
	 * Sentences are 6 to 16 words, capitalized, ending in a period, with a
	 * comma now and then and a newline after every few sentences. Entities
	 * are inserted after a word with the given percent chance.
	 *
	 * @param int      $max          Byte limit.
	 * @param string[] $words        Word list.
	 * @param int      $entity_rate  Percent chance of an entity after each word.
	 * @param string[] $entities     Entities to draw from when the chance fires.
	 * @return string Prose, possibly empty when nothing fits.
	 */
	private static function prose( int $max, array $words, int $entity_rate = 0, array $entities = array() ): string {
		$out       = '';
		$len       = 0;
		$in_sent   = 0;
		$sent_len  = mt_rand( 6, 16 );
		$sentences = 0;

		while ( true ) {
			$word = self::pick( $words );
			if ( 0 === $in_sent ) {
				$word = ucfirst( $word );
			}

			$piece = $word;
			if ( $entity_rate > 0 && self::chance( $entity_rate ) ) {
				$piece .= self::pick( $entities );
			}

			++$in_sent;
			$sep = ' ';
			if ( $in_sent >= $sent_len ) {
				$piece   .= '.';
				$in_sent  = 0;
				$sent_len = mt_rand( 6, 16 );
				++$sentences;
				if ( 0 === $sentences % 4 ) {
					$sep = "\n";
				}
			} elseif ( self::chance( 8 ) ) {
				$piece .= ',';
			}

			$need = ( $len > 0 ? 1 : 0 ) + strlen( $piece );
			if ( $len + $need > $max ) {
				break;
			}

			if ( $len > 0 ) {
				$out .= $sep_prev;
			}
			$out     .= $piece;
			$len     += $need;
			$sep_prev = $sep;
		}

		return $out;
	}

	/**
	 * Appends `$piece` to `$out` if it fits in `$max`, reporting whether it did.
	 *
	 * @param string $out   Output buffer, by reference.
	 * @param string $piece Piece to append.
	 * @param int    $max   Byte limit for `$out` after the append.
	 * @return bool Whether the piece was appended.
	 */
	private static function append( string &$out, string $piece, int $max ): bool {
		if ( strlen( $out ) + strlen( $piece ) > $max ) {
			return false;
		}
		$out .= $piece;
		return true;
	}

	/*
	 * Chunk generators. Each returns a self-contained, balanced fragment no
	 * longer than `$max` bytes, or an empty string if nothing fits.
	 */

	/**
	 * `tags-dense`: trees of short attribute-less elements, little text.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_tags_dense( int $max ): string {
		static $tags = array( 'div', 'span', 'b', 'i', 'em', 'strong', 'section', 'article', 'aside', 'small', 'u', 's', 'code', 'kbd', 'nav', 'label' );

		$out = '';
		while ( true ) {
			$depth = mt_rand( 1, 8 );
			$tree  = self::dense_tree( $tags, $depth, $max - strlen( $out ) - 1 );
			if ( '' === $tree ) {
				break;
			}
			$out .= $tree;
		}

		if ( '' !== $out && strlen( $out ) < $max ) {
			$out .= "\n";
		}

		return $out;
	}

	/**
	 * Builds one nested tree for `tags-dense` within `$max` bytes.
	 *
	 * The budget is checked at every open tag so the tree is cut at an element
	 * boundary: a subtree that does not fit is dropped whole.
	 *
	 * @param string[] $tags  Tag names to draw from.
	 * @param int      $depth Remaining depth.
	 * @param int      $max   Byte limit.
	 * @return string Tree markup, or empty when even the root does not fit.
	 */
	private static function dense_tree( array $tags, int $depth, int $max ): string {
		$tag  = self::pick( $tags );
		$open = "<{$tag}>";
		$end  = "</{$tag}>";
		if ( strlen( $open ) + strlen( $end ) > $max ) {
			return '';
		}

		$inner = '';
		$limit = $max - strlen( $open ) - strlen( $end );
		if ( $depth > 1 ) {
			$children = mt_rand( 1, 3 );
			for ( $i = 0; $i < $children; $i++ ) {
				$child = self::dense_tree( $tags, $depth - 1, $limit - strlen( $inner ) );
				if ( '' === $child ) {
					break;
				}
				$inner .= $child;
			}
		} elseif ( self::chance( 15 ) ) {
			self::append( $inner, self::pick( self::ASCII_WORDS ), $limit );
		}

		return $open . $inner . $end;
	}

	/**
	 * `text-long`: one P of long plain prose.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_text_long( int $max ): string {
		$frame = strlen( "<p>\n</p>\n" );
		if ( $max < $frame + 8 ) {
			return '';
		}

		$text = self::prose( $max - $frame, self::ASCII_WORDS );
		if ( '' === $text ) {
			return '';
		}

		return "<p>\n{$text}</p>\n";
	}

	/**
	 * `text-entities`: prose with frequent character references.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_text_entities( int $max ): string {
		static $entities = array(
			'&amp;',
			'&amp;',
			'&nbsp;',
			'&nbsp;',
			'&#8217;',
			'&#8217;s',
			'&#8220;',
			'&#8221;',
			'&hellip;',
			'&mdash;',
			'&ndash;',
			'&lt;',
			'&gt;',
			'&quot;',
			'&#x27;',
			'&#x2014;',
			'&copy;',
			'&eacute;',
			'&notanentity;',
			'&bogus;',
			'&amp',
			'&#',
			'&#99999999;',
			'&ampersand',
			'&Aring',
		);

		$frame = strlen( "<p>\n</p>\n" );
		if ( $max < $frame + 8 ) {
			return '';
		}

		$text = self::prose( $max - $frame, self::ASCII_WORDS, 35, $entities );
		if ( '' === $text ) {
			return '';
		}

		return "<p>\n{$text}</p>\n";
	}

	/**
	 * `text-utf8`: prose of multi-byte words.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_text_utf8( int $max ): string {
		$frame = strlen( "<p>\n</p>\n" );
		if ( $max < $frame + 8 ) {
			return '';
		}

		$text = self::prose( $max - $frame, self::UTF8_WORDS );
		if ( '' === $text ) {
			return '';
		}

		return "<p>\n{$text}</p>\n";
	}

	/**
	 * `comments-many`: short comments between tiny elements.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_comments_many( int $max ): string {
		static $tags = array( 'span', 'b', 'i', 'em', 'li', 'td', 'p', 'div' );

		$out = '';
		if ( ! self::append( $out, '<div>', $max - 7 ) ) {
			return '';
		}
		$limit = $max - 7;

		while ( true ) {
			$roll = mt_rand( 1, 100 );
			if ( $roll <= 70 ) {
				$words = array();
				$n     = mt_rand( 2, 8 );
				for ( $i = 0; $i < $n; $i++ ) {
					$words[] = self::pick( self::ASCII_WORDS );
				}
				$text = implode( ' ', $words );
				if ( self::chance( 20 ) ) {
					$text = str_replace( ' ', ' -- ', $text );
				}
				$piece = "<!-- {$text} -->";
			} elseif ( $roll <= 78 ) {
				$piece = '<!--' . self::pick( array( '', ' ', ' - ', ' -- ', ' ---- ', '--x--', ' spaced -- out -- dashes ' ) ) . '-->';
			} elseif ( $roll <= 84 ) {
				$piece = '<!' . self::pick( array( 'x', 'ELEMENT br EMPTY', 'ENTITY nbsp', 'x bogus comment' ) ) . '>';
			} elseif ( $roll <= 90 ) {
				$piece = '<?' . self::pick( array( 'pi?', 'xml version="1.0"?', 'php echo 1; ?', 'hint target data' ) ) . '>';
			} else {
				$tag   = self::pick( $tags );
				$piece = "<{$tag}></{$tag}>";
				if ( 'li' === $tag || 'td' === $tag ) {
					$piece = '<span></span>';
				}
			}

			if ( ! self::append( $out, $piece, $limit ) ) {
				break;
			}
			if ( self::chance( 10 ) ) {
				self::append( $out, "\n", $limit );
			}
		}

		return $out . "</div>\n";
	}

	/**
	 * `attributes-heavy`: elements with 10 to 30 attributes each.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_attributes_heavy( int $max ): string {
		static $elements   = array( 'div', 'span', 'section', 'button', 'input', 'img', 'a', 'li', 'p', 'label' );
		static $booleans   = array( 'hidden', 'disabled', 'checked', 'required', 'readonly', 'open', 'inert', 'autofocus', 'async', 'defer', 'itemscope' );
		static $plain      = array( 'id', 'title', 'name', 'role', 'lang', 'dir', 'tabindex', 'href', 'src', 'alt', 'rel', 'target', 'type', 'value', 'placeholder', 'aria-label', 'aria-hidden', 'aria-describedby', 'itemprop', 'draggable', 'contenteditable', 'spellcheck', 'accesskey', 'for', 'width', 'height', 'loading', 'decoding' );
		static $css_props  = array( 'color', 'background-color', 'margin', 'padding', 'font-size', 'line-height', 'display', 'position', 'top', 'left', 'width', 'max-width', 'border', 'border-radius', 'opacity', 'transform', 'transition', 'z-index', 'flex', 'grid-template-columns', 'text-align', 'letter-spacing', 'overflow', 'box-shadow' );
		static $css_values = array( '#1a2b3c', 'rgb(10, 20, 30)', '0', '1px', '0.75rem', '1.5', 'block', 'relative', 'auto', '100%', '42em', '1px solid #ccc', '4px', '0.9', 'translateY(-2px)', 'all 0.2s ease-in-out', '10', '1 1 auto', 'repeat(3, minmax(0, 1fr))', 'center', '0.02em', 'hidden', '0 2px 8px rgba(0,0,0,0.15)', 'var(--wp--preset--color--primary)' );

		$out    = '';
		$misses = 0;
		while ( true ) {
			$tag   = self::pick( $elements );
			$attrs = array();
			$count = mt_rand( 10, 30 );
			for ( $i = 0; $i < $count; $i++ ) {
				$kind = mt_rand( 1, 100 );
				if ( $kind <= 12 ) {
					$attrs[] = self::pick( $booleans );
				} elseif ( $kind <= 20 ) {
					// Unquoted value.
					$attrs[] = self::pick( $plain ) . '=' . self::pick( array( '0', '42', 'abc', 'x-' . mt_rand( 1, 9999 ), 'true', 'ltr', '_blank', 'lazy' ) );
				} elseif ( $kind <= 30 ) {
					// Single-quoted value.
					$attrs[] = self::pick( $plain ) . "='" . self::pick( self::ASCII_WORDS ) . ' ' . self::pick( self::ASCII_WORDS ) . "'";
				} elseif ( $kind <= 42 ) {
					// Long class list.
					$classes = array();
					$n       = mt_rand( 8, 30 );
					for ( $j = 0; $j < $n; $j++ ) {
						$classes[] = self::pick( array( 'wp-block', 'has', 'is', 'col', 'row', 'u', 'js' ) ) . '-' . self::pick( self::ASCII_WORDS ) . ( self::chance( 30 ) ? '-' . mt_rand( 1, 12 ) : '' );
					}
					$attrs[] = 'class="' . implode( ' ', $classes ) . '"';
				} elseif ( $kind <= 54 ) {
					// Long style value.
					$decls = array();
					$n     = mt_rand( 4, 14 );
					for ( $j = 0; $j < $n; $j++ ) {
						$decls[] = self::pick( $css_props ) . ': ' . self::pick( $css_values );
					}
					$attrs[] = 'style="' . implode( '; ', $decls ) . ';"';
				} elseif ( $kind <= 78 ) {
					// data-* names, quoted.
					$name    = 'data-' . self::pick( self::ASCII_WORDS ) . ( self::chance( 50 ) ? '-' . self::pick( self::ASCII_WORDS ) : '' );
					$attrs[] = $name . '="' . self::pick( self::ASCII_WORDS ) . ' ' . self::pick( self::ASCII_WORDS ) . ' ' . mt_rand( 0, 99999 ) . '"';
				} elseif ( $kind <= 90 ) {
					// Plain quoted value.
					$name = self::pick( $plain );
					if ( 'href' === $name || 'src' === $name ) {
						$attrs[] = $name . '="https://example.com/' . self::pick( self::ASCII_WORDS ) . '/' . self::pick( self::ASCII_WORDS ) . '-' . mt_rand( 1, 9999 ) . '/?x=' . mt_rand( 1, 999 ) . '&amp;y=' . self::pick( self::ASCII_WORDS ) . '"';
					} else {
						$attrs[] = $name . '="' . self::pick( self::ASCII_WORDS ) . ' ' . self::pick( self::ASCII_WORDS ) . '"';
					}
				} elseif ( ! empty( $attrs ) ) {
					// Duplicate of an earlier attribute.
					$attrs[] = self::pick( $attrs );
				}
			}

			$is_void = 'input' === $tag || 'img' === $tag;
			$piece   = "<{$tag} " . implode( ' ', $attrs ) . '>';
			if ( ! $is_void ) {
				$piece .= ( self::chance( 30 ) ? self::pick( self::ASCII_WORDS ) : '' ) . "</{$tag}>";
			}
			$piece .= "\n";

			if ( ! self::append( $out, $piece, $max ) ) {
				// One element can run past a small limit; try a few more before giving up.
				if ( ++$misses < 8 ) {
					continue;
				}
				break;
			}
		}

		return $out;
	}

	/**
	 * `nesting-deep`: nest to depth 200 and unwind.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_nesting_deep( int $max ): string {
		static $tags = array( 'div', 'section', 'article', 'aside', 'nav', 'span', 'header', 'footer', 'figure', 'blockquote' );

		$openers = array();
		$closers = array();
		$len     = 0;
		$depth   = 200;
		for ( $i = 0; $i < $depth; $i++ ) {
			$tag  = self::pick( $tags );
			$cost = strlen( $tag ) * 2 + 5;
			if ( $len + $cost + 8 > $max ) {
				break;
			}
			$openers[] = "<{$tag}>";
			$closers[] = "</{$tag}>";
			$len      += $cost;
		}

		if ( count( $openers ) < 2 ) {
			return '';
		}

		$out  = implode( '', $openers ) . self::pick( self::ASCII_WORDS );
		$out .= implode( '', array_reverse( $closers ) );
		if ( strlen( $out ) < $max ) {
			$out .= "\n";
		}

		return $out;
	}

	/**
	 * `script-style`: SCRIPT and STYLE bodies with false closers, plus RCDATA.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_script_style( int $max ): string {
		static $js_lines  = array(
			'if ( a < b && c > d ) { x = "</div>"; }',
			'var html = "<p>" + text + "</p>";',
			'for ( var i = 0; i < n; i++ ) { out += "<li>" + items[ i ] + "</li>"; }',
			'// <!-- not a comment opener in a script -->',
			'const tpl = `<section class="${cls}">${body}</section>`;',
			'if ( x </ y ) { /* malformed but harmless */ }',
			'el.innerHTML = "<scr" + "ipt>alert(1)</scr" + "ipt>";',
			'return a <= b ? "<" : "</";',
			'while ( i-- > 0 && j < k ) { s += "</"; }',
			'document.write( "<\/script>" );',
			'const re = /<\/?[a-z][^>]*>/gi;',
			'let data = { "key": "value", "list": [ 1, 2, 3 ], "nested": { "a": "<b>" } };',
		);
		static $css_lines = array(
			'a > b { color: red; }',
			'/* comment with </ inside */',
			'.x::before { content: "</div>"; }',
			'.y[data-x="</ not a closer"] { display: none; }',
			'ul li:nth-child(2n+1) > a:hover { text-decoration: underline; }',
			'@media (max-width: 782px) { .wp-block-group { padding: 0 1rem; } }',
			'.z { background: url("data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\'/>"); }',
			'.w::after { content: "<\\/styl"; }',
			':root { --gap: 1.5rem; --accent: #3858e9; }',
		);
		static $rcdata    = array(
			'<b>not a tag</b> &amp; &lt;i&gt; still text <!-- nor a comment -->',
			'Plain text with < and </ and </text and &copy; inside',
			'Line one\nLine two <br> line three </br>',
		);

		$out = '';
		while ( true ) {
			$roll  = mt_rand( 1, 100 );
			$limit = $max - strlen( $out );

			if ( $roll <= 45 ) {
				$body      = '';
				$inner_max = $limit - strlen( "<script>\n</script>\n" );
				while ( self::append( $body, self::pick( $js_lines ) . "\n", $inner_max ) ) {
					if ( strlen( $body ) > mt_rand( 400, 1500 ) ) {
						break;
					}
				}
				if ( '' === $body ) {
					break;
				}
				$piece = "<script>\n{$body}</script>\n";
			} elseif ( $roll <= 80 ) {
				$body      = '';
				$inner_max = $limit - strlen( "<style>\n</style>\n" );
				while ( self::append( $body, self::pick( $css_lines ) . "\n", $inner_max ) ) {
					if ( strlen( $body ) > mt_rand( 300, 1200 ) ) {
						break;
					}
				}
				if ( '' === $body ) {
					break;
				}
				$piece = "<style>\n{$body}</style>\n";
			} elseif ( $roll <= 92 ) {
				$body      = '';
				$inner_max = $limit - strlen( "<textarea>\n</textarea>\n" );
				while ( self::append( $body, self::pick( $rcdata ) . "\n", $inner_max ) ) {
					if ( strlen( $body ) > mt_rand( 100, 500 ) ) {
						break;
					}
				}
				if ( '' === $body ) {
					break;
				}
				$piece = "<textarea>\n{$body}</textarea>\n";
			} else {
				$piece = '<title>' . self::pick( $rcdata ) . "</title>\n";
			}

			if ( ! self::append( $out, $piece, $max ) ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * `whitespace-runs`: long whitespace runs between tags and inside text.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_whitespace_runs( int $max ): string {
		static $tags = array( 'div', 'p', 'span', 'section', 'pre', 'li', 'em' );

		$out    = '';
		$misses = 0;
		while ( true ) {
			$tag   = self::pick( $tags );
			$piece = self::whitespace_run() . "<{$tag}>" . self::whitespace_run();
			$words = mt_rand( 0, 2 );
			for ( $i = 0; $i < $words; $i++ ) {
				$piece .= self::pick( self::ASCII_WORDS ) . self::whitespace_run();
			}
			$piece .= "</{$tag}>";

			if ( ! self::append( $out, $piece, $max ) ) {
				// Runs are up to 400 bytes each; try a few more before giving up.
				if ( ++$misses < 8 ) {
					continue;
				}
				break;
			}
		}

		return $out;
	}

	/**
	 * Builds one run of whitespace, 1 to 400 characters.
	 *
	 * @return string Whitespace.
	 */
	private static function whitespace_run(): string {
		static $chars = array( ' ', ' ', ' ', "\t", "\n", "\n", "\r\n", "\f" );

		$length = self::chance( 25 ) ? mt_rand( 1, 20 ) : mt_rand( 20, 400 );
		$out    = '';
		$char   = self::pick( $chars );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( self::chance( 10 ) ) {
				$char = self::pick( $chars );
			}
			$out .= $char;
		}

		return $out;
	}

	/**
	 * `foreign-content`: SVG and MathML subtrees.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_foreign_content( int $max ): string {
		$out = '';
		while ( true ) {
			$limit = $max - strlen( $out );
			$piece = self::chance( 65 ) ? self::svg_block( $limit ) : self::math_block( $limit );
			if ( '' === $piece ) {
				break;
			}
			$out .= $piece;
		}

		return $out;
	}

	/**
	 * Builds one inline SVG within `$max` bytes.
	 *
	 * @param int $max Byte limit.
	 * @return string SVG markup, or empty when it does not fit.
	 */
	private static function svg_block( int $max ): string {
		$open = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">';
		$end  = "</svg>\n";
		if ( strlen( $open ) + strlen( $end ) > $max ) {
			return '';
		}

		$inner = '';
		$limit = $max - strlen( $open ) - strlen( $end );
		$count = mt_rand( 3, 12 );
		for ( $i = 0; $i < $count; $i++ ) {
			$roll = mt_rand( 1, 100 );
			if ( $roll <= 35 ) {
				$d = 'M' . mt_rand( 0, 24 ) . ' ' . mt_rand( 0, 24 );
				$n = mt_rand( 2, 10 );
				for ( $j = 0; $j < $n; $j++ ) {
					$d .= self::pick( array( 'L', 'C', 'Q', 'A', 'H', 'V', 'Z' ) ) . mt_rand( 0, 24 ) . '.' . mt_rand( 0, 9 ) . ' ' . mt_rand( 0, 24 ) . '.' . mt_rand( 0, 9 );
				}
				$piece = "<path d=\"{$d}\" fill=\"currentColor\" fill-rule=\"evenodd\" clip-rule=\"evenodd\"/>";
			} elseif ( $roll <= 50 ) {
				$piece = '<circle cx="' . mt_rand( 0, 24 ) . '" cy="' . mt_rand( 0, 24 ) . '" r="' . mt_rand( 1, 12 ) . '" stroke-width="1.5" stroke-linecap="round" />';
			} elseif ( $roll <= 60 ) {
				$piece = '<use xlink:href="#icon-' . self::pick( self::ASCII_WORDS ) . '" xml:space="preserve" />';
			} elseif ( $roll <= 70 ) {
				$piece = '<g transform="translate(' . mt_rand( 0, 12 ) . ',' . mt_rand( 0, 12 ) . ')"><rect x="1" y="1" width="10" height="10" rx="2"/><line x1="0" y1="0" x2="24" y2="24"/></g>';
			} elseif ( $roll <= 78 ) {
				$piece = '<linearGradient id="g' . mt_rand( 1, 999 ) . '" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#000" stop-opacity="0.5"/></linearGradient>';
			} elseif ( $roll <= 86 ) {
				$piece = '<text x="2" y="12" font-size="8" text-anchor="middle"><tspan dy="1">' . self::pick( self::ASCII_WORDS ) . '</tspan></text>';
			} elseif ( $roll <= 94 ) {
				$piece = '<foreignObject x="0" y="0" width="24" height="24"><div xmlns="http://www.w3.org/1999/xhtml" class="label"><p>' . self::pick( self::ASCII_WORDS ) . ' <b>' . self::pick( self::ASCII_WORDS ) . '</b></p><ul><li>' . self::pick( self::ASCII_WORDS ) . '</li></ul></div></foreignObject>';
			} else {
				$piece = '<title>' . self::pick( self::ASCII_WORDS ) . ' icon</title><desc>' . self::pick( self::ASCII_WORDS ) . ' ' . self::pick( self::ASCII_WORDS ) . '</desc>';
			}

			if ( ! self::append( $inner, $piece, $limit ) ) {
				break;
			}
		}

		return $open . $inner . $end;
	}

	/**
	 * Builds one inline MathML block within `$max` bytes.
	 *
	 * @param int $max Byte limit.
	 * @return string MathML markup, or empty when it does not fit.
	 */
	private static function math_block( int $max ): string {
		$open = '<math xmlns="http://www.w3.org/1998/Math/MathML" display="block">';
		$end  = "</math>\n";
		if ( strlen( $open ) + strlen( $end ) > $max ) {
			return '';
		}

		$inner = '';
		$limit = $max - strlen( $open ) - strlen( $end );
		$count = mt_rand( 2, 8 );
		for ( $i = 0; $i < $count; $i++ ) {
			$roll = mt_rand( 1, 100 );
			$a    = chr( mt_rand( 97, 122 ) );
			$b    = chr( mt_rand( 97, 122 ) );
			if ( $roll <= 30 ) {
				$piece = "<mrow><mi>{$a}</mi><mo>=</mo><mn>" . mt_rand( 1, 99 ) . '</mn></mrow>';
			} elseif ( $roll <= 55 ) {
				$piece = "<mfrac><mrow><mi>{$a}</mi><mo>+</mo><mi>{$b}</mi></mrow><mn>" . mt_rand( 2, 9 ) . '</mn></mfrac>';
			} elseif ( $roll <= 70 ) {
				$piece = "<msup><mi>{$a}</mi><mn>" . mt_rand( 2, 5 ) . "</mn></msup><mo>&#x2212;</mo><msqrt><mi>{$b}</mi></msqrt>";
			} elseif ( $roll <= 82 ) {
				$piece = "<mi mathvariant=\"bold\" definitionURL=\"#def-{$a}\">{$a}</mi><mspace width=\"0.5em\"/><mo stretchy=\"false\">(</mo><mi>{$b}</mi><mo>)</mo>";
			} elseif ( $roll <= 92 ) {
				$piece = '<semantics><mi>' . $a . '</mi><annotation-xml encoding="text/html"><div class="fallback"><p>' . self::pick( self::ASCII_WORDS ) . ' <i>' . self::pick( self::ASCII_WORDS ) . '</i></p></div></annotation-xml></semantics>';
			} else {
				$piece = '<mtext>' . self::pick( self::ASCII_WORDS ) . ' ' . self::pick( self::ASCII_WORDS ) . '</mtext>';
			}

			if ( ! self::append( $inner, $piece, $limit ) ) {
				break;
			}
		}

		return $open . $inner . $end;
	}

	/**
	 * `formatting-adoption`: misnested formatting elements.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_formatting_adoption( int $max ): string {
		$out = '';
		while ( true ) {
			$w = array();
			for ( $i = 0; $i < 8; $i++ ) {
				$w[] = self::pick( self::ASCII_WORDS );
			}
			$roll = mt_rand( 1, 100 );
			if ( $roll <= 18 ) {
				// B and I crossing: the I closer follows the B closer directly.
				$piece = "<p>{$w[0]} <b>{$w[1]} <i>{$w[2]} {$w[3]}</b></i> {$w[4]} {$w[5]}</p>\n";
			} elseif ( $roll <= 32 ) {
				// A inside A: the second A start tag runs the adoption agency on the first.
				$piece = "<p><a href=\"#{$w[0]}\">{$w[1]} <a href=\"#{$w[2]}\">{$w[3]}</a> {$w[4]} {$w[5]}</p>\n";
			} elseif ( $roll <= 46 ) {
				// P closed by DIV, then a stray P closer that inserts an empty P.
				$piece = "<p>{$w[0]} {$w[1]} <div>{$w[2]} {$w[3]}</div> {$w[4]}</p>\n";
			} elseif ( $roll <= 56 ) {
				// A non-formatting element popped by the B closer, then its stray closer is ignored.
				$piece = "<p><b>{$w[0]} <span>{$w[1]}</b> {$w[2]}</span> {$w[3]}</p>\n";
			} elseif ( $roll <= 66 ) {
				$piece = "<p><em><strong>{$w[0]} <code>{$w[1]}</em></strong></code> {$w[2]} {$w[3]}</p>\n";
			} elseif ( $roll <= 74 ) {
				// Three nested B elements; the P closer pops the outermost, whose own closer follows.
				$piece = "<p><b>{$w[0]} <b>{$w[1]} <b>{$w[2]}</b></b> {$w[3]}</p></b> {$w[4]}\n";
			} elseif ( $roll <= 84 ) {
				$piece = "<p><s>{$w[0]} <u>{$w[1]} <small>{$w[2]}</s></u></small> {$w[3]} {$w[4]}</p>\n";
			} elseif ( $roll <= 90 ) {
				// A second NOBR runs the adoption agency on the first; FONT then pops the rest.
				$piece = "<div><font color=\"red\" face=\"serif\">{$w[0]} <nobr>{$w[1]} <nobr>{$w[2]}</font></nobr> {$w[3]}</div>\n";
			} elseif ( $roll <= 95 ) {
				// P closer pops the open B; the B closer then removes it from the formatting list.
				$piece = "<p><b>{$w[0]} {$w[1]}</p></b> {$w[2]}\n";
			} else {
				// P inside P closes the first.
				$piece = "<p>{$w[0]} {$w[1]}<p><i>{$w[2]} <b>{$w[3]}</i></b> {$w[4]}</p>\n";
			}

			if ( ! self::append( $out, $piece, $max ) ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * `tables`: tables with and without TBODY, implied rows and cells, nested tables.
	 *
	 * Stray non-whitespace text and stray tags directly inside a table would
	 * be foster-parented, which the HTML Processor does not support; this shape
	 * stays within what it parses so the document never bails.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_tables( int $max ): string {
		$open = self::chance( 50 ) ? "<table>\n" : '<table class="wp-block-table is-style-stripes" border=1>' . "\n";
		$end  = "</table>\n";
		if ( strlen( $open ) + strlen( $end ) > $max ) {
			return '';
		}

		$inner = '';
		$limit = $max - strlen( $open ) - strlen( $end );

		if ( self::chance( 30 ) ) {
			self::append( $inner, '<caption>' . self::pick( self::ASCII_WORDS ) . ' <b>' . self::pick( self::ASCII_WORDS ) . "</b></caption>\n", $limit );
		}
		if ( self::chance( 30 ) ) {
			self::append( $inner, '<colgroup><col span="2"><col style="width: 40%"/></colgroup>' . "\n", $limit );
		}
		if ( self::chance( 40 ) ) {
			self::append( $inner, '<thead><tr><th scope="col">' . self::pick( self::ASCII_WORDS ) . '<th>' . self::pick( self::ASCII_WORDS ) . "</tr></thead>\n", $limit );
		}

		$style = mt_rand( 1, 4 );
		if ( 1 === $style ) {
			self::append( $inner, "<tbody>\n", $limit );
		}

		$rows = mt_rand( 2, 20 );
		for ( $r = 0; $r < $rows; $r++ ) {
			$cells = mt_rand( 1, 6 );
			if ( 4 === $style ) {
				// Cells directly in the table imply both TBODY and TR.
				$row = '';
			} elseif ( self::chance( 20 ) ) {
				$row = '<tr class="row-' . $r . '">';
			} else {
				$row = '<tr>';
			}
			for ( $c = 0; $c < $cells; $c++ ) {
				$tag  = self::chance( 15 ) ? 'th' : 'td';
				$cell = "<{$tag}>" . self::pick( self::ASCII_WORDS );
				$sub  = mt_rand( 1, 100 );
				if ( $sub <= 10 ) {
					$cell .= ' <b>' . self::pick( self::ASCII_WORDS ) . '</b> <a href="#">' . self::pick( self::ASCII_WORDS ) . '</a>';
				} elseif ( $sub <= 16 ) {
					$cell .= '<table><tr><td>' . self::pick( self::ASCII_WORDS ) . '<td>' . self::pick( self::ASCII_WORDS ) . '</table>';
				} elseif ( $sub <= 22 ) {
					$cell .= '<p>' . self::pick( self::ASCII_WORDS ) . ' ' . self::pick( self::ASCII_WORDS ) . '</p>';
				}
				// Closers are optional for TD and TH; omit some.
				if ( 4 !== $style && self::chance( 50 ) ) {
					$cell .= "</{$tag}>";
				}
				$row .= $cell;
			}
			if ( 4 !== $style && self::chance( 60 ) ) {
				$row .= '</tr>';
			}
			$row .= "\n";

			// Tokens the "in table" mode handles between rows: whitespace, comments, hidden inputs.
			$between = mt_rand( 1, 100 );
			if ( $between <= 15 ) {
				$row .= "<!-- row {$r} -->\n";
			} elseif ( $between <= 22 ) {
				$row .= '<input type="hidden" name="row" value="' . $r . "\">\n";
			} elseif ( $between <= 30 ) {
				$row .= "\t\t\n";
			}

			if ( ! self::append( $inner, $row, $limit ) ) {
				break;
			}
		}

		if ( 1 === $style ) {
			self::append( $inner, "</tbody>\n", $limit );
		}
		if ( self::chance( 20 ) ) {
			self::append( $inner, '<tfoot><tr><td colspan=3>' . self::pick( self::ASCII_WORDS ) . "</tfoot>\n", $limit );
		}

		if ( '' === $inner ) {
			return '';
		}

		return $open . $inner . $end;
	}

	/**
	 * `wordpress-post`: a block-themed page.
	 *
	 * @param int $max Byte limit.
	 * @return string Chunk.
	 */
	private static function chunk_wordpress_post( int $max ): string {
		$out  = '';
		$roll = mt_rand( 1, 100 );

		if ( $roll <= 30 ) {
			$frame = strlen( "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->\n" );
			$text  = self::prose( min( $max - $frame, mt_rand( 200, 900 ) ), self::ASCII_WORDS, 6, array( '&#8217;s', '&nbsp;', '&amp;', '&#8220;', '&#8221;', '&hellip;' ) );
			if ( '' === $text ) {
				return '';
			}
			if ( self::chance( 30 ) ) {
				$text = preg_replace( '/^(\S+ \S+)/', '<strong>$1</strong>', $text, 1 );
			}
			if ( self::chance( 30 ) ) {
				$text = preg_replace( '/(\S+)\.$/', '<a href="https://example.com/' . self::pick( self::ASCII_WORDS ) . '/">$1</a>.', $text, 1 );
			}
			$out = "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n";
		} elseif ( $roll <= 40 ) {
			$level = mt_rand( 2, 4 );
			$words = array();
			$n     = mt_rand( 2, 8 );
			for ( $i = 0; $i < $n; $i++ ) {
				$words[] = self::pick( self::ASCII_WORDS );
			}
			$text = ucfirst( implode( ' ', $words ) );
			$id   = strtolower( str_replace( ' ', '-', $text ) );
			$out  = ( 2 === $level ? '<!-- wp:heading -->' : "<!-- wp:heading {\"level\":{$level}} -->" ) . "\n<h{$level} class=\"wp-block-heading\" id=\"{$id}\">{$text}</h{$level}>\n<!-- /wp:heading -->\n";
		} elseif ( $roll <= 50 ) {
			$id   = mt_rand( 100, 99999 );
			$slug = self::pick( self::ASCII_WORDS ) . '-' . self::pick( self::ASCII_WORDS );
			$w    = self::pick( array( 1024, 1536, 2048 ) );
			$h    = intdiv( $w * mt_rand( 50, 80 ), 100 );
			$base = 'https://example.com/wp-content/uploads/2026/' . str_pad( (string) mt_rand( 1, 12 ), 2, '0', STR_PAD_LEFT ) . "/{$slug}";
			$out  = "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n" .
				"<figure class=\"wp-block-image size-large\"><img loading=\"lazy\" decoding=\"async\" width=\"{$w}\" height=\"{$h}\" src=\"{$base}-{$w}x{$h}.jpg\" alt=\"" . self::pick( self::ASCII_WORDS ) . ' ' . self::pick( self::ASCII_WORDS ) . "\" class=\"wp-image-{$id}\" srcset=\"{$base}-{$w}x{$h}.jpg {$w}w, {$base}-300x" . intdiv( 300 * $h, $w ) . ".jpg 300w, {$base}-768x" . intdiv( 768 * $h, $w ) . ".jpg 768w, {$base}.jpg " . ( $w * 2 ) . "w\" sizes=\"(max-width: {$w}px) 100vw, {$w}px\" /><figcaption class=\"wp-element-caption\">" . ucfirst( self::pick( self::ASCII_WORDS ) ) . ' ' . self::pick( self::ASCII_WORDS ) . ".</figcaption></figure>\n" .
				"<!-- /wp:image -->\n";
		} elseif ( $roll <= 60 ) {
			$ordered = self::chance( 30 );
			$tag     = $ordered ? 'ol' : 'ul';
			$out     = ( $ordered ? "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">" : "<!-- wp:list -->\n<ul class=\"wp-block-list\">" ) . "\n";
			$items   = mt_rand( 2, 8 );
			for ( $i = 0; $i < $items; $i++ ) {
				$out .= "<!-- wp:list-item -->\n<li>" . ucfirst( self::pick( self::ASCII_WORDS ) ) . ' ' . self::pick( self::ASCII_WORDS ) . ' ' . self::pick( self::ASCII_WORDS ) . "</li>\n<!-- /wp:list-item -->\n";
			}
			$out .= "</{$tag}>\n<!-- /wp:list -->\n";
		} elseif ( $roll <= 78 ) {
			// Nested group DIVs with class and style, holding paragraphs.
			$depth = mt_rand( 1, 4 );
			$open  = '';
			$close = '';
			for ( $i = 0; $i < $depth; $i++ ) {
				$layout = self::pick( array( 'constrained', 'flex', 'grid', 'default' ) );
				$open  .= "<!-- wp:group {\"layout\":{\"type\":\"{$layout}\"}," . ( self::chance( 50 ) ? '"style":{"spacing":{"padding":{"top":"var:preset|spacing|40"}}},' : '' ) . "\"backgroundColor\":\"base\"} -->\n" .
					"<div class=\"wp-block-group has-base-background-color has-background is-layout-{$layout} wp-block-group-is-layout-{$layout}\" style=\"padding-top:var(--wp--preset--spacing--40);margin-block-start:0\">\n";
				$close  = "</div>\n<!-- /wp:group -->\n" . $close;
			}
			$text = self::prose( mt_rand( 80, 300 ), self::ASCII_WORDS );
			$out  = $open . "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">{$text}</p>\n<!-- /wp:paragraph -->\n" . $close;
		} elseif ( $roll <= 88 ) {
			$services = array( 'wordpress', 'mastodon', 'github', 'bluesky', 'rss' );
			$out      = "<!-- wp:social-links {\"iconColor\":\"contrast\",\"className\":\"is-style-logos-only\"} -->\n<ul class=\"wp-block-social-links has-icon-color is-style-logos-only\">\n";
			$n        = mt_rand( 2, 5 );
			for ( $i = 0; $i < $n; $i++ ) {
				$s    = self::pick( $services );
				$out .= "<!-- wp:social-link {\"url\":\"https://{$s}.example\",\"service\":\"{$s}\"} /-->\n" .
					"<li class=\"wp-social-link wp-social-link-{$s} wp-block-social-link\"><a rel=\"noopener nofollow\" target=\"_blank\" href=\"https://{$s}.example\" class=\"wp-block-social-link-anchor\"><svg width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" version=\"1.1\" xmlns=\"http://www.w3.org/2000/svg\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm" . mt_rand( 0, 9 ) . '.5 14.5l-' . mt_rand( 1, 5 ) . ' 2 1-5 4-4z"></path></svg><span class="wp-block-social-link-label screen-reader-text">' . ucfirst( $s ) . "</span></a></li>\n";
			}
			$out .= "</ul>\n<!-- /wp:social-links -->\n";
		} elseif ( $roll <= 94 ) {
			$id   = mt_rand( 1, 999 );
			$keys = array();
			$n    = mt_rand( 3, 12 );
			for ( $i = 0; $i < $n; $i++ ) {
				$keys[] = '"' . self::pick( self::ASCII_WORDS ) . mt_rand( 1, 99 ) . '":' . self::pick( array( 'true', 'false', 'null', (string) mt_rand( 0, 9999 ), '"' . self::pick( self::ASCII_WORDS ) . '"', '{"nested":"<\/div>"}', '["a","b"]' ) );
			}
			$out = "<script type=\"application/json\" id=\"wp-script-module-data-@wordpress/interactivity-{$id}\">\n{\"config\":{" . implode( ',', $keys ) . "},\"state\":{\"core/image\":{\"lightbox\":{\"enabled\":true}}}}\n</script>\n";
		} else {
			$out = "<!-- wp:separator {\"className\":\"is-style-wide\"} -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity is-style-wide\"/>\n<!-- /wp:separator -->\n" .
				'<!-- wp:spacer {"height":"' . mt_rand( 10, 100 ) . "px\"} -->\n<div style=\"height:" . mt_rand( 10, 100 ) . "px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->\n";
		}

		if ( strlen( $out ) > $max ) {
			// Fall back to the smallest block that fits, else stop.
			$small = "<!-- wp:paragraph -->\n<p>" . self::pick( self::ASCII_WORDS ) . "</p>\n<!-- /wp:paragraph -->\n";
			return strlen( $small ) <= $max ? $small : '';
		}

		return $out;
	}
}
