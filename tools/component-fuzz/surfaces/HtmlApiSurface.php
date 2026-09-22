<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WordPress HTML API tag and tree processors.
 */
final class HtmlApiSurface {
	public const NAME = 'html-api';

	private const CASES      = 18;
	private const MAX_TOKENS = 1000;

	private const MODE_FRAGMENT      = 'fragment-body';
	private const MODE_FULL_DOCUMENT = 'full-document';

	private const PROFILES = array(
		'balanced',
		'tables',
		'template',
		'select',
		'foreign-content',
		'rawtext-rcdata',
		'attributes-entities',
		'comments-doctype-bogus',
		'formatting-adoption',
		'incomplete-malformed',
	);

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
				self::check_tag_processor_structural_invariants( $ctx ),
				self::check_tag_processor_bookmark_seek( $ctx ),
				self::check_processor_normalization( $ctx ),
				self::check_processor_semantic_oracles( $ctx ),
				self::check_processor_tokens_and_text( $ctx ),
				self::check_processor_boundaries( $ctx ),
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
		$cases    = self::attribute_update_cases( $ctx->fork( 'tag-updates' ) );

		foreach ( $cases as $index => $case ) {
			$processor = new \WP_HTML_Tag_Processor( $case['html'] );
			$found     = $processor->next_tag( array( 'tag_name' => 'A' ) );
			$href      = $found ? $processor->get_attribute( 'href' ) : null;
			$enabled   = $found ? $processor->get_attribute( 'enabled' ) : null;
			$before    = $found ? $processor->get_attribute_names_with_prefix( 'data-' ) : null;

			$set_token  = false;
			$set_title  = false;
			$set_unsafe = false;
			$removed    = false;
			$added      = false;
			if ( $found ) {
				$set_token  = $processor->set_attribute( 'data-cfz-token', $case['token'] );
				$set_title  = $processor->set_attribute( 'title', $case['title'] );
				$set_unsafe = $processor->set_attribute( 'data-cfz-unsafe', $case['unsafeAttribute'] );
				$removed    = $processor->remove_attribute( 'data-remove' );
				$added      = $processor->add_class( $case['addedClass'] );
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
					&& $set_token
					&& $set_title
					&& $set_unsafe
					&& $removed
					&& $added
					&& $case['href'] === $href
					&& true === $enabled
					&& is_array( $before )
					&& in_array( 'data-index', $before, true )
					&& $case['token'] === $round_trip->get_attribute( 'data-cfz-token' )
					&& $case['title'] === $round_trip->get_attribute( 'title' )
					&& $case['unsafeAttribute'] === $round_trip->get_attribute( 'data-cfz-unsafe' )
					&& null === $round_trip->get_attribute( 'data-remove' )
					&& is_array( $after_data )
					&& in_array( 'data-index', $after_data, true )
					&& in_array( 'data-cfz-token', $after_data, true )
					&& in_array( 'data-cfz-unsafe', $after_data, true )
					&& self::class_list_contains( $class, 'start-class' )
					&& self::class_list_contains( $class, $case['addedClass'] )
					&& ! self::class_list_contains( $class, 'old-class' )
					&& ! str_contains( strtolower( $updated ), '<script' )
					&& str_contains( $updated, '&lt;script' )
					&& str_contains( $updated, '&quot;' ),
				"WP_HTML_Tag_Processor escapes and round-trips attribute mutations case {$index}",
				array(
					'case'           => $case,
					'updated'        => $updated,
					'class'          => $class,
					'before'         => $before,
					'afterData'      => $after_data,
					'mutationReturn' => array(
						'setToken'  => $set_token,
						'setTitle'  => $set_title,
						'setUnsafe' => $set_unsafe,
						'removed'   => $removed,
						'added'     => $added,
					),
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.tag-processor.attribute-mutation-escaping',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_tag_processor_structural_invariants( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::rich_html_cases( $ctx->fork( 'tag-structural' ) );

		foreach ( $cases as $index => $case ) {
			$walk = self::walk_tag_processor( $case['html'] );

			self::collect_failure(
				$failures,
				$walk['ok']
					&& $walk['unchanged']
					&& $walk['tagCount'] >= 5
					&& $walk['textCount'] >= 1
					&& in_array( 'A', $walk['tokenNames'], true )
					&& in_array( 'IMG', $walk['tokenNames'], true ),
				"WP_HTML_Tag_Processor structural walk case {$index}",
				array(
					'case' => self::case_summary( $case ),
					'walk' => $walk,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.tag-processor.structural-token-walk',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'profiles' => self::case_profiles( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_tag_processor_bookmark_seek( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::rich_html_cases( $ctx->fork( 'tag-seek' ) );

		foreach ( $cases as $index => $case ) {
			$seek = self::check_tag_seek_consistency( $case['html'] );

			self::collect_failure(
				$failures,
				$seek['ok'],
				"WP_HTML_Tag_Processor bookmark/seek consistency case {$index}",
				array(
					'case' => self::case_summary( $case ),
					'seek' => $seek,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.tag-processor.bookmark-seek-replay',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_processor_normalization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array_merge(
			self::normalization_cases( $ctx->fork( 'normalize-stable' ) ),
			self::recovery_cases( $ctx->fork( 'normalize-recovery' ) )
		);

		foreach ( $cases as $index => $case ) {
			$normalize = self::check_normalize_idempotence( $case['html'], $case['mode'], ! empty( $case['expectSupported'] ) );

			self::collect_failure(
				$failures,
				$normalize['ok'],
				"WP_HTML_Processor normalization recovery case {$index}",
				array(
					'case'      => self::case_summary( $case ),
					'normalize' => $normalize,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.processor.normalization-recovery-idempotent',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'profiles' => self::case_profiles( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_processor_semantic_oracles( \ComponentFuzz\FuzzContext $ctx ): array {
		$oracles = array(
			'foreign-seek-namespace-state'       => self::check_foreign_seek_namespace_state(),
			'table-form-comment-mode'           => self::check_table_form_comment_mode(),
			'select-breakout-mode'              => self::check_select_breakout_mode(),
			'template-table-mode'               => self::check_template_table_mode(),
			'foreign-attribute-token-serialize' => self::check_foreign_attribute_token_serialization(),
			'textarea-rcdata-text-mutation'     => self::check_textarea_rcdata_text_mutation(),
			'foreign-atomic-text-rejection'     => self::check_foreign_atomic_text_rejection(),
			'active-formatting-reconstruction'  => self::check_active_formatting_reconstruction_path(),
		);

		$failures = array();
		foreach ( $oracles as $name => $oracle ) {
			self::collect_failure(
				$failures,
				$oracle['ok'],
				"WP_HTML_Processor semantic oracle {$name}",
				array(
					'name'   => $name,
					'oracle' => $oracle,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.processor.semantic-parser-oracles',
			array() === $failures,
			array(
				'cases'    => count( $oracles ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_processor_tokens_and_text( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::rich_html_cases( $ctx->fork( 'processor-tokens' ) );

		foreach ( $cases as $index => $case ) {
			$img_processor = self::create_html_processor( $case['html'], $case['mode'] );
			$img_found     = $img_processor instanceof \WP_HTML_Processor ? $img_processor->next_tag( 'IMG' ) : false;
			$breadcrumbs   = $img_found ? $img_processor->get_breadcrumbs() : array();
			$img_token     = $img_found ? $img_processor->get_token_name() : null;
			$img_type      = $img_found ? $img_processor->get_token_type() : null;

			$walk = self::walk_html_processor( $case['html'], $case['mode'] );
			$text = self::check_processor_text_mutation( $case );
			$seek = self::check_html_processor_seek_consistency( $case['html'], $case['mode'] );

			self::collect_failure(
				$failures,
				$img_found
					&& 'IMG' === $img_token
					&& '#tag' === $img_type
					&& array_slice( $breadcrumbs, 0, 2 ) === array( 'HTML', 'BODY' )
					&& 'IMG' === end( $breadcrumbs )
					&& $walk['ok']
					&& $walk['tagCount'] >= 6
					&& $walk['textCount'] >= 1
					&& $walk['maxDepth'] >= 3
					&& in_array( 'A', $walk['tokenNames'], true )
					&& in_array( 'IMG', $walk['tokenNames'], true )
					&& $text['ok']
					&& $seek['ok'],
				"WP_HTML_Processor token walk, text mutation, and seek case {$index}",
				array(
					'case'        => self::case_summary( $case ),
					'breadcrumbs' => $breadcrumbs,
					'walk'        => $walk,
					'text'        => $text,
					'seek'        => $seek,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.processor.tokens-text-seek',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'profiles' => self::case_profiles( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_processor_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::boundary_cases( $ctx->fork( 'boundaries' ) );

		foreach ( $cases as $index => $case ) {
			$tag_tags = array();
			$tag_scan = new \WP_HTML_Tag_Processor( $case['html'] );
			while ( $tag_scan->next_tag() ) {
				$tag_tags[] = $tag_scan->get_tag();
				if ( count( $tag_tags ) > self::MAX_TOKENS ) {
					break;
				}
			}

			$walk             = self::walk_html_processor( $case['html'], self::MODE_FRAGMENT );
			$boundary_summary = self::collect_boundary_summary( $case['html'] );
			$comment          = self::check_comment_mutation_boundary( $case['html'] );
			$rawtext          = self::check_rawtext_mutation_boundary( $case['html'] );

			self::collect_failure(
				$failures,
				$walk['ok']
					&& ! in_array( 'A', $tag_tags, true )
					&& ! in_array( 'SPAN', $tag_tags, true )
					&& ! in_array( 'A', $walk['tokenNames'], true )
					&& ! in_array( 'SPAN', $walk['tokenNames'], true )
					&& $boundary_summary['sawCommentMarkup']
					&& $boundary_summary['sawScriptRawText']
					&& $boundary_summary['sawStyleRawText']
					&& $boundary_summary['sawTextareaRcdata']
					&& $boundary_summary['sawTitleRcdata']
					&& $boundary_summary['sawSvgNamespace']
					&& $boundary_summary['sawMathNamespace']
					&& $boundary_summary['sawHtmlIntegrationPoint']
					&& $comment['ok']
					&& $rawtext['ok'],
				"HTML API keeps comment, rawtext, RCDATA, and namespace boundaries case {$index}",
				array(
					'case'             => self::case_summary( $case ),
					'tagProcessorTags' => $tag_tags,
					'walk'             => $walk,
					'boundary'         => $boundary_summary,
					'commentMutation'  => $comment,
					'rawtextMutation'  => $rawtext,
				)
			);
		}

		return self::result(
			$ctx,
			'html-api.processor.namespace-comment-rawtext-boundaries',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_foreign_seek_namespace_state(): array {
		$processor = \WP_HTML_Processor::create_fragment( '<custom-element /><svg><rect />' );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'create-fragment-failed' );
		}

		$found_custom = $processor->next_tag( 'CUSTOM-ELEMENT' );
		$marked       = $found_custom && $processor->set_bookmark( 'cfz-foreign-seek' );
		$custom       = $found_custom ? self::processor_tag_state( $processor ) : null;

		$found_rect = $processor->next_tag( 'RECT' );
		$rect       = $found_rect ? self::processor_tag_state( $processor ) : null;

		$sought       = $marked && $processor->seek( 'cfz-foreign-seek' );
		$custom_again = $sought ? self::processor_tag_state( $processor ) : null;

		$found_rect_again = $sought && $processor->next_tag( 'RECT' );
		$rect_again       = $found_rect_again ? self::processor_tag_state( $processor ) : null;

		return array(
			'ok' => $found_custom
				&& $marked
				&& $found_rect
				&& $sought
				&& $found_rect_again
				&& $custom === $custom_again
				&& $rect === $rect_again
				&& array(
					'tag'         => 'CUSTOM-ELEMENT',
					'namespace'   => 'html',
					'selfClosing' => true,
					'expectsClose' => true,
					'breadcrumbs' => array( 'HTML', 'BODY', 'CUSTOM-ELEMENT' ),
				) === $custom
				&& array(
					'tag'         => 'RECT',
					'namespace'   => 'svg',
					'selfClosing' => true,
					'expectsClose' => false,
					'breadcrumbs' => array( 'HTML', 'BODY', 'CUSTOM-ELEMENT', 'SVG', 'RECT' ),
				) === $rect,
			'custom'      => $custom,
			'rect'        => $rect,
			'customAgain' => $custom_again,
			'rectAgain'   => $rect_again,
		);
	}

	private static function check_table_form_comment_mode(): array {
		$processor = \WP_HTML_Processor::create_fragment( '<table><form><!--comment-->' );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'create-fragment-failed' );
		}

		$found_form = $processor->next_tag( 'FORM' );
		$form       = $found_form ? self::processor_tag_state( $processor ) : null;

		$found_closer = $found_form && $processor->next_token();
		$closer       = $found_closer
			? array(
				'tokenName'   => $processor->get_token_name(),
				'tokenType'   => $processor->get_token_type(),
				'isCloser'    => $processor->is_tag_closer(),
				'breadcrumbs' => $processor->get_breadcrumbs(),
			)
			: null;

		$found_comment = $found_closer && $processor->next_token();
		$comment       = $found_comment
			? array(
				'tokenName'   => $processor->get_token_name(),
				'tokenType'   => $processor->get_token_type(),
				'breadcrumbs' => $processor->get_breadcrumbs(),
				'text'        => $processor->get_modifiable_text(),
			)
			: null;

		return array(
			'ok' => $found_form
				&& array(
					'tag'         => 'FORM',
					'namespace'   => 'html',
					'selfClosing' => false,
					'expectsClose' => true,
					'breadcrumbs' => array( 'HTML', 'BODY', 'TABLE', 'FORM' ),
				) === $form
				&& $found_closer
				&& 'FORM' === $closer['tokenName']
				&& '#tag' === $closer['tokenType']
				&& true === $closer['isCloser']
				&& array( 'HTML', 'BODY', 'TABLE' ) === $closer['breadcrumbs']
				&& $found_comment
				&& '#comment' === $comment['tokenName']
				&& '#comment' === $comment['tokenType']
				&& array( 'HTML', 'BODY', 'TABLE', '#comment' ) === $comment['breadcrumbs']
				&& 'comment' === $comment['text'],
			'form'    => $form,
			'closer'  => $closer,
			'comment' => $comment,
		);
	}

	private static function check_select_breakout_mode(): array {
		$html      = '<select><option>one<option>two<input><textarea>x</textarea><button>b</button><hr><datalist><option>d</datalist></select><p>after';
		$processor = \WP_HTML_Processor::create_fragment( $html );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'create-fragment-failed' );
		}

		$found_input = $processor->next_tag( 'INPUT' );
		$input       = $found_input ? self::processor_tag_state( $processor ) : null;

		$found_textarea = $found_input && $processor->next_tag( 'TEXTAREA' );
		$textarea       = $found_textarea
			? array_merge(
				self::processor_tag_state( $processor ),
				array( 'text' => $processor->get_modifiable_text() )
			)
			: null;

		$found_after = $found_textarea && $processor->next_tag( 'P' );
		$after       = $found_after ? self::processor_tag_state( $processor ) : null;

		return array(
			'ok' => array(
				'tag'         => 'INPUT',
				'namespace'   => 'html',
				'selfClosing' => false,
				'expectsClose' => false,
				'breadcrumbs' => array( 'HTML', 'BODY', 'INPUT' ),
			) === $input
				&& array(
					'tag'         => 'TEXTAREA',
					'namespace'   => 'html',
					'selfClosing' => false,
					'expectsClose' => false,
					'breadcrumbs' => array( 'HTML', 'BODY', 'TEXTAREA' ),
					'text'        => 'x',
				) === $textarea
				&& array(
					'tag'         => 'P',
					'namespace'   => 'html',
					'selfClosing' => false,
					'expectsClose' => true,
					'breadcrumbs' => array( 'HTML', 'BODY', 'P' ),
				) === $after,
			'input'    => $input,
			'textarea' => $textarea,
			'after'    => $after,
		);
	}

	private static function check_template_table_mode(): array {
		$html      = '<template><p>inside</p><table><tr><td>cell</table></template><p>after</p>';
		$processor = \WP_HTML_Processor::create_fragment( $html );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'create-fragment-failed' );
		}

		$found_inside = $processor->next_tag( 'P' );
		$inside       = $found_inside ? self::processor_tag_state( $processor ) : null;

		$found_tbody = $found_inside && $processor->next_tag( 'TBODY' );
		$tbody       = $found_tbody ? self::processor_tag_state( $processor ) : null;

		$found_after = $found_tbody && $processor->next_tag( 'P' );
		$after       = $found_after ? self::processor_tag_state( $processor ) : null;

		return array(
			'ok' => array(
				'tag'         => 'P',
				'namespace'   => 'html',
				'selfClosing' => false,
				'expectsClose' => true,
				'breadcrumbs' => array( 'HTML', 'BODY', 'TEMPLATE', 'P' ),
			) === $inside
				&& array(
					'tag'         => 'TBODY',
					'namespace'   => 'html',
					'selfClosing' => false,
					'expectsClose' => true,
					'breadcrumbs' => array( 'HTML', 'BODY', 'TEMPLATE', 'TABLE', 'TBODY' ),
				) === $tbody
				&& array(
					'tag'         => 'P',
					'namespace'   => 'html',
					'selfClosing' => false,
					'expectsClose' => true,
					'breadcrumbs' => array( 'HTML', 'BODY', 'P' ),
				) === $after,
			'inside' => $inside,
			'tbody'  => $tbody,
			'after'  => $after,
		);
	}

	private static function check_foreign_attribute_token_serialization(): array {
		$svg = '<svg><a xlink:actuate="onLoad" xlink:arcrole="arc" xlink:href="#target" xlink:role="role" xlink:show="new" xlink:title="title" xlink:type="simple" xml:lang="en" xml:space="preserve" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"></a></svg>';

		$processor = \WP_HTML_Processor::create_fragment( $svg );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'create-fragment-failed' );
		}

		$found_svg = $processor->next_token();
		$svg_token = $found_svg ? $processor->serialize_token() : null;
		$found_a   = $found_svg && $processor->next_token();
		$a_token   = $found_a ? $processor->serialize_token() : null;

		return array(
			'ok' => '<svg>' === $svg_token
				&& '<a xlink:actuate="onLoad" xlink:arcrole="arc" xlink:href="#target" xlink:role="role" xlink:show="new" xlink:title="title" xlink:type="simple" xml:lang="en" xml:space="preserve" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">' === $a_token,
			'svgToken' => $svg_token,
			'aToken'   => $a_token,
		);
	}

	private static function check_textarea_rcdata_text_mutation(): array {
		$processor = \WP_HTML_Processor::create_fragment( '<textarea></textarea>' );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'create-fragment-failed' );
		}

		$found   = $processor->next_token();
		$updated = $found && $processor->set_modifiable_text( "\nAFTER NEWLINE" );
		$text    = $found ? $processor->get_modifiable_text() : null;
		$html    = $processor->get_updated_html();

		return array(
			'ok' => $found
				&& 'TEXTAREA' === $processor->get_token_name()
				&& $updated
				&& "\nAFTER NEWLINE" === $text
				&& "<textarea>\n\nAFTER NEWLINE</textarea>" === $html,
			'text'    => $text,
			'htmlHex' => bin2hex( $html ),
		);
	}

	private static function check_foreign_atomic_text_rejection(): array {
		$html      = '<svg><textarea></textarea></svg>';
		$processor = \WP_HTML_Processor::create_fragment( $html );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'create-fragment-failed' );
		}

		$found   = $processor->next_tag( 'TEXTAREA' );
		$state   = $found ? self::processor_tag_state( $processor ) : null;
		$updated = $found ? $processor->set_modifiable_text( 'test' ) : null;
		$output  = $processor->get_updated_html();

		return array(
			'ok' => $found
				&& 'svg' === $state['namespace']
				&& false === $updated
				&& $html === $output,
			'state'   => $state,
			'updated' => $updated,
			'output'  => $output,
		);
	}

	private static function check_active_formatting_reconstruction_path(): array {
		$processor = \WP_HTML_Processor::create_fragment( '<p><b>One<p><source>Two<source>' );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'create-fragment-failed' );
		}

		$found_first = $processor->next_tag( 'SOURCE' );
		$first       = $found_first ? self::processor_tag_state( $processor ) : null;

		$found_second = $found_first && $processor->next_tag( 'SOURCE' );
		$second       = $found_second ? self::processor_tag_state( $processor ) : null;
		$last_error   = $processor->get_last_error();

		return array(
			'ok' => array(
				'tag'         => 'SOURCE',
				'namespace'   => 'html',
				'selfClosing' => false,
				'expectsClose' => false,
				'breadcrumbs' => array( 'HTML', 'BODY', 'P', 'SOURCE' ),
			) === $first
				&& (
					array(
						'tag'         => 'SOURCE',
						'namespace'   => 'html',
						'selfClosing' => false,
						'expectsClose' => false,
						'breadcrumbs' => array( 'HTML', 'BODY', 'P', 'B', 'SOURCE' ),
					) === $second
					|| ( false === $found_second && \WP_HTML_Processor::ERROR_UNSUPPORTED === $last_error )
				),
			'first'      => $first,
			'second'     => $second,
			'lastError'  => $last_error,
			'supported'  => $found_second,
		);
	}

	private static function processor_tag_state( \WP_HTML_Processor $processor ): array {
		return array(
			'tag'         => $processor->get_tag(),
			'namespace'   => $processor->get_namespace(),
			'selfClosing' => $processor->has_self_closing_flag(),
			'expectsClose' => $processor->expects_closer(),
			'breadcrumbs' => $processor->get_breadcrumbs(),
		);
	}

	private static function attribute_update_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case   = $ctx->fork( 'update-' . $i );
			$token  = self::safe_token( $case, 'token' );
			$href   = '#frag-' . $token;
			$title  = 'Title <' . $token . '> & "quoted"';
			$text   = self::safe_text( $case, 12, 32 );
			$tail   = self::safe_text( $case, 8, 24 );
			$unsafe = 'unsafe <script data-token="' . $token . '"> & "quote"';

			$cases[] = array(
				'token'           => $token,
				'addedClass'      => 'added-' . self::safe_token( $case, 'class' ),
				'href'            => $href,
				'title'           => $title,
				'unsafeAttribute' => $unsafe,
				'html'            => self::attribute_update_html( $case, $i, $href, $text, $tail ),
			);
		}

		return $cases;
	}

	private static function rich_html_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case         = $ctx->fork( 'rich-' . $i );
			$profile      = self::PROFILES[ $i % count( self::PROFILES ) ];
			$mode         = 0 === $i % 5 ? self::MODE_FULL_DOCUMENT : self::MODE_FRAGMENT;
			$token        = self::safe_token( $case, 'token' );
			$text_marker  = 'cfz-text-' . $token;
			$text         = self::safe_text( $case, 12, 32 );
			$tail         = self::safe_text( $case, 8, 24 );
			$href         = '#frag-' . $token;
			$generated    = self::generated_nodes( $case->fork( 'generated' ), $profile, self::depth_for_profile( $case, $profile ) );
			$fragment     = self::rich_case_fragment( $case, $i, $href, $text, $tail, $text_marker, $generated );
			$full_document = '<!DOCTYPE html><html data-cfz-doc="' . $i . '"><head><title>'
				. esc_html( 'Doc ' . $token )
				. '</title><meta charset="utf-8"></head><body>'
				. $fragment
				. '</body></html>';

			$cases[] = array(
				'token'           => $token,
				'profile'         => $profile,
				'mode'            => $mode,
				'href'            => $href,
				'textMarker'      => $text_marker,
				'replacementText' => 'replacement <' . $token . '> & "quote" \'single\'',
				'html'            => self::MODE_FULL_DOCUMENT === $mode ? $full_document : $fragment,
			);
		}

		return $cases;
	}

	private static function normalization_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::safe_token( $ctx, 'normalize' );
		$text  = esc_html( self::safe_text( $ctx, 6, 18 ) );

		return array(
			array(
				'token'           => $token,
				'profile'         => 'normalize-balanced',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<article><p>' . $text . '<em>inline</em></p><ul><li>one<li>two</ul></article>',
			),
			array(
				'token'           => $token,
				'profile'         => 'normalize-table',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<table><tr><td>' . $text . '</td><td><p>cell</table><p>tail',
			),
			array(
				'token'           => $token,
				'profile'         => 'normalize-template',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<template><p>' . $text . '</p><table><tr><td>inert</table></template><p>after</p>',
			),
			array(
				'token'           => $token,
				'profile'         => 'normalize-select',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<select><option>one<option>two<optgroup><option>three</select><p>after</p>',
			),
			array(
				'token'           => $token,
				'profile'         => 'normalize-formatting-adoption',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<p><b>one<i>two</i></b></p><p><em>three</em><a>inner</a></p>',
			),
			array(
				'token'           => $token,
				'profile'         => 'normalize-rawtext-rcdata',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<div><script>if (a < b) { "x"; }</script>'
					. '<style>.x>a{color:red}</style><textarea>A &amp; B</textarea>'
					. '<title>T &amp; C</title></div>',
			),
			array(
				'token'           => $token,
				'profile'         => 'normalize-svg-foreignobject',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<svg viewBox="0 0 10 10"><title>svg</title><foreignObject><p>' . $text . '</p></foreignObject></svg>',
			),
			array(
				'token'           => $token,
				'profile'         => 'normalize-math-annotation',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<math><mi>x</mi><annotation-xml encoding="text/html"><p>' . $text . '</p></annotation-xml></math>',
			),
			array(
				'token'           => $token,
				'profile'         => 'normalize-full-document',
				'mode'            => self::MODE_FULL_DOCUMENT,
				'expectSupported' => true,
				'html'            => '<!DOCTYPE html><html><head><title>'
					. esc_html( $token )
					. '</title></head><body><p>' . $text
					. '<table><tr><td>cell</table></body></html>',
			),
		);
	}

