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
				'get_comment_excerpt',
				'get_comment_text',
				'is_wp_error',
				'wp_trim_words',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
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

		$author_link_call = self::call( static fn() => \get_comment_author_link( $comment ) );
		$author_link      = ! $author_link_call['threw'] && is_string( $author_link_call['value'] ) ? $author_link_call['value'] : '';
		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'comments.template.author-link.href-and-rel-follow-sanitized-url',
			! $author_link_call['threw']
				&& is_string( $author_link_call['value'] )
				&& self::author_link_matches_url_contract( $author_link, $expected_url ),
			array(
				'expectedUrl' => self::describe_value( $expected_url ),
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
				'post',
				'comment_alt',
				'comment_depth',
				'comment_thread_alt',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_actions',
				'wpdb',
			) as $key
		) {
			$globals[ $key ] = array(
				'exists' => array_key_exists( $key, $GLOBALS ),
				'value'  => 'wp_filter' === $key && array_key_exists( $key, $GLOBALS ) ? self::clone_wp_filter( $GLOBALS[ $key ] ) : ( $GLOBALS[ $key ] ?? null ),
			);
		}

		return array(
			'_COOKIE' => $_COOKIE,
			'globals' => $globals,
		);
	}

	private static function restore_globals( array $snapshot ): void {
		$_COOKIE = $snapshot['_COOKIE'];

		foreach ( $snapshot['globals'] as $key => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $key ] = 'wp_filter' === $key ? self::clone_wp_filter( $entry['value'] ) : $entry['value'];
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
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

	private static function author_link_matches_url_contract( string $link, string $expected_url ): bool {
		if ( '' === $expected_url ) {
			return ! str_contains( $link, '<a ' ) && ! str_contains( $link, 'href=' );
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
