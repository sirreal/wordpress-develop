<?php
namespace ComponentFuzz\Surfaces;

final class CommentsSurface {
	public const NAME = 'comments';

	private const GENERATED_CASES = 8;
	private const SAMPLE_BYTES     = 120;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'comments.bootstrap-apis-available',
					'Required WordPress comment APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_globals();

		try {
			self::install_scoped_filters();

			$cases = self::comment_cases( $ctx );
			foreach ( $cases as $case_index => $case ) {
				$rows = array_merge( $rows, self::check_wp_filter_comment( $ctx, $case_index, $case ) );
				$rows = array_merge( $rows, self::check_max_lengths_shape( $ctx, $case_index, $case ) );
				$rows = array_merge( $rows, self::check_template_helpers( $ctx, $case_index, $case ) );
			}

			$rows = array_merge( $rows, self::check_max_length_oracles( $ctx ) );
			$rows = array_merge( $rows, self::check_separate_comments( $ctx, $cases ) );
			$rows = array_merge( $rows, self::check_comment_cookies( $ctx, $cases ) );
			$rows = array_merge( $rows, self::check_comment_permalink_pagination( $ctx ) );
			$rows = array_merge( $rows, self::check_comment_reply_links( $ctx ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'comments.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'add_filter',
				'remove_all_filters',
				'wp_filter_comment',
				'separate_comments',
				'sanitize_comment_cookies',
				'sanitize_text_field',
				'sanitize_email',
				'wp_sanitize_unicode_email',
				'sanitize_url',
				'convert_invalid_entities',
				'balanceTags',
				'wp_unslash',
				'esc_attr',
				'esc_html',
				'esc_url',
				'get_comment_author_link',
				'get_comment_author_url',
				'get_comment_author_url_link',
				'get_comment_author',
				'get_comment_excerpt',
				'get_comment_text',
				'get_comment_link',
				'get_comment_reply_link',
				'comment_reply_link',
				'get_post_reply_link',
				'post_reply_link',
				'get_cancel_comment_reply_link',
				'cancel_comment_reply_link',
				'get_page_of_comment',
				'get_permalink',
				'has_filter',
				'is_wp_error',
				'remove_query_arg',
				'wp_login_url',
				'wp_cache_delete',
				'wp_cache_set',
				'wp_parse_url',
				'wp_trim_words',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'WP_Comment', 'WP_Comment_Query', 'WP_Post', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		if ( ! class_exists( 'WP_Email_Address' ) ) {
			$missing[] = 'class WP_Email_Address';
		}

		return $missing;
	}

	private static function install_scoped_filters(): void {
		foreach (
			array(
				'pre_option_blog_charset',
				'pre_comment_author_name',
				'pre_comment_author_email',
				'pre_comment_author_url',
				'pre_comment_content',
				'pre_comment_user_agent',
				'pre_comment_user_ip',
				'pre_user_id',
				'sanitize_email',
				'is_email',
				'sanitize_text_field',
				'clean_url',
				'comment_email',
				'comment_class',
				'get_comment',
				'wp_get_comment_fields_max_lengths',
			) as $hook
		) {
			\remove_all_filters( $hook );
		}

		\add_filter(
			'pre_option_blog_charset',
			static function () {
				return 'UTF-8';
			},
			0,
			3
		);

		\add_filter( 'sanitize_email', 'wp_sanitize_unicode_email', 10, 3 );
		\add_filter( 'pre_comment_author_name', 'sanitize_text_field' );
		\add_filter( 'pre_comment_author_email', 'trim' );
		\add_filter( 'pre_comment_author_email', 'sanitize_email' );
		\add_filter( 'pre_comment_author_url', 'sanitize_url' );
		\add_filter( 'pre_comment_content', 'convert_invalid_entities' );
		\add_filter( 'pre_comment_content', 'balanceTags', 50 );
		\add_filter( 'pre_comment_user_agent', 'sanitize_text_field' );
		\add_filter( 'pre_comment_user_ip', 'sanitize_text_field' );
	}

	private static function check_wp_filter_comment( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case ): array {
		$comment = $case['comment'];
		$call    = self::call( static fn() => \wp_filter_comment( $comment ) );
		$rows    = array(
			self::case_result(
				$ctx,
				$case_index,
				$case,
				'comments.wp-filter-comment.no-throw-array',
				! $call['threw'] && is_array( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			),
		);

		if ( $call['threw'] || ! is_array( $call['value'] ) ) {
			return $rows;
		}

		$filtered = $call['value'];
		$expected = self::expected_filtered_comment( $comment );

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.wp-filter-comment.filtered-flag',
			true === ( $filtered['filtered'] ?? null ),
			array( 'actual' => $filtered['filtered'] ?? null )
		);

		$required_fields = array(
			'comment_author',
			'comment_author_email',
			'comment_author_url',
			'comment_content',
			'comment_author_IP',
			'comment_agent',
		);
		$missing_fields  = array();
		foreach ( $required_fields as $field ) {
			if ( ! array_key_exists( $field, $filtered ) || ! is_string( $filtered[ $field ] ) ) {
				$missing_fields[ $field ] = self::describe_value( $filtered[ $field ] ?? null );
			}
		}

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.wp-filter-comment.preserves-required-string-fields',
			array() === $missing_fields,
			array( 'missingOrNonString' => $missing_fields )
		);

		foreach (
			array(
				'comment_author'       => 'comments.wp-filter-comment.author-agrees-with-sanitize-text-field',
				'comment_author_email' => 'comments.wp-filter-comment.email-agrees-with-sanitize-email',
				'comment_author_url'   => 'comments.wp-filter-comment.url-agrees-with-sanitize-url',
				'comment_content'      => 'comments.wp-filter-comment.content-agrees-with-comment-content-filters',
				'comment_author_IP'    => 'comments.wp-filter-comment.ip-agrees-with-sanitize-text-field',
				'comment_agent'        => 'comments.wp-filter-comment.agent-agrees-with-sanitize-text-field',
			) as $field => $invariant
		) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				$invariant,
				array_key_exists( $field, $filtered ) && $expected[ $field ] === $filtered[ $field ],
				array(
					'field'    => $field,
					'expected' => self::describe_value( $expected[ $field ] ),
					'actual'   => self::describe_value( $filtered[ $field ] ?? null ),
				)
			);
		}

