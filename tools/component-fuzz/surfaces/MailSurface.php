<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes wp_mail() argument filters, header parsing, and PHPMailer handoff.
 */
final class MailSurface {
	public const NAME = 'mail';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'mail.bootstrap-apis-available',
					'Required WordPress mail APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot  = self::snapshot_state();
		$temp_root = self::make_temp_root( $ctx );
		$rows      = array();

		try {
			$rows[] = self::check_pre_wp_mail_short_circuit( $ctx->fork( 'pre' ) );
			$rows[] = self::check_phpmailer_composition( $ctx->fork( 'compose' ), $temp_root );
			$rows[] = self::check_string_header_and_path_parsing( $ctx->fork( 'strings' ), $temp_root );
			$rows[] = self::check_multipart_boundary_preservation( $ctx->fork( 'multipart' ) );
			$rows[] = self::check_rfc2822_display_name_header_encoding( $ctx->fork( 'address-encoding' ) );
			$rows[] = self::check_unicode_recipient_handoff( $ctx->fork( 'unicode' ) );
			$rows[] = self::check_unicode_domain_recipient_handoff( $ctx->fork( 'unicode-domain' ) );
			$rows[] = self::check_phpmailer_reuse_resets_message_state( $ctx->fork( 'reuse' ), $temp_root );
			$rows[] = self::check_phpmailer_failure_action( $ctx->fork( 'failure' ) );
			$rows[] = self::check_invalid_from_address_failure( $ctx->fork( 'from-failure' ), $temp_root );
			$rows[] = self::check_staticize_emoji_for_email( $ctx->fork( 'emoji' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'mail.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $temp_root ) {
				self::remove_dir_recursive( $temp_root );
			}
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'mail.global-state-restored',
			self::state_matches( $snapshot ) && ( null === $temp_root || ! is_dir( $temp_root ) ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'tempRoot'       => $temp_root,
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_PHPMailer', 'WP_Error', 'PHPMailer\PHPMailer\PHPMailer', 'PHPMailer\PHPMailer\Exception' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'do_action',
				'has_filter',
				'remove_action',
				'remove_filter',
				'wp_mail',
				'wp_specialchars_decode',
				'wp_staticize_emoji',
				'wp_staticize_emoji_for_email',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_pre_wp_mail_short_circuit( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$seen     = array();
		$token    = 'subject-' . $ctx->identifier( 4, 10 );

		$mail_filter = static function ( array $atts ) use ( $token ): array {
			$atts['subject'] .= ' [' . $token . ']';
			$atts['headers'] = array_merge( (array) $atts['headers'], array( 'X-Filtered: yes' ) );
			return $atts;
		};
		$pre_filter  = static function ( $pre, array $atts ) use ( &$seen ) {
			$seen[] = $atts;
			return 'short-circuited';
		};
		$init_calls  = 0;
		$init_action = static function () use ( &$init_calls ): void {
			++$init_calls;
		};

		\add_filter( 'wp_mail', $mail_filter );
		\add_filter( 'pre_wp_mail', $pre_filter, 10, 2 );
		\add_action( 'phpmailer_init', $init_action );
		try {
			$result = \wp_mail(
				array( 'person@example.test' ),
				'Original',
				'Message',
				array( 'From: Sender <sender@example.test>' )
			);
		} finally {
			\remove_filter( 'wp_mail', $mail_filter );
			\remove_filter( 'pre_wp_mail', $pre_filter, 10 );
			\remove_action( 'phpmailer_init', $init_action );
		}

		self::collect_failure(
			$failures,
			'short-circuited' === $result
				&& 1 === count( $seen )
				&& 'Original [' . $token . ']' === ( $seen[0]['subject'] ?? null )
				&& in_array( 'X-Filtered: yes', (array) ( $seen[0]['headers'] ?? array() ), true )
				&& 0 === $init_calls
				&& false === \has_filter( 'pre_wp_mail', $pre_filter ),
			'pre_wp_mail receives filtered arguments and prevents PHPMailer initialization',
			array(
				'result'    => $result,
				'seen'      => self::describe_value( $seen ),
				'initCalls' => $init_calls,
			)
		);

		return self::row( $ctx, 'mail.pre_wp_mail.short-circuit-filtered-atts', $failures );
	}

	private static function check_phpmailer_composition( \ComponentFuzz\FuzzContext $ctx, ?string $temp_root ): array {
		$failures = array();

		if ( null === $temp_root ) {
			return $ctx->skip( 'mail.phpmailer.composition', 'Could not create temporary attachment root.' );
		}

		$attachment = $temp_root . DIRECTORY_SEPARATOR . 'attachment-' . $ctx->identifier( 4, 8 ) . '.txt';
		$embed      = $temp_root . DIRECTORY_SEPARATOR . 'embed-' . $ctx->identifier( 4, 8 ) . '.png';
		file_put_contents( $attachment, 'attachment ' . $ctx->text( 0, 16 ) );
		file_put_contents( $embed, "\x89PNG\r\n\x1A\n" . $ctx->identifier( 4, 8 ) );

		$subject      = 'Mail Subject ' . $ctx->identifier( 4, 12 );
		$message      = '<p>Body &amp; ' . $ctx->identifier( 3, 8 ) . '</p>';
		$embed_calls  = array();
		$init_seen    = array();
		$succeeded    = array();
		$from_filter  = static fn (): string => 'filtered-from@example.test';
		$name_filter  = static fn (): string => 'Filtered Sender';
		$type_filter  = static fn (): string => 'text/html';
		$charset_filter = static fn (): string => 'UTF-8';
		$embed_filter = static function ( array $args ) use ( &$embed_calls ): array {
			$embed_calls[] = $args;
			$args['cid']  .= '-filtered';
			$args['name']  = 'filtered-' . $args['name'];
			$args['type']  = 'image/png';
			return $args;
		};
		$init_action  = static function ( $mailer ) use ( &$init_seen ): void {
			$init_seen[] = array(
				'class'       => is_object( $mailer ) ? get_class( $mailer ) : gettype( $mailer ),
				'contentType' => $mailer->ContentType ?? null,
				'charset'     => $mailer->CharSet ?? null,
			);
		};
		$success_action = static function ( array $mail_data ) use ( &$succeeded ): void {
			$succeeded[] = $mail_data;
		};

		MailSurfaceMailer::$mode = 'success';
		MailSurfaceMailer::$sent = array();
		$GLOBALS['phpmailer']    = self::new_mailer();

		\add_filter( 'wp_mail_from', $from_filter );
		\add_filter( 'wp_mail_from_name', $name_filter );
		\add_filter( 'wp_mail_content_type', $type_filter );
		\add_filter( 'wp_mail_charset', $charset_filter );
		\add_filter( 'wp_mail_embed_args', $embed_filter );
		\add_action( 'phpmailer_init', $init_action );
		\add_action( 'wp_mail_succeeded', $success_action );
		try {
			$result = \wp_mail(
				array(
					'Recipient One <one@example.test>',
					'two@example.test',
				),
				$subject,
				$message,
				array(
					'From: Header Sender <header@example.test>',
					'Cc: Copy One <copy@example.test>, copy2@example.test',
					'Bcc: Blind <blind@example.test>',
					'Reply-To: Reply <reply@example.test>',
					'Content-Type: text/plain; charset=ISO-8859-1',
					'X-Fuzz-Token: ' . $ctx->identifier( 4, 10 ),
					'MIME-Version: should-not-be-custom',
				),
				array( 'named.txt' => $attachment ),
				array( 'logo' => $embed )
			);
		} finally {
			\remove_filter( 'wp_mail_from', $from_filter );
			\remove_filter( 'wp_mail_from_name', $name_filter );
			\remove_filter( 'wp_mail_content_type', $type_filter );
			\remove_filter( 'wp_mail_charset', $charset_filter );
			\remove_filter( 'wp_mail_embed_args', $embed_filter );
			\remove_action( 'phpmailer_init', $init_action );
			\remove_action( 'wp_mail_succeeded', $success_action );
		}

		$sent = MailSurfaceMailer::$sent[0] ?? array();
		self::collect_failure(
			$failures,
			true === $result
				&& 1 === count( MailSurfaceMailer::$sent )
				&& 'filtered-from@example.test' === ( $sent['from'] ?? null )
				&& 'Filtered Sender' === ( $sent['fromName'] ?? null )
				&& $subject === ( $sent['subject'] ?? null )
				&& $message === ( $sent['body'] ?? null )
				&& 'text/html' === ( $sent['contentType'] ?? null )
				&& 'UTF-8' === ( $sent['charset'] ?? null )
				&& self::addresses_include( $sent['to'] ?? array(), 'one@example.test', 'Recipient One' )
				&& self::addresses_include( $sent['to'] ?? array(), 'two@example.test', '' )
				&& self::addresses_include( $sent['cc'] ?? array(), 'copy@example.test', 'Copy One' )
				&& self::addresses_include( $sent['cc'] ?? array(), 'copy2@example.test', '' )
				&& self::addresses_include( $sent['bcc'] ?? array(), 'blind@example.test', 'Blind' )
				&& self::addresses_include( $sent['replyTo'] ?? array(), 'reply@example.test', 'Reply' )
				&& self::attachments_include( $sent['attachments'] ?? array(), 'named.txt', 'attachment' )
				&& self::attachments_include( $sent['attachments'] ?? array(), 'filtered-' . basename( $embed ), 'inline', 'logo-filtered' )
				&& 1 === count( $sent['customHeaders'] ?? array() )
				&& self::custom_headers_include( $sent['customHeaders'] ?? array(), 'X-Fuzz-Token' )
				&& 1 === count( $embed_calls )
				&& 1 === count( $init_seen )
				&& 1 === count( $succeeded )
				&& false === \has_filter( 'wp_mail_embed_args', $embed_filter ),
			'wp_mail parses headers, recipients, attachments, embeds, content filters, and success action before intercepted send',
			array(
				'result'     => $result,
				'sent'       => self::describe_value( $sent ),
				'embedCalls' => self::describe_value( $embed_calls ),
				'initSeen'   => self::describe_value( $init_seen ),
				'succeeded'  => self::describe_value( $succeeded ),
			)
		);

		return self::row( $ctx, 'mail.phpmailer.composition-and-actions', $failures );
	}

	private static function check_string_header_and_path_parsing( \ComponentFuzz\FuzzContext $ctx, ?string $temp_root ): array {
		$failures = array();

		if ( null === $temp_root ) {
			return $ctx->skip( 'mail.phpmailer.string-header-and-path-parsing', 'Could not create temporary attachment root.' );
		}

		$attachment_a = $temp_root . DIRECTORY_SEPARATOR . 'string-attachment-a-' . $ctx->identifier( 4, 8 ) . '.txt';
		$attachment_b = $temp_root . DIRECTORY_SEPARATOR . 'string-attachment-b-' . $ctx->identifier( 4, 8 ) . '.txt';
		$embed        = $temp_root . DIRECTORY_SEPARATOR . 'string-embed-' . $ctx->identifier( 4, 8 ) . '.gif';
		file_put_contents( $attachment_a, 'attachment-a ' . $ctx->text( 0, 16 ) );
		file_put_contents( $attachment_b, 'attachment-b ' . $ctx->text( 0, 16 ) );
		file_put_contents( $embed, "GIF89a" . $ctx->identifier( 4, 8 ) );

		$token     = 'str-' . $ctx->identifier( 4, 10 );
		$to        = 'Alpha Recipient <alpha@example.test>, beta@example.test';
		$subject   = 'String Header ' . $token;
		$message   = '<strong>' . $token . '</strong>';
		$headers   = implode(
			"\r\n",
			array(
				'From: String Sender <string-sender@example.test>',
				'Cc: Carbon One <carbon@example.test>, carbon2@example.test',
				'Bcc: Blind One <blind-string@example.test>',
				'Reply-To: Reply String <reply-string@example.test>',
				'Content-Type: text/html; charset=UTF-8',
				'X-String-Token: ' . $token,
				'MIME-Version: ignored-custom',
				'X-Mailer: ignored-custom',
			)
		);
		$succeeded = array();

		MailSurfaceMailer::$mode = 'success';
		MailSurfaceMailer::$sent = array();
		$GLOBALS['phpmailer']    = self::new_mailer();

		$success_action = static function ( array $mail_data ) use ( &$succeeded ): void {
			$succeeded[] = $mail_data;
		};

		\add_action( 'wp_mail_succeeded', $success_action );
		try {
			$result = \wp_mail(
				$to,
				$subject,
				$message,
				$headers,
				$attachment_a . "\n" . $attachment_b,
				$embed
			);
		} finally {
			\remove_action( 'wp_mail_succeeded', $success_action );
		}

		$sent = MailSurfaceMailer::$sent[0] ?? array();
		self::collect_failure(
			$failures,
			true === $result
				&& 1 === count( MailSurfaceMailer::$sent )
				&& self::addresses_include( $sent['to'] ?? array(), 'alpha@example.test', 'Alpha Recipient' )
				&& self::addresses_include( $sent['to'] ?? array(), 'beta@example.test', '' )
				&& self::addresses_include( $sent['cc'] ?? array(), 'carbon@example.test', 'Carbon One' )
				&& self::addresses_include( $sent['cc'] ?? array(), 'carbon2@example.test', '' )
				&& self::addresses_include( $sent['bcc'] ?? array(), 'blind-string@example.test', 'Blind One' )
				&& self::addresses_include( $sent['replyTo'] ?? array(), 'reply-string@example.test', 'Reply String' )
				&& 'string-sender@example.test' === ( $sent['from'] ?? null )
				&& 'String Sender' === ( $sent['fromName'] ?? null )
				&& $subject === ( $sent['subject'] ?? null )
				&& $message === ( $sent['body'] ?? null )
				&& 'text/html' === ( $sent['contentType'] ?? null )
				&& 'UTF-8' === ( $sent['charset'] ?? null )
				&& self::attachments_include( $sent['attachments'] ?? array(), basename( $attachment_a ), 'attachment' )
				&& self::attachments_include( $sent['attachments'] ?? array(), basename( $attachment_b ), 'attachment' )
				&& self::attachments_include( $sent['attachments'] ?? array(), basename( $embed ), 'inline', '0' )
				&& 1 === count( $sent['customHeaders'] ?? array() )
				&& self::custom_headers_include( $sent['customHeaders'] ?? array(), 'X-String-Token' )
				&& 1 === count( $succeeded )
				&& is_array( $succeeded[0]['to'] ?? null )
				&& array( $attachment_a, $attachment_b ) === ( $succeeded[0]['attachments'] ?? null )
				&& array( $embed ) === ( $succeeded[0]['embeds'] ?? null ),
			'wp_mail parses comma recipients plus newline headers, attachments, and embeds',
			array(
				'result'    => $result,
				'sent'      => self::describe_value( $sent ),
				'succeeded' => self::describe_value( $succeeded ),
			)
		);

		return self::row( $ctx, 'mail.phpmailer.string-header-and-path-parsing', $failures );
	}

	private static function check_multipart_boundary_preservation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array(
				'label'          => 'array-lf-alternative',
				'inputSubtype'   => 'Alternative',
				'expectedSubtype' => 'alternative',
				'headerForm'     => 'array',
				'eol'            => "\n",
				'boundaryParam'  => 'boundary',
				'quotedBoundary' => true,
				'partCount'      => 2,
			),
			array(
				'label'          => 'string-crlf-mixed-uppercase-boundary',
				'inputSubtype'   => 'Mixed',
				'expectedSubtype' => 'mixed',
				'headerForm'     => 'string',
				'eol'            => "\r\n",
				'boundaryParam'  => 'BOUNDARY',
				'quotedBoundary' => true,
				'partCount'      => 3,
			),
			array(
				'label'          => 'array-crlf-related-unquoted-boundary',
				'inputSubtype'   => 'related',
				'expectedSubtype' => 'related',
				'headerForm'     => 'array',
				'eol'            => "\r\n",
				'boundaryParam'  => 'boundary',
				'quotedBoundary' => false,
				'partCount'      => 3,
			),
		);

		foreach ( $cases as $index => $case ) {
			$case_ctx = $ctx->fork( $case['label'] );
			$token    = preg_replace( '/[^A-Za-z0-9]+/', '', $case_ctx->identifier( 6, 12 ) );
			$token    = '' === $token ? 'boundary' . abs( $case_ctx->seed() % 100000 ) : $token;
			$boundary = '----=_ComponentFuzz_' . $index . '_' . $token;
			$parts    = self::multipart_parts( $case_ctx, (int) $case['partCount'] );
			$message  = self::multipart_body( $boundary, $parts, $case['eol'] );
			$subject  = 'Multipart ' . $case['label'] . ' ' . $token;
			$content_type = 'Content-Type: multipart/' . $case['inputSubtype'] . '; ' . $case['boundaryParam'] . '=';
			$content_type .= $case['quotedBoundary'] ? '"' . $boundary . '"' : $boundary;
			$headers = array(
				'From: Multipart Sender <multipart@example.test>',
				$content_type,
				'MIME-Version: ignored-custom',
				'X-Multipart-Token: ' . $token,
			);

			if ( 'string' === $case['headerForm'] ) {
				$headers = implode( $case['eol'], $headers );
			}

			$init_seen = array();

			MailSurfaceMailer::$mode = 'success';
			MailSurfaceMailer::$sent = array();
			$GLOBALS['phpmailer']    = self::new_mailer();

			$init_action = static function ( $mailer ) use ( &$init_seen ): void {
				$init_seen[] = array(
					'contentType' => $mailer->ContentType ?? null,
					'charset'     => $mailer->CharSet ?? null,
					'body'        => $mailer->Body ?? null,
				);
			};

			\add_action( 'phpmailer_init', $init_action );
			try {
				$result = \wp_mail( 'multipart-recipient@example.test', $subject, $message, $headers );
			} finally {
				\remove_action( 'phpmailer_init', $init_action );
			}

			$sent         = MailSurfaceMailer::$sent[0] ?? array();
			$mime_message = self::normalize_eol( (string) ( $sent['mimeMessage'] ?? '' ) );
			$mime_headers = self::mime_headers( $mime_message );
			$expected_content_type = 'multipart/' . $case['expectedSubtype'] . '; boundary="' . $boundary . '"';

			self::collect_failure(
				$failures,
				true === $result
					&& 1 === count( MailSurfaceMailer::$sent )
					&& 1 === count( $init_seen )
					&& $expected_content_type === ( $sent['contentType'] ?? null )
					&& '' === ( $sent['charset'] ?? null )
					&& self::normalize_eol( $message ) === self::normalize_eol( (string) ( $sent['body'] ?? '' ) )
					&& $expected_content_type === ( $init_seen[0]['contentType'] ?? null )
					&& '' === ( $init_seen[0]['charset'] ?? null )
					&& self::normalize_eol( $message ) === self::normalize_eol( (string) ( $init_seen[0]['body'] ?? '' ) )
					&& self::addresses_include( $sent['to'] ?? array(), 'multipart-recipient@example.test', '' )
					&& self::custom_headers_include( $sent['customHeaders'] ?? array(), 'X-Multipart-Token' )
					&& ! self::custom_headers_include( $sent['customHeaders'] ?? array(), 'Content-Type' )
					&& ! self::custom_headers_include( $sent['customHeaders'] ?? array(), 'MIME-Version' )
					&& str_contains( $mime_headers, 'Content-Type: multipart/' . $case['expectedSubtype'] . ';' )
					&& str_contains( $mime_headers, 'boundary="' . $boundary . '"' )
					&& 1 === substr_count( $mime_headers, 'Content-Type: multipart/' . $case['expectedSubtype'] . ';' )
					&& count( $parts ) + 1 === substr_count( $mime_message, '--' . $boundary )
					&& self::mime_message_contains_parts( $mime_message, $boundary, $parts )
					&& str_contains( $mime_message, '--' . $boundary . '--' ),
				'wp_mail preserves multipart boundary headers and body parts through PHPMailer for ' . $case['label'],
				array(
					'case'        => $case,
					'result'      => $result,
					'contentType' => self::describe_value( $sent['contentType'] ?? null ),
					'charset'     => self::describe_value( $sent['charset'] ?? null ),
					'mimeHeaders' => self::describe_string( $mime_headers ),
					'initSeen'    => self::describe_value( $init_seen ),
				)
			);
		}

		return self::row( $ctx, 'mail.phpmailer.multipart-boundary-matrix', $failures );
	}

	private static function check_rfc2822_display_name_header_encoding( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'iconv_mime_decode' ) ) {
			return $ctx->skip( 'mail.phpmailer.rfc2822-display-name-header-encoding', 'iconv MIME decoding is unavailable.' );
		}

