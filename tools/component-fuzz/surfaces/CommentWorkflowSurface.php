<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB comment submission, moderation, and status workflows.
 */
final class CommentWorkflowSurface {
	public const NAME = 'comment-workflow';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'comment-workflow.bootstrap-apis-available',
					'Required WordPress comment workflow APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::prepare_runtime();
			$case = self::case_for_context( $ctx );

			$rows[] = self::check_handle_submission( $ctx->fork( 'submission' ), $case );
			$rows[] = self::check_new_comment_pipeline( $ctx->fork( 'new-comment' ), $case );
			$rows[] = self::check_notification_mail_paths( $ctx->fork( 'notifications' ), $case );
			$rows[] = self::check_allow_comment_decisions( $ctx->fork( 'allow' ), $case );
			$rows[] = self::check_update_and_status_transitions( $ctx->fork( 'status' ), $case );
			$rows[] = self::check_trash_spam_restore_helpers( $ctx->fork( 'trash-spam' ), $case );
			$rows[] = self::check_force_delete_comment_semantics( $ctx->fork( 'delete' ), $case );
			$rows[] = self::check_force_delete_non_counted_count_semantics( $ctx->fork( 'delete-counts' ), $case );
			$rows[] = self::check_failure_paths( $ctx->fork( 'failures' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'comment-workflow.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'comment-workflow.global-state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedServer'  => array_keys( $snapshot['server'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Post', 'WP_Comment', 'WP_Error', 'WP_Http', 'WP_User', 'Component_Fuzz_WPDB_Stub' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_comment_meta',
				'create_initial_post_types',
				'get_comment',
				'get_comment_meta',
				'get_option',
				'get_post',
				'get_user_by',
				'get_userdata',
				'has_action',
				'has_filter',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'update_option',
				'user_can',
				'wp_allow_comment',
				'wp_delete_comment',
				'wp_handle_comment_submission',
				'wp_insert_comment',
				'wp_insert_post',
				'wp_insert_user',
				'wp_mail',
				'wp_new_comment',
				'wp_new_comment_notify_moderator',
				'wp_new_comment_notify_postauthor',
				'wp_notify_moderator',
				'wp_notify_postauthor',
				'wp_set_comment_status',
				'wp_slash',
				'wp_spam_comment',
				'wp_set_current_user',
				'wp_trash_comment',
				'wp_unspam_comment',
				'wp_untrash_comment',
				'wp_update_comment',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_handle_submission( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$post_id  = self::insert_post( $case, 'open' );
		$approve  = static function () {
			return 1;
		};
		\add_filter( 'pre_comment_approved', $approve, 10, 2 );
		try {
			$comment = \wp_handle_comment_submission(
				array(
					'comment_post_ID' => $post_id,
					'author'          => '  <b>' . $case['author'] . '</b>  ',
					'email'           => $case['email'],
					'url'             => $case['url'],
					'comment'         => '  ' . $case['content'] . '  ',
					'comment_parent'  => 0,
				)
			);
		} finally {
			\remove_filter( 'pre_comment_approved', $approve, 10 );
		}

		$stored = $comment instanceof \WP_Comment ? \get_comment( $comment->comment_ID ) : null;
		$post   = \get_post( $post_id );
		self::collect_failure(
			$failures,
			$comment instanceof \WP_Comment
				&& $stored instanceof \WP_Comment
				&& (string) $post_id === (string) $stored->comment_post_ID
				&& $case['author'] === $stored->comment_author
				&& $case['email'] === $stored->comment_author_email
				&& $case['content'] === $stored->comment_content
				&& '1' === (string) $stored->comment_approved,
			'wp_handle_comment_submission stores sanitized approved comment',
			array(
				'returned' => self::comment_summary( $comment ),
				'comment'  => self::comment_summary( $stored ),
			)
		);
		self::collect_failure(
			$failures,
			$post instanceof \WP_Post && 1 === (int) $post->comment_count,
			'approved submission increments post comment count',
			array( 'count' => $post instanceof \WP_Post ? $post->comment_count : null )
		);

		return self::result(
			$ctx,
			'comment-workflow.handle-submission.success-sanitizes-and-counts',
			$failures,
			array( 'postId' => $post_id )
		);
	}

	private static function check_new_comment_pipeline( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures       = array();
		$post_id        = self::insert_post( $case, 'open' );
		$user_id        = self::insert_user( $ctx->fork( 'user' ), $case );
		$parent_id      = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Parent ' . $case['token'],
				'comment_author_email' => 'parent-' . $case['token'] . '@example.test',
				'comment_content'      => 'parent ' . $case['token'],
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
			)
		);
		$preprocess_seen = array();
		$insert_seen     = array();
		$post_seen       = array();
		$prefilter_user  = 0;
		$filtered_user   = $user_id;
		$agent           = 'ComponentFuzz-Agent-' . str_repeat( $case['token'], 40 );
		$expected_agent  = substr( $agent, 0, 254 );
		$expected_ip     = '203.0.113.' . $ctx->int( 1, 200 );
		$expected_author = 'Pipeline Author ' . $case['token'];
		$expected_body   = 'Pipeline body ' . $case['token'];
		$preprocess      = static function ( array $commentdata ) use ( &$preprocess_seen, $expected_author, $expected_body, $expected_ip, $filtered_user ): array {
			$preprocess_seen[] = array(
				'userID' => $commentdata['user_ID'] ?? null,
				'userId' => $commentdata['user_id'] ?? null,
				'ip'     => $commentdata['comment_author_IP'] ?? null,
				'agent'  => $commentdata['comment_agent'] ?? null,
			);

			$commentdata['user_ID']              = $filtered_user;
			$commentdata['comment_author']       = $expected_author;
			$commentdata['comment_author_IP']    = $expected_ip . 'zz!';
			$commentdata['comment_author_email'] = 'pipeline-filtered@example.test';
			$commentdata['comment_content']      = $expected_body;
			return $commentdata;
		};
		$approve         = static function () {
			return 0;
		};
		$insert_action   = static function ( int $comment_id, \WP_Comment $comment ) use ( &$insert_seen ): void {
			$insert_seen[] = array(
				'id'       => (int) $comment_id,
				'approved' => (string) $comment->comment_approved,
				'parent'   => (int) $comment->comment_parent,
			);
		};
		$post_action     = static function ( int $comment_id, $approved, array $commentdata ) use ( &$post_seen ): void {
			$post_seen[] = array(
				'id'       => (int) $comment_id,
				'approved' => (string) $approved,
				'userId'   => isset( $commentdata['user_id'] ) ? (int) $commentdata['user_id'] : null,
				'filtered' => true === ( $commentdata['filtered'] ?? null ),
			);
		};

		\add_filter( 'preprocess_comment', $preprocess );
		\add_filter( 'pre_comment_approved', $approve, 10, 2 );
		\add_action( 'wp_insert_comment', $insert_action, 10, 2 );
		\add_action( 'comment_post', $post_action, 10, 3 );
		try {
			$comment_id = \wp_new_comment(
				array(
					'comment_post_ID'      => $post_id,
					'comment_parent'       => $parent_id,
					'comment_author'       => 'Raw Author ' . $case['token'],
					'comment_author_email' => $case['email'],
					'comment_author_url'   => $case['url'],
					'comment_content'      => 'Raw body ' . $case['token'],
					'comment_agent'        => $agent,
					'user_ID'              => $prefilter_user,
				),
				true
			);
		} finally {
			\remove_action( 'comment_post', $post_action, 10 );
			\remove_action( 'wp_insert_comment', $insert_action, 10 );
			\remove_filter( 'pre_comment_approved', $approve, 10 );
			\remove_filter( 'preprocess_comment', $preprocess );
		}

		$comment = is_int( $comment_id ) ? \get_comment( $comment_id ) : null;
		$post    = \get_post( $post_id );

		self::collect_failure(
			$failures,
			is_int( $parent_id )
				&& is_int( $comment_id )
				&& $comment instanceof \WP_Comment
				&& (string) $post_id === (string) $comment->comment_post_ID
				&& (string) $parent_id === (string) $comment->comment_parent
				&& (string) $filtered_user === (string) $comment->user_id
				&& $expected_author === $comment->comment_author
				&& 'pipeline-filtered@example.test' === $comment->comment_author_email
				&& $expected_body === $comment->comment_content
				&& $expected_ip === $comment->comment_author_IP
				&& $expected_agent === $comment->comment_agent
				&& '0' === (string) $comment->comment_approved,
			'wp_new_comment preprocesses, normalizes, filters, and stores a pending child comment without live user lookup',
			array(
				'parentId' => $parent_id,
				'comment'  => self::comment_summary( $comment ),
			)
		);
		self::collect_failure(
			$failures,
			array(
				array(
					'userID' => $prefilter_user,
					'userId' => $prefilter_user,
					'ip'     => '127.0.0.1',
					'agent'  => $agent,
				),
			) === $preprocess_seen
				&& array(
					array(
						'id'       => (int) $comment_id,
						'approved' => '0',
						'parent'   => (int) $parent_id,
					),
				) === $insert_seen
				&& array(
					array(
						'id'       => (int) $comment_id,
						'approved' => '0',
						'userId'   => (int) $filtered_user,
						'filtered' => true,
					),
				) === $post_seen
				&& false === \has_action( 'comment_post', $post_action )
				&& false === \has_action( 'wp_insert_comment', $insert_action )
				&& false === \has_filter( 'pre_comment_approved', $approve )
				&& false === \has_filter( 'preprocess_comment', $preprocess ),
			'wp_new_comment fires preprocess, insert, and comment_post hooks with normalized data and removes hooks',
			array(
				'preprocess' => $preprocess_seen,
				'insert'     => $insert_seen,
				'post'       => $post_seen,
			)
		);
		self::collect_failure(
			$failures,
			$post instanceof \WP_Post && 1 === (int) $post->comment_count,
			'pending wp_new_comment child does not increment approved comment count beyond approved parent',
			array( 'count' => $post instanceof \WP_Post ? $post->comment_count : null )
		);

		return self::result(
			$ctx,
			'comment-workflow.new-comment.preprocess-hooks-and-parent-normalization',
			$failures,
			array(
				'postId' => $post_id,
				'userId' => $user_id,
			)
		);
	}