		foreach ( array( 'comment_post_ID', 'comment_parent', 'comment_type' ) as $field ) {
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'comments.wp-filter-comment.metadata-preserved.' . $field,
				! array_key_exists( $field, $comment ) || ( array_key_exists( $field, $filtered ) && $comment[ $field ] === $filtered[ $field ] ),
				array(
					'field'    => $field,
					'expected' => self::describe_value( $comment[ $field ] ?? null ),
					'actual'   => self::describe_value( $filtered[ $field ] ?? null ),
				)
			);
		}

		$expected_user_id = array_key_exists( 'user_ID', $comment ) ? $comment['user_ID'] : ( $comment['user_id'] ?? null );
		$rows[]           = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.wp-filter-comment.user-id-preserved-or-back-compat-mapped',
			$expected_user_id === ( $filtered['user_id'] ?? null ),
			array(
				'expected' => self::describe_value( $expected_user_id ),
				'actual'   => self::describe_value( $filtered['user_id'] ?? null ),
			)
		);

		$again = self::call( static fn() => \wp_filter_comment( $filtered ) );
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.wp-filter-comment.idempotent-after-scoped-sanitizers',
			! $again['threw'] && $filtered === $again['value'],
			array(
				'first'  => self::describe_comment( $filtered ),
				'second' => self::describe_call( $again ),
			)
		);

		return $rows;
	}

	private static function expected_filtered_comment( array $comment ): array {
		$expected = $comment;

		if ( array_key_exists( 'user_ID', $comment ) ) {
			$expected['user_id'] = $comment['user_ID'];
		} elseif ( array_key_exists( 'user_id', $comment ) ) {
			$expected['user_id'] = $comment['user_id'];
		}

		$expected['comment_agent']        = \sanitize_text_field( (string) ( $comment['comment_agent'] ?? '' ) );
		$expected['comment_author']       = \sanitize_text_field( (string) $comment['comment_author'] );
		$expected['comment_content']      = \balanceTags( \convert_invalid_entities( (string) $comment['comment_content'] ) );
		$expected['comment_author_IP']    = \sanitize_text_field( (string) $comment['comment_author_IP'] );
		$expected['comment_author_url']   = \sanitize_url( (string) $comment['comment_author_url'] );
		$expected['comment_author_email'] = \sanitize_email( trim( (string) $comment['comment_author_email'] ) );
		$expected['filtered']             = true;

		return $expected;
	}

	private static function check_max_lengths_shape( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case ): array {
		if ( ! function_exists( 'wp_check_comment_data_max_lengths' ) ) {
			return array(
				$ctx->skip(
					'comments.max-lengths.shape-no-throw',
					'wp_check_comment_data_max_lengths() is unavailable.',
					self::case_data( $case_index, $case )
				),
			);
		}

		$call = self::call(
			static fn() => self::with_non_mysql_wpdb(
				static fn() => \wp_check_comment_data_max_lengths( $case['comment'] )
			)
		);

		return array(
			self::case_result(
				$ctx,
				$case_index,
				$case,
				'comments.max-lengths.shape-no-throw',
				! $call['threw'] && ( true === $call['value'] || \is_wp_error( $call['value'] ) ),
				array( 'result' => self::describe_max_length_result( $call ) )
			),
		);
	}

	private static function check_max_length_oracles( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'wp_check_comment_data_max_lengths' ) || ! function_exists( 'wp_get_comment_fields_max_lengths' ) ) {
			return array(
				$ctx->skip(
					'comments.max-lengths.boundary-oracles',
					'Comment max length helpers are unavailable.'
				),
			);
		}

		$limits_call = self::call(
			static fn() => self::with_non_mysql_wpdb(
				static fn() => \wp_get_comment_fields_max_lengths()
			)
		);

		$rows = array(
			$ctx->result(
				'comments.max-lengths.limits-shape',
				! $limits_call['threw'] && is_array( $limits_call['value'] ) && array() === array_diff(
					array( 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_content' ),
					array_keys( $limits_call['value'] ?? array() )
				),
				array( 'limits' => self::describe_call( $limits_call ) )
			),
		);

		if ( $limits_call['threw'] || ! is_array( $limits_call['value'] ) ) {
			return $rows;
		}

		$limits = $limits_call['value'];
		$base   = self::base_comment();
		$within = $base;
		foreach ( $limits as $field => $limit ) {
			if ( isset( $within[ $field ] ) ) {
				$within[ $field ] = str_repeat( 'x', max( 0, (int) $limit ) );
			}
		}

		$within_call = self::call(
			static fn() => self::with_non_mysql_wpdb(
				static fn() => \wp_check_comment_data_max_lengths( $within )
			)
		);
		$rows[]      = $ctx->result(
			'comments.max-lengths.accepts-values-at-default-limits',
			! $within_call['threw'] && true === $within_call['value'],
			array(
				'lengths' => array_map( 'strlen', array_intersect_key( $within, array_flip( array_keys( $limits ) ) ) ),
				'result'  => self::describe_max_length_result( $within_call ),
			)
		);

		$error_codes = array(
			'comment_author'       => 'comment_author_column_length',
			'comment_author_email' => 'comment_author_email_column_length',
			'comment_author_url'   => 'comment_author_url_column_length',
			'comment_content'      => 'comment_content_column_length',
		);

		foreach ( $error_codes as $field => $error_code ) {
			$overlong           = $base;
			$overlong[ $field ] = str_repeat( 'x', (int) $limits[ $field ] + 1 );
			$call               = self::call(
				static fn() => self::with_non_mysql_wpdb(
					static fn() => \wp_check_comment_data_max_lengths( $overlong )
				)
			);
			$actual_code        = ( ! $call['threw'] && \is_wp_error( $call['value'] ) ) ? $call['value']->get_error_code() : null;

			$rows[] = $ctx->result(
				'comments.max-lengths.detects-overlong.' . $field,
				! $call['threw'] && $error_code === $actual_code,
				array(
					'field'       => $field,
					'limit'       => $limits[ $field ],
					'actualBytes' => strlen( $overlong[ $field ] ),
					'expected'    => $error_code,
					'result'      => self::describe_max_length_result( $call ),
				)
			);
		}

		return $rows;
	}

	private static function check_separate_comments( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$comments = array();
		foreach ( $cases as $case_index => $case ) {
			$comments[] = self::comment_object( $case['comment'], $case_index + 1 );
		}

		$original_ids = array_map( 'spl_object_id', $comments );
		$call         = self::call(
			static function () use ( &$comments ) {
				return \separate_comments( $comments );
			}
		);

		$rows = array(
			$ctx->result(
				'comments.separate-comments.no-throw-shape',
				! $call['threw'] && is_array( $call['value'] ),
				array( 'result' => self::describe_call( $call ) )
			),
		);

		if ( $call['threw'] || ! is_array( $call['value'] ) ) {
			return $rows;
		}

		$expected = array(
			'comment'   => array(),
			'trackback' => array(),
			'pingback'  => array(),
			'pings'     => array(),
		);
		foreach ( $comments as $comment ) {
			$type = (string) $comment->comment_type;
			if ( empty( $type ) ) {
				$type = 'comment';
			}
			$expected[ $type ][] = spl_object_id( $comment );
			if ( 'trackback' === $type || 'pingback' === $type ) {
				$expected['pings'][] = spl_object_id( $comment );
			}
		}

		$actual = array();
		foreach ( $call['value'] as $type => $bucket ) {
			$actual[ (string) $type ] = array_map( 'spl_object_id', $bucket );
		}

		$partition_ok = true;
		foreach ( $expected as $type => $ids ) {
			if ( $ids !== ( $actual[ $type ] ?? array() ) ) {
				$partition_ok = false;
				break;
			}
		}

		$rows[] = $ctx->result(
			'comments.separate-comments.partitions-by-comment-type',
			$partition_ok,
			array(
				'types'    => self::describe_type_counts( $expected ),
				'expected' => self::describe_partition_ids( $expected ),
				'actual'   => self::describe_partition_ids( $actual ),
			)
		);

		$ping_ids = $actual['pings'] ?? array();
		$rows[]   = $ctx->result(
			'comments.separate-comments.pings-are-trackbacks-and-pingbacks',
			$ping_ids === $expected['pings'],
			array(
				'expectedPings' => $expected['pings'],
				'actualPings'   => $ping_ids,
			)
		);

		$rows[] = $ctx->result(
			'comments.separate-comments.input-array-order-and-identity-preserved',
			$original_ids === array_map( 'spl_object_id', $comments ),
			array(
				'expected' => $original_ids,
				'actual'   => array_map( 'spl_object_id', $comments ),
			)
		);

		return $rows;
	}

	private static function check_template_helpers( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case ): array {
		$missing = array();
		foreach ( array( 'get_comment_class', 'get_comment_author_email_link', 'get_comment_author_link', 'get_comment_author_url', 'get_comment_author_url_link', 'get_comment_excerpt', 'get_comment_text', 'get_comment' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = $function;
			}
		}
		if ( ! class_exists( 'WP_Comment' ) ) {
			$missing[] = 'WP_Comment';
		}

		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'comments.template-helpers.available',
					'Comment template helpers are unavailable.',
					self::case_data( $case_index, $case, array( 'missing' => implode( ', ', $missing ) ) )
				),
			);
		}

		$comment          = self::comment_object( $case['comment'], $case_index + 100 );
		$comment->user_id = '0';
		$depth            = 1 + ( $case_index % 3 );
		$extra_class      = "fuzz-extra extra-{$case_index} <tag>";
		$class_snapshot   = self::snapshot_globals();
		$class_call       = self::call(
			static function () use ( $comment, $depth, $extra_class ) {
				$GLOBALS['comment_alt']        = 0;
				$GLOBALS['comment_depth']      = $depth;
				$GLOBALS['comment_thread_alt'] = 0;

				return \get_comment_class( $extra_class, $comment, null );
			}
		);
		self::restore_globals( $class_snapshot );

		$type          = empty( $comment->comment_type ) ? 'comment' : (string) $comment->comment_type;
		$expected_type = \esc_attr( $type );
		$classes       = ( ! $class_call['threw'] && is_array( $class_call['value'] ) ) ? $class_call['value'] : array();

		$rows = array(
			self::case_result(
				$ctx,
				$case_index,
				$case,
				'comments.template.get-comment-class.no-throw-list',
				! $class_call['threw'] && is_array( $class_call['value'] ) && self::all_strings( $class_call['value'] ),
				array( 'result' => self::describe_call( $class_call ) )
			),
			self::case_result(
				$ctx,
				$case_index,
				$case,
				'comments.template.get-comment-class.includes-type-parity-depth',
				in_array( $expected_type, $classes, true ) && in_array( 'even', $classes, true ) && in_array( "depth-{$depth}", $classes, true ),
				array(
					'expectedType' => self::describe_value( $expected_type ),
					'depth'        => $depth,
					'classes'      => self::describe_value( $classes ),
				)
			),
			self::case_result(
				$ctx,
				$case_index,
				$case,
				'comments.template.get-comment-class.escapes-class-tokens',
				self::classes_have_no_raw_angle_brackets( $classes ),
				array( 'classes' => self::describe_value( $classes ) )
			),
		);

		$email     = (string) $comment->comment_author_email;
		$link_text = 0 === $case_index % 2 ? 'Contact <author>' : '';
		$before    = '[';
		$after     = ']';
		$link_call = self::call(
			static fn() => \get_comment_author_email_link( $link_text, $before, $after, $comment )
		);
		$display   = '' !== $link_text ? $link_text : $email;
		$expected  = ( '' !== $email && '@' !== $email )
			? $before . sprintf( '<a href="%1$s">%2$s</a>', \esc_url( 'mailto:' . $email ), \esc_html( $display ) ) . $after
			: '';

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.template.email-link.no-throw-string',
			! $link_call['threw'] && is_string( $link_call['value'] ),
			array( 'result' => self::describe_call( $link_call ) )
		);
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.template.email-link.matches-escaped-mailto-format',
			! $link_call['threw'] && $expected === $link_call['value'],
			array(
				'expected' => self::describe_value( $expected ),
				'actual'   => self::describe_call( $link_call ),
			)
		);

		$url_call = self::call( static fn() => \get_comment_author_url( $comment ) );
		$expected_url = 'http://' === $comment->comment_author_url
			? ''
			: \esc_url( $comment->comment_author_url, array( 'http', 'https' ) );
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.template.author-url.matches-http-https-escaped-url',
			! $url_call['threw'] && $expected_url === $url_call['value'],
			array(
				'expected' => self::describe_value( $expected_url ),
				'actual'   => self::describe_call( $url_call ),
			)
		);

		$author_name_call = self::call( static fn() => \get_comment_author( $comment ) );
		$author_link_call = self::call( static fn() => \get_comment_author_link( $comment ) );
		$author_link      = ! $author_link_call['threw'] && is_string( $author_link_call['value'] ) ? $author_link_call['value'] : '';
		$author_name      = ! $author_name_call['threw'] && is_string( $author_name_call['value'] ) ? $author_name_call['value'] : null;
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.template.author-link.href-and-rel-follow-sanitized-url',
			! $author_link_call['threw']
				&& is_string( $author_link_call['value'] )
				&& self::author_link_matches_url_contract( $author_link, $expected_url, $author_name ),
			array(
				'expectedUrl' => self::describe_value( $expected_url ),
				'authorName'  => self::describe_call( $author_name_call ),
				'actual'      => self::describe_call( $author_link_call ),
			)
		);

		$url_link_call = self::call( static fn() => \get_comment_author_url_link( 'Visit <site>', '{', '}', $comment ) );
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.template.author-url-link-uses-sanitized-href',
			! $url_link_call['threw']
				&& is_string( $url_link_call['value'] )
				&& str_starts_with( $url_link_call['value'], '{<a href="' . $expected_url . '" rel="external">' )
				&& str_ends_with( $url_link_call['value'], '</a>}' )
				&& ! str_contains( $url_link_call['value'], 'javascript:' ),
			array(
				'expectedUrl' => self::describe_value( $expected_url ),
				'actual'      => self::describe_call( $url_link_call ),
			)
		);

		$text_call = self::call( static fn() => \get_comment_text( $comment, array( 'component_fuzz' => true ) ) );
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.template.get-comment-text-preserves-comment-content',
			! $text_call['threw'] && $comment->comment_content === $text_call['value'],
			array(
				'expected' => self::describe_value( $comment->comment_content ),
				'actual'   => self::describe_call( $text_call ),
			)
		);

		$excerpt_call = self::call( static fn() => \get_comment_excerpt( $comment ) );
		$expected_excerpt = \wp_trim_words(
			strip_tags( str_replace( array( "\n", "\r" ), ' ', $comment->comment_content ) ),
			20,
			'&hellip;'
		);
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.template.get-comment-excerpt-strips-tags-and-trims',
			! $excerpt_call['threw'] && $expected_excerpt === $excerpt_call['value'],
			array(
				'expected' => self::describe_value( $expected_excerpt ),
				'actual'   => self::describe_call( $excerpt_call ),
			)
		);

		return $rows;
	}

	private static function check_comment_cookies( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		if ( ! defined( 'COOKIEHASH' ) ) {
			return array(
				$ctx->skip( 'comments.cookies.cookiehash-defined', 'COOKIEHASH is not defined.' ),
			);
		}

		$hash  = (string) constant( 'COOKIEHASH' );
		$rows  = array();
		$limit = min( 8, count( $cases ) );

		for ( $i = 0; $i < $limit; ++$i ) {
			$case    = $cases[ $i ];
			$comment = $case['comment'];
			$cookies = array(
				'comment_author_' . $hash       => $comment['comment_author'],
				'comment_author_email_' . $hash => $comment['comment_author_email'],
				'comment_author_url_' . $hash   => $comment['comment_author_url'],
			);

			if ( 0 === $i % 3 ) {
				unset( $cookies[ 'comment_author_url_' . $hash ] );
			}

			$snapshot = self::snapshot_globals();
			$call     = self::call(
				static function () use ( $cookies ) {
					$_COOKIE = $cookies;
					\sanitize_comment_cookies();
					return $_COOKIE;
				}
			);
			$once     = $call['value'];
			$again    = self::call(
				static function () use ( $once ) {
					$_COOKIE = $once;
					\sanitize_comment_cookies();
					return $_COOKIE;
				}
			);
			self::restore_globals( $snapshot );

			$expected = self::expected_sanitized_cookies( $cookies, $hash );
			$expected_second = self::expected_sanitized_cookies( $expected, $hash );
			$rows[]   = self::case_result(
				$ctx,
				$i,
				$case,
				'comments.cookies.sanitize-comment-cookies.no-throw-array',
				! $call['threw'] && is_array( $call['value'] ),
				array( 'result' => self::describe_call( $call ) )
			);
			$rows[]   = self::case_result(
				$ctx,
				$i,
				$case,
				'comments.cookies.sanitize-comment-cookies.matches-lower-level-sanitizers',
				! $call['threw'] && $expected === $call['value'],
				array(
					'expected' => self::describe_cookie_set( $expected ),
					'actual'   => self::describe_call( $call ),
				)
			);
			$rows[]   = self::case_result(
				$ctx,
				$i,
				$case,
				'comments.cookies.sanitize-comment-cookies.repeat-matches-lower-level-sanitizers',
				! $again['threw'] && $expected_second === $again['value'],
				array(
					'expectedSecond' => self::describe_cookie_set( $expected_second ),
					'actualSecond'   => self::describe_call( $again ),
				)
			);
			$rows[]   = self::case_result(
				$ctx,
				$i,
				$case,
				'comments.cookies.sanitize-comment-cookies.idempotent-when-output-is-stable',
				$expected !== $expected_second || ( ! $call['threw'] && ! $again['threw'] && $call['value'] === $again['value'] ),
				array(
					'stableExpected' => $expected === $expected_second,
					'first'          => self::describe_call( $call ),
					'second'         => self::describe_call( $again ),
				)
			);
		}

		return $rows;
	}

	private static function check_comment_permalink_pagination( \ComponentFuzz\FuzzContext $ctx ): array {
		$fixture      = self::comment_permalink_fixture( $ctx );
		$query_events = array();
		$options      = array(
			'page_comments'          => 1,
			'comments_per_page'      => 2,
			'default_comments_page'  => 'newest',
			'thread_comments'        => 1,
			'thread_comments_depth'  => 5,
			'permalink_structure'    => '',
		);
		$snapshot     = self::snapshot_globals();

		try {
			self::cache_comment_permalink_fixture( $fixture );
			self::force_query_style_comment_links();
			\remove_all_filters( 'comments_pre_query' );
			\remove_all_filters( 'get_page_of_comment' );
			\remove_all_filters( 'get_page_of_comment_query_args' );
			\remove_all_filters( 'get_comment_link' );

			foreach ( array_keys( $options ) as $option ) {
				\add_filter(
					'pre_option_' . $option,
					static function () use ( &$options, $option ) {
						return $options[ $option ];
					},
					10,
					3
				);
			}

			\add_filter(
				'comments_pre_query',
				static function ( $comment_data, \WP_Comment_Query $query ) use ( $fixture, &$query_events ) {
					if ( empty( $query->query_vars['count'] ) ) {
						return $comment_data;
					}

					$count          = self::count_permalink_fixture_comments( $fixture['comments'], $query->query_vars );
					$query_events[] = array(
						'postId' => (int) ( $query->query_vars['post_id'] ?? 0 ),
						'type'   => (string) ( $query->query_vars['type'] ?? 'all' ),
						'parent' => (string) ( $query->query_vars['parent'] ?? '' ),
						'before' => self::query_date_before( $query->query_vars ),
						'count'  => $count,
					);

					$query->found_comments = $count;
					$query->max_num_pages  = $count;
					return $count;
				},
				10,
				2
			);

			$scenarios = array(
				array(
					'name'    => 'comment-type-counts-empty-and-comment-roots',
					'comment' => 'middle-comment',
					'args'    => array(
						'type'      => 'comment',
						'per_page'  => 2,
						'max_depth' => 1,
					),
					'options' => array(
						'default_comments_page' => 'newest',
					),
				),
				array(
					'name'    => 'post-id-constraint-excludes-off-post-older-comments',
					'comment' => 'first-comment',
					'args'    => array(
						'type'      => 'comment',
						'per_page'  => 2,
						'max_depth' => 1,
					),
					'options' => array(
						'default_comments_page' => 'newest',
					),
				),
				array(
					'name'    => 'parent-constraint-excludes-child-comments',
					'comment' => 'middle-comment',
					'args'    => array(
						'type'      => 'comment',
						'per_page'  => 3,
						'max_depth' => 1,
					),
					'options' => array(
						'default_comments_page' => 'newest',
					),
				),
				array(
					'name'    => 'pings-type-counts-pingback-and-trackback',
					'comment' => 'first-trackback',
					'args'    => array(
						'type'      => 'pings',
						'per_page'  => 1,
						'max_depth' => 1,
					),
					'options' => array(
						'default_comments_page' => 'newest',
					),
				),
				array(
					'name'    => 'threaded-child-resolves-to-top-level-parent-page',
					'comment' => 'child-of-middle-comment',
					'args'    => array(
						'type'      => 'comment',
						'per_page'  => 2,
						'max_depth' => 5,
					),
					'options' => array(
						'default_comments_page' => 'newest',
					),
				),
				array(
					'name'    => 'oldest-default-first-page-omits-cpage',
					'comment' => 'first-empty-comment',
					'args'    => array(
						'type'      => 'comment',
						'per_page'  => 2,
						'max_depth' => 1,
					),
					'options' => array(
						'default_comments_page' => 'oldest',
					),
				),
			);

			$scenario_results = array();
			$base_options     = $options;
			foreach ( $scenarios as $scenario ) {
				$options         = array_merge( $base_options, $scenario['options'] );
				$comment_id      = $fixture['aliases'][ $scenario['comment'] ];
				$args            = $scenario['args'];
				$expected_page   = self::expected_permalink_comment_page( $fixture['comments'], $comment_id, $args, $options );
				$query_offset    = count( $query_events );
				$page_call       = self::call( static fn() => \get_page_of_comment( $comment_id, $args ) );
				$link_call       = self::call( static fn() => \get_comment_link( $comment_id, $args ) );
				$scenario_queries = array_slice( $query_events, $query_offset );
				$link            = ! $link_call['threw'] && is_string( $link_call['value'] ) ? $link_call['value'] : '';
				$expects_cpage   = ! ( 'oldest' === $options['default_comments_page'] && 1 === $expected_page );
				$scenario_results[] = array(
					'name'          => $scenario['name'],
					'commentId'     => $comment_id,
					'expectedPage'  => $expected_page,
					'actualPage'    => self::describe_call( $page_call ),
					'link'          => self::describe_call( $link_call ),
					'anchorOk'      => str_ends_with( $link, '#comment-' . $comment_id ),
					'cpageOk'       => $expects_cpage ? self::comment_link_has_cpage( $link, $expected_page ) : ! self::comment_link_has_any_cpage( $link ),
					'pageOk'        => ! $page_call['threw'] && $expected_page === $page_call['value'],
					'linkNoThrow'   => ! $link_call['threw'] && is_string( $link_call['value'] ),
					'queryShapeOk'  => self::permalink_query_events_match( $scenario_queries, $fixture['comments'], $comment_id, $args, $options ),
					'queries'       => $scenario_queries,
					'expectedCpage' => $expects_cpage ? $expected_page : null,
				);
			}
			$options = $base_options;

			$invalid_args        = array(
				'type'      => 'comment',
				'per_page'  => 0,
				'max_depth' => 1,
			);
			$invalid_comment_id  = $fixture['aliases']['last-comment'];
			$invalid_page_call   = self::call( static fn() => \get_page_of_comment( $invalid_comment_id, $invalid_args ) );
			$override_before     = count( $query_events );
			$override_comment_id = $fixture['aliases']['last-comment'];
			$override_call       = self::call(
				static fn() => \get_comment_link(
					$override_comment_id,
					array(
						'type'      => 'comment',
						'per_page'  => 2,
						'max_depth' => 1,
						'cpage'     => 9,
					)
				)
			);
			$override_link       = ! $override_call['threw'] && is_string( $override_call['value'] ) ? $override_call['value'] : '';
			$override_query_ok   = $override_before === count( $query_events );

			$rows = array(
				$ctx->result(
					'comments.permalink.page-of-comment-link-contract',
					self::all_permalink_scenarios_ok( $scenario_results ),
					array(
						'scenarios' => $scenario_results,
						'queries'   => $query_events,
					)
				),
				$ctx->result(
					'comments.permalink.invalid-per-page-falls-back-to-first-page',
					! $invalid_page_call['threw'] && 1 === $invalid_page_call['value'],
					array(
						'commentId' => $invalid_comment_id,
						'args'      => $invalid_args,
						'actual'    => self::describe_call( $invalid_page_call ),
					)
				),
				$ctx->result(
					'comments.permalink.explicit-cpage-overrides-page-query',
					! $override_call['threw']
						&& is_string( $override_call['value'] )
						&& str_ends_with( $override_link, '#comment-' . $override_comment_id )
						&& self::comment_link_has_cpage( $override_link, 9 )
						&& $override_query_ok,
					array(
						'commentId'       => $override_comment_id,
						'actual'          => self::describe_call( $override_call ),
						'queryCountBefore' => $override_before,
						'queryCountAfter' => count( $query_events ),
					)
				),
			);
		} finally {
			self::clear_comment_permalink_fixture_cache( $fixture );
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	private static function check_comment_reply_links( \ComponentFuzz\FuzzContext $ctx ): array {
		$fixture        = self::comment_permalink_fixture( $ctx->fork( 'reply-links' ) );
		$post           = $fixture['posts'][0];
		$comment_id     = $fixture['aliases']['middle-comment'];
		$comment        = $fixture['comments'][ $comment_id ];
		$closed_post    = self::permalink_post( $post->ID + 77, 'Closed Reply Fixture ' . $ctx->iteration() );
		$closed_comment = self::permalink_comment( $comment_id + 77, $closed_post->ID, 'comment', 0, '2026-06-01 00:01:00' );

		$closed_post->comment_status = 'closed';

		$reply_marker  = 'cfz-reply-' . strtolower( $ctx->identifier( 4, 10 ) );
		$post_marker   = 'cfz-post-' . strtolower( $ctx->identifier( 4, 10 ) );
		$cancel_marker = 'cfz-cancel-' . strtolower( $ctx->identifier( 4, 10 ) );
		$options       = array(
			'comment_registration' => 0,
			'home'                 => 'http://example.test',
			'page_comments'        => 0,
			'permalink_structure'  => '',
			'siteurl'              => 'http://example.test',
		);
		$args_events   = array();
		$reply_events  = array();
		$post_events   = array();
		$cancel_events = array();
		$snapshot      = self::snapshot_globals();

		$args_filter = static function ( array $args, \WP_Comment $filtered_comment, \WP_Post $filtered_post ) use (
			&$args_events,
			$reply_marker
		): array {
			$args_events[] = array(
				'commentId' => (int) $filtered_comment->comment_ID,
				'postId'    => (int) $filtered_post->ID,
				'depth'     => (int) $args['depth'],
				'maxDepth'  => (int) $args['max_depth'],
				'replyText' => self::describe_value( (string) $args['reply_text'] ),
			);
			$args['reply_text'] .= ' ' . $reply_marker;
			$args['login_text'] .= ' ' . $reply_marker;
			return $args;
		};

		$reply_filter = static function ( string $link, array $args, \WP_Comment $filtered_comment, \WP_Post $filtered_post ) use (
			&$reply_events,
			$reply_marker
		): string {
			$reply_events[] = array(
				'commentId' => (int) $filtered_comment->comment_ID,
				'postId'    => (int) $filtered_post->ID,
				'link'      => self::describe_value( $link ),
				'args'      => array(
					'depth'     => $args['depth'] ?? null,
					'respondId' => $args['respond_id'] ?? null,
				),
			);

			return str_replace( 'comment-reply-link', 'comment-reply-link ' . \esc_attr( $reply_marker ), $link );
		};

		$post_filter = static function ( string $link, $filtered_post ) use ( &$post_events, $post_marker ): string {
			$post_id       = $filtered_post instanceof \WP_Post ? $filtered_post->ID : (int) $filtered_post;
			$post_events[] = array(
				'postId' => $post_id,
				'link'   => self::describe_value( $link ),
			);

			return str_replace( '<a ', '<a data-cfz-post="' . \esc_attr( $post_marker ) . '" ', $link );
		};

		$cancel_filter = static function ( string $link, string $url, string $text ) use ( &$cancel_events, $cancel_marker ): string {
			$cancel_events[] = array(
				'url'  => self::describe_value( $url ),
				'text' => self::describe_value( $text ),
				'link' => self::describe_value( $link ),
			);

			return str_replace( '<a ', '<a data-cfz-cancel="' . \esc_attr( $cancel_marker ) . '" ', $link );
		};

		try {
			$fixture['posts'][]                                   = $closed_post;
			$fixture['comments'][ $closed_comment->comment_ID ]   = $closed_comment;
			self::cache_comment_permalink_fixture( $fixture );
			self::force_query_style_comment_links();

			$GLOBALS['post']       = $post;
			$GLOBALS['comment']    = $comment;
			$_SERVER['HTTP_HOST']  = 'example.test';
			$_SERVER['REQUEST_URI'] = '/comments/reply/?replytocom=' . rawurlencode( (string) $comment_id ) . '&unapproved=1&moderation-hash=bad&keep=1';
			$_GET                  = array(
				'keep'            => '1',
				'moderation-hash' => 'bad',
				'replytocom'      => (string) $comment_id,
				'unapproved'      => '1',
			);

			foreach ( array_keys( $options ) as $option ) {
				\add_filter(
					'pre_option_' . $option,
					static function () use ( &$options, $option ) {
						return $options[ $option ];
					},
					10,
					3
				);
			}
			\add_filter( 'comment_reply_link_args', $args_filter, 10, 3 );
			\add_filter( 'comment_reply_link', $reply_filter, 10, 4 );
			\add_filter( 'post_comments_link', $post_filter, 10, 2 );
			\add_filter( 'cancel_comment_reply_link', $cancel_filter, 10, 3 );

			if ( function_exists( 'wp_set_current_user' ) ) {
				\wp_set_current_user( 0 );
			}

			$reply_args = array(
				'add_below'          => 'comment-cfz',
				'after'              => '</span>',
				'before'             => '<span class="reply-before">',
				'depth'              => 2,
				'login_text'         => 'Sign in to reply',
				'max_depth'          => 5,
				'reply_text'         => 'Reply now',
				'reply_to_text'      => 'Reply to %s <unsafe>',
				'respond_id'         => 'respond-cfz',
				'show_reply_to_text' => false,
			);
			$reply_call = self::call( static fn() => \get_comment_reply_link( $reply_args, $comment, $post ) );
			ob_start();
			\comment_reply_link( $reply_args, $comment, $post );
			$reply_echo = (string) ob_get_clean();

			$depth_gate_call = self::call(
				static fn() => \get_comment_reply_link(
					array_merge(
						$reply_args,
						array(
							'depth'     => 5,
							'max_depth' => 5,
						)
					),
					$comment,
					$post
				)
			);
			$closed_call     = self::call( static fn() => \get_comment_reply_link( $reply_args, $closed_comment, $closed_post ) );

			$options['comment_registration'] = 1;
			$login_call = self::call( static fn() => \get_comment_reply_link( $reply_args, $comment, $post ) );
			$options['comment_registration'] = 0;

			$post_args = array(
				'add_below'  => 'post-cfz',
				'after'      => '</section>',
				'before'     => '<section class="post-before">',
				'login_text' => 'Sign in for post reply',
				'reply_text' => 'Leave a deterministic comment',
				'respond_id' => 'respond-post-cfz',
			);
			$post_reply_call = self::call( static fn() => \get_post_reply_link( $post_args, $post ) );
			ob_start();
			\post_reply_link( $post_args, $post );
			$post_reply_echo = (string) ob_get_clean();

			$cancel_call = self::call( static fn() => \get_cancel_comment_reply_link( 'Cancel reply now', $post ) );
			ob_start();
			\cancel_comment_reply_link( 'Cancel reply now' );
			$cancel_echo = (string) ob_get_clean();

			$_GET['replytocom'] = 'not-a-number';
			$hidden_cancel_call = self::call( static fn() => \get_cancel_comment_reply_link( 'Cancel hidden reply', $post ) );
		} finally {
			foreach ( array_keys( $options ) as $option ) {
				\remove_all_filters( 'pre_option_' . $option );
			}
			\remove_all_filters( 'comment_reply_link_args' );
			\remove_all_filters( 'comment_reply_link' );
			\remove_all_filters( 'post_comments_link' );
			\remove_all_filters( 'cancel_comment_reply_link' );
			self::clear_comment_permalink_fixture_cache( $fixture );
			self::restore_globals( $snapshot );
		}

		$reply      = ! $reply_call['threw'] && is_string( $reply_call['value'] ) ? $reply_call['value'] : '';
		$login      = ! $login_call['threw'] && is_string( $login_call['value'] ) ? $login_call['value'] : '';
		$post_reply = ! $post_reply_call['threw'] && is_string( $post_reply_call['value'] ) ? $post_reply_call['value'] : '';
		$cancel     = ! $cancel_call['threw'] && is_string( $cancel_call['value'] ) ? $cancel_call['value'] : '';
		$hidden     = ! $hidden_cancel_call['threw'] && is_string( $hidden_cancel_call['value'] ) ? $hidden_cancel_call['value'] : '';

		return array(
			$ctx->result(
				'comments.reply-links.comment-reply-normal-login-and-gates',
				! $reply_call['threw']
					&& str_contains( $reply, '<span class="reply-before">' )
					&& str_contains( $reply, 'class="comment-reply-link ' . \esc_attr( $reply_marker ) . '"' )
					&& str_contains( $reply, 'replytocom=' . $comment_id )
					&& str_contains( $reply, '#respond-cfz' )
					&& str_contains( $reply, 'data-commentid="' . $comment_id . '"' )
					&& str_contains( $reply, 'data-postid="' . $post->ID . '"' )
					&& str_contains( $reply, 'data-belowelement="comment-cfz-' . $comment_id . '"' )
					&& str_contains( $reply, 'data-respondelement="respond-cfz"' )
					&& str_contains( $reply, 'aria-label="Reply to Commenter ' . $comment_id . ' &lt;unsafe&gt;"' )
					&& str_contains( $reply, 'Reply now ' . $reply_marker )
					&& ! str_contains( $reply, '<unsafe>' )
					&& $reply === $reply_echo
					&& ! $depth_gate_call['threw']
					&& null === $depth_gate_call['value']
					&& ! $closed_call['threw']
					&& false === $closed_call['value']
					&& ! $login_call['threw']
					&& str_contains( $login, 'class="comment-reply-login"' )
					&& str_contains( $login, 'wp-login.php' )
					&& str_contains( $login, 'Sign in to reply ' . $reply_marker ),
				array(
					'closed'    => self::describe_call( $closed_call ),
					'depthGate' => self::describe_call( $depth_gate_call ),
					'login'     => self::describe_call( $login_call ),
					'reply'     => self::describe_call( $reply_call ),
					'replyEcho' => self::describe_value( $reply_echo ),
				)
			),
			$ctx->result(
				'comments.reply-links.post-reply-and-cancel-contracts',
				! $post_reply_call['threw']
					&& str_contains( $post_reply, '<section class="post-before">' )
					&& str_contains( $post_reply, 'data-cfz-post="' . \esc_attr( $post_marker ) . '"' )
					&& str_contains( $post_reply, "class='comment-reply-link'" )
					&& str_contains( $post_reply, '#respond-post-cfz' )
					&& str_contains( $post_reply, 'post-cfz-' . $post->ID )
					&& str_contains( $post_reply, 'Leave a deterministic comment' )
					&& $post_reply === $post_reply_echo
					&& ! $cancel_call['threw']
					&& str_contains( $cancel, 'data-cfz-cancel="' . \esc_attr( $cancel_marker ) . '"' )
					&& str_contains( $cancel, 'id="cancel-comment-reply-link"' )
					&& str_contains( $cancel, 'keep=1#respond' )
					&& ! str_contains( $cancel, 'replytocom=' )
					&& ! str_contains( $cancel, 'unapproved=' )
					&& ! str_contains( $cancel, 'moderation-hash=' )
					&& ! str_contains( $cancel, 'style="display:none;"' )
					&& $cancel === $cancel_echo
					&& ! $hidden_cancel_call['threw']
					&& str_contains( $hidden, 'style="display:none;"' ),
				array(
					'cancel'       => self::describe_call( $cancel_call ),
					'cancelEcho'   => self::describe_value( $cancel_echo ),
					'hiddenCancel' => self::describe_call( $hidden_cancel_call ),
					'postEcho'     => self::describe_value( $post_reply_echo ),
					'postReply'    => self::describe_call( $post_reply_call ),
				)
			),
			$ctx->result(
				'comments.reply-links.filters-payloads-and-restoration',
				0 < count( $args_events )
					&& 0 < count( $reply_events )
					&& 0 < count( $post_events )
					&& 0 < count( $cancel_events )
					&& false === \has_filter( 'comment_reply_link_args', $args_filter )
					&& false === \has_filter( 'comment_reply_link', $reply_filter )
					&& false === \has_filter( 'post_comments_link', $post_filter )
					&& false === \has_filter( 'cancel_comment_reply_link', $cancel_filter ),
				array(
					'argsEvents'   => $args_events,
					'cancelEvents' => $cancel_events,
					'postEvents'   => $post_events,
					'replyEvents'  => $reply_events,
				)
			),
		);
	}

	private static function all_permalink_scenarios_ok( array $scenario_results ): bool {
		foreach ( $scenario_results as $result ) {
			if (
				empty( $result['pageOk'] )
				|| empty( $result['linkNoThrow'] )
				|| empty( $result['anchorOk'] )
				|| empty( $result['cpageOk'] )
				|| empty( $result['queryShapeOk'] )
			) {
				return false;
			}
		}

		return true;
	}

	private static function permalink_query_events_match( array $events, array $comments, int $comment_id, array $args, array $options ): bool {
		$target = self::permalink_query_target_comment( $comments, $comment_id, $args, $options );
		if ( ! $target instanceof \WP_Comment || 2 !== count( $events ) ) {
			return false;
		}

		$expected_type  = (string) ( $args['type'] ?? 'all' );
		$expected_count = self::count_permalink_fixture_comments(
			$comments,
			array(
				'type'       => $expected_type,
				'post_id'    => (int) $target->comment_post_ID,
				'parent'     => 0,
				'date_query' => array(
					array(
						'before' => (string) $target->comment_date_gmt,
					),
				),
			)
		);

		foreach ( $events as $event ) {
			if (
				(int) ( $event['postId'] ?? 0 ) !== (int) $target->comment_post_ID
				|| (string) ( $event['type'] ?? '' ) !== $expected_type
				|| '0' !== (string) ( $event['parent'] ?? '' )
				|| (string) ( $event['before'] ?? '' ) !== (string) $target->comment_date_gmt
				|| (int) ( $event['count'] ?? -1 ) !== $expected_count
			) {
				return false;
			}
		}

		return true;
	}

	private static function permalink_query_target_comment( array $comments, int $comment_id, array $args, array $options ): ?\WP_Comment {
		if ( ! isset( $comments[ $comment_id ] ) ) {
			return null;
		}

		$comment   = $comments[ $comment_id ];
		$max_depth = $args['max_depth'] ?? '';
		if ( '' === $max_depth ) {
			$max_depth = $options['thread_comments'] ? $options['thread_comments_depth'] : -1;
		}

		if ( (int) $max_depth > 1 && '0' !== (string) $comment->comment_parent ) {
			$parent_args              = $args;
			$parent_args['max_depth'] = $max_depth;
			return self::permalink_query_target_comment( $comments, (int) $comment->comment_parent, $parent_args, $options );
		}

		return $comment;
	}

	private static function force_query_style_comment_links(): void {
		if ( ! isset( $GLOBALS['wp_rewrite'] ) || ! is_object( $GLOBALS['wp_rewrite'] ) ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}

		if ( isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] ) ) {
			$GLOBALS['wp_rewrite']->permalink_structure         = '';
			$GLOBALS['wp_rewrite']->comments_pagination_base    = 'comment-page';
			$GLOBALS['wp_rewrite']->use_trailing_slashes        = false;
		}
	}

	private static function comment_permalink_fixture( \ComponentFuzz\FuzzContext $ctx ): array {
		$base_id       = 910000 + ( $ctx->iteration() * 100 );
		$post_id       = $base_id + 1;
		$other_post_id = $base_id + 2;
		$post          = self::permalink_post( $post_id, 'Comment Permalink Fixture ' . $ctx->iteration() );
		$other_post    = self::permalink_post( $other_post_id, 'Other Comment Permalink Fixture ' . $ctx->iteration() );
		$rows          = array(
			'first-empty-comment'     => array( 11, $post_id, '', 0, '2026-06-01 00:00:01' ),
			'first-comment'           => array( 12, $post_id, 'comment', 0, '2026-06-01 00:00:02' ),
			'first-pingback'          => array( 13, $post_id, 'pingback', 0, '2026-06-01 00:00:03' ),
			'first-trackback'         => array( 14, $post_id, 'trackback', 0, '2026-06-01 00:00:04' ),
			'middle-comment'          => array( 15, $post_id, 'comment', 0, '2026-06-01 00:00:05' ),
			'later-empty-comment'     => array( 16, $post_id, '', 0, '2026-06-01 00:00:06' ),
			'last-comment'            => array( 17, $post_id, 'comment', 0, '2026-06-01 00:00:07' ),
			'child-of-middle-comment' => array( 18, $post_id, 'comment', $base_id + 15, '2026-06-01 00:00:08' ),
			'other-post-comment'      => array( 19, $other_post_id, 'comment', 0, '2026-06-01 00:00:00' ),
			'early-child-comment'     => array( 20, $post_id, 'comment', $base_id + 12, '2026-06-01 00:00:03' ),
		);
		$comments      = array();
		$aliases       = array();

		foreach ( $rows as $alias => $row ) {
			list( $offset, $comment_post_id, $type, $parent, $date ) = $row;
			$comment_id            = $base_id + $offset;
			$aliases[ $alias ]     = $comment_id;
			$comments[ $comment_id ] = self::permalink_comment( $comment_id, $comment_post_id, $type, $parent, $date );
		}

		return array(
			'posts'    => array( $post, $other_post ),
			'comments' => $comments,
			'aliases'  => $aliases,
		);
	}

	private static function permalink_post( int $post_id, string $title ): \WP_Post {
		return new \WP_Post(
			(object) array(
				'ID'                    => $post_id,
				'post_author'           => '0',
				'post_date'             => '2026-06-01 00:00:00',
				'post_date_gmt'         => '2026-06-01 00:00:00',
				'post_content'          => 'Synthetic comment permalink fixture.',
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'open',
				'post_password'         => '',
				'post_name'             => 'comment-permalink-fixture-' . $post_id,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-01 00:00:00',
				'post_modified_gmt'     => '2026-06-01 00:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'https://example.test/?p=' . $post_id,
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			)
		);
	}

	private static function permalink_comment( int $comment_id, int $post_id, string $type, int $parent, string $date ): \WP_Comment {
		return new \WP_Comment(
			(object) array(
				'comment_ID'           => (string) $comment_id,
				'comment_post_ID'      => (string) $post_id,
				'comment_author'       => 'Commenter ' . $comment_id,
				'comment_author_email' => 'commenter' . $comment_id . '@example.test',
				'comment_author_url'   => 'https://example.test/commenter/' . $comment_id,
				'comment_author_IP'    => '192.0.2.' . ( $comment_id % 250 ),
				'comment_date'         => $date,
				'comment_date_gmt'     => $date,
				'comment_content'      => 'Comment permalink fixture ' . $comment_id,
				'comment_karma'        => '0',
				'comment_approved'     => '1',
				'comment_agent'        => 'ComponentFuzz',
				'comment_type'         => $type,
				'comment_parent'       => (string) $parent,
				'user_id'              => '0',
			)
		);
	}

	private static function cache_comment_permalink_fixture( array $fixture ): void {
		foreach ( $fixture['posts'] as $post ) {
			\wp_cache_set( (int) $post->ID, (object) $post->to_array(), 'posts' );
		}

		foreach ( $fixture['comments'] as $comment ) {
			\wp_cache_set( (int) $comment->comment_ID, (object) $comment->to_array(), 'comment' );
		}
	}

	private static function clear_comment_permalink_fixture_cache( array $fixture ): void {
		foreach ( $fixture['posts'] as $post ) {
			\wp_cache_delete( (int) $post->ID, 'posts' );
		}

		foreach ( $fixture['comments'] as $comment ) {
			\wp_cache_delete( (int) $comment->comment_ID, 'comment' );
		}
	}

	private static function expected_permalink_comment_page( array $comments, int $comment_id, array $args, array $options ): int {
		if ( ! isset( $comments[ $comment_id ] ) ) {
			return 1;
		}

		$comment  = $comments[ $comment_id ];
		$per_page = $args['per_page'] ?? '';

		if ( $options['page_comments'] && '' === $per_page ) {
			$per_page = $options['comments_per_page'];
		}

		if ( empty( $per_page ) || (int) $per_page < 1 ) {
			return 1;
		}

		$max_depth = $args['max_depth'] ?? '';
		if ( '' === $max_depth ) {
			$max_depth = $options['thread_comments'] ? $options['thread_comments_depth'] : -1;
		}

		if ( (int) $max_depth > 1 && '0' !== (string) $comment->comment_parent ) {
			$parent_args              = $args;
			$parent_args['max_depth'] = $max_depth;
			return self::expected_permalink_comment_page( $comments, (int) $comment->comment_parent, $parent_args, $options );
		}

		$older = self::count_permalink_fixture_comments(
			$comments,
			array(
				'type'       => $args['type'] ?? 'all',
				'post_id'    => (int) $comment->comment_post_ID,
				'parent'     => 0,
				'date_query' => array(
					array(
						'before' => (string) $comment->comment_date_gmt,
					),
				),
			)
		);

		return 0 === $older ? 1 : (int) ceil( ( $older + 1 ) / (int) $per_page );
	}

	private static function count_permalink_fixture_comments( array $comments, array $query_vars ): int {
		$post_id = (int) ( $query_vars['post_id'] ?? 0 );
		$type    = (string) ( $query_vars['type'] ?? 'all' );
		$parent  = array_key_exists( 'parent', $query_vars ) ? (string) $query_vars['parent'] : '';
		$before  = self::query_date_before( $query_vars );
		$count   = 0;

		foreach ( $comments as $comment ) {
			if ( $post_id && (int) $comment->comment_post_ID !== $post_id ) {
				continue;
			}

			if ( '' !== $parent && (string) $comment->comment_parent !== $parent ) {
				continue;
			}

			if ( '' !== $before && strtotime( (string) $comment->comment_date_gmt . ' UTC' ) >= strtotime( $before . ' UTC' ) ) {
				continue;
			}

			if ( ! self::comment_type_matches_query_type( (string) $comment->comment_type, $type ) ) {
				continue;
			}

			++$count;
		}

		return $count;
	}

	private static function comment_type_matches_query_type( string $comment_type, string $query_type ): bool {
		switch ( $query_type ) {
			case '':
			case 'all':
				return true;
			case 'comment':
			case 'comments':
				return '' === $comment_type || 'comment' === $comment_type;
			case 'pings':
				return 'pingback' === $comment_type || 'trackback' === $comment_type;
			default:
				return $comment_type === $query_type;
		}
	}

	private static function query_date_before( array $query_vars ): string {
		foreach ( (array) ( $query_vars['date_query'] ?? array() ) as $clause ) {
			if ( is_array( $clause ) && isset( $clause['before'] ) ) {
				return (string) $clause['before'];
			}
		}

		return '';
	}

	private static function comment_link_has_cpage( string $link, int $page ): bool {
		$query_vars = self::comment_link_query_vars( $link );
		if ( isset( $query_vars['cpage'] ) && ! is_array( $query_vars['cpage'] ) && (string) $page === (string) $query_vars['cpage'] ) {
			return true;
		}

		$path = wp_parse_url( $link, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return false;
		}

		$base = preg_quote( self::comments_pagination_base(), '#' );
		return 1 === preg_match( '#(?:^|/)' . $base . '-' . preg_quote( (string) $page, '#' ) . '/?$#', $path );
	}

	private static function comment_link_has_any_cpage( string $link ): bool {
		if ( array_key_exists( 'cpage', self::comment_link_query_vars( $link ) ) ) {
			return true;
		}

		$path = wp_parse_url( $link, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return false;
		}

		return 1 === preg_match( '#(?:^|/)' . preg_quote( self::comments_pagination_base(), '#' ) . '-[^/]+/?$#', $path );
	}

	private static function comment_link_query_vars( string $link ): array {
		$query = wp_parse_url( $link, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return array();
		}

		$vars = array();
		parse_str( $query, $vars );
		return $vars;
	}

	private static function comments_pagination_base(): string {
		if ( isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] ) && isset( $GLOBALS['wp_rewrite']->comments_pagination_base ) ) {
			return (string) $GLOBALS['wp_rewrite']->comments_pagination_base;
		}

		return 'comment-page';
	}

	private static function expected_sanitized_cookies( array $cookies, string $hash ): array {
		$expected = $cookies;

		$author = 'comment_author_' . $hash;
		if ( array_key_exists( $author, $cookies ) ) {
			$expected[ $author ] = \esc_attr( \wp_unslash( \sanitize_text_field( $cookies[ $author ] ) ) );
		}

		$email = 'comment_author_email_' . $hash;
		if ( array_key_exists( $email, $cookies ) ) {
			$expected[ $email ] = \esc_attr( \wp_unslash( \sanitize_email( trim( $cookies[ $email ] ) ) ) );
		}

		$url = 'comment_author_url_' . $hash;
		if ( array_key_exists( $url, $cookies ) ) {
			$expected[ $url ] = \wp_unslash( \sanitize_url( $cookies[ $url ] ) );
		}

		return $expected;
	}

	private static function comment_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'profile' => 'valid-basic',
				'comment' => self::comment_array(
					'Alice Example',
					'alice@example.com',
					'https://example.com/posts/1?x=1&y=2',
					"Hello world.\nSecond line.",
					'192.0.2.10',
					'Mozilla/5.0 ComponentFuzz',
					array(
						'comment_type'   => '',
						'comment_parent' => '0',
						'user_id'        => 0,
					)
				),
			),
			array(
				'profile' => 'html-script-content',
				'comment' => self::comment_array(
					'<b>Alice</b> O\\\'Reilly',
					' Display Name <alice@example.com> ',
					'example.com/a path/?q=<script>',
					"<p>Hello <script>alert(1)</script><em>world</p>\n<a href=\"javascript:alert(1)\">x</a>",
					'127.0.0.1',
					"Agent/1.0\t<script>",
					array(
						'comment_type'   => 'comment',
						'comment_parent' => 17,
						'user_ID'        => '42',
					)
				),
			),
			array(
				'profile' => 'invalid-utf8-and-malformed-email',
				'comment' => self::comment_array(
					"bad\x80\xFFname",
					"bad@@example.com\x80",
					'javascript:alert(1)',
					"Invalid bytes \xC3\x28 <strong>ok</strong>\r\n\xFF",
					"::1\x00tail",
					"Agent\x00Two\x80",
					array(
						'comment_type'   => "review\x80",
						'comment_parent' => -99,
						'user_id'        => -1,
					)
				),
			),
			array(
				'profile' => 'unicode-email-and-url',
				'comment' => self::comment_array(
					"Gr\xC3\xA5 Sk\xC3\xA5l",
					"gr\xC3\xA5@gr\xC3\xA5.example",
					'https://xn--bcher-kva.example/%E2%98%83?x=%E6%B5%8B',
					"Unicode snowman \xE2\x98\x83 and CJK \xE4\xB8\xAD\xE6\x96\x87",
					'2001:db8::1',
					"Fuzz/\xE2\x98\x83",
					array(
						'comment_type'   => 'pingback',
						'comment_parent' => '00012',
						'user_id'        => '5',
					)
				),
			),
			array(
				'profile' => 'huge-bounded-fields',
				'comment' => self::comment_array(
					str_repeat( 'A', 260 ),
					str_repeat( 'local', 18 ) . '@example.com',
					'https://example.com/' . str_repeat( 'path/', 80 ) . '?q=' . str_repeat( 'x', 128 ),
					str_repeat( "Long <em>comment</em> & ", 140 ),
					'203.0.113.44',
					str_repeat( 'Agent', 80 ),
					array(
						'comment_type'   => 'trackback',
						'comment_parent' => PHP_INT_MAX,
						'user_ID'        => PHP_INT_MAX,
					)
				),
			),
			array(
				'profile' => 'empty-and-sentinel-values',
				'comment' => self::comment_array(
					'',
					'@',
					'#fragment',
					'',
					'',
					'',
					array(
						'comment_type'   => '0',
						'comment_parent' => 'not-a-number',
						'user_id'        => 'not-a-number',
					)
				),
			),
			array(
				'profile' => 'unusual-comment-type',
				'comment' => self::comment_array(
					'Type Tester',
					'type.tester@example.test.',
					'ftp://example.test/file.txt',
					"<blockquote>Quote\n<ul><li>One<li>Two</ul>",
					'198.51.100.7',
					"curl/8.0\r\nInjected: header",
					array(
						'comment_type'   => 'review:<script>alert(1)</script>',
						'comment_parent' => '7',
						'user_id'        => 0,
					)
				),
			),
			array(
				'profile' => 'slash-heavy-cookies',
				'comment' => self::comment_array(
					"Bob \\\\\\'Quote\\\\\\'",
					" bob.o\\\\\\'reilly@example.com ",
					'https://example.test/a%5Cb?x=1\\\\&y=2',
					"Slashy \\\\\\'content\\\\\\' <b>bold</b>",
					'192.0.2.55',
					"Agent\\\\Slash",
					array(
						'comment_type'   => 'webmention',
						'comment_parent' => 3,
						'user_ID'        => 0,
					)
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; ++$i ) {
			$case_ctx = $ctx->fork( 'comment-case-' . $i );
			$cases[]  = array(
				'profile' => 'generated-' . $i,
				'comment' => self::generated_comment( $case_ctx, $i ),
			);
		}

		return $cases;
	}

	private static function generated_comment( \ComponentFuzz\FuzzContext $ctx, int $index ): array {
		$email = $ctx->choice(
			array(
				$ctx->text( 0, 40 ) . '@example.test',
				'Display <' . $ctx->text( 0, 24 ) . '@example.test>',
				$ctx->text( 0, 30 ) . '@@example..test',
				"user\x80" . $index . '@example.test',
				"gr\xC3\xA5" . $index . "@gr\xC3\xA5.example",
				'',
				'@',
			)
		);

		$type = $ctx->choice(
			array(
				'',
				'comment',
				'trackback',
				'pingback',
				'review',
				'webmention',
				'emoji-' . "\xE2\x98\x83",
				'<script-type>',
				$ctx->identifier( 1, 18 ),
				substr( $ctx->text( 0, 24 ), 0, 24 ),
			)
		);

		return self::comment_array(
			$ctx->text( 0, 160 ),
			$email,
			$ctx->choice( array( $ctx->url(), 'example.test/' . rawurlencode( $ctx->text( 0, 16 ) ), 'javascript:' . $ctx->text( 0, 24 ), '' ) ),
			$ctx->choice( array( $ctx->htmlFragment(), $ctx->text( 0, 512 ), str_repeat( $ctx->choice( array( 'x', '<b>x</b>', '&amp;', "\x80" ) ), $ctx->int( 0, 256 ) ) ) ),
			$ctx->choice( array( '127.0.0.1', '::1', '2001:db8::' . $ctx->int( 1, 99 ), $ctx->text( 0, 48 ) ) ),
			$ctx->text( 0, 160 ),
			array(
				'comment_type'    => $type,
				'comment_parent'  => $ctx->choice( array( 0, 1, -1, '42', 'not-a-number', PHP_INT_MAX, $ctx->text( 0, 18 ) ) ),
				'comment_post_ID' => $ctx->choice( array( 0, 1, '123', -3, 'post-' . $index ) ),
				'user_id'         => $ctx->choice( array( 0, 1, -1, '7', 'not-a-user', PHP_INT_MAX ) ),
			)
		);
	}

	private static function comment_array( string $author, string $email, string $url, string $content, string $ip, string $agent, array $extra = array() ): array {
		return array_merge(
			array(
				'comment_post_ID'      => 0,
				'comment_author'       => $author,
				'comment_author_email' => $email,
				'comment_author_url'   => $url,
				'comment_content'      => $content,
				'comment_author_IP'    => $ip,
				'comment_agent'        => $agent,
				'comment_type'         => '',
				'comment_parent'       => 0,
				'user_id'              => 0,
			),
			$extra
		);
	}

	private static function base_comment(): array {
		return self::comment_array(
			'Alice',
			'alice@example.com',
			'https://example.com/',
			'Hello',
			'192.0.2.1',
			'ComponentFuzz/1.0'
		);
	}

	private static function comment_object( array $comment, int $id ): object {
		$data = array_merge(
			array(
				'comment_ID'       => (string) $id,
				'comment_date'     => '2024-01-01 00:00:00',
				'comment_date_gmt' => '2024-01-01 00:00:00',
				'comment_approved' => '1',
				'comment_karma'    => '0',
			),
			$comment,
			array(
				'comment_ID' => (string) $id,
			)
		);

		if ( class_exists( 'WP_Comment' ) ) {
			return new \WP_Comment( (object) $data );
		}

		return (object) $data;
	}

	private static function with_non_mysql_wpdb( callable $callback ) {
		$had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$wpdb     = $GLOBALS['wpdb'] ?? null;

		$GLOBALS['wpdb'] = new class() {
			public bool $is_mysql = false;
			public string $comments = 'wp_comments';
		};

		try {
			return $callback();
		} finally {
			if ( $had_wpdb ) {
				$GLOBALS['wpdb'] = $wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}

	private static function case_result( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case, string $invariant, bool $ok, array $data = array() ): array {
		return $ctx->result( $invariant, $ok, self::case_data( $case_index, $case, $data ) );
	}

	private static function case_data( int $case_index, array $case, array $data = array() ): array {
		return array_merge(
			array(
				'case'    => $case_index,
				'profile' => $case['profile'] ?? 'unknown',
				'input'   => self::describe_comment( $case['comment'] ),
			),
			$data
		);
	}

	private static function call( callable $callback ): array {
		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'value'     => null,
			);
		}
	}

	private static function snapshot_globals(): array {
		$globals = array();
		foreach (
			array(
				'comment',
				'current_user',
				'post',
				'user_ID',
				'comment_alt',
				'comment_depth',
				'comment_thread_alt',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_actions',
				'wpdb',
				'wp_rewrite',
			) as $key
		) {
			$globals[ $key ] = array(
				'exists' => array_key_exists( $key, $GLOBALS ),
				'value'  => array_key_exists( $key, $GLOBALS ) ? self::clone_global_value( $key, $GLOBALS[ $key ] ) : null,
			);
		}

		return array(
			'_COOKIE' => $_COOKIE,
			'_GET'    => $_GET,
			'_SERVER' => $_SERVER,
			'globals' => $globals,
		);
	}

	private static function restore_globals( array $snapshot ): void {
		$_COOKIE = $snapshot['_COOKIE'];
		$_GET    = $snapshot['_GET'];
		$_SERVER = $snapshot['_SERVER'];

		foreach ( $snapshot['globals'] as $key => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $key ] = self::clone_global_value( $key, $entry['value'] );
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
	}

	private static function clone_global_value( string $key, $value ) {
		if ( 'wp_filter' === $key ) {
			return self::clone_wp_filter( $value );
		}

		if ( 'wp_rewrite' === $key && is_object( $value ) ) {
			return clone $value;
		}

		return $value;
	}

	private static function clone_wp_filter( $wp_filter ) {
		if ( ! is_array( $wp_filter ) ) {
			return $wp_filter;
		}

		$clone = array();
		foreach ( $wp_filter as $hook => $value ) {
			$clone[ $hook ] = is_object( $value ) ? clone $value : $value;
		}

		return $clone;
	}

	private static function describe_call( array $call ): array {
		if ( $call['threw'] ) {
			return array(
				'threw'     => true,
				'throwable' => $call['throwable'],
			);
		}

		return array(
			'threw' => false,
			'value' => self::describe_value( $call['value'] ),
		);
	}

	private static function describe_max_length_result( array $call ): array {
		if ( $call['threw'] ) {
			return self::describe_call( $call );
		}

		if ( \is_wp_error( $call['value'] ) ) {
			return array(
				'type'    => 'WP_Error',
				'code'    => $call['value']->get_error_code(),
				'message' => $call['value']->get_error_message(),
				'data'    => $call['value']->get_error_data(),
			);
		}

		return self::describe_call( $call );
	}

	private static function describe_comment( array $comment ): array {
		return array(
			'author'   => self::describe_value( (string) ( $comment['comment_author'] ?? '' ) ),
			'email'    => self::describe_value( (string) ( $comment['comment_author_email'] ?? '' ) ),
			'url'      => self::describe_value( (string) ( $comment['comment_author_url'] ?? '' ) ),
			'content'  => self::describe_value( (string) ( $comment['comment_content'] ?? '' ) ),
			'type'     => self::describe_value( (string) ( $comment['comment_type'] ?? '' ) ),
			'parent'   => self::describe_value( $comment['comment_parent'] ?? null ),
			'userId'   => self::describe_value( $comment['user_id'] ?? ( $comment['user_ID'] ?? null ) ),
			'postId'   => self::describe_value( $comment['comment_post_ID'] ?? null ),
			'ip'       => self::describe_value( (string) ( $comment['comment_author_IP'] ?? '' ) ),
			'agent'    => self::describe_value( (string) ( $comment['comment_agent'] ?? '' ) ),
			'fieldMap' => array_keys( $comment ),
		);
	}

	private static function describe_cookie_set( array $cookies ): array {
		$out = array();
		foreach ( $cookies as $name => $value ) {
			$out[ $name ] = self::describe_value( (string) $value );
		}

		return $out;
	}

	private static function describe_type_counts( array $partitions ): array {
		$counts = array();
		foreach ( $partitions as $type => $ids ) {
			$counts[] = array(
				'type'  => self::describe_value( (string) $type ),
				'count' => count( $ids ),
			);
		}

		return $counts;
	}

	private static function describe_partition_ids( array $partitions ): array {
		$out = array();
		foreach ( $partitions as $type => $ids ) {
			$out[] = array(
				'type' => self::describe_value( (string) $type ),
				'ids'  => array_values( $ids ),
			);
		}

		return $out;
	}

	private static function describe_value( $value, int $depth = 0 ): array {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return array(
				'type'  => gettype( $value ),
				'value' => $value,
			);
		}

		if ( is_array( $value ) ) {
			$items = array();
			if ( $depth < 2 ) {
				$count = 0;
				foreach ( $value as $key => $item ) {
					$items[] = array(
						'key'   => self::describe_value( is_int( $key ) ? $key : (string) $key, $depth + 1 ),
						'value' => self::describe_value( $item, $depth + 1 ),
					);
					++$count;
					if ( $count >= 8 ) {
						break;
					}
				}
			}

			return array(
				'type'  => 'array',
				'count' => count( $value ),
				'items' => $items,
			);
		}

		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return array( 'type' => gettype( $value ) );
	}

	private static function describe_string( string $value ): array {
		return array(
			'type'      => 'string',
			'bytes'     => strlen( $value ),
			'sha1'      => sha1( $value ),
			'validUtf8' => 1 === preg_match( '//u', $value ),
			'preview'   => self::preview_bytes( $value ),
		);
	}

	private static function preview_bytes( string $value ): string {
		$slice = substr( $value, 0, self::SAMPLE_BYTES );
		$json  = json_encode( $slice, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			$json = base64_encode( $slice );
		}

		return strlen( $value ) > self::SAMPLE_BYTES ? $json . '...' : $json;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function all_strings( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				return false;
			}
		}

		return true;
	}

	private static function author_link_matches_url_contract( string $link, string $expected_url, ?string $author_name ): bool {
		if ( '' === $expected_url ) {
			return null !== $author_name && $link === $author_name;
		}

		return str_contains( $link, '<a href="' . $expected_url . '" class="url"' )
			&& str_contains( $link, 'rel="' )
			&& str_contains( $link, 'ugc' )
			&& ! str_contains( $link, 'javascript:' );
	}

	private static function classes_have_no_raw_angle_brackets( array $classes ): bool {
		foreach ( $classes as $class ) {
			if ( str_contains( $class, '<' ) || str_contains( $class, '>' ) ) {
				return false;
			}
		}

		return true;
	}
}