		$failures  = array();
		$succeeded = array();
		$token     = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 4, 8 ) ) );
		$token     = '' === $token ? 'addr' . abs( $ctx->seed() % 10000 ) : $token;

		$to_name    = "Luk\u{00E1}\u{0161} To {$token}";
		$from_name  = "Luk\u{00E1}\u{0161} From {$token}";
		$cc_name    = "Luk\u{00E1}\u{0161} Cc {$token}";
		$bcc_name   = "Luk\u{00E1}\u{0161} Bcc {$token}";
		$reply_name = "Luk\u{00E1}\u{0161} Reply {$token}";

		$to_email    = "to-{$token}@example.org";
		$from_email  = "from-{$token}@example.org";
		$cc_email    = "cc-{$token}@example.org";
		$bcc_email   = "bcc-{$token}@example.org";
		$reply_email = "reply-{$token}@example.org";
		$subject     = 'Address Encoding ' . $token;
		$message     = 'Only display names should be MIME encoded for ' . $token . '.';

		$success_action = static function ( array $mail_data ) use ( &$succeeded ): void {
			$succeeded[] = $mail_data;
		};

		MailSurfaceMailer::$mode = 'success';
		MailSurfaceMailer::$sent = array();
		$GLOBALS['phpmailer']    = self::new_mailer();

		\add_action( 'wp_mail_succeeded', $success_action );
		try {
			$result = \wp_mail(
				$to_name . ' <' . $to_email . '>',
				$subject,
				$message,
				array(
					'From: ' . $from_name . '<' . $from_email . '>',
					'Cc: ' . $cc_name . ' <' . $cc_email . '>',
					'Bcc: ' . $bcc_name . ' <' . $bcc_email . '>',
					'Reply-To: ' . $reply_name . ' <' . $reply_email . '>',
					'Content-Type: text/plain; charset=UTF-8',
					'X-Address-Token: ' . $token,
				)
			);
		} finally {
			\remove_action( 'wp_mail_succeeded', $success_action );
		}

		$sent         = MailSurfaceMailer::$sent[0] ?? array();
		$mime_headers = self::mime_headers( (string) ( $sent['mimeMessage'] ?? '' ) );
		$header_lines = array(
			'To'       => self::mime_header_lines( $mime_headers, 'To' ),
			'From'     => self::mime_header_lines( $mime_headers, 'From' ),
			'Cc'       => self::mime_header_lines( $mime_headers, 'Cc' ),
			'Bcc'      => self::mime_header_lines( $mime_headers, 'Bcc' ),
			'Reply-To' => self::mime_header_lines( $mime_headers, 'Reply-To' ),
		);

		self::collect_failure(
			$failures,
			true === $result
				&& 1 === count( MailSurfaceMailer::$sent )
				&& self::addresses_include( $sent['to'] ?? array(), $to_email, $to_name )
				&& self::addresses_include( $sent['cc'] ?? array(), $cc_email, $cc_name )
				&& self::addresses_include( $sent['bcc'] ?? array(), $bcc_email, $bcc_name )
				&& self::addresses_include( $sent['replyTo'] ?? array(), $reply_email, $reply_name )
				&& $from_email === ( $sent['from'] ?? null )
				&& $from_name === ( $sent['fromName'] ?? null )
				&& '' === ( $sent['sender'] ?? null )
				&& false === ( $sent['useSMTPUTF8'] ?? true )
				&& self::custom_headers_include( $sent['customHeaders'] ?? array(), 'X-Address-Token' )
				&& 1 === count( $succeeded )
				&& $to_name . ' <' . $to_email . '>' === ( $succeeded[0]['to'][0] ?? null ),
			'RFC2822 address parts are handed to PHPMailer while Core leaves the SMTP Sender unset',
			array(
				'result'    => $result,
				'sent'      => self::describe_value( $sent ),
				'succeeded' => self::describe_value( $succeeded ),
			)
		);

		foreach (
			array(
				'To'       => array( $to_name, $to_email ),
				'From'     => array( $from_name, $from_email ),
				'Cc'       => array( $cc_name, $cc_email ),
				'Bcc'      => array( $bcc_name, $bcc_email ),
				'Reply-To' => array( $reply_name, $reply_email ),
			) as $header => $expected
		) {
			list( $name, $email ) = $expected;
			$line    = $header_lines[ $header ][0] ?? '';
			$decoded = self::decode_mime_header_value( $line, $header );

			self::collect_failure(
				$failures,
				1 === count( $header_lines[ $header ] ?? array() )
					&& self::address_header_encodes_name_only( $line, $header, $name, $email )
					&& $name . ' <' . $email . '>' === $decoded,
				$header . ' MIME header encodes only the display name, leaves the address literal, and decodes losslessly',
				array(
					'count'   => count( $header_lines[ $header ] ?? array() ),
					'line'    => self::describe_string( $line ),
					'decoded' => self::describe_value( $decoded ),
					'name'    => self::describe_string( $name ),
					'email'   => $email,
				)
			);
		}

		self::collect_failure(
			$failures,
			str_contains( $mime_headers, 'X-Address-Token: ' . $token )
				&& ! str_contains( $mime_headers, $to_name )
				&& ! str_contains( $mime_headers, $from_name )
				&& ! str_contains( $mime_headers, $cc_name )
				&& ! str_contains( $mime_headers, $bcc_name )
				&& ! str_contains( $mime_headers, $reply_name )
				&& false === \has_filter( 'wp_mail_succeeded', $success_action ),
			'final MIME headers contain generated custom headers and no raw UTF-8 display names after cleanup',
			array(
				'headers'       => self::describe_string( $mime_headers ),
				'successFilter' => \has_filter( 'wp_mail_succeeded', $success_action ),
			)
		);

		return self::row( $ctx, 'mail.phpmailer.rfc2822-display-name-header-encoding', $failures );
	}

	private static function check_unicode_recipient_handoff( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'is_email' ) || ! function_exists( 'wp_is_unicode_email' ) || ! class_exists( 'WP_Email_Address' ) ) {
			return $ctx->skip( 'mail.phpmailer.unicode-recipient-handoff', 'Unicode email APIs are unavailable.' );
		}

		$failures          = array();
		$token             = preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $ctx->identifier( 4, 8 ) ) );
		$token             = trim( (string) $token, '-' );
		$token             = '' === $token ? 'mail' . ( $ctx->seed() % 10000 ) : $token;
		$to_email          = "gr\u{00E5}-{$token}@example.test";
		$cc_email          = "c\u{00E9}cile-{$token}@example.test";
		$reply_email       = "r\u{00E9}ply-{$token}@example.test";
		$to_name           = "Gr\u{00E5} Recipient";
		$cc_name           = "C\u{00E9}cile Copy";
		$reply_name        = "R\u{00E9}ply Contact";
		$subject           = "Unicode Subject \u{2603} {$token}";
		$message           = "Unicode body gr\u{00E5} c\u{00E9}cile \u{2603} {$token}";
		$succeeded         = array();
		$had_unicode_email = false !== \has_filter( 'is_email', 'wp_is_unicode_email' );
		$previous_validator = \PHPMailer\PHPMailer\PHPMailer::$validator;
		$success_action    = static function ( array $mail_data ) use ( &$succeeded ): void {
			$succeeded[] = $mail_data;
		};

		if ( ! $had_unicode_email ) {
			\add_filter( 'is_email', 'wp_is_unicode_email', 10, 3 );
		}

		MailSurfaceMailer::$mode = 'success';
		MailSurfaceMailer::$sent = array();
		$GLOBALS['phpmailer']    = self::new_mailer();
		\PHPMailer\PHPMailer\PHPMailer::$validator = static function ( $email ): bool {
			return (bool) \is_email( $email );
		};
		$to_address     = \WP_Email_Address::from_string( $to_email, 'unicode' );
		$cc_address     = \WP_Email_Address::from_string( $cc_email, 'unicode' );
		$reply_address  = \WP_Email_Address::from_string( $reply_email, 'unicode' );
		$to_is_email    = \is_email( $to_email );
		$cc_is_email    = \is_email( $cc_email );
		$reply_is_email = \is_email( $reply_email );

		\add_action( 'wp_mail_succeeded', $success_action );
		try {
			$result = \wp_mail(
				array( $to_name . ' <' . $to_email . '>' ),
				$subject,
				$message,
				array(
					'From: Unicode Sender <unicode-sender@example.test>',
					'Cc: ' . $cc_name . ' <' . $cc_email . '>',
					'Reply-To: ' . $reply_name . ' <' . $reply_email . '>',
					'Content-Type: text/plain; charset=UTF-8',
					'X-Unicode-Token: ' . $token,
				)
			);
		} finally {
			\remove_action( 'wp_mail_succeeded', $success_action );
			\PHPMailer\PHPMailer\PHPMailer::$validator = $previous_validator;
			if ( ! $had_unicode_email ) {
				\remove_filter( 'is_email', 'wp_is_unicode_email', 10 );
			}
		}

		$sent         = MailSurfaceMailer::$sent[0] ?? array();
		$succeeded_to = (array) ( $succeeded[0]['to'] ?? array() );

		self::collect_failure(
			$failures,
			$to_address instanceof \WP_Email_Address
				&& $cc_address instanceof \WP_Email_Address
				&& $reply_address instanceof \WP_Email_Address
				&& $to_email === $to_is_email
				&& $cc_email === $cc_is_email
				&& $reply_email === $reply_is_email,
			'generated UTF-8 local-part addresses are valid under the Unicode email filter',
			array(
				'to'      => self::describe_value( $to_address ),
				'cc'      => self::describe_value( $cc_address ),
				'replyTo' => self::describe_value( $reply_address ),
			)
		);

		self::collect_failure(
			$failures,
			true === $result
				&& 1 === count( MailSurfaceMailer::$sent )
				&& self::addresses_include( $sent['to'] ?? array(), $to_email, $to_name )
				&& self::addresses_include( $sent['cc'] ?? array(), $cc_email, $cc_name )
				&& self::addresses_include( $sent['replyTo'] ?? array(), $reply_email, $reply_name )
				&& $subject === ( $sent['subject'] ?? null )
				&& $message === ( $sent['body'] ?? null )
				&& 'text/plain' === ( $sent['contentType'] ?? null )
				&& 'UTF-8' === ( $sent['charset'] ?? null )
				&& self::custom_headers_include( $sent['customHeaders'] ?? array(), 'X-Unicode-Token' )
				&& 1 === count( $succeeded )
				&& in_array( $to_name . ' <' . $to_email . '>', $succeeded_to, true )
				&& false === \has_filter( 'wp_mail_succeeded', $success_action ),
			'wp_mail hands UTF-8 local-part recipients, display names, subject, body, and headers to PHPMailer',
			array(
				'result'      => $result,
				'sent'        => self::describe_value( $sent ),
				'succeeded'   => self::describe_value( $succeeded ),
				'filterState' => \has_filter( 'is_email', 'wp_is_unicode_email' ),
			)
		);

		return self::row( $ctx, 'mail.phpmailer.unicode-recipient-handoff', $failures );
	}

	private static function check_unicode_domain_recipient_handoff( \ComponentFuzz\FuzzContext $ctx ): array {
		if (
			! function_exists( 'is_email' )
			|| ! function_exists( 'idn_to_ascii' )
			|| ! function_exists( 'idn_to_utf8' )
			|| ! function_exists( 'wp_is_unicode_email' )
			|| ! class_exists( 'WP_Email_Address' )
		) {
			return $ctx->skip( 'mail.phpmailer.unicode-domain-recipient-handoff', 'Unicode email or IDN APIs are unavailable.' );
		}

		$failures       = array();
		$token          = preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $ctx->identifier( 4, 8 ) ) );
		$token          = trim( (string) $token, '-' );
		$token          = '' === $token ? 'idn' . ( $ctx->seed() % 10000 ) : $token;
		$unicode_domain = "gr\u{00E5}.org";
		$ascii_domain   = idn_to_ascii( $unicode_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		$decoded_domain = false === $ascii_domain ? false : idn_to_utf8( $ascii_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );

		if ( false === $ascii_domain || false === $decoded_domain ) {
			return $ctx->skip(
				'mail.phpmailer.unicode-domain-recipient-handoff',
				'IDN conversion failed for the generated domain.',
				array( 'domain' => self::describe_string( $unicode_domain ) )
			);
		}

		$unicode_local   = "m\u{00F8}de-{$token}";
		$punycode_local  = 'books-' . $token;
		$reply_local     = "svar-{$token}";
		$unicode_email   = $unicode_local . '@' . $unicode_domain;
		$punycode_email  = $punycode_local . '@' . $ascii_domain;
		$reply_email     = $reply_local . '@' . $unicode_domain;
		$unicode_name    = "\u{00C5}sa Domain";
		$punycode_name   = "B\u{00FC}cher Copy";
		$reply_name      = "Gr\u{00E5} Reply";
		$subject         = "Unicode Domain Subject \u{2603} {$token}";
		$message         = "Unicode domain body {$unicode_domain} {$ascii_domain} \u{2603}";
		$succeeded       = array();
		$had_unicode     = false !== \has_filter( 'is_email', 'wp_is_unicode_email' );
		$previous_validator = \PHPMailer\PHPMailer\PHPMailer::$validator;
		$success_action  = static function ( array $mail_data ) use ( &$succeeded ): void {
			$succeeded[] = $mail_data;
		};

		if ( ! $had_unicode ) {
			\add_filter( 'is_email', 'wp_is_unicode_email', 10, 3 );
		}

		MailSurfaceMailer::$mode = 'success';
		MailSurfaceMailer::$sent = array();
		$GLOBALS['phpmailer']    = self::new_mailer();
		\PHPMailer\PHPMailer\PHPMailer::$validator = static function ( $email ): bool {
			return (bool) \is_email( $email );
		};

		$unicode_address = \WP_Email_Address::from_string( $unicode_email, 'unicode' );
		$punycode_address = \WP_Email_Address::from_string( $punycode_email, 'unicode' );
		$reply_address   = \WP_Email_Address::from_string( $reply_email, 'unicode' );
		$unicode_is      = \is_email( $unicode_email );
		$punycode_is     = \is_email( $punycode_email );
		$reply_is        = \is_email( $reply_email );

		\add_action( 'wp_mail_succeeded', $success_action );
		try {
			$result = \wp_mail(
				array( $unicode_name . ' <' . $unicode_email . '>' ),
				$subject,
				$message,
				array(
					'From: Unicode Domain Sender <sender@example.test>',
					'Cc: ' . $punycode_name . ' <' . $punycode_email . '>',
					'Reply-To: ' . $reply_name . ' <' . $reply_email . '>',
					'Content-Type: text/plain; charset=UTF-8',
					'X-IDN-Token: ' . $token,
				)
			);
		} finally {
			\remove_action( 'wp_mail_succeeded', $success_action );
			\PHPMailer\PHPMailer\PHPMailer::$validator = $previous_validator;
			if ( ! $had_unicode ) {
				\remove_filter( 'is_email', 'wp_is_unicode_email', 10 );
			}
		}

		$sent         = MailSurfaceMailer::$sent[0] ?? array();
		$succeeded_to = (array) ( $succeeded[0]['to'] ?? array() );
		$unicode_handoff = $unicode_address instanceof \WP_Email_Address ? $unicode_address->get_ascii_address() : $unicode_email;
		$punycode_handoff = $punycode_address instanceof \WP_Email_Address ? $punycode_address->get_ascii_address() : $punycode_email;
		$reply_handoff   = $reply_address instanceof \WP_Email_Address ? $reply_address->get_ascii_address() : $reply_email;

		self::collect_failure(
			$failures,
			$unicode_address instanceof \WP_Email_Address
				&& $punycode_address instanceof \WP_Email_Address
				&& $reply_address instanceof \WP_Email_Address
				&& $ascii_domain === $unicode_address->get_ascii_domain()
				&& $unicode_domain === $unicode_address->get_unicode_domain()
				&& $unicode_local . '@' . $ascii_domain === $unicode_address->get_ascii_address()
				&& $unicode_email === $unicode_address->get_unicode_address()
				&& $ascii_domain === $punycode_address->get_ascii_domain()
				&& $unicode_domain === $punycode_address->get_unicode_domain()
				&& $punycode_local . '@' . $unicode_domain === $punycode_address->get_unicode_address()
				&& $unicode_email === $unicode_is
				&& $punycode_local . '@' . $unicode_domain === $punycode_is
				&& $reply_email === $reply_is,
			'Unicode-domain and punycode-domain addresses expose stable machine/readable views before wp_mail handoff',
			array(
				'unicode' => self::describe_value( $unicode_address ),
				'punycode' => self::describe_value( $punycode_address ),
				'reply'   => self::describe_value( $reply_address ),
				'isEmail' => array(
					'unicode' => self::describe_value( $unicode_is ),
					'punycode' => self::describe_value( $punycode_is ),
					'reply'   => self::describe_value( $reply_is ),
				),
			)
		);

		self::collect_failure(
			$failures,
			true === $result
				&& 1 === count( MailSurfaceMailer::$sent )
				&& self::addresses_include( $sent['to'] ?? array(), $unicode_handoff, $unicode_name )
				&& self::addresses_include( $sent['cc'] ?? array(), $punycode_handoff, $punycode_name )
				&& self::addresses_include( $sent['replyTo'] ?? array(), $reply_handoff, $reply_name )
				&& ! self::addresses_include( $sent['to'] ?? array(), $unicode_email, $unicode_name )
				&& ! self::addresses_include( $sent['replyTo'] ?? array(), $reply_email, $reply_name )
				&& $subject === ( $sent['subject'] ?? null )
				&& $message === ( $sent['body'] ?? null )
				&& 'text/plain' === ( $sent['contentType'] ?? null )
				&& 'UTF-8' === ( $sent['charset'] ?? null )
				&& self::custom_headers_include( $sent['customHeaders'] ?? array(), 'X-IDN-Token' )
				&& 1 === count( $succeeded )
				&& in_array( $unicode_name . ' <' . $unicode_email . '>', $succeeded_to, true )
				&& false === \has_filter( 'wp_mail_succeeded', $success_action ),
			'wp_mail hands UTF-8 local parts and display names to PHPMailer with SMTP-safe IDN domains',
			array(
				'result'    => $result,
				'handoff'   => array(
					'to'      => self::describe_string( $unicode_handoff ),
					'cc'      => self::describe_string( $punycode_handoff ),
					'replyTo' => self::describe_string( $reply_handoff ),
				),
				'sent'      => self::describe_value( $sent ),
				'succeeded' => self::describe_value( $succeeded ),
			)
		);

		return self::row( $ctx, 'mail.phpmailer.unicode-domain-recipient-handoff', $failures );
	}

	private static function check_phpmailer_reuse_resets_message_state( \ComponentFuzz\FuzzContext $ctx, ?string $temp_root ): array {
		$failures = array();

		if ( null === $temp_root ) {
			return $ctx->skip( 'mail.phpmailer.reuse-resets-message-state', 'Could not create temporary attachment root.' );
		}

		$attachment = $temp_root . DIRECTORY_SEPARATOR . 'reuse-attachment-' . $ctx->identifier( 4, 8 ) . '.txt';
		$embed      = $temp_root . DIRECTORY_SEPARATOR . 'reuse-embed-' . $ctx->identifier( 4, 8 ) . '.png';
		file_put_contents( $attachment, 'first attachment ' . $ctx->text( 0, 16 ) );
		file_put_contents( $embed, "\x89PNG\r\n\x1A\nreuse-" . $ctx->identifier( 4, 8 ) );

		MailSurfaceMailer::$mode = 'success';
		MailSurfaceMailer::$sent = array();
		$GLOBALS['phpmailer']    = self::new_mailer();

		$first_subject  = 'Reuse First ' . $ctx->identifier( 4, 8 );
		$second_subject = 'Reuse Second ' . $ctx->identifier( 4, 8 );
		$first_result   = \wp_mail(
			array( 'First Recipient <first@example.test>' ),
			$first_subject,
			'<p>first body</p>',
			array(
				'From: Reuse Sender <reuse-sender@example.test>',
				'Cc: Reuse Copy <reuse-copy@example.test>',
				'Bcc: Reuse Blind <reuse-blind@example.test>',
				'Reply-To: Reuse Reply <reuse-reply@example.test>',
				'Content-Type: text/html; charset=UTF-8',
				'X-Reuse-Token: first',
			),
			array( 'reuse-first.txt' => $attachment ),
			array( 'reuse-image' => $embed )
		);

		$GLOBALS['phpmailer']->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
		$second_result                  = \wp_mail(
			'second@example.test',
			$second_subject,
			'second body',
			array(),
			array(),
			array()
		);

		$first  = MailSurfaceMailer::$sent[0] ?? array();
		$second = MailSurfaceMailer::$sent[1] ?? array();

		self::collect_failure(
			$failures,
			true === $first_result
				&& true === $second_result
				&& 2 === count( MailSurfaceMailer::$sent )
				&& self::addresses_include( $first['to'] ?? array(), 'first@example.test', 'First Recipient' )
				&& self::addresses_include( $first['cc'] ?? array(), 'reuse-copy@example.test', 'Reuse Copy' )
				&& self::addresses_include( $first['bcc'] ?? array(), 'reuse-blind@example.test', 'Reuse Blind' )
				&& self::addresses_include( $first['replyTo'] ?? array(), 'reuse-reply@example.test', 'Reuse Reply' )
				&& self::attachments_include( $first['attachments'] ?? array(), 'reuse-first.txt', 'attachment' )
				&& self::attachments_include( $first['attachments'] ?? array(), basename( $embed ), 'inline', 'reuse-image' )
				&& self::custom_headers_include( $first['customHeaders'] ?? array(), 'X-Reuse-Token' )
				&& self::addresses_include( $second['to'] ?? array(), 'second@example.test', '' )
				&& array() === ( $second['cc'] ?? null )
				&& array() === ( $second['bcc'] ?? null )
				&& array() === ( $second['replyTo'] ?? null )
				&& array() === ( $second['attachments'] ?? null )
				&& array() === ( $second['customHeaders'] ?? null )
				&& $second_subject === ( $second['subject'] ?? null )
				&& 'second body' === ( $second['body'] ?? null )
				&& 'text/plain' === ( $second['contentType'] ?? null )
				&& \PHPMailer\PHPMailer\PHPMailer::ENCODING_7BIT === ( $second['encoding'] ?? null ),
			'reusing the same PHPMailer instance clears stale recipients, headers, attachments, embeds, body, and lets PHPMailer normalize encoding',
			array(
				'firstResult'  => $first_result,
				'secondResult' => $second_result,
				'first'        => self::describe_value( $first ),
				'second'       => self::describe_value( $second ),
			)
		);

		return self::row( $ctx, 'mail.phpmailer.reuse-resets-message-state', $failures );
	}

	private static function check_phpmailer_failure_action( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$failed   = array();
		$action   = static function ( \WP_Error $error ) use ( &$failed ): void {
			$failed[] = array(
				'code' => $error->get_error_code(),
				'data' => $error->get_error_data(),
			);
		};

		MailSurfaceMailer::$mode = 'throw';
		MailSurfaceMailer::$sent = array();
		$GLOBALS['phpmailer']    = self::new_mailer();

		\add_action( 'wp_mail_failed', $action );
		try {
			$result = \wp_mail( 'fail@example.test', 'Failure ' . $ctx->identifier( 4, 8 ), 'body' );
		} finally {
			\remove_action( 'wp_mail_failed', $action );
		}

		self::collect_failure(
			$failures,
			false === $result
				&& 1 === count( $failed )
				&& 'wp_mail_failed' === ( $failed[0]['code'] ?? null )
				&& isset( $failed[0]['data']['to'], $failed[0]['data']['subject'], $failed[0]['data']['message'] ),
			'PHPMailer exceptions produce wp_mail_failed action with original mail data',
			array(
				'result' => $result,
				'failed' => self::describe_value( $failed ),
			)
		);

		MailSurfaceMailer::$mode = 'success';
		return self::row( $ctx, 'mail.phpmailer.failure-action', $failures );
	}

	private static function check_invalid_from_address_failure( \ComponentFuzz\FuzzContext $ctx, ?string $temp_root ): array {
		if ( null === $temp_root ) {
			return $ctx->skip( 'mail.phpmailer.invalid-from-address-failure', 'Could not create temporary attachment root.' );
		}

		$failures    = array();
		$failed      = array();
		$succeeded   = array();
		$init_calls  = 0;
		$token       = 'from-' . $ctx->identifier( 4, 8 );
		$invalid_from = 'invalid-from-' . $token;
		$to          = array( 'recipient@example.test' );
		$subject     = 'Invalid From ' . $token;
		$message     = 'body ' . $token;
		$headers     = array( 'X-Invalid-From-Token: ' . $token );
		$attachments = array( '/component-fuzz-missing-' . $token . '.txt' );
		$embeds      = array( 'invalid-from-inline' => '/component-fuzz-missing-embed-' . $token . '.png' );
		$stale_attachment = $temp_root . DIRECTORY_SEPARATOR . 'stale-from-' . $token . '.txt';
		$stale_subject    = 'Stale Subject ' . $token;

		file_put_contents( $stale_attachment, 'stale attachment ' . $token );

		$from_filter = static function () use ( $invalid_from ): string {
			return $invalid_from;
		};
		$name_filter = static function () use ( $token ): string {
			return 'Invalid Sender ' . $token;
		};
		$failed_action = static function ( \WP_Error $error ) use ( &$failed ): void {
			$failed[] = array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			);
		};
		$success_action = static function ( array $mail_data ) use ( &$succeeded ): void {
			$succeeded[] = $mail_data;
		};
		$init_action    = static function () use ( &$init_calls ): void {
			++$init_calls;
		};

		MailSurfaceMailer::$mode = 'success';
		MailSurfaceMailer::$sent = array();
		$mailer                  = self::new_mailer();

		try {
			$mailer->addAddress( 'stale-to@example.test', 'Stale To' );
			$mailer->addCC( 'stale-cc@example.test', 'Stale Cc' );
			$mailer->addBCC( 'stale-bcc@example.test', 'Stale Bcc' );
			$mailer->addReplyTo( 'stale-reply@example.test', 'Stale Reply' );
			$mailer->addCustomHeader( 'X-Stale-Header: yes' );
			$mailer->addAttachment( $stale_attachment, 'stale-from.txt' );
		} catch ( \Throwable $e ) {
			self::collect_failure(
				$failures,
				false,
				'prepopulating reusable PHPMailer state for invalid-from coverage does not throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);

			return self::row( $ctx, 'mail.phpmailer.invalid-from-address-failure', $failures );
		}

		$mailer->Subject = $stale_subject;
		$mailer->Body    = 'stale body ' . $token;
		$mailer->AltBody = 'stale alt body ' . $token;
		$mailer->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
		$GLOBALS['phpmailer'] = $mailer;

		\add_filter( 'wp_mail_from', $from_filter );
		\add_filter( 'wp_mail_from_name', $name_filter );
		\add_action( 'wp_mail_failed', $failed_action );
		\add_action( 'wp_mail_succeeded', $success_action );
		\add_action( 'phpmailer_init', $init_action );
		try {
			$result = \wp_mail( $to, $subject, $message, $headers, $attachments, $embeds );
		} finally {
			\remove_filter( 'wp_mail_from', $from_filter );
			\remove_filter( 'wp_mail_from_name', $name_filter );
			\remove_action( 'wp_mail_failed', $failed_action );
			\remove_action( 'wp_mail_succeeded', $success_action );
			\remove_action( 'phpmailer_init', $init_action );
			MailSurfaceMailer::$mode = 'success';
		}

		$failed_data    = $failed[0]['data'] ?? array();
		$failed_headers = $failed_data['headers'] ?? array();
		$mailer         = $GLOBALS['phpmailer'] ?? null;

		self::collect_failure(
			$failures,
			false === $result
				&& 1 === count( $failed )
				&& 'wp_mail_failed' === ( $failed[0]['code'] ?? null )
				&& str_contains( (string) ( $failed[0]['message'] ?? '' ), '(From): ' . $invalid_from )
				&& array_key_exists( 'phpmailer_exception_code', $failed_data )
				&& 0 === $failed_data['phpmailer_exception_code']
				&& $to === ( $failed_data['to'] ?? null )
				&& $subject === ( $failed_data['subject'] ?? null )
				&& $message === ( $failed_data['message'] ?? null )
				&& array( 'X-Invalid-From-Token' => $token ) === $failed_headers
				&& $attachments === ( $failed_data['attachments'] ?? null )
				&& $embeds === ( $failed_data['embeds'] ?? null ),
			'invalid wp_mail_from value fails at setFrom with original mail data, embeds, and PHPMailer exception code',
			array(
				'result'  => $result,
				'failed'  => self::describe_value( $failed ),
				'headers' => self::describe_value( $failed_headers ),
			)
		);

		self::collect_failure(
			$failures,
			0 === count( MailSurfaceMailer::$sent )
				&& 0 === $init_calls
				&& array() === $succeeded
				&& $mailer instanceof \PHPMailer\PHPMailer\PHPMailer
				&& array() === $mailer->getToAddresses()
				&& array() === $mailer->getCcAddresses()
				&& array() === $mailer->getBccAddresses()
				&& array() === $mailer->getReplyToAddresses()
				&& array() === $mailer->getCustomHeaders()
				&& array() === $mailer->getAttachments()
				&& $stale_subject === $mailer->Subject
				&& '' === $mailer->Body
				&& '' === $mailer->AltBody
				&& \PHPMailer\PHPMailer\PHPMailer::ENCODING_8BIT === $mailer->Encoding,
			'setFrom failure clears reusable mailer state before recipient/header/attachment handoff, phpmailer_init, success action, or send',
			array(
				'sent'      => self::describe_value( MailSurfaceMailer::$sent ),
				'initCalls' => $init_calls,
				'succeeded' => self::describe_value( $succeeded ),
				'mailer'    => self::describe_value(
					$mailer instanceof \PHPMailer\PHPMailer\PHPMailer
						? array(
							'to'            => $mailer->getToAddresses(),
							'cc'            => $mailer->getCcAddresses(),
							'bcc'           => $mailer->getBccAddresses(),
							'replyTo'       => $mailer->getReplyToAddresses(),
							'customHeaders' => $mailer->getCustomHeaders(),
							'attachments'   => $mailer->getAttachments(),
							'subject'       => $mailer->Subject,
							'body'          => $mailer->Body,
							'altBody'       => $mailer->AltBody,
							'encoding'      => $mailer->Encoding,
						)
						: $mailer
				),
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'wp_mail_from', $from_filter )
				&& false === \has_filter( 'wp_mail_from_name', $name_filter )
				&& false === \has_filter( 'wp_mail_failed', $failed_action )
				&& false === \has_filter( 'wp_mail_succeeded', $success_action )
				&& false === \has_filter( 'phpmailer_init', $init_action ),
			'invalid-from failure filters and actions are removed after wp_mail returns',
			array(
				'wp_mail_from'      => \has_filter( 'wp_mail_from', $from_filter ),
				'wp_mail_from_name' => \has_filter( 'wp_mail_from_name', $name_filter ),
				'wp_mail_failed'    => \has_filter( 'wp_mail_failed', $failed_action ),
				'wp_mail_succeeded' => \has_filter( 'wp_mail_succeeded', $success_action ),
				'phpmailer_init'    => \has_filter( 'phpmailer_init', $init_action ),
			)
		);

		return self::row( $ctx, 'mail.phpmailer.invalid-from-address-failure', $failures );
	}

	private static function check_staticize_emoji_for_email( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$message     = '<p>Emoji ' . $ctx->identifier( 4, 8 ) . " \u{1F600} \u{2764}\u{FE0F}</p>";
		$staticized  = \wp_staticize_emoji( $message );
		$base_mail   = array(
			'to'          => array( 'emoji+' . $ctx->identifier( 3, 6 ) . '@example.test' ),
			'subject'     => 'Subject ' . $ctx->identifier( 4, 8 ),
			'message'     => $message,
			'headers'     => array(),
			'attachments' => array( '/tmp/component-fuzz-' . $ctx->identifier( 3, 6 ) . '.txt' ),
			'embeds'      => array( 'cid-' . $ctx->identifier( 3, 6 ) ),
		);

		$cases = array(
			'array-html'          => array(
				'headers'   => array( 'Content-Type: text/html; charset=UTF-8', 'X-Trace: array' ),
				'expected'  => 'staticized',
			),
			'string-html-crlf'    => array(
				'headers'   => "X-Trace: crlf\r\nContent-Type: text/html; charset=UTF-8\r\n",
				'expected'  => 'staticized',
			),
			'string-html-lf'      => array(
				'headers'   => "X-Trace: lf\nContent-Type: text/html\n",
				'expected'  => 'staticized',
			),
			'text-plain'          => array(
				'headers'   => array( 'Content-Type: text/plain; charset=UTF-8' ),
				'expected'  => 'unchanged',
			),
			'mixed-case-html'     => array(
				'headers'   => array( 'Content-Type: Text/Html; charset=UTF-8' ),
				'expected'  => 'unchanged',
			),
			'missing-headers'     => array(
				'headers'   => null,
				'expected'  => 'unchanged',
			),
			'filter-forces-html'  => array(
				'headers'   => array( 'Content-Type: text/plain; charset=UTF-8' ),
				'filter'    => 'text/html',
				'expected'  => 'staticized',
			),
			'filter-forces-plain' => array(
				'headers'   => array( 'Content-Type: text/html; charset=UTF-8' ),
				'filter'    => 'text/plain',
				'expected'  => 'unchanged',
			),
			'no-message'          => array(
				'headers'   => array( 'Content-Type: text/html; charset=UTF-8' ),
				'noMessage' => true,
				'expected'  => 'no-message',
			),
		);

		foreach ( $cases as $label => $case ) {
			$mail = $base_mail;
			if ( null === $case['headers'] ) {
				unset( $mail['headers'] );
			} else {
				$mail['headers'] = $case['headers'];
			}
			if ( ! empty( $case['noMessage'] ) ) {
				unset( $mail['message'] );
			}

			$content_type_filter = null;
			if ( isset( $case['filter'] ) ) {
				$content_type_filter = static function () use ( $case ): string {
					return $case['filter'];
				};
				\add_filter( 'wp_mail_content_type', $content_type_filter );
			}

			try {
				$result = \wp_staticize_emoji_for_email( $mail );
			} finally {
				if ( null !== $content_type_filter ) {
					\remove_filter( 'wp_mail_content_type', $content_type_filter );
				}
			}

			$expected_message = 'staticized' === $case['expected'] ? $staticized : $message;
			$non_message_ok   = is_array( $result );
			if ( $non_message_ok ) {
				foreach ( array( 'to', 'subject', 'headers', 'attachments', 'embeds' ) as $key ) {
					if ( array_key_exists( $key, $mail ) && ( ! array_key_exists( $key, $result ) || $mail[ $key ] !== $result[ $key ] ) ) {
						$non_message_ok = false;
						break;
					}
					if ( ! array_key_exists( $key, $mail ) && array_key_exists( $key, $result ) ) {
						$non_message_ok = false;
						break;
					}
				}
			}

			$has_message        = is_array( $result ) && array_key_exists( 'message', $result );
			$message_ok         = 'no-message' === $case['expected']
				? ! $has_message && $mail === $result
				: $has_message && $expected_message === $result['message'];
			$filter_removed     = null === $content_type_filter || false === \has_filter( 'wp_mail_content_type', $content_type_filter );
			$staticized_changed = 'staticized' !== $case['expected'] || $staticized !== $message;

			self::collect_failure(
				$failures,
				$non_message_ok && $message_ok && $filter_removed && $staticized_changed,
				'wp_staticize_emoji_for_email honors parsed and filtered content type while preserving non-message mail fields',
				array(
					'label'           => $label,
					'case'            => $case,
					'result'          => self::describe_value( $result ),
					'expectedMessage' => self::describe_string( 'no-message' === $case['expected'] ? '' : $expected_message ),
				)
			);
		}

		return self::row( $ctx, 'mail.emoji-staticization.content-type-matrix', $failures );
	}

	private static function addresses_include( array $addresses, string $email, string $name ): bool {
		foreach ( $addresses as $address ) {
			if ( isset( $address[0], $address[1] ) && $email === $address[0] && $name === trim( (string) $address[1] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function custom_headers_include( array $headers, string $name ): bool {
		foreach ( $headers as $header ) {
			if ( is_array( $header ) && isset( $header[0] ) && $name === $header[0] ) {
				return true;
			}
			if ( is_string( $header ) && str_starts_with( $header, $name . ':' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function attachments_include( array $attachments, string $name, string $disposition, ?string $content_id = null ): bool {
		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			if ( $name !== ( $attachment[2] ?? null ) || $disposition !== ( $attachment[6] ?? null ) ) {
				continue;
			}

			if ( null === $content_id || $content_id === ( $attachment[7] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function multipart_parts( \ComponentFuzz\FuzzContext $ctx, int $count ): array {
		$parts = array(
			array(
				'headers' => array( 'Content-Type: text/plain; charset=us-ascii' ),
				'body'    => 'Plain part ' . $ctx->identifier( 4, 8 ),
			),
			array(
				'headers' => array(
					'Content-Type: text/html; charset=UTF-8',
					'Content-Transfer-Encoding: 8bit',
				),
				'body'    => '<html><body><p>HTML part ' . $ctx->identifier( 4, 8 ) . " \u{2603}</p></body></html>",
			),
			array(
				'headers' => array( 'Content-Type: application/json; charset=UTF-8' ),
				'body'    => '{"token":"' . $ctx->identifier( 4, 8 ) . '"}',
			),
		);

		return array_slice( $parts, 0, max( 2, min( 3, $count ) ) );
	}

	private static function multipart_body( string $boundary, array $parts, string $eol ): string {
		$lines = array();
		foreach ( $parts as $part ) {
			$lines[] = '--' . $boundary;
			foreach ( $part['headers'] as $header ) {
				$lines[] = $header;
			}
			$lines[] = '';
			$lines[] = $part['body'];
		}
		$lines[] = '--' . $boundary . '--';

		return implode( $eol, $lines );
	}

	private static function mime_message_contains_parts( string $mime_message, string $boundary, array $parts ): bool {
		foreach ( $parts as $part ) {
			$expected = '--' . $boundary . "\n" . implode( "\n", $part['headers'] ) . "\n\n" . $part['body'];
			if ( ! str_contains( $mime_message, $expected ) ) {
				return false;
			}
		}

		return true;
	}

	private static function normalize_eol( string $value ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}

	private static function mime_headers( string $mime_message ): string {
		$normalized = self::normalize_eol( $mime_message );
		$parts      = explode( "\n\n", $normalized, 2 );
		return $parts[0] ?? '';
	}

	private static function mime_header_lines( string $mime_headers, string $name ): array {
		$current = '';
		$lines   = array();
		foreach ( explode( "\n", self::normalize_eol( $mime_headers ) ) as $line ) {
			if ( '' !== $current && preg_match( '/^\s+/', $line ) ) {
				$current .= ' ' . trim( $line );
				continue;
			}
			if ( '' !== $current ) {
				$lines[] = $current;
			}
			$current = $line;
		}
		if ( '' !== $current ) {
			$lines[] = $current;
		}

		$matches = array();
		foreach ( $lines as $line ) {
			if ( 0 === strcasecmp( substr( $line, 0, strlen( $name ) + 1 ), $name . ':' ) ) {
				$matches[] = $line;
			}
		}

		return $matches;
	}

	private static function address_header_encodes_name_only( string $line, string $header, string $name, string $email ): bool {
		return str_starts_with( $line, $header . ':' )
			&& str_contains( $line, '=?UTF-8?' )
			&& 1 === substr_count( $line, '<' . $email . '>' )
			&& false === strpos( $line, $name )
			&& false === strpos( $line, '=?UTF-8?' . $email );
	}

	private static function decode_mime_header_value( string $line, string $header ): ?string {
		if ( '' === $line || ! str_starts_with( $line, $header . ':' ) ) {
			return null;
		}

		$value   = trim( substr( $line, strlen( $header ) + 1 ) );
		$decoded = iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' );

		return false === $decoded ? null : $decoded;
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-mail-' . getmypid() . '-' . $ctx->seed();
		if ( is_dir( $dir ) ) {
			self::remove_dir_recursive( $dir );
		}

		return mkdir( $dir, 0700, true ) || is_dir( $dir ) ? $dir : null;
	}

	private static function remove_dir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_dir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'phpmailer',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
				)
			),
		);
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		MailSurfaceMailer::$mode = 'success';
		MailSurfaceMailer::$sent = array();
	}

	private static function state_matches( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] != $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function clone_value( $value ) {
		if ( is_object( $value ) ) {
			return clone $value;
		}
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		return $value;
	}

	private static function collect_failure( array &$failures, bool $ok, string $label, array $data = array() ): void {
		if ( $ok ) {
			return;
		}
		$failures[] = array(
			'label' => $label,
			'data'  => self::describe_value( $data ),
		);
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}
		if ( is_array( $value ) ) {
			if ( $depth >= 4 ) {
				return array( 'count' => count( $value ) );
			}
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_int( $key ) ? $key : (string) $key ] = self::describe_value( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return array( 'class' => get_class( $value ) );
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => strlen( $value ) > self::PREVIEW_BYTES ? substr( $value, 0, self::PREVIEW_BYTES ) . '...' : $value,
		);
	}

	private static function new_mailer(): object {
		return new class( true ) extends \WP_PHPMailer {
			public function send(): bool {
				if ( 'throw' === MailSurfaceMailer::$mode ) {
					throw new \PHPMailer\PHPMailer\Exception( 'Component fuzz forced mail failure.', 123 );
				}

				$this->preSend();
				MailSurfaceMailer::$sent[] = array(
					'to'            => $this->getToAddresses(),
					'cc'            => $this->getCcAddresses(),
					'bcc'           => $this->getBccAddresses(),
					'replyTo'       => $this->getReplyToAddresses(),
					'attachments'   => $this->getAttachments(),
					'customHeaders' => $this->getCustomHeaders(),
					'from'          => $this->From,
					'fromName'      => $this->FromName,
					'sender'        => $this->Sender,
					'useSMTPUTF8'   => $this->UseSMTPUTF8,
					'subject'       => $this->Subject,
					'body'          => $this->Body,
					'contentType'   => $this->ContentType,
					'charset'       => $this->CharSet,
					'encoding'      => $this->Encoding,
					'mimeMessage'   => $this->getSentMIMEMessage(),
				);

				return true;
			}
		};
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

final class MailSurfaceMailer {
	/** @var string */
	public static $mode = 'success';

	/** @var array<int,array<string,mixed>> */
	public static array $sent = array();
}