	private static function check_notification_mail_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures  = array();
		$author_id = self::insert_user( $ctx->fork( 'post-author' ), $case );
		$post_id   = self::insert_post( $case, 'open', $author_id );
		$author    = \get_userdata( $author_id );
		$approved_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Notify Approved ' . $case['token'],
				'comment_author_email' => 'approved-' . $case['token'] . '@example.test',
				'comment_author_url'   => $case['url'],
				'comment_content'      => 'approved notify ' . $case['content'],
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
				'user_id'              => 0,
			)
		);
		$pending_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Notify Pending ' . $case['token'],
				'comment_author_email' => 'pending-' . $case['token'] . '@example.test',
				'comment_author_url'   => $case['url'],
				'comment_content'      => 'pending notify ' . $case['content'],
				'comment_approved'     => '0',
				'comment_type'         => 'comment',
				'user_id'              => 0,
			)
		);

		$mail_calls        = array();
		$post_events       = array();
		$moderation_events = array();
		$gate_events       = array();
		$filters           = array();
		$old_comments_notify   = \get_option( 'comments_notify' );
		$old_moderation_notify = \get_option( 'moderation_notify' );
		$add_filter            = static function ( string $hook, callable $callback, int $accepted_args = 1 ) use ( &$filters ): void {
			\add_filter( $hook, $callback, 10, $accepted_args );
			$filters[] = array( $hook, $callback, 10 );
		};

		$add_filter(
			'pre_wp_mail',
			static function ( $return, array $atts ) use ( &$mail_calls ) {
				unset( $return );
				$mail_calls[] = array(
					'to'      => $atts['to'] ?? null,
					'subject' => (string) ( $atts['subject'] ?? '' ),
					'message' => (string) ( $atts['message'] ?? '' ),
					'headers' => (string) ( $atts['headers'] ?? '' ),
				);
				return true;
			},
			2
		);
		$add_filter(
			'notify_post_author',
			static function ( $maybe_notify, int $comment_id ) use ( &$gate_events ) {
				$gate_events[] = array(
					'hook'      => 'notify_post_author',
					'commentId' => $comment_id,
					'maybe'     => (bool) $maybe_notify,
				);
				return $maybe_notify;
			},
			2
		);
		$add_filter(
			'notify_moderator',
			static function ( $maybe_notify, int $comment_id ) use ( &$gate_events ) {
				$gate_events[] = array(
					'hook'      => 'notify_moderator',
					'commentId' => $comment_id,
					'maybe'     => (bool) $maybe_notify,
				);
				return $maybe_notify;
			},
			2
		);
		$add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $author_id, $post_id ): array {
				if ( 'read_post' === $cap && $author_id === $user_id && $post_id === (int) ( $args[0] ?? 0 ) ) {
					return array( 'exist' );
				}
				return $caps;
			},
			4
		);
		$add_filter(
			'comment_notification_recipients',
			static function ( array $emails, $comment_id ) use ( &$post_events ): array {
				$post_events[] = array(
					'hook'      => 'recipients',
					'commentId' => (int) $comment_id,
					'emails'    => array_values( $emails ),
				);
				$emails[] = 'watcher-' . (int) $comment_id . '@example.test';
				return $emails;
			},
			2
		);
		$add_filter(
			'comment_notification_headers',
			static function ( string $headers, $comment_id ) use ( &$post_events ): string {
				$post_events[] = array(
					'hook'           => 'headers',
					'commentId'      => (int) $comment_id,
					'hasContentType' => str_contains( $headers, 'Content-Type: text/plain; charset="UTF-8"' ),
				);
				return $headers . 'X-Component-Fuzz-Notification: ' . (int) $comment_id . "\n";
			},
			2
		);
		$add_filter(
			'comment_notification_text',
			static function ( string $message, $comment_id ) use ( &$post_events, $case ): string {
				$post_events[] = array(
					'hook'       => 'text',
					'commentId'  => (int) $comment_id,
					'hasContent' => str_contains( $message, 'approved notify' ) && str_contains( $message, $case['token'] ),
				);
				return $message . "\r\nComponent fuzz notification " . (int) $comment_id;
			},
			2
		);
		$add_filter(
			'comment_notification_subject',
			static function ( string $subject, $comment_id ) use ( &$post_events ): string {
				$post_events[] = array(
					'hook'      => 'subject',
					'commentId' => (int) $comment_id,
					'subject'   => $subject,
				);
				return $subject . ' [component-fuzz notification]';
			},
			2
		);
		$add_filter(
			'comment_moderation_recipients',
			static function ( array $emails, int $comment_id ) use ( &$moderation_events ): array {
				$moderation_events[] = array(
					'hook'      => 'recipients',
					'commentId' => $comment_id,
					'emails'    => array_values( $emails ),
				);
				$emails[] = 'moderator-' . $comment_id . '@example.test';
				return $emails;
			},
			2
		);
		$add_filter(
			'comment_moderation_headers',
			static function ( string $headers, int $comment_id ) use ( &$moderation_events ): string {
				$moderation_events[] = array(
					'hook'      => 'headers',
					'commentId' => $comment_id,
					'headers'   => $headers,
				);
				return $headers . 'X-Component-Fuzz-Moderation: ' . $comment_id . "\n";
			},
			2
		);
		$add_filter(
			'comment_moderation_text',
			static function ( string $message, int $comment_id ) use ( &$moderation_events, $case ): string {
				$moderation_events[] = array(
					'hook'       => 'text',
					'commentId'  => $comment_id,
					'hasContent' => str_contains( $message, 'pending notify' ) && str_contains( $message, $case['token'] ),
				);
				return $message . "\r\nComponent fuzz moderation " . $comment_id;
			},
			2
		);
		$add_filter(
			'comment_moderation_subject',
			static function ( string $subject, int $comment_id ) use ( &$moderation_events ): string {
				$moderation_events[] = array(
					'hook'      => 'subject',
					'commentId' => $comment_id,
					'subject'   => $subject,
				);
				return $subject . ' [component-fuzz moderation]';
			},
			2
		);

		$approved_author_before   = 0;
		$approved_author_after    = 0;
		$pending_author_after     = 0;
		$pending_moderator_after  = 0;
		$approved_moderator_after = 0;
		$direct_moderator_after   = 0;
		$direct_author_after      = 0;
		$approved_author_result   = null;
		$pending_author_result    = null;
		$pending_moderator_result = null;
		$approved_moderator_result = null;
		$direct_moderator_result   = null;
		$direct_author_result      = null;

		try {
			\update_option( 'comments_notify', 1 );
			\update_option( 'moderation_notify', 1 );
			\wp_set_current_user( 0 );

			$approved_author_before    = count( $mail_calls );
			$approved_author_result    = \wp_new_comment_notify_postauthor( $approved_id );
			$approved_author_after     = count( $mail_calls );
			$pending_author_result     = \wp_new_comment_notify_postauthor( $pending_id );
			$pending_author_after      = count( $mail_calls );
			$pending_moderator_result  = \wp_new_comment_notify_moderator( $pending_id );
			$pending_moderator_after   = count( $mail_calls );
			$approved_moderator_result = \wp_new_comment_notify_moderator( $approved_id );
			$approved_moderator_after  = count( $mail_calls );
			$direct_moderator_result   = \wp_notify_moderator( $pending_id );
			$direct_moderator_after    = count( $mail_calls );
			$direct_author_result      = \wp_notify_postauthor( $approved_id );
			$direct_author_after       = count( $mail_calls );
		} finally {
			\update_option( 'comments_notify', $old_comments_notify );
			\update_option( 'moderation_notify', $old_moderation_notify );
			\wp_set_current_user( 0 );
			self::remove_filters( $filters );
		}

		$approved_author_calls = array_slice( $mail_calls, $approved_author_before, $approved_author_after - $approved_author_before );
		$pending_moderator_calls = array_slice( $mail_calls, $pending_author_after, $pending_moderator_after - $pending_author_after );
		$direct_moderator_calls = array_slice( $mail_calls, $approved_moderator_after, $direct_moderator_after - $approved_moderator_after );
		$direct_author_calls = array_slice( $mail_calls, $direct_moderator_after, $direct_author_after - $direct_moderator_after );
		$expected_author_email = $author instanceof \WP_User ? $author->user_email : null;

		self::collect_failure(
			$failures,
			is_int( $approved_id )
				&& is_int( $pending_id )
				&& $author instanceof \WP_User
				&& true === $approved_author_result
				&& false === $pending_author_result
				&& true === $pending_moderator_result
				&& false === $approved_moderator_result
				&& true === $direct_moderator_result
				&& true === $direct_author_result,
			'comment notification wrappers gate approved author mail and pending moderation mail while direct notifiers send',
			array(
				'approvedId'              => $approved_id,
				'pendingId'               => $pending_id,
				'approvedAuthorResult'    => $approved_author_result,
				'pendingAuthorResult'     => $pending_author_result,
				'pendingModeratorResult'  => $pending_moderator_result,
				'approvedModeratorResult' => $approved_moderator_result,
				'directModeratorResult'   => $direct_moderator_result,
				'directAuthorResult'      => $direct_author_result,
			)
		);
		self::collect_failure(
			$failures,
			2 === count( $approved_author_calls )
				&& $expected_author_email === ( $approved_author_calls[0]['to'] ?? null )
				&& 'watcher-' . $approved_id . '@example.test' === ( $approved_author_calls[1]['to'] ?? null )
				&& self::mail_calls_have( $approved_author_calls, 'subject', '[Component Fuzz] Comment: "Comment Workflow ' . $case['token'] . '"' )
				&& self::mail_calls_have( $approved_author_calls, 'subject', '[component-fuzz notification]' )
				&& self::mail_calls_have( $approved_author_calls, 'message', 'approved notify' )
				&& self::mail_calls_have( $approved_author_calls, 'message', $case['token'] )
				&& self::mail_calls_have( $approved_author_calls, 'headers', 'X-Component-Fuzz-Notification: ' . $approved_id )
				&& $approved_author_after === $pending_author_after,
			'approved post-author wrapper sends filtered author recipients through wp_mail and pending wrapper sends none',
			array(
				'expectedAuthorEmail' => $expected_author_email,
				'approvedCalls'       => $approved_author_calls,
				'pendingDelta'        => $pending_author_after - $approved_author_after,
			)
		);
		self::collect_failure(
			$failures,
			2 === count( $pending_moderator_calls )
				&& 'admin@example.test' === ( $pending_moderator_calls[0]['to'] ?? null )
				&& 'moderator-' . $pending_id . '@example.test' === ( $pending_moderator_calls[1]['to'] ?? null )
				&& self::mail_calls_have( $pending_moderator_calls, 'subject', '[Component Fuzz] Please moderate: "Comment Workflow ' . $case['token'] . '"' )
				&& self::mail_calls_have( $pending_moderator_calls, 'subject', '[component-fuzz moderation]' )
				&& self::mail_calls_have( $pending_moderator_calls, 'message', 'pending notify' )
				&& self::mail_calls_have( $pending_moderator_calls, 'message', $case['token'] )
				&& self::mail_calls_have( $pending_moderator_calls, 'message', 'Approve it:' )
				&& self::mail_calls_have( $pending_moderator_calls, 'headers', 'X-Component-Fuzz-Moderation: ' . $pending_id )
				&& $pending_moderator_after === $approved_moderator_after,
			'pending moderation wrapper sends filtered moderator recipients with moderation actions and approved wrapper sends none',
			array(
				'pendingModeratorCalls' => $pending_moderator_calls,
				'approvedDelta'         => $approved_moderator_after - $pending_moderator_after,
			)
		);
		self::collect_failure(
			$failures,
			2 === count( $direct_moderator_calls )
				&& 2 === count( $direct_author_calls )
				&& 'admin@example.test' === ( $direct_moderator_calls[0]['to'] ?? null )
				&& 'moderator-' . $pending_id . '@example.test' === ( $direct_moderator_calls[1]['to'] ?? null )
				&& $expected_author_email === ( $direct_author_calls[0]['to'] ?? null )
				&& 'watcher-' . $approved_id . '@example.test' === ( $direct_author_calls[1]['to'] ?? null ),
			'direct notification functions compose the same filtered recipient sets without wrapper approval gates',
			array(
				'directModeratorCalls' => $direct_moderator_calls,
				'directAuthorCalls'    => $direct_author_calls,
			)
		);
		self::collect_failure(
			$failures,
			self::event_hooks_seen(
				$post_events,
				$approved_id,
				array(
					'recipients',
					'headers',
					'text',
					'subject',
					'text',
					'subject',
					'recipients',
					'headers',
					'text',
					'subject',
					'text',
					'subject',
				)
			)
				&& self::event_hooks_seen(
					$moderation_events,
					$pending_id,
					array(
						'recipients',
						'headers',
						'text',
						'subject',
						'text',
						'subject',
						'recipients',
						'headers',
						'text',
						'subject',
						'text',
						'subject',
					)
				)
				&& self::notification_gates_seen( $gate_events, $approved_id, $pending_id )
				&& self::filters_are_removed( $filters ),
			'notification filters observe expected comment IDs, run for wrapper/direct paths, and are removed',
			array(
				'postEvents'       => $post_events,
				'moderationEvents' => $moderation_events,
				'gateEvents'       => $gate_events,
				'filtersRemoved'   => self::filters_are_removed( $filters ),
			)
		);

		return self::result(
			$ctx,
			'comment-workflow.notifications.wrapper-direct-mail-filters',
			$failures,
			array(
				'postId'    => $post_id,
				'authorId'  => $author_id,
				'mailCount' => count( $mail_calls ),
			)
		);
	}

	private static function check_allow_comment_decisions( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures       = array();
		$post_id        = self::insert_post( $case, 'open' );
		$duplicate_case = $case;
		$duplicate_case['author'] = 'Duplicate Author ' . $case['token'];
		$data           = self::slashed_comment_data( $duplicate_case, $post_id, 'duplicate seed ' . $case['token'] );
		$first_id = \wp_insert_comment(
			array_merge(
				\wp_unslash( $data ),
				array( 'comment_approved' => '1' )
			)
		);

		$duplicate = \wp_allow_comment( $data, true );
		$flood     = static function () {
			return true;
		};
		\add_filter( 'wp_is_comment_flood', $flood );
		try {
			$flood_result = \wp_allow_comment( self::slashed_comment_data( $case, $post_id, 'flood ' . $case['token'] ), true );
		} finally {
			\remove_filter( 'wp_is_comment_flood', $flood );
		}

		$approval_error = static function () {
			return new \WP_Error( 'component_fuzz_moderation_block', 'Blocked by fuzz filter.' );
		};
		\add_filter( 'pre_comment_approved', $approval_error );
		try {
			$blocked = \wp_allow_comment( self::slashed_comment_data( $case, $post_id, 'blocked ' . $case['token'] ), true );
		} finally {
			\remove_filter( 'pre_comment_approved', $approval_error );
		}

		self::collect_failure(
			$failures,
			is_int( $first_id )
				&& \is_wp_error( $duplicate )
				&& 'comment_duplicate' === $duplicate->get_error_code(),
			'wp_allow_comment detects duplicates as WP_Error when requested',
			array( 'firstId' => $first_id, 'duplicate' => self::error_summary( $duplicate ) )
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $flood_result )
				&& 'comment_flood' === $flood_result->get_error_code()
				&& false === \has_filter( 'wp_is_comment_flood', $flood ),
			'wp_allow_comment flood filter returns comment_flood without leaking filter',
			array( 'flood' => self::error_summary( $flood_result ) )
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $blocked )
				&& 'component_fuzz_moderation_block' === $blocked->get_error_code()
				&& false === \has_filter( 'pre_comment_approved', $approval_error ),
			'pre_comment_approved WP_Error short-circuits approval',
			array( 'blocked' => self::error_summary( $blocked ) )
		);

		return self::result(
			$ctx,
			'comment-workflow.allow-comment.duplicates-flood-and-filter-errors',
			$failures,
			array( 'postId' => $post_id )
		);
	}

	private static function check_update_and_status_transitions( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$post_id  = self::insert_post( $case, 'open' );
		$comment_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => $case['author'],
				'comment_author_email' => $case['email'],
				'comment_author_url'   => $case['url'],
				'comment_content'      => 'status ' . $case['content'],
				'comment_approved'     => '0',
				'comment_type'         => 'comment',
			)
		);

		$events = array();
		$hooks  = self::install_transition_hooks( $events );
		try {
			$updated = \wp_update_comment(
				array(
					'comment_ID'       => $comment_id,
					'comment_content'  => $case['updatedContent'],
					'comment_approved' => 'approve',
				),
				true
			);
			$after_update = \get_comment( $comment_id );
			$held         = \wp_set_comment_status( $comment_id, 'hold', true );
			$after_hold   = \get_comment( $comment_id );
		} finally {
			self::remove_hooks( $hooks );
		}

		$expected_content = \wp_unslash( $case['updatedContent'] );
		$expected_content = function_exists( 'wp_filter_kses' ) ? \wp_filter_kses( $expected_content ) : $expected_content;
		self::collect_failure(
			$failures,
			1 === $updated
				&& $after_update instanceof \WP_Comment
				&& $expected_content === $after_update->comment_content
				&& '1' === (string) $after_update->comment_approved,
			'wp_update_comment updates content and maps approve to approved',
			array(
				'updated'         => $updated,
				'expectedContent' => $expected_content,
				'after'           => self::comment_summary( $after_update ),
			)
		);
		self::collect_failure(
			$failures,
			true === $held
				&& $after_hold instanceof \WP_Comment
				&& '0' === (string) $after_hold->comment_approved,
			'wp_set_comment_status maps hold to unapproved',
			array( 'held' => $held, 'after' => self::comment_summary( $after_hold ) )
		);
		self::collect_failure(
			$failures,
			self::events_are_ordered(
				$events,
				array(
					'edit_comment',
					'transition:approved:unapproved',
					'specific:unapproved_to_approved',
					'set:hold',
					'transition:unapproved:approved',
					'specific:approved_to_unapproved',
				)
			),
			'comment update/status transition hooks fire in expected relative order',
			array( 'events' => $events )
		);

		return self::result(
			$ctx,
			'comment-workflow.update-and-status.transitions-and-hooks',
			$failures,
			array( 'events' => $events )
		);
	}

	private static function check_trash_spam_restore_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$post_id  = self::insert_post( $case, 'open' );
		$comment_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => $case['author'],
				'comment_author_email' => $case['email'],
				'comment_content'      => 'trash spam ' . $case['token'],
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
			)
		);

		$trashed       = \wp_trash_comment( $comment_id );
		$after_trash   = \get_comment( $comment_id );
		$untrashed     = \wp_untrash_comment( $comment_id );
		$after_untrash = \get_comment( $comment_id );
		$spammed       = \wp_spam_comment( $comment_id );
		$after_spam    = \get_comment( $comment_id );
		$unspammed     = \wp_unspam_comment( $comment_id );
		$after_unspam  = \get_comment( $comment_id );

		self::collect_failure(
			$failures,
			true === $trashed
				&& $after_trash instanceof \WP_Comment
				&& 'trash' === $after_trash->comment_approved
				&& true === $untrashed
				&& $after_untrash instanceof \WP_Comment
				&& '1' === (string) $after_untrash->comment_approved,
			'trash and untrash restore prior approved status',
			array(
				'trashed'      => $trashed,
				'afterTrash'   => self::comment_summary( $after_trash ),
				'untrashed'    => $untrashed,
				'afterUntrash' => self::comment_summary( $after_untrash ),
			)
		);
		self::collect_failure(
			$failures,
			true === $spammed
				&& $after_spam instanceof \WP_Comment
				&& 'spam' === $after_spam->comment_approved
				&& true === $unspammed
				&& $after_unspam instanceof \WP_Comment
				&& '1' === (string) $after_unspam->comment_approved,
			'spam and unspam restore prior approved status',
			array(
				'spammed'     => $spammed,
				'afterSpam'   => self::comment_summary( $after_spam ),
				'unspammed'   => $unspammed,
				'afterUnspam' => self::comment_summary( $after_unspam ),
			)
		);

		return self::result(
			$ctx,
			'comment-workflow.trash-spam.restore-previous-status',
			$failures,
			array( 'commentId' => $comment_id )
		);
	}

	private static function check_force_delete_comment_semantics( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$post_id  = self::insert_post( $case, 'open' );
		$parent_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Delete Parent ' . $case['token'],
				'comment_author_email' => 'delete-parent-' . $case['token'] . '@example.test',
				'comment_content'      => 'delete parent ' . $case['token'],
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
			)
		);
		$target_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => $parent_id,
				'comment_author'       => 'Delete Target ' . $case['token'],
				'comment_author_email' => 'delete-target-' . $case['token'] . '@example.test',
				'comment_content'      => 'delete target ' . $case['token'],
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
			)
		);
		$child_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => $target_id,
				'comment_author'       => 'Delete Child ' . $case['token'],
				'comment_author_email' => 'delete-child-' . $case['token'] . '@example.test',
				'comment_content'      => 'delete child ' . $case['token'],
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
			)
		);
		$meta_key   = '_component_fuzz_delete_' . $case['token'];
		$meta_value = 'meta-' . $ctx->int( 1000, 9999 );
		$meta_id    = \add_comment_meta( $target_id, $meta_key, $meta_value, true );
		$before     = array(
			'post'   => \get_post( $post_id ),
			'target' => \get_comment( $target_id ),
			'child'  => \get_comment( $child_id ),
			'meta'   => \get_comment_meta( $target_id, $meta_key, true ),
		);

		$events = array();
		$hooks  = array();
		$add    = static function ( string $hook, callable $callback, int $accepted_args = 1 ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};

		$add(
			'delete_comment',
			static function ( $comment_id, \WP_Comment $comment ) use ( &$events ): void {
				$events[] = array(
					'name'     => 'delete_comment',
					'id'       => (int) $comment_id,
					'objectId' => (int) $comment->comment_ID,
					'status'   => (string) $comment->comment_approved,
					'parent'   => (int) $comment->comment_parent,
				);
			},
			2
		);
		$add(
			'deleted_comment',
			static function ( $comment_id, \WP_Comment $comment ) use ( &$events ): void {
				$events[] = array(
					'name'     => 'deleted_comment',
					'id'       => (int) $comment_id,
					'objectId' => (int) $comment->comment_ID,
					'status'   => (string) $comment->comment_approved,
					'parent'   => (int) $comment->comment_parent,
				);
			},
			2
		);
		$add(
			'delete_comment_meta',
			static function ( array $meta_ids, int $object_id, string $seen_key, $seen_value ) use ( &$events ): void {
				$events[] = array(
					'name'     => 'delete_comment_meta',
					'metaIds'  => array_map( 'intval', $meta_ids ),
					'objectId' => $object_id,
					'key'      => $seen_key,
					'value'    => $seen_value,
				);
			},
			4
		);
		$add(
			'delete_commentmeta',
			static function ( int $seen_meta_id ) use ( &$events ): void {
				$events[] = array(
					'name'   => 'delete_commentmeta',
					'metaId' => $seen_meta_id,
				);
			}
		);
		$add(
			'deleted_comment_meta',
			static function ( array $meta_ids, int $object_id, string $seen_key, $seen_value ) use ( &$events ): void {
				$events[] = array(
					'name'     => 'deleted_comment_meta',
					'metaIds'  => array_map( 'intval', $meta_ids ),
					'objectId' => $object_id,
					'key'      => $seen_key,
					'value'    => $seen_value,
				);
			},
			4
		);
		$add(
			'deleted_commentmeta',
			static function ( int $seen_meta_id ) use ( &$events ): void {
				$events[] = array(
					'name'   => 'deleted_commentmeta',
					'metaId' => $seen_meta_id,
				);
			}
		);
		$add(
			'wp_set_comment_status',
			static function ( $comment_id, string $status ) use ( &$events ): void {
				$events[] = array(
					'name'   => 'wp_set_comment_status',
					'id'     => (int) $comment_id,
					'status' => $status,
				);
			},
			2
		);
		$add(
			'transition_comment_status',
			static function ( string $new_status, string $old_status, \WP_Comment $comment ) use ( &$events ): void {
				$events[] = array(
					'name' => 'transition_comment_status',
					'id'   => (int) $comment->comment_ID,
					'new'  => $new_status,
					'old'  => $old_status,
				);
			},
			3
		);
		$add(
			'comment_approved_to_delete',
			static function ( \WP_Comment $comment ) use ( &$events ): void {
				$events[] = array(
					'name' => 'comment_approved_to_delete',
					'id'   => (int) $comment->comment_ID,
				);
			}
		);
		$add(
			'comment_delete_comment',
			static function ( $comment_id, \WP_Comment $comment ) use ( &$events ): void {
				$events[] = array(
					'name'     => 'comment_delete_comment',
					'id'       => (int) $comment_id,
					'objectId' => (int) $comment->comment_ID,
				);
			},
			2
		);

		try {
			$deleted        = \wp_delete_comment( $target_id, true );
			$missing_delete = \wp_delete_comment( $target_id, true );
		} finally {
			self::remove_hooks( $hooks );
		}

		$after_target = \get_comment( $target_id );
		$after_child  = \get_comment( $child_id );
		$after_parent = \get_comment( $parent_id );
		$after_post   = \get_post( $post_id );
		$after_meta   = \get_comment_meta( $target_id, $meta_key, true );
		$event_names  = array_column( $events, 'name' );

		self::collect_failure(
			$failures,
			is_int( $parent_id )
				&& is_int( $target_id )
				&& is_int( $child_id )
				&& is_int( $meta_id )
				&& $before['post'] instanceof \WP_Post
				&& 3 === (int) $before['post']->comment_count
				&& $before['target'] instanceof \WP_Comment
				&& $before['child'] instanceof \WP_Comment
				&& (int) $target_id === (int) $before['child']->comment_parent
				&& $meta_value === $before['meta'],
			'delete fixture starts with approved parent, target, child, and target meta',
			array(
				'parentId' => $parent_id,
				'targetId' => $target_id,
				'childId'  => $child_id,
				'metaId'   => $meta_id,
				'before'   => array(
					'postCount' => $before['post'] instanceof \WP_Post ? $before['post']->comment_count : null,
					'target'    => self::comment_summary( $before['target'] ),
					'child'     => self::comment_summary( $before['child'] ),
					'meta'      => $before['meta'],
				),
			)
		);
		self::collect_failure(
			$failures,
			true === $deleted
				&& false === $missing_delete
				&& ! ( $after_target instanceof \WP_Comment )
				&& $after_parent instanceof \WP_Comment
				&& $after_child instanceof \WP_Comment
				&& (int) $parent_id === (int) $after_child->comment_parent
				&& $after_post instanceof \WP_Post
				&& 2 === (int) $after_post->comment_count
				&& '' === $after_meta,
			'force deleting an approved comment removes it, reparents children, deletes meta, and refreshes counts',
			array(
				'deleted'       => $deleted,
				'missingDelete' => $missing_delete,
				'afterTarget'   => self::comment_summary( $after_target ),
				'afterParent'   => self::comment_summary( $after_parent ),
				'afterChild'    => self::comment_summary( $after_child ),
				'afterCount'    => $after_post instanceof \WP_Post ? $after_post->comment_count : null,
				'afterMeta'     => $after_meta,
			)
		);
		self::collect_failure(
			$failures,
			self::events_are_ordered(
				$event_names,
				array(
					'delete_comment',
					'delete_comment_meta',
					'delete_commentmeta',
					'deleted_comment_meta',
					'deleted_commentmeta',
					'deleted_comment',
					'wp_set_comment_status',
					'transition_comment_status',
					'comment_approved_to_delete',
					'comment_delete_comment',
				)
			)
				&& array(
					'delete_comment',
					'delete_comment_meta',
					'delete_commentmeta',
					'deleted_comment_meta',
					'deleted_commentmeta',
					'deleted_comment',
					'wp_set_comment_status',
					'transition_comment_status',
					'comment_approved_to_delete',
					'comment_delete_comment',
				) === $event_names
				&& (int) $target_id === (int) ( $events[0]['id'] ?? 0 )
				&& (int) $target_id === (int) ( $events[0]['objectId'] ?? 0 )
				&& '1' === ( $events[0]['status'] ?? null )
				&& (int) $parent_id === (int) ( $events[0]['parent'] ?? 0 )
				&& array( (int) $meta_id ) === ( $events[1]['metaIds'] ?? null )
				&& (int) $target_id === (int) ( $events[1]['objectId'] ?? 0 )
				&& $meta_key === ( $events[1]['key'] ?? null )
				&& $meta_value === ( $events[1]['value'] ?? null )
				&& (int) $meta_id === (int) ( $events[2]['metaId'] ?? 0 )
				&& array( (int) $meta_id ) === ( $events[3]['metaIds'] ?? null )
				&& (int) $target_id === (int) ( $events[3]['objectId'] ?? 0 )
				&& $meta_key === ( $events[3]['key'] ?? null )
				&& $meta_value === ( $events[3]['value'] ?? null )
				&& (int) $meta_id === (int) ( $events[4]['metaId'] ?? 0 )
				&& (int) $target_id === (int) ( $events[5]['id'] ?? 0 )
				&& (int) $target_id === (int) ( $events[5]['objectId'] ?? 0 )
				&& '1' === ( $events[5]['status'] ?? null )
				&& (int) $parent_id === (int) ( $events[5]['parent'] ?? 0 )
				&& 'delete' === ( $events[6]['status'] ?? null )
				&& 'delete' === ( $events[7]['new'] ?? null )
				&& 'approved' === ( $events[7]['old'] ?? null )
				&& (int) $target_id === (int) ( $events[8]['id'] ?? 0 )
				&& (int) $target_id === (int) ( $events[9]['id'] ?? 0 )
				&& (int) $target_id === (int) ( $events[9]['objectId'] ?? 0 )
				&& self::hooks_are_removed( $hooks ),
			'force delete fires delete/status transition hooks with the deleted comment payload and removes hooks',
			array( 'events' => $events )
		);

		return self::result(
			$ctx,
			'comment-workflow.delete-comment.force-delete-reparents-meta-counts-hooks',
			$failures,
			array(
				'postId'   => $post_id,
				'targetId' => $target_id,
				'childId'  => $child_id,
				'events'   => $events,
			)
		);
	}

	private static function check_force_delete_non_counted_count_semantics( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$post_id  = self::insert_post( $case, 'open' );
		$baseline_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Count Baseline ' . $case['token'],
				'comment_author_email' => 'count-baseline-' . $case['token'] . '@example.test',
				'comment_content'      => 'count baseline ' . $case['token'],
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
			)
		);
		$delete_cases = array(
			'pending-comment' => array(
				'approved' => '0',
				'type'     => 'comment',
			),
			'spam-comment'    => array(
				'approved' => 'spam',
				'type'     => 'comment',
			),
			'trash-comment'   => array(
				'approved' => 'trash',
				'type'     => 'comment',
			),
			'approved-note'   => array(
				'approved' => '1',
				'type'     => 'note',
			),
		);
		$comment_ids  = array();

		foreach ( $delete_cases as $label => $spec ) {
			$comment_ids[ $label ] = \wp_insert_comment(
				array(
					'comment_post_ID'      => $post_id,
					'comment_author'       => 'Delete Count ' . $label . ' ' . $case['token'],
					'comment_author_email' => 'delete-count-' . $label . '-' . $case['token'] . '@example.test',
					'comment_content'      => 'delete count ' . $label . ' ' . $case['token'],
					'comment_approved'     => $spec['approved'],
					'comment_type'         => $spec['type'],
				)
			);
		}

		$before_post  = \get_post( $post_id );
		$count_events = array();
		$count_hook   = static function ( int $seen_post_id, int $new, int $old ) use ( &$count_events ): void {
			$count_events[] = array(
				'postId' => $seen_post_id,
				'new'    => $new,
				'old'    => $old,
			);
		};

		\add_action( 'wp_update_comment_count', $count_hook, 10, 3 );
		try {
			$delete_results = array();
			foreach ( $comment_ids as $label => $comment_id ) {
				$delete_results[ $label ] = \wp_delete_comment( $comment_id, true );
			}
		} finally {
			\remove_action( 'wp_update_comment_count', $count_hook, 10 );
		}

		$after_post = \get_post( $post_id );
		$remaining  = array();
		foreach ( $comment_ids as $label => $comment_id ) {
			$remaining[ $label ] = \get_comment( $comment_id );
		}

		self::collect_failure(
			$failures,
			is_int( $baseline_id )
				&& $before_post instanceof \WP_Post
				&& 1 === (int) $before_post->comment_count
				&& $after_post instanceof \WP_Post
				&& 1 === (int) $after_post->comment_count
				&& array_fill_keys( array_keys( $delete_cases ), true ) === $delete_results
				&& array() === array_filter(
					$remaining,
					static function ( $comment ): bool {
						return $comment instanceof \WP_Comment;
					}
				),
			'force deleting pending/spam/trash and approved note comments leaves approved comment count stable',
			array(
				'postId'        => $post_id,
				'baselineId'    => $baseline_id,
				'beforeCount'   => $before_post instanceof \WP_Post ? $before_post->comment_count : null,
				'afterCount'    => $after_post instanceof \WP_Post ? $after_post->comment_count : null,
				'deleteResults' => $delete_results ?? array(),
				'remaining'     => array_map( array( self::class, 'comment_summary' ), $remaining ),
			)
		);
		self::collect_failure(
			$failures,
			array(
				array(
					'postId' => $post_id,
					'new'    => 1,
					'old'    => 1,
				),
			) === $count_events
				&& false === \has_action( 'wp_update_comment_count', $count_hook ),
			'only the approved non-counted note delete refreshes the post count, and the count stays unchanged',
			array( 'countEvents' => $count_events )
		);

		return self::result(
			$ctx,
			'comment-workflow.delete-comment.non-counted-statuses-preserve-counts',
			$failures,
			array(
				'postId'      => $post_id,
				'commentIds'  => $comment_ids,
				'countEvents' => $count_events,
			)
		);
	}

	private static function check_failure_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures       = array();
		$closed_post_id = self::insert_post( $case, 'closed' );
		$missing        = \wp_handle_comment_submission(
			array(
				'comment_post_ID' => 999999,
				'author'          => $case['author'],
				'email'           => $case['email'],
				'comment'         => $case['content'],
			)
		);
		$closed         = \wp_handle_comment_submission(
			array(
				'comment_post_ID' => $closed_post_id,
				'author'          => $case['author'],
				'email'           => $case['email'],
				'comment'         => $case['content'],
			)
		);
		$invalid_update = \wp_update_comment( array( 'comment_ID' => 999999, 'comment_content' => 'missing' ), true );
		$valid_comment_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $closed_post_id,
				'comment_author'       => $case['author'],
				'comment_author_email' => $case['email'],
				'comment_content'      => 'invalid status ' . $case['token'],
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
			)
		);
		$invalid_status = \wp_set_comment_status( $valid_comment_id, 'not-a-comment-status', true );

		self::collect_failure(
			$failures,
			\is_wp_error( $missing ) && 'comment_id_not_found' === $missing->get_error_code(),
			'missing post submission returns comment_id_not_found',
			array( 'missing' => self::error_summary( $missing ) )
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $closed ) && 'comment_closed' === $closed->get_error_code(),
			'closed post submission returns comment_closed',
			array( 'closed' => self::error_summary( $closed ) )
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $invalid_update ) && 'invalid_comment_id' === $invalid_update->get_error_code(),
			'missing comment update returns invalid_comment_id',
			array( 'invalidUpdate' => self::error_summary( $invalid_update ) )
		);
		self::collect_failure(
			$failures,
			false === $invalid_status,
			'invalid comment status returns false without mutation',
			array( 'invalidStatus' => self::error_summary( $invalid_status ) )
		);

		return self::result(
			$ctx,
			'comment-workflow.failure-paths.wp-errors-no-exits',
			$failures
		);
	}

	private static function prepare_runtime(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->component_fuzz_reset_content();
		$wpdb->component_fuzz_reset_options(
			array(
				'admin_email'              => 'admin@example.test',
				'blog_charset'             => 'UTF-8',
				'blogname'                 => 'Component Fuzz',
				'close_comments_days_old'  => 0,
				'comment_max_links'        => 2,
				'comment_moderation'       => 0,
				'comment_order'            => 'asc',
				'comment_registration'     => 0,
				'comments_notify'          => 0,
				'default_comment_status'   => 'open',
				'default_ping_status'      => 'closed',
				'default_role'             => 'subscriber',
				'disallowed_keys'          => '',
				'home'                     => 'http://example.test',
				'moderation_keys'          => '',
				'page_comments'            => 0,
				'require_name_email'       => 0,
				'siteurl'                  => 'http://example.test',
			)
		);

		\wp_cache_flush();
		$GLOBALS['wp_rewrite']              = new \WP_Rewrite();
		$GLOBALS['wp_post_types']           = array();
		$GLOBALS['wp_post_statuses']        = array();
		$GLOBALS['_wp_post_type_features']  = array();
		$GLOBALS['post_type_meta_caps']     = array();
		\create_initial_post_types();
		\wp_set_current_user( 0 );

		$_SERVER['REMOTE_ADDR']      = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT']  = 'ComponentFuzz CommentWorkflow';
		$_SERVER['REQUEST_URI']      = '/component-fuzz/comment-workflow/';
		$_SERVER['HTTP_HOST']        = 'example.test';
		$_SERVER['SERVER_SOFTWARE']  = 'ComponentFuzz';
	}

	private static function insert_post( array $case, string $comment_status, int $post_author = 0 ): int {
		return \wp_insert_post(
			\wp_slash(
				array(
					'post_type'      => 'post',
					'post_title'     => 'Comment Workflow ' . $case['token'],
					'post_content'   => 'Comment workflow host',
					'post_status'    => 'publish',
					'post_name'      => 'comment-workflow-' . $case['token'] . '-' . $comment_status,
					'post_author'    => $post_author,
					'comment_status' => $comment_status,
					'ping_status'    => 'closed',
				)
			),
			true,
			false
		);
	}

	private static function insert_user( \ComponentFuzz\FuzzContext $ctx, array $case ): int {
		$login = 'cfz_comment_user_' . substr( hash( 'sha1', $case['token'] . ':' . $ctx->seed() ), 0, 12 );
		$user  = \wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => 'component-fuzz-pass',
				'user_email'   => $login . '@example.test',
				'display_name' => 'Comment Workflow User ' . $case['token'],
				'role'         => 'subscriber',
			)
		);

		if ( \is_wp_error( $user ) ) {
			throw new \RuntimeException( 'Could not insert comment workflow user: ' . $user->get_error_message() );
		}

		return (int) $user;
	}

	private static function slashed_comment_data( array $case, int $post_id, string $content ): array {
		return \wp_slash(
			array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => 0,
				'comment_author'       => $case['author'],
				'comment_author_email' => $case['email'],
				'comment_author_url'   => $case['url'],
				'comment_content'      => $content,
				'comment_author_IP'    => '127.0.0.1',
				'comment_agent'        => 'ComponentFuzz CommentWorkflow',
				'comment_date'         => '2026-06-23 12:00:00',
				'comment_date_gmt'     => '2026-06-23 12:00:00',
				'comment_type'         => 'comment',
				'user_id'              => 0,
			)
		);
	}

	private static function install_transition_hooks( array &$events ): array {
		$hooks = array();
		$add   = static function ( string $hook, callable $callback, int $accepted_args = 1 ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};

		$add(
			'edit_comment',
			static function () use ( &$events ): void {
				$events[] = 'edit_comment';
			},
			2
		);
		$add(
			'wp_set_comment_status',
			static function ( $comment_id, $status ) use ( &$events ): void {
				unset( $comment_id );
				$events[] = 'set:' . $status;
			},
			2
		);
		$add(
			'transition_comment_status',
			static function ( $new_status, $old_status ) use ( &$events ): void {
				$events[] = 'transition:' . $new_status . ':' . $old_status;
			},
			3
		);
		foreach ( array( 'comment_unapproved_to_approved', 'comment_approved_to_unapproved' ) as $hook ) {
			$add(
				$hook,
				static function () use ( &$events, $hook ): void {
					$events[] = 'specific:' . str_replace( 'comment_', '', $hook );
				}
			);
		}

		return $hooks;
	}

	private static function remove_hooks( array $hooks ): void {
		foreach ( $hooks as $hook ) {
			\remove_action( $hook[0], $hook[1], $hook[2] );
		}
	}

	private static function remove_filters( array $filters ): void {
		foreach ( $filters as $filter ) {
			\remove_filter( $filter[0], $filter[1], $filter[2] );
		}
	}

	private static function hooks_are_removed( array $hooks ): bool {
		foreach ( $hooks as $hook ) {
			if ( false !== \has_action( $hook[0], $hook[1] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function filters_are_removed( array $filters ): bool {
		foreach ( $filters as $filter ) {
			if ( false !== \has_filter( $filter[0], $filter[1] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function mail_calls_have( array $calls, string $field, string $needle ): bool {
		foreach ( $calls as $call ) {
			if ( str_contains( (string) ( $call[ $field ] ?? '' ), $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private static function event_hooks_seen( array $events, int $comment_id, array $expected_hooks ): bool {
		$seen = array();
		foreach ( $events as $event ) {
			if ( (int) ( $event['commentId'] ?? 0 ) === $comment_id ) {
				$seen[] = (string) ( $event['hook'] ?? '' );
			}
		}

		sort( $seen );
		sort( $expected_hooks );

		return $expected_hooks === $seen;
	}

	private static function notification_gates_seen( array $events, int $approved_id, int $pending_id ): bool {
		$counts = array(
			'post-approved-true'     => 0,
			'post-pending-true'      => 0,
			'moderator-approved-false' => 0,
			'moderator-pending-true' => 0,
		);

		foreach ( $events as $event ) {
			$hook       = (string) ( $event['hook'] ?? '' );
			$comment_id = (int) ( $event['commentId'] ?? 0 );
			$maybe      = (bool) ( $event['maybe'] ?? false );

			if ( 'notify_post_author' === $hook && $approved_id === $comment_id && $maybe ) {
				++$counts['post-approved-true'];
			}
			if ( 'notify_post_author' === $hook && $pending_id === $comment_id && $maybe ) {
				++$counts['post-pending-true'];
			}
			if ( 'notify_moderator' === $hook && $approved_id === $comment_id && ! $maybe ) {
				++$counts['moderator-approved-false'];
			}
			if ( 'notify_moderator' === $hook && $pending_id === $comment_id && $maybe ) {
				++$counts['moderator-pending-true'];
			}
		}

		return 1 === $counts['post-approved-true']
			&& 1 === $counts['post-pending-true']
			&& 1 === $counts['moderator-approved-false']
			&& 3 === $counts['moderator-pending-true'];
	}

	private static function events_are_ordered( array $events, array $expected ): bool {
		$offset = -1;
		foreach ( $expected as $event ) {
			$found = array_search( $event, array_slice( $events, $offset + 1 ), true );
			if ( false === $found ) {
				return false;
			}
			$offset += $found + 1;
		}

		return true;
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', 'wp_post_types', 'wp_post_statuses', '_wp_post_type_features', 'post_type_meta_caps', 'wp_rewrite', 'current_user', 'user_ID' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'REQUEST_URI', 'HTTP_HOST', 'SERVER_SOFTWARE' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'server'  => $server,
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		if ( function_exists( 'wp_defer_comment_counting' ) ) {
			\wp_defer_comment_counting( false );
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}
	}

	private static function state_matches( array $snapshot ): bool {
		$wpdb   = $GLOBALS['wpdb'] ?? null;
		$counts = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_content_counts() : array();
		return array() === array_filter( $counts )
			&& $snapshot['options'] === ( $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_get_options() : array() )
			&& false === \has_filter( 'wp_is_comment_flood' );
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = substr( hash( 'sha256', $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );

		return array(
			'token'          => $token,
			'author'         => self::clean_text( $ctx->fork( 'author' )->choice( array( 'Author ' . $token, '<i>Author</i> ' . $token, "A\\B {$token}" ) ), 'Author ' . $token ),
			'email'          => 'comment-workflow-' . $token . '@example.test',
			'url'            => 'http://example.test/comment-workflow-' . $token,
			'content'        => self::clean_text( $ctx->fork( 'content' )->choice( array( 'Content ' . $token, '<p>Content</p> ' . $token, "slashes \\\\ ' \" {$token}" ) ), 'Content ' . $token ),
			'updatedContent' => self::clean_text( $ctx->fork( 'updated' )->choice( array( 'Updated ' . $token, '<em>Updated</em> ' . $token, 'updated text ' . $token ) ), 'Updated ' . $token ),
		);
	}

	private static function clean_text( string $value, string $fallback ): string {
		$value = trim( strip_tags( $value ) );
		if ( '' === $value ) {
			$value = $fallback;
		}

		return substr( $value, 0, 120 );
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			$data + array(
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $label, array $details = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function comment_summary( $comment ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return $comment;
		}

		return array(
			'comment_ID'           => $comment->comment_ID,
			'comment_post_ID'      => $comment->comment_post_ID,
			'comment_author'       => $comment->comment_author,
			'comment_author_email' => $comment->comment_author_email,
			'comment_content'      => $comment->comment_content,
			'comment_approved'     => $comment->comment_approved,
			'comment_type'         => $comment->comment_type,
		);
	}

	private static function error_summary( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return $value;
		}

		return array(
			'code' => $value->get_error_code(),
			'data' => $value->get_error_data(),
		);
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
