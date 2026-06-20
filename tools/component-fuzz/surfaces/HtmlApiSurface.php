<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WordPress HTML API tag and tree processors.
 */
final class HtmlApiSurface {
	public const NAME = 'html-api';

	private const CASES = 12;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'html-api.bootstrap-apis-available',
					'Required WordPress HTML API classes are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		try {
			return array(
				self::check_tag_processor_updates( $ctx ),
				self::check_processor_normalization( $ctx ),
				self::check_processor_tokens_and_text( $ctx ),
			);
		} catch ( \Throwable $e ) {
			return array(
				$ctx->fail(
					'html-api.surface-no-throw',
					array(
						'throwable' => self::describe_throwable( $e ),
					)
				),
			);
		}
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach ( array( 'WP_HTML_Processor', 'WP_HTML_Tag_Processor' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_tag_processor_updates( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		foreach ( self::html_cases( $ctx->fork( 'tag-updates' ) ) as $index => $case ) {
			$processor = new \WP_HTML_Tag_Processor( $case['html'] );
			$found     = $processor->next_tag( array( 'tag_name' => 'A' ) );
			$href      = $found ? $processor->get_attribute( 'href' ) : null;
			$before    = $found ? $processor->get_attribute_names_with_prefix( 'data-' ) : null;
			if ( $found ) {
				$processor->set_attribute( 'data-cfz-token', $case['token'] );
				$processor->set_attribute( 'title', $case['title'] );
				$processor->add_class( $case['addedClass'] );
				$processor->remove_class( 'old-class' );
			}
			$updated = $processor->get_updated_html();

			$round_trip       = new \WP_HTML_Tag_Processor( $updated );
			$round_trip_found = $round_trip->next_tag( array( 'tag_name' => 'A' ) );
			$after_data       = $round_trip_found ? $round_trip->get_attribute_names_with_prefix( 'data-' ) : null;
			$class            = $round_trip_found ? (string) $round_trip->get_attribute( 'class' ) : '';

			self::collect_failure(
				$failures,
				$found
					&& $round_trip_found
					&& $case['href'] === $href
					&& is_array( $before )
					&& in_array( 'data-index', $before, true )
					&& $case['token'] === $round_trip->get_attribute( 'data-cfz-token' )
					&& $case['title'] === $round_trip->get_attribute( 'title' )
					&& is_array( $after_data )
					&& in_array( 'data-index', $after_data, true )
					&& in_array( 'data-cfz-token', $after_data, true )
					&& self::class_list_contains( $class, 'start-class' )
					&& self::class_list_contains( $class, $case['addedClass'] )
					&& ! self::class_list_contains( $class, 'old-class' )
					&& ! str_contains( $updated, '<script' ),
				"WP_HTML_Tag_Processor updates attributes and classes case {$index}",
				array(
					'case'      => $case,
					'updated'   => $updated,
					'class'     => $class,
					'before'    => $before,
					'afterData' => $after_data,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.tag-processor.update-round-trip',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_processor_normalization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		foreach ( self::html_cases( $ctx->fork( 'normalize' ) ) as $index => $case ) {
			$normalized   = \WP_HTML_Processor::normalize( $case['html'] );
			$renormalized = is_string( $normalized ) ? \WP_HTML_Processor::normalize( $normalized ) : null;
			$serialized   = null;
			if ( is_string( $normalized ) ) {
				$processor  = \WP_HTML_Processor::create_fragment( $normalized );
				$serialized = $processor instanceof \WP_HTML_Processor ? $processor->serialize() : null;
			}

			self::collect_failure(
				$failures,
				is_string( $normalized )
					&& $normalized === $renormalized
					&& $normalized === $serialized
					&& ! str_contains( $normalized, "\0" )
					&& ! str_contains( strtolower( $normalized ), '<script' )
					&& substr_count( strtolower( $normalized ), '<a ' ) <= 1,
				"WP_HTML_Processor normalization is idempotent case {$index}",
				array(
					'case'         => $case,
					'normalized'   => $normalized,
					'renormalized' => $renormalized,
					'serialized'   => $serialized,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.processor.normalization-idempotent',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_processor_tokens_and_text( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		foreach ( self::html_cases( $ctx->fork( 'tokens' ) ) as $index => $case ) {
			$img_processor = \WP_HTML_Processor::create_fragment( $case['html'] );
			$img_found     = $img_processor instanceof \WP_HTML_Processor ? $img_processor->next_tag( 'IMG' ) : false;
			$breadcrumbs   = $img_found ? $img_processor->get_breadcrumbs() : array();
			$img_token     = $img_found ? $img_processor->get_token_name() : null;
			$img_type      = $img_found ? $img_processor->get_token_type() : null;

			$token_processor = \WP_HTML_Processor::create_fragment( $case['html'] );
			$tag_count       = 0;
			$text_count      = 0;
			$comment_count   = 0;
			$max_depth       = 0;
			$token_names     = array();
			if ( $token_processor instanceof \WP_HTML_Processor ) {
				while ( $token_processor->next_token() ) {
					$type = $token_processor->get_token_type();
					$name = $token_processor->get_token_name();
					if ( '#tag' === $type ) {
						++$tag_count;
					} elseif ( '#text' === $type ) {
						++$text_count;
					} elseif ( '#comment' === $type ) {
						++$comment_count;
					}
					$max_depth     = max( $max_depth, $token_processor->get_current_depth() );
					$token_names[] = $name;
					if ( count( $token_names ) > 80 ) {
						break;
					}
				}
			}

			$text_processor = \WP_HTML_Processor::create_fragment( $case['html'] );
			$text_updated   = false;
			if ( $text_processor instanceof \WP_HTML_Processor ) {
				while ( $text_processor->next_token() ) {
					if ( '#text' === $text_processor->get_token_type() && '' !== trim( $text_processor->get_modifiable_text() ) ) {
						$text_updated = $text_processor->set_modifiable_text( $case['replacementText'] );
						break;
					}
				}
			}
			$text_html = $text_processor instanceof \WP_HTML_Processor ? $text_processor->get_updated_html() : '';

			self::collect_failure(
				$failures,
				$img_found
					&& 'IMG' === $img_token
					&& '#tag' === $img_type
					&& array_slice( $breadcrumbs, 0, 2 ) === array( 'HTML', 'BODY' )
					&& 'IMG' === end( $breadcrumbs )
					&& $tag_count >= 5
					&& $text_count >= 1
					&& $comment_count >= 1
					&& $max_depth >= 3
					&& in_array( 'A', $token_names, true )
					&& true === $text_updated
					&& str_contains( $text_html, '&lt;' )
					&& str_contains( $text_html, '&amp;' ),
				"WP_HTML_Processor token walk, breadcrumbs, and text replacement case {$index}",
				array(
					'case'         => $case,
					'breadcrumbs'  => $breadcrumbs,
					'tagCount'     => $tag_count,
					'textCount'    => $text_count,
					'commentCount' => $comment_count,
					'maxDepth'     => $max_depth,
					'tokenNames'   => array_slice( $token_names, 0, 20 ),
					'textUpdated'  => $text_updated,
					'textHtml'     => $text_html,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.processor.tokens-breadcrumbs-text',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function html_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case  = $ctx->fork( 'html-' . $i );
			$token = self::safe_token( $case, 'token' );
			$href  = '#frag-' . $token;
			$title = 'Title <' . $token . '> & "quoted"';
			$text  = self::safe_text( $case, 12, 32 );
			$tail  = self::safe_text( $case, 8, 24 );

			$cases[] = array(
				'token'           => $token,
				'addedClass'      => 'added-' . self::safe_token( $case, 'class' ),
				'href'            => $href,
				'title'           => $title,
				'replacementText' => 'replacement <' . $token . '> & value',
				'html'            => self::case_html( $case, $i, $href, $text, $tail ),
			);
		}

		return $cases;
	}

	private static function case_html( \ComponentFuzz\FuzzContext $ctx, int $index, string $href, string $text, string $tail ): string {
		$wrapper = $ctx->choice( array( 'article', 'section', 'div', 'main' ) );
		$inline  = $ctx->choice( array( 'strong', 'em', 'span', 'b' ) );
		$list    = $ctx->bool()
			? '<ul><li>' . esc_html( $text ) . '<li>' . esc_html( $tail ) . '</ul>'
			: '<ol><li>' . esc_html( $text ) . '</li><li>' . esc_html( $tail ) . '</li></ol>';

		return '<' . $wrapper . ' data-wrapper="' . $index . '">'
			. '<p class="lead">Lead ' . esc_html( $text ) . ' <' . $inline . '>inline</' . $inline . '></p>'
			. '<a class="start-class old-class" data-index="' . $index . '" href="' . esc_attr( $href ) . '" enabled href="/duplicate">Link ' . esc_html( $tail ) . '</a>'
			. '<figure><img src="image-' . $index . '.jpg" alt="' . esc_attr( $tail ) . '"><figcaption>' . esc_html( $text ) . '</figcaption></figure>'
			. $list
			. '<!-- cfz comment ' . $index . ' -->'
			. '</' . $wrapper . '>';
	}

	private static function safe_token( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$token = strtolower( $label . '-' . $ctx->identifier( 3, 10 ) . '-' . dechex( $ctx->seed() & 0xffff ) );
		$token = preg_replace( '/[^a-z0-9-]+/', '-', $token );
		$token = trim( (string) $token, '-' );
		return '' === $token ? 'token-' . dechex( $ctx->seed() & 0xffff ) : substr( $token, 0, 40 );
	}

	private static function safe_text( \ComponentFuzz\FuzzContext $ctx, int $min, int $max ): string {
		$text = preg_replace( '/[^A-Za-z0-9 ._-]+/', ' ', $ctx->text( $min, $max ) );
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? 'component fuzz text' : substr( $text, 0, $max );
	}

	private static function class_list_contains( string $class, string $needle ): bool {
		return in_array( $needle, preg_split( '/\s+/', trim( $class ), -1, PREG_SPLIT_NO_EMPTY ), true );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