	private static function recovery_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::safe_token( $ctx, 'recovery' );

		return array(
			array(
				'token'           => $token,
				'profile'         => 'recovery-balanced',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<ul><li>one<li><p>two<table><tr><td>cell</table><p>tail',
			),
			array(
				'token'           => $token,
				'profile'         => 'recovery-comments-doctype-bogus',
				'mode'            => self::MODE_FRAGMENT,
				'expectSupported' => true,
				'html'            => '<!not-a-comment><!-- open<div><p data-x="<bad">text</section>',
			),
			array(
				'token'           => $token,
				'profile'         => 'recovery-full-document',
				'mode'            => self::MODE_FULL_DOCUMENT,
				'expectSupported' => true,
				'html'            => '<!DOCTYPE html><title>' . esc_html( $token ) . '</title><p>body<td>stray',
			),
		);
	}

	private static function boundary_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::safe_token( $ctx, 'boundary' );
		$html  = '<div data-boundary="' . esc_attr( $token ) . '">'
			. '<!-- <a href="comment">no</a> -->'
			. '<script>if (a < b) { "<span>"; }</script>'
			. '<style>.x > a { color:red }</style>'
			. '<textarea>alpha &lt; beta</textarea>'
			. '<title>T &amp; C</title>'
			. '<svg viewBox="0 0 10 10"><title>svg title</title><foreignObject><p>html child</p></foreignObject></svg>'
			. '<math><mi>x</mi><annotation-xml encoding="text/html"><p>math html</p></annotation-xml></math>'
			. '<p>final ' . esc_html( $token ) . '</p>'
			. '</div>';

		return array(
			array(
				'token'   => $token,
				'profile' => 'boundary-rawtext-comment-namespace',
				'mode'    => self::MODE_FRAGMENT,
				'html'    => $html,
			),
		);
	}

	private static function attribute_update_html(
		\ComponentFuzz\FuzzContext $ctx,
		int $index,
		string $href,
		string $text,
		string $tail
	): string {
		$wrapper = $ctx->choice( array( 'article', 'section', 'div', 'main' ) );
		$inline  = $ctx->choice( array( 'strong', 'em', 'span', 'b' ) );
		$list    = $ctx->bool()
			? '<ul><li>' . esc_html( $text ) . '<li>' . esc_html( $tail ) . '</ul>'
			: '<ol><li>' . esc_html( $text ) . '</li><li>' . esc_html( $tail ) . '</li></ol>';

		return '<' . $wrapper . ' data-wrapper="' . $index . '">'
			. '<p class="lead">Lead ' . esc_html( $text ) . ' <' . $inline . '>inline</' . $inline . '></p>'
			. '<a class="start-class old-class" data-index="' . $index . '" data-remove="drop" href="'
			. esc_attr( $href )
			. '" enabled href="/duplicate">Link ' . esc_html( $tail ) . '</a>'
			. '<figure><img src="image-' . $index . '.jpg" alt="' . esc_attr( $tail ) . '"><figcaption>'
			. esc_html( $text )
			. '</figcaption></figure>'
			. $list
			. '<!-- cfz comment ' . $index . ' -->'
			. '</' . $wrapper . '>';
	}

	private static function rich_case_fragment(
		\ComponentFuzz\FuzzContext $ctx,
		int $index,
		string $href,
		string $text,
		string $tail,
		string $text_marker,
		string $generated
	): string {
		$wrapper = $ctx->choice( array( 'article', 'section', 'div', 'main' ) );
		$inline  = $ctx->choice( array( 'strong', 'em', 'span', 'b' ) );

		return '<' . $wrapper . ' data-wrapper="' . $index . '">'
			. '<p class="lead">Lead ' . esc_html( $text ) . ' <' . $inline . '>inline</' . $inline . '></p>'
			. '<p data-cfz-text="' . esc_attr( $text_marker ) . '">' . esc_html( $text_marker ) . '</p>'
			. '<a class="start-class old-class" data-index="' . $index . '" href="' . esc_attr( $href ) . '">'
			. 'Link ' . esc_html( $tail ) . '</a>'
			. '<figure><img src="image-' . $index . '.jpg" alt="' . esc_attr( $tail ) . '"><figcaption>'
			. esc_html( $text )
			. '</figcaption></figure>'
			. $generated
			. '<!-- cfz comment ' . $index . ' -->'
			. '</' . $wrapper . '>';
	}

	private static function depth_for_profile( \ComponentFuzz\FuzzContext $ctx, string $profile ): int {
		if ( 'incomplete-malformed' === $profile ) {
			return $ctx->int( 2, 4 );
		}

		if ( in_array( $profile, array( 'tables', 'foreign-content', 'formatting-adoption' ), true ) ) {
			return $ctx->int( 3, 5 );
		}

		return $ctx->int( 2, 4 );
	}

	private static function generated_nodes( \ComponentFuzz\FuzzContext $ctx, string $profile, int $depth ): string {
		$count = $depth > 3 ? $ctx->int( 1, 3 ) : $ctx->int( 2, 5 );
		$out   = '';

		for ( $i = 0; $i < $count; ++$i ) {
			$out .= self::generated_node( $ctx->fork( 'node-' . $depth . '-' . $i ), $profile, $depth );
			if ( strlen( $out ) > 12000 ) {
				return substr( $out, 0, 12000 );
			}
		}

		return $out;
	}

	private static function generated_node( \ComponentFuzz\FuzzContext $ctx, string $profile, int $depth ): string {
		if ( $depth <= 0 ) {
			return $ctx->bool( 70 ) ? self::terminal_text( $ctx ) : self::comment( $ctx );
		}

		$kind = $ctx->weightedChoice( self::node_weights( $profile ) );
		switch ( $kind ) {
			case 'text':
				return self::terminal_text( $ctx );

			case 'comment':
				return self::comment( $ctx );

			case 'void':
				return '<' . $ctx->choice( array( 'br', 'hr', 'img', 'input', 'source', 'wbr' ) )
					. self::attrs( $ctx, $profile )
					. ( $ctx->bool( 25 ) ? '/>' : '>' );

			case 'raw':
				return self::raw_element( $ctx );

			case 'template':
				return '<template' . self::attrs( $ctx, $profile ) . '>'
					. self::generated_nodes( $ctx->fork( 'template' ), $profile, $depth - 1 )
					. ( $ctx->bool( 80 ) ? '</template>' : '' );

			case 'table':
				return self::table_markup( $ctx, $profile, $depth - 1 );

			case 'select':
				return self::select_markup( $ctx, $profile, $depth - 1 );

			case 'foreign':
				return self::foreign_markup( $ctx, $profile, $depth - 1 );

			case 'adoption':
				return self::adoption_markup( $ctx );

			case 'auto-close':
				return self::auto_closing_markup( $ctx );

			case 'bogus':
				return self::bogus_markup( $ctx );

			case 'weird':
				return self::weird_element( $ctx, $profile, $depth - 1 );

			case 'element':
			default:
				return self::normal_element( $ctx, $profile, $depth - 1 );
		}
	}

	private static function node_weights( string $profile ): array {
		$weights = array(
			array( 30, 'element' ),
			array( 13, 'text' ),
			array( 8, 'comment' ),
			array( 8, 'void' ),
			array( 7, 'raw' ),
			array( 6, 'template' ),
			array( 6, 'table' ),
			array( 4, 'select' ),
			array( 7, 'foreign' ),
			array( 3, 'adoption' ),
			array( 3, 'auto-close' ),
			array( 3, 'bogus' ),
			array( 2, 'weird' ),
		);

		switch ( $profile ) {
			case 'tables':
				return array_merge( $weights, array( array( 34, 'table' ), array( 10, 'auto-close' ) ) );
			case 'template':
				return array_merge( $weights, array( array( 34, 'template' ) ) );
			case 'select':
				return array_merge( $weights, array( array( 34, 'select' ), array( 8, 'table' ) ) );
			case 'foreign-content':
				return array_merge( $weights, array( array( 36, 'foreign' ) ) );
			case 'rawtext-rcdata':
				return array_merge( $weights, array( array( 36, 'raw' ) ) );
			case 'attributes-entities':
				return array_merge( $weights, array( array( 24, 'element' ), array( 14, 'weird' ), array( 10, 'text' ) ) );
			case 'comments-doctype-bogus':
				return array_merge( $weights, array( array( 24, 'comment' ), array( 18, 'bogus' ) ) );
			case 'formatting-adoption':
				return array_merge( $weights, array( array( 34, 'adoption' ), array( 12, 'auto-close' ) ) );
			case 'incomplete-malformed':
				return array_merge( $weights, array( array( 24, 'bogus' ), array( 20, 'weird' ), array( 8, 'auto-close' ) ) );
			default:
				return $weights;
		}
	}

	private static function normal_element( \ComponentFuzz\FuzzContext $ctx, string $profile, int $depth ): string {
		$tag = $ctx->choice(
			array(
				'div',
				'p',
				'span',
				'section',
				'article',
				'header',
				'footer',
				'a',
				'b',
				'i',
				'em',
				'strong',
				'code',
				'pre',
				'blockquote',
				'ul',
				'ol',
				'li',
				'dl',
				'dt',
				'dd',
				'h1',
				'h2',
				'button',
				'form',
				'label',
			)
		);
		$close = $ctx->bool( 'incomplete-malformed' === $profile ? 55 : 85 );

		return '<' . $tag . self::attrs( $ctx, $profile ) . '>'
			. self::generated_nodes( $ctx->fork( 'children' ), $profile, $depth )
			. ( $close ? '</' . ( $ctx->bool( 90 ) ? $tag : $ctx->choice( array( 'div', 'span', 'p', 'section' ) ) ) . '>' : '' );
	}

	private static function weird_element( \ComponentFuzz\FuzzContext $ctx, string $profile, int $depth ): string {
		$tag = $ctx->choice( array( 'x-widget', 'foo:bar', 'foo.bar', 'foo_bar', 'MiXeD-Custom', 'x-0' ) );
		if ( $ctx->bool( 35 ) ) {
			$tag = $ctx->choice( array( '1bad', '?pi', '!not', '=bad', '"bad' ) );
		}

		$gap = $ctx->choice( array( ' ', "\t", "\n", "\f", "\r\n", '  ' ) );
		return '<' . $tag . $gap . self::attrs( $ctx, $profile )
			. ( $ctx->bool( 20 ) ? '/' . $gap : '' )
			. '>'
			. self::generated_nodes( $ctx->fork( 'weird-children' ), $profile, max( 0, $depth ) )
			. ( $ctx->bool( 65 ) ? '</' . $tag . $gap . '>' : '' );
	}

	private static function table_markup( \ComponentFuzz\FuzzContext $ctx, string $profile, int $depth ): string {
		$rows = '';
		for ( $r = 0; $r < $ctx->int( 1, 3 ); ++$r ) {
			$cells = '';
			for ( $c = 0; $c < $ctx->int( 1, 3 ); ++$c ) {
				$cell = $ctx->choice( array( 'td', 'th' ) );
				$cells .= '<' . $cell . self::attrs( $ctx->fork( 'cell-' . $r . '-' . $c ), $profile ) . '>'
					. self::generated_nodes( $ctx->fork( 'cell-children-' . $r . '-' . $c ), $profile, max( 0, $depth - 1 ) )
					. ( $ctx->bool( 80 ) ? '</' . $cell . '>' : '' );
			}
			$rows .= '<tr' . self::attrs( $ctx->fork( 'row-' . $r ), $profile ) . '>' . $cells . ( $ctx->bool( 80 ) ? '</tr>' : '' );
		}

		if ( $ctx->bool( 50 ) ) {
			$section_tag = $ctx->choice( array( 'tbody', 'thead', 'tfoot' ) );
			$section     = '<' . $section_tag . '>' . $rows . '</' . $ctx->choice( array( 'tbody', 'thead', 'tfoot' ) ) . '>';
		} else {
			$section = $rows;
		}

		$noise = $ctx->bool( 45 )
			? self::terminal_text( $ctx->fork( 'foster-text' ) )
				. self::normal_element( $ctx->fork( 'foster-element' ), $profile, max( 0, $depth - 1 ) )
			: '';

		return '<table' . self::attrs( $ctx, $profile ) . '>' . $noise . $section . ( $ctx->bool( 82 ) ? '</table>' : '' );
	}

	private static function select_markup( \ComponentFuzz\FuzzContext $ctx, string $profile, int $depth ): string {
		$out = '';
		for ( $i = 0; $i < $ctx->int( 2, 5 ); ++$i ) {
			$kind = $ctx->weightedChoice(
				array(
					array( 42, 'option' ),
					array( 22, 'optgroup' ),
					array( 18, 'breaker' ),
					array( 8, 'nested-select' ),
					array( 10, 'other' ),
				)
			);

			if ( 'option' === $kind ) {
				$out .= '<option'
					. self::attrs( $ctx->fork( 'option-' . $i ), $profile )
					. '>'
					. self::terminal_text( $ctx->fork( 'option-text-' . $i ) )
					. ( $ctx->bool( 60 ) ? '</option>' : '' );
			} elseif ( 'optgroup' === $kind ) {
				$out .= '<optgroup'
					. self::attrs( $ctx->fork( 'optgroup-' . $i ), $profile )
					. '><option>'
					. self::terminal_text( $ctx->fork( 'optgroup-text-' . $i ) )
					. ( $ctx->bool( 50 ) ? '</optgroup>' : '' );
			} elseif ( 'breaker' === $kind ) {
				$out .= $ctx->choice( array( '<input>', '<textarea>x</textarea>', '<button>b</button>', '<hr>', '<datalist><option>d</datalist>' ) );
			} elseif ( 'nested-select' === $kind ) {
				$out .= '<select><option>' . self::terminal_text( $ctx->fork( 'nested-select-' . $i ) );
			} else {
				$out .= self::generated_node( $ctx->fork( 'select-other-' . $i ), $profile, max( 0, $depth - 1 ) );
			}
		}

		$select = '<select' . self::attrs( $ctx, $profile ) . '>' . $out . ( $ctx->bool( 70 ) ? '</select>' : '' );
		if ( $ctx->bool( 20 ) ) {
			return '<table><tr><td>' . $select . '</td></tr></table>';
		}

		return $select;
	}

	private static function foreign_markup( \ComponentFuzz\FuzzContext $ctx, string $profile, int $depth ): string {
		if ( $ctx->bool( 50 ) ) {
			$foreign_object = $ctx->choice( array( 'foreignObject', 'foreignobject', 'FOREIGNOBJECT' ) );
			return '<svg' . self::attrs( $ctx, $profile ) . ' viewBox="0 0 10 10"><title>'
				. esc_html( self::safe_text( $ctx, 4, 12 ) )
				. '</title><' . $foreign_object . '>'
				. self::generated_nodes( $ctx->fork( 'svg-html' ), $profile, max( 0, $depth - 1 ) )
				. '</' . $foreign_object . '></svg>';
		}

		$encoding = $ctx->choice( array( 'encoding="text/html"', 'encoding="application/xhtml+xml"', 'ENCODING="TEXT/HTML"', '' ) );
		return '<math' . self::attrs( $ctx, $profile ) . '><mi>' . esc_html( self::safe_text( $ctx, 1, 6 ) ) . '</mi><annotation-xml'
			. ( '' === $encoding ? '' : ' ' . $encoding )
			. '>'
			. self::generated_nodes( $ctx->fork( 'math-html' ), $profile, max( 0, $depth - 1 ) )
			. '</annotation-xml></math>';
	}

	private static function raw_element( \ComponentFuzz\FuzzContext $ctx ): string {
		$tag = $ctx->weightedChoice(
			array(
				array( 36, 'script' ),
				array( 30, 'style' ),
				array( 18, 'textarea' ),
				array( 16, 'title' ),
			)
		);
		$text = str_replace( '</', '<\/', self::terminal_text( $ctx, true ) );

		return '<' . $tag . self::attrs( $ctx, 'rawtext-rcdata' ) . '>' . $text . ( $ctx->bool( 82 ) ? '</' . $tag . '>' : '' );
	}

	private static function adoption_markup( \ComponentFuzz\FuzzContext $ctx ): string {
		$f1    = $ctx->choice( array( 'b', 'i', 'em', 'strong', 'a', 'font', 'nobr' ) );
		$f2    = $ctx->choice( array( 'b', 'i', 'em', 'strong', 'a', 'font', 'nobr' ) );
		$block = $ctx->choice( array( 'p', 'div', 'address', 'blockquote' ) );
		$t1    = esc_html( self::safe_text( $ctx->fork( 'a' ), 1, 8 ) );
		$t2    = esc_html( self::safe_text( $ctx->fork( 'b' ), 1, 8 ) );
		$t3    = esc_html( self::safe_text( $ctx->fork( 'c' ), 1, 8 ) );

		switch ( $ctx->int( 1, 5 ) ) {
			case 1:
				return "<{$f1}>{$t1}<{$f2}>{$t2}</{$f1}>{$t3}</{$f2}>";
			case 2:
				return "<{$f1}>{$t1}<{$block}>{$t2}</{$f1}>{$t3}</{$block}>";
			case 3:
				return "<{$block}><{$f1}>{$t1}</{$block}><{$block}>{$t2}</{$block}>";
			case 4:
				return "<a>{$t1}<{$block}>{$t2}<a>{$t3}";
			default:
				return '<p>' . str_repeat( "<{$f1}>{$t1}", $ctx->int( 4, 7 ) ) . '</p><p>' . $t2 . '</p>';
		}
	}

	private static function auto_closing_markup( \ComponentFuzz\FuzzContext $ctx ): string {
		switch ( $ctx->int( 1, 4 ) ) {
			case 1:
				return '<ul><li>'
					. esc_html( self::safe_text( $ctx, 2, 10 ) )
					. '<li>'
					. esc_html( self::safe_text( $ctx->fork( 'li' ), 2, 10 ) )
					. '</ul>';
			case 2:
				return '<dl><dt>'
					. esc_html( self::safe_text( $ctx, 2, 10 ) )
					. '<dd>'
					. esc_html( self::safe_text( $ctx->fork( 'dd' ), 2, 10 ) )
					. '</dl>';
			case 3:
				return '<h1>' . esc_html( self::safe_text( $ctx, 2, 10 ) ) . '<h2>' . esc_html( self::safe_text( $ctx->fork( 'h' ), 2, 10 ) );
			default:
				return '<p>' . esc_html( self::safe_text( $ctx, 2, 10 ) ) . '<p>' . esc_html( self::safe_text( $ctx->fork( 'p' ), 2, 10 ) );
		}
	}

	private static function bogus_markup( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				'<!DOCTYPE html>',
				'<!DOCTYPE>',
				'<!not-a-comment>',
				'<?target?>',
				'</ ' . self::safe_text( $ctx, 1, 6 ),
				'<//' . self::safe_text( $ctx, 1, 8 ) . '>',
				'<![CDATA[' . self::safe_text( $ctx, 2, 12 ) . ']]>',
			)
		);
	}

	private static function comment( \ComponentFuzz\FuzzContext $ctx ): string {
		$text = str_replace( '-->', '-- >', self::safe_text( $ctx, 0, 24 ) );
		return $ctx->choice(
			array(
				'<!--' . $text . '-->',
				'<!---->',
				'<!-->',
				'<!--->',
				'<!--a<!--b--c-->',
				'<!--x--!>',
				'<!--x>',
				'<?target?>',
				'<!not-a-comment>',
			)
		);
	}

	private static function attrs( \ComponentFuzz\FuzzContext $ctx, string $profile ): string {
		$count = 'attributes-entities' === $profile ? $ctx->int( 1, 7 ) : $ctx->int( 0, 4 );
		$out   = '';
		$seen  = array();

		for ( $i = 0; $i < $count; ++$i ) {
			if ( in_array( $profile, array( 'attributes-entities', 'incomplete-malformed' ), true ) && $ctx->bool( 18 ) ) {
				$out .= self::malformed_attr_chunk( $ctx );
				continue;
			}

			$name = ! empty( $seen ) && $ctx->bool( 10 )
				? $ctx->choice( $seen )
				: $ctx->choice(
					array(
						'id',
						'class',
						'href',
						'src',
						'alt',
						'title',
						'data-x',
						'data-Foo',
						'xlink:href',
						'xml:lang',
						'checked',
						'disabled',
						'style',
						'aria-label',
						'data--x',
						':colon',
						'@click',
					)
				);
			$seen[] = $name;

			$gap = $ctx->choice( array( ' ', ' ', "\t", "\n", "\f", "\r\n", '  ' ) );
			if ( $ctx->bool( 18 ) ) {
				$out .= $gap . $name;
				continue;
			}

			$value = self::attribute_value( $ctx );
			$quote = $ctx->choice( array( '"', "'", '', '"' ) );
			if ( '' === $quote ) {
				$out .= $gap . $name . $ctx->choice( array( '=', ' = ', "\t=\n" ) ) . preg_replace( '/[\x00-\x20"\'<>`=]+/', '_', $value );
			} else {
				$out .= $gap
					. $name
					. $ctx->choice( array( '=', ' = ', "\t=\n" ) )
					. $quote
					. str_replace( $quote, '"' === $quote ? "'" : '"', $value )
					. $quote;
			}
		}

		return $out;
	}

	private static function malformed_attr_chunk( \ComponentFuzz\FuzzContext $ctx ): string {
		$name  = $ctx->choice( array( '@x', '<bad', '"quoted"', '=empty', 'data-<' ) );
		$value = self::attribute_value( $ctx );
		$gap   = $ctx->choice( array( ' ', "\t", "\n", "\f" ) );

		return $ctx->choice(
			array(
				$gap . $name . '="' . str_replace( '"', "'", $value ) . '"',
				$gap . $name . '<' . self::safe_text( $ctx, 1, 5 ),
				$gap . '=' . '"' . str_replace( '"', "'", $value ) . '"',
				$gap . $name . "\t=\n" . preg_replace( '/[\x00-\x20"\'<>`=]+/', '_', $value ),
			)
		);
	}

	private static function attribute_value( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				self::safe_text( $ctx, 0, 18 ),
				'&amp;&lt;&notin;',
				'<tag>',
				'one two',
				'javascript:alert(1)',
				'data-' . self::safe_token( $ctx, 'attr' ),
			)
		);
	}

	private static function terminal_text( \ComponentFuzz\FuzzContext $ctx, bool $raw = false ): string {
		$parts = array();
		for ( $i = 0; $i < $ctx->int( 1, 4 ); ++$i ) {
			$parts[] = $ctx->choice(
				array(
					self::safe_text( $ctx->fork( 'text-' . $i ), 0, 24 ),
					'&amp;',
					'&notin;',
					'&#x3c;',
					'&#62;',
					'<<',
					'>>',
					'"quote"',
					"'single'",
				)
			);
			if ( ! $raw && $ctx->bool( 25 ) ) {
				$parts[] = $ctx->choice( array( '<', '>', '&bogus;', '&amp ;' ) );
			}
		}

		return implode( '', $parts );
	}

	private static function walk_tag_processor( string $html ): array {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$summary   = self::empty_walk_summary();

		while ( $processor->next_token() ) {
			++$summary['tokenCount'];
			if ( $summary['tokenCount'] > self::MAX_TOKENS ) {
				$summary['failures'][] = array( 'name' => 'token-limit-exceeded' );
				break;
			}

			$type = $processor->get_token_type();
			$name = $processor->get_token_name();
			if ( null === $type || null === $name ) {
				$summary['failures'][] = array(
					'name' => 'null-token-metadata',
					'type' => $type,
					'tokenName' => $name,
				);
				break;
			}

			self::record_walk_token( $summary, $type, $name, $processor->get_namespace(), 0 );
			$processor->get_modifiable_text();

			if ( '#tag' === $type ) {
				if ( ! $processor->is_tag_closer() && null === $processor->get_tag() ) {
					$summary['failures'][] = array( 'name' => 'null-tag-for-tag-token' );
					break;
				}

				if ( ! $processor->is_tag_closer() ) {
					self::exercise_tag_attributes( $processor, $summary );
				}
			}
		}

		$summary['unchanged'] = $processor->get_updated_html() === $html;
		if ( ! $summary['unchanged'] ) {
			$summary['failures'][] = array( 'name' => 'updated-html-changed-without-edits' );
		}

		$summary['ok'] = array() === $summary['failures'];
		return $summary;
	}

	private static function walk_html_processor( string $html, string $mode ): array {
		$processor         = self::create_html_processor( $html, $mode );
		$summary           = self::empty_walk_summary();
		$breadcrumb_prefix = self::MODE_FULL_DOCUMENT === $mode ? array() : array( 'HTML', 'BODY' );
		$element_stack     = array();

		if ( ! $processor instanceof \WP_HTML_Processor ) {
			$summary['failures'][] = array( 'name' => 'processor-create-returned-null' );
			$summary['ok']         = false;
			return $summary;
		}

		while ( $processor->next_token() ) {
			++$summary['tokenCount'];
			if ( $summary['tokenCount'] > self::MAX_TOKENS ) {
				$summary['failures'][] = array( 'name' => 'token-limit-exceeded' );
				break;
			}

			$type = $processor->get_token_type();
			$name = $processor->get_token_name();
			if ( null === $type || null === $name ) {
				$summary['failures'][] = array(
					'name' => 'null-token-metadata',
					'type' => $type,
					'tokenName' => $name,
				);
				break;
			}

			self::record_walk_token( $summary, $type, $name, $processor->get_namespace(), $processor->get_current_depth() );
			$processor->get_modifiable_text();

			if ( '#tag' === $type ) {
				$breadcrumb_failure = self::apply_breadcrumb_stack_invariant( $processor, $breadcrumb_prefix, $element_stack );
				if ( null !== $breadcrumb_failure ) {
					$summary['failures'][] = $breadcrumb_failure;
					break;
				}

				if ( ! $processor->is_tag_closer() ) {
					self::exercise_tag_attributes( $processor, $summary );
				}
			}

			if ( '#tag' === $type && ! $processor->is_tag_closer() && array() === $processor->get_breadcrumbs() ) {
				$summary['failures'][] = array( 'name' => 'empty-breadcrumbs-for-processor-token' );
				break;
			}
		}

		$summary['ok'] = array() === $summary['failures'];
		return $summary;
	}

	private static function empty_walk_summary(): array {
		return array(
			'ok'           => true,
			'tokenCount'   => 0,
			'tagCount'     => 0,
			'textCount'    => 0,
			'commentCount' => 0,
			'doctypeCount' => 0,
			'maxDepth'     => 0,
			'tokenNames'   => array(),
			'namespaces'   => array(),
			'failures'     => array(),
			'unchanged'    => true,
		);
	}

	private static function record_walk_token( array &$summary, string $type, string $name, string $namespace, int $depth ): void {
		if ( '#tag' === $type ) {
			++$summary['tagCount'];
		} elseif ( '#text' === $type ) {
			++$summary['textCount'];
		} elseif ( '#comment' === $type ) {
			++$summary['commentCount'];
		} elseif ( '#doctype' === $type ) {
			++$summary['doctypeCount'];
		}

		$summary['maxDepth'] = max( $summary['maxDepth'], $depth );
		if ( count( $summary['tokenNames'] ) < 80 ) {
			$summary['tokenNames'][] = $name;
		}
		$summary['namespaces'][ $namespace ] = ( $summary['namespaces'][ $namespace ] ?? 0 ) + 1;
	}

	private static function apply_breadcrumb_stack_invariant( \WP_HTML_Processor $processor, array $breadcrumb_prefix, array &$element_stack ): ?array {
		if ( '#tag' !== $processor->get_token_type() ) {
			return null;
		}

		if ( $processor->is_tag_closer() ) {
			array_pop( $element_stack );
			return self::breadcrumb_stack_mismatch( $processor, $breadcrumb_prefix, $element_stack, null );
		}

		$token_name = (string) $processor->get_token_name();
		$mismatch   = self::breadcrumb_stack_mismatch( $processor, $breadcrumb_prefix, $element_stack, $token_name );
		if ( null !== $mismatch ) {
			return $mismatch;
		}

		if ( true === $processor->expects_closer() ) {
			$element_stack[] = $token_name;
		}

		return null;
	}

	private static function breadcrumb_stack_mismatch( \WP_HTML_Processor $processor, array $prefix, array $element_stack, ?string $current ): ?array {
		$actual   = $processor->get_breadcrumbs();
		$expected = array_merge( $prefix, $element_stack );
		if ( null !== $current ) {
			$expected[] = $current;
		}

		if ( $expected === $actual ) {
			return null;
		}

		$divergence = 0;
		$limit      = min( count( $expected ), count( $actual ) );
		while ( $divergence < $limit && $expected[ $divergence ] === $actual[ $divergence ] ) {
			++$divergence;
		}

		return array(
			'name'            => 'breadcrumb-stack-mismatch',
			'divergenceDepth' => $divergence,
			'expectedDepth'   => count( $expected ),
			'actualDepth'     => count( $actual ),
			'expected'        => array_slice( $expected, 0, 40 ),
			'actual'          => array_slice( $actual, 0, 40 ),
			'tokenName'       => $processor->get_token_name(),
			'tokenType'       => $processor->get_token_type(),
			'isCloser'        => $processor->is_tag_closer(),
		);
	}

	private static function exercise_tag_attributes( \WP_HTML_Tag_Processor $processor, array &$summary ): void {
		$attrs = $processor->get_attribute_names_with_prefix( '' );
		if ( ! is_array( $attrs ) ) {
			return;
		}

		foreach ( $attrs as $attr ) {
			$processor->get_attribute( $attr );
			$qualified = $processor->get_qualified_attribute_name( $attr );
			if ( null !== $qualified && '' === $qualified ) {
				$summary['failures'][] = array(
					'name'      => 'empty-qualified-attribute-name',
					'attribute' => $attr,
				);
				return;
			}
		}

		if ( null !== $processor->get_attribute( 'class' ) ) {
			foreach ( $processor->class_list() as $_class_name ) {
				// Iteration itself is the invariant.
			}
		}
	}

	private static function check_tag_seek_consistency( string $html ): array {
		$processor  = new \WP_HTML_Tag_Processor( $html );
		$target     = self::bookmark_target( $html );
		$span_limit = self::seek_span_limit( $html );
		$index      = 0;
		$bookmarked = false;
		$window_complete = false;
		$first_pass = array();

		while ( $processor->next_token() ) {
			if ( $index > self::MAX_TOKENS ) {
				return array(
					'ok'      => false,
					'failure' => array( 'name' => 'first-pass-token-limit-exceeded' ),
				);
			}

			if ( ! $bookmarked && $index === $target ) {
				if ( ! $processor->set_bookmark( 'cfz-seek' ) ) {
					return array(
						'ok'      => false,
						'failure' => array( 'name' => 'set-bookmark-failed' ),
					);
				}
				$bookmarked = true;
			}

			if ( $bookmarked ) {
				$first_pass[] = self::tag_token_fingerprint( $processor );
				if ( count( $first_pass ) >= $span_limit ) {
					$window_complete = true;
					break;
				}
			}
			++$index;
		}

		if ( ! $bookmarked ) {
			return array( 'ok' => true, 'status' => 'no-tokens' );
		}

		if ( ! $window_complete ) {
			return array(
				'ok'        => true,
				'status'    => 'insufficient-tail',
				'target'    => $target,
				'tokenSpan' => count( $first_pass ),
			);
		}

		if ( ! $processor->seek( 'cfz-seek' ) ) {
			return array(
				'ok'      => false,
				'failure' => array( 'name' => 'seek-failed' ),
			);
		}

		$second_pass = array( self::tag_token_fingerprint( $processor ) );
		while ( count( $second_pass ) < count( $first_pass ) && $processor->next_token() ) {
			$second_pass[] = self::tag_token_fingerprint( $processor );
		}

		return self::compare_seek_passes( $first_pass, $second_pass, $target );
	}

	private static function check_html_processor_seek_consistency( string $html, string $mode ): array {
		$processor = self::create_html_processor( $html, $mode );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => true, 'status' => 'unsupported' );
		}

		$target     = self::bookmark_target( $html );
		$span_limit = self::seek_span_limit( $html );
		$index      = 0;
		$bookmarked = false;
		$window_complete = false;
		$first_pass = array();

		while ( $processor->next_token() ) {
			if ( $index > self::MAX_TOKENS ) {
				return array(
					'ok'      => false,
					'failure' => array( 'name' => 'first-pass-token-limit-exceeded' ),
				);
			}

			if ( ! $bookmarked && $index === $target ) {
				if ( ! $processor->set_bookmark( 'cfz-seek' ) ) {
					return array(
						'ok'      => false,
						'failure' => array( 'name' => 'set-bookmark-failed' ),
					);
				}
				$bookmarked = true;
			}

			if ( $bookmarked ) {
				$first_pass[] = self::html_token_fingerprint( $processor );
				if ( count( $first_pass ) >= $span_limit ) {
					$window_complete = true;
					break;
				}
			}
			++$index;
		}

		if ( ! $bookmarked ) {
			return array( 'ok' => true, 'status' => 'no-tokens' );
		}

		if ( ! $window_complete ) {
			return array(
				'ok'        => true,
				'status'    => 'insufficient-tail',
				'target'    => $target,
				'tokenSpan' => count( $first_pass ),
			);
		}

		if ( ! $processor->seek( 'cfz-seek' ) ) {
			return array(
				'ok'      => false,
				'failure' => array( 'name' => 'seek-failed' ),
			);
		}

		$second_pass = array( self::html_token_fingerprint( $processor ) );
		while ( count( $second_pass ) < count( $first_pass ) && $processor->next_token() ) {
			$second_pass[] = self::html_token_fingerprint( $processor );
		}

		return self::compare_seek_passes( $first_pass, $second_pass, $target );
	}

	private static function bookmark_target( string $html ): int {
		return abs( (int) crc32( $html ) ) % 7;
	}

	private static function seek_span_limit( string $html ): int {
		return 8 + ( abs( (int) crc32( 'span:' . $html ) ) % 13 );
	}

	private static function compare_seek_passes( array $first_pass, array $second_pass, int $target ): array {
		if ( $first_pass === $second_pass ) {
			return array(
				'ok'        => true,
				'target'    => $target,
				'tokenSpan' => count( $first_pass ),
			);
		}

		$divergence = 0;
		$limit      = min( count( $first_pass ), count( $second_pass ) );
		while ( $divergence < $limit && $first_pass[ $divergence ] === $second_pass[ $divergence ] ) {
			++$divergence;
		}

		return array(
			'ok'      => false,
			'failure' => array(
				'name'              => 'seek-token-stream-mismatch',
				'target'            => $target,
				'divergenceOffset'  => $divergence,
				'firstPassCount'    => count( $first_pass ),
				'secondPassCount'   => count( $second_pass ),
				'firstFingerprint'  => $first_pass[ $divergence ] ?? null,
				'secondFingerprint' => $second_pass[ $divergence ] ?? null,
			),
		);
	}

	private static function tag_token_fingerprint( \WP_HTML_Tag_Processor $processor ): string {
		$parts = array(
			(string) $processor->get_token_type(),
			(string) $processor->get_token_name(),
			(string) $processor->get_namespace(),
			$processor->is_tag_closer() ? '/' : '',
			(string) $processor->get_modifiable_text(),
		);

		self::append_attribute_fingerprint( $processor, $parts );
		return sha1( implode( "\x1f", $parts ) );
	}

	private static function html_token_fingerprint( \WP_HTML_Processor $processor ): string {
		$parts = array(
			(string) $processor->get_token_type(),
			(string) $processor->get_token_name(),
			(string) $processor->get_namespace(),
			$processor->is_tag_closer() ? '/' : '',
			(string) $processor->get_current_depth(),
			implode( '/', $processor->get_breadcrumbs() ),
			(string) $processor->get_modifiable_text(),
		);

		self::append_attribute_fingerprint( $processor, $parts );
		return sha1( implode( "\x1f", $parts ) );
	}

	private static function append_attribute_fingerprint( \WP_HTML_Tag_Processor $processor, array &$parts ): void {
		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			return;
		}

		$names = $processor->get_attribute_names_with_prefix( '' );
		if ( ! is_array( $names ) ) {
			return;
		}

		foreach ( $names as $name ) {
			$value   = $processor->get_attribute( $name );
			$parts[] = $name . '=' . ( true === $value ? '(true)' : (string) $value );
		}
	}

	private static function check_normalize_idempotence( string $html, string $mode, bool $expect_supported ): array {
		$errors           = array();
		$normalized       = null;
		$normalized_twice = null;
		$serialized       = null;
		$input_tree       = null;
		$normalized_tree  = null;
		$throwable        = null;

		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$errors ): bool {
				$errors[] = "{$errno}: {$errstr}";
				return true;
			}
		);

		try {
			$normalized       = self::normalize_html( $html, $mode );
			$normalized_twice = is_string( $normalized ) ? self::normalize_html( $normalized, $mode ) : null;
			$serialized       = is_string( $normalized ) ? self::serialize_html( $normalized, $mode ) : null;
			$input_tree       = self::processor_tree_fingerprint( $html, $mode );
			$normalized_tree  = is_string( $normalized ) ? self::processor_tree_fingerprint( $normalized, $mode ) : null;
		} catch ( \Throwable $e ) {
			$throwable = $e;
		} finally {
			restore_error_handler();
		}

		if ( null !== $throwable ) {
			return array(
				'ok'        => false,
				'status'    => 'throwable',
				'throwable' => self::describe_throwable( $throwable ),
			);
		}

		if ( array() !== $errors ) {
			return array(
				'ok'     => false,
				'status' => 'native-error',
				'errors' => $errors,
			);
		}

		if ( null === $normalized ) {
			return array(
				'ok'          => ! $expect_supported,
				'status'      => 'unsupported',
				'inputLength' => strlen( $html ),
			);
		}

		if ( null === $normalized_twice || $normalized !== $normalized_twice ) {
			return array(
				'ok'                    => false,
				'status'                => 'not-idempotent',
				'normalizedLength'      => strlen( $normalized ),
				'normalizedTwiceLength' => is_string( $normalized_twice ) ? strlen( $normalized_twice ) : null,
				'normalizedSha1'        => sha1( $normalized ),
				'normalizedTwiceSha1'   => is_string( $normalized_twice ) ? sha1( $normalized_twice ) : null,
				'firstDifference'       => is_string( $normalized_twice ) ? self::first_string_difference( $normalized, $normalized_twice ) : null,
			);
		}

		if ( null === $serialized || $serialized !== $normalized ) {
			return array(
				'ok'               => false,
				'status'           => 'serialize-mismatch',
				'normalizedLength' => strlen( $normalized ),
				'serializedLength' => is_string( $serialized ) ? strlen( $serialized ) : null,
				'normalizedSha1'   => sha1( $normalized ),
				'serializedSha1'   => is_string( $serialized ) ? sha1( $serialized ) : null,
			);
		}

		if ( str_contains( $normalized, "\0" ) ) {
			return array(
				'ok'     => false,
				'status' => 'nul-in-normalized-html',
			);
		}

		if (
			is_array( $input_tree )
			&& is_array( $normalized_tree )
			&& true === ( $input_tree['ok'] ?? false )
			&& true === ( $normalized_tree['ok'] ?? false )
			&& $input_tree['fingerprint'] !== $normalized_tree['fingerprint']
		) {
			return array(
				'ok'             => false,
				'status'         => 'normalize-tree-changed',
				'inputTree'      => $input_tree,
				'normalizedTree' => $normalized_tree,
			);
		}

		return array(
			'ok'               => true,
			'status'           => 'idempotent',
			'inputLength'      => strlen( $html ),
			'normalizedLength' => strlen( $normalized ),
			'normalizedSha1'   => sha1( $normalized ),
			'treeSha1'         => true === ( $normalized_tree['ok'] ?? false ) ? $normalized_tree['fingerprint'] : null,
		);
	}

	private static function normalize_html( string $html, string $mode ): ?string {
		if ( self::MODE_FULL_DOCUMENT === $mode ) {
			$processor = \WP_HTML_Processor::create_full_parser( $html );
			return $processor instanceof \WP_HTML_Processor ? $processor->serialize() : null;
		}

		return \WP_HTML_Processor::normalize( $html );
	}

	private static function serialize_html( string $html, string $mode ): ?string {
		$processor = self::create_html_processor( $html, $mode );
		return $processor instanceof \WP_HTML_Processor ? $processor->serialize() : null;
	}

	private static function processor_tree_fingerprint( string $html, string $mode ): array {
		$processor = self::create_html_processor( $html, $mode );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array(
				'ok'     => false,
				'status' => 'unsupported',
			);
		}

		$tokens        = 0;
		$parts         = array();
		$last_text_key = null;
		while ( $processor->next_token() ) {
			++$tokens;
			if ( $tokens > self::MAX_TOKENS ) {
				return array(
					'ok'         => false,
					'status'     => 'token-limit-exceeded',
					'tokenCount' => $tokens,
				);
			}

			$token_type = (string) $processor->get_token_type();
			if ( in_array( $token_type, array( '#text', '#cdata-section' ), true ) ) {
				$text_key = implode(
					"\x1f",
					array(
						$token_type,
						(string) $processor->get_token_name(),
						(string) $processor->get_namespace(),
						(string) $processor->get_current_depth(),
						implode( '/', $processor->get_breadcrumbs() ),
					)
				);

				if ( $last_text_key === $text_key && array() !== $parts ) {
					$parts[ count( $parts ) - 1 ] .= (string) $processor->get_modifiable_text();
				} else {
					$parts[]       = $text_key . "\x1f" . (string) $processor->get_modifiable_text();
					$last_text_key = $text_key;
				}
				continue;
			}

			$last_text_key = null;
			$token_parts = array(
				$token_type,
				(string) $processor->get_token_name(),
				(string) $processor->get_namespace(),
				$processor->is_tag_closer() ? '/' : '',
				(string) $processor->get_current_depth(),
				implode( '/', $processor->get_breadcrumbs() ),
				(string) $processor->get_modifiable_text(),
			);

			if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
				$attributes = array();
				$names      = $processor->get_attribute_names_with_prefix( '' );
				if ( is_array( $names ) ) {
					foreach ( $names as $name ) {
						$value        = $processor->get_attribute( $name );
						$qualified    = $processor->get_qualified_attribute_name( $name );
						$attributes[] = (string) $qualified . '=' . ( true === $value ? '(true)' : (string) $value );
					}
					sort( $attributes, SORT_STRING );
				}
				$token_parts[] = implode( "\x1e", $attributes );
			}

			$parts[] = implode( "\x1f", $token_parts );
		}

		if ( null !== $processor->get_last_error() ) {
			return array(
				'ok'         => false,
				'status'     => 'last-error',
				'lastError'  => $processor->get_last_error(),
				'tokenCount' => $tokens,
			);
		}

		if ( null !== $processor->get_unsupported_exception() || $processor->paused_at_incomplete_token() ) {
			return array(
				'ok'         => false,
				'status'     => 'unsupported',
				'tokenCount' => $tokens,
			);
		}

		return array(
			'ok'          => true,
			'status'      => 'ok',
			'tokenCount'  => $tokens,
			'fingerprint' => sha1( implode( "\x1d", $parts ) ),
		);
	}

	private static function create_html_processor( string $html, string $mode ) {
		if ( self::MODE_FULL_DOCUMENT === $mode ) {
			return \WP_HTML_Processor::create_full_parser( $html );
		}

		return \WP_HTML_Processor::create_fragment( $html );
	}

	private static function first_string_difference( string $a, string $b ): array {
		$max    = min( strlen( $a ), strlen( $b ) );
		$offset = 0;
		while ( $offset < $max && $a[ $offset ] === $b[ $offset ] ) {
			++$offset;
		}

		$window_start = max( 0, $offset - 16 );
		return array(
			'firstByteOffset' => $offset,
			'aDiffHex'        => bin2hex( substr( $a, $window_start, 64 ) ),
			'bDiffHex'        => bin2hex( substr( $b, $window_start, 64 ) ),
		);
	}

	private static function check_processor_text_mutation( array $case ): array {
		$processor = self::create_html_processor( $case['html'], $case['mode'] );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => true, 'status' => 'unsupported' );
		}

		while ( $processor->next_token() ) {
			if ( '#text' !== $processor->get_token_type() || ! str_contains( $processor->get_modifiable_text(), $case['textMarker'] ) ) {
				continue;
			}

			$updated = $processor->set_modifiable_text( $case['replacementText'] );
			$html    = $processor->get_updated_html();

			$round_trip = self::create_html_processor( $html, $case['mode'] );
			$found_text = false;
			if ( $round_trip instanceof \WP_HTML_Processor ) {
				while ( $round_trip->next_token() ) {
					if ( '#text' === $round_trip->get_token_type() && $case['replacementText'] === $round_trip->get_modifiable_text() ) {
						$found_text = true;
						break;
					}
				}
			}

			return array(
				'ok'        => $updated
					&& $found_text
					&& str_contains( $html, '&lt;' )
					&& str_contains( $html, '&amp;' )
					&& str_contains( $html, '&quot;' )
					&& str_contains( $html, '&apos;' ),
				'updated'   => $updated,
				'foundText' => $found_text,
				'html'      => $html,
			);
		}

		return array(
			'ok'      => false,
			'failure' => array( 'name' => 'text-marker-not-found' ),
		);
	}

	private static function collect_boundary_summary( string $html ): array {
		$processor = \WP_HTML_Processor::create_fragment( $html );
		$summary   = array(
			'sawCommentMarkup'        => false,
			'sawScriptRawText'        => false,
			'sawStyleRawText'         => false,
			'sawTextareaRcdata'       => false,
			'sawTitleRcdata'          => false,
			'sawSvgNamespace'         => false,
			'sawMathNamespace'        => false,
			'sawHtmlIntegrationPoint' => false,
		);

		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return $summary;
		}

		while ( $processor->next_token() ) {
			$name       = $processor->get_token_name();
			$type       = $processor->get_token_type();
			$namespace  = $processor->get_namespace();
			$text       = $processor->get_modifiable_text();
			$breadcrumbs = $processor->get_breadcrumbs();

			if ( '#comment' === $type && str_contains( $text, '<a href="comment">' ) ) {
				$summary['sawCommentMarkup'] = true;
			}
			if ( 'SCRIPT' === $name && str_contains( $text, '<span>' ) ) {
				$summary['sawScriptRawText'] = true;
			}
			if ( 'STYLE' === $name && str_contains( $text, 'a { color:red }' ) ) {
				$summary['sawStyleRawText'] = true;
			}
			if ( 'TEXTAREA' === $name && 'alpha < beta' === $text ) {
				$summary['sawTextareaRcdata'] = true;
			}
			if ( 'TITLE' === $name && 'T & C' === $text ) {
				$summary['sawTitleRcdata'] = true;
			}
			if ( 'svg' === $namespace ) {
				$summary['sawSvgNamespace'] = true;
			}
			if ( 'math' === $namespace ) {
				$summary['sawMathNamespace'] = true;
			}
			if (
				'P' === $name
				&& 'html' === $namespace
				&& (
					in_array( 'FOREIGNOBJECT', $breadcrumbs, true )
					|| in_array( 'ANNOTATION-XML', $breadcrumbs, true )
				)
			) {
				$summary['sawHtmlIntegrationPoint'] = true;
			}
		}

		return $summary;
	}

	private static function check_comment_mutation_boundary( string $html ): array {
		$processor = \WP_HTML_Processor::create_fragment( $html );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'unsupported' );
		}

		while ( $processor->next_token() ) {
			if ( '#comment' !== $processor->get_token_type() ) {
				continue;
			}

			$replacement = 'raw <b>comment</b>';
			$updated     = $processor->set_modifiable_text( $replacement );
			$html        = $processor->get_updated_html();
			$scan        = new \WP_HTML_Tag_Processor( $html );
			$saw_b       = false;
			while ( $scan->next_tag() ) {
				if ( 'B' === $scan->get_tag() ) {
					$saw_b = true;
					break;
				}
			}

			return array(
				'ok'      => $updated && ! $saw_b && str_contains( $html, '<!--' . $replacement . '-->' ),
				'updated' => $updated,
				'sawB'    => $saw_b,
			);
		}

		return array( 'ok' => false, 'status' => 'comment-not-found' );
	}

	private static function check_rawtext_mutation_boundary( string $html ): array {
		$processor = \WP_HTML_Processor::create_fragment( $html );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return array( 'ok' => false, 'status' => 'unsupported' );
		}

		while ( $processor->next_token() ) {
			if ( 'SCRIPT' !== $processor->get_token_name() ) {
				continue;
			}

			$replacement = '<a href=x>inside raw</a> & "q"';
			$updated     = $processor->set_modifiable_text( $replacement );
			$html        = $processor->get_updated_html();
			$scan        = new \WP_HTML_Tag_Processor( $html );
			$saw_a       = false;
			while ( $scan->next_tag() ) {
				if ( 'A' === $scan->get_tag() ) {
					$saw_a = true;
					break;
				}
			}

			$round_trip = \WP_HTML_Processor::create_fragment( $html );
			$script_text = null;
			if ( $round_trip instanceof \WP_HTML_Processor ) {
				while ( $round_trip->next_token() ) {
					if ( 'SCRIPT' === $round_trip->get_token_name() ) {
						$script_text = $round_trip->get_modifiable_text();
						break;
					}
				}
			}

			return array(
				'ok'         => $updated && ! $saw_a && $replacement === $script_text,
				'updated'    => $updated,
				'sawA'       => $saw_a,
				'scriptText' => $script_text,
			);
		}

		return array( 'ok' => false, 'status' => 'script-not-found' );
	}

	private static function case_profiles( array $cases ): array {
		$profiles = array();
		foreach ( $cases as $case ) {
			$profile = $case['profile'] ?? 'unknown';
			$profiles[ $profile ] = ( $profiles[ $profile ] ?? 0 ) + 1;
		}
		ksort( $profiles );
		return $profiles;
	}

	private static function case_summary( array $case ): array {
		return array(
			'token'      => $case['token'] ?? null,
			'profile'    => $case['profile'] ?? null,
			'mode'       => $case['mode'] ?? null,
			'htmlLength' => isset( $case['html'] ) ? strlen( $case['html'] ) : null,
			'htmlSha1'   => isset( $case['html'] ) ? sha1( $case['html'] ) : null,
			'html'       => $case['html'] ?? null,
		);
	}

	private static function safe_token( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$token = strtolower( $label . '-' . $ctx->identifier( 3, 10 ) . '-' . dechex( $ctx->seed() & 0xffff ) );
		$token = preg_replace( '/[^a-z0-9-]+/', '-', $token );
		$token = trim( (string) $token, '-' );
		return '' === $token ? 'token-' . dechex( $ctx->seed() & 0xffff ) : substr( $token, 0, 40 );
	}

	private static function safe_text( \ComponentFuzz\FuzzContext $ctx, int $min, int $max ): string {
		$text = preg_replace( '/[^A-Za-z0-9 ._:-]+/', ' ', $ctx->text( $min, $max ) );
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
