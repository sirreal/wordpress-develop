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
			$rows[] = self::check_phpmailer_failure_action( $ctx->fork( 'failure' ) );
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

	private static function check_staticize_emoji_for_email( \ComponentFuzz\FuzzContext $ctx ): array {
		$mail = array(
			'to'          => 'emoji@example.test',
			'subject'     => 'Subject ' . $ctx->identifier( 4, 8 ),
			'message'     => "Emoji \u{1F600} body",
			'headers'     => array( 'Content-Type: text/html; charset=UTF-8' ),
			'attachments' => array(),
			'embeds'      => array(),
		);
		$result = \wp_staticize_emoji_for_email( $mail );

		$ok = is_array( $result )
			&& $mail['to'] === $result['to']
			&& $mail['subject'] === $result['subject']
			&& is_string( $result['message'] )
			&& '' !== $result['message'];

		return $ctx->result(
			'mail.emoji-staticization.shape-preserving',
			$ok,
			array(
				'message' => self::describe_string( is_array( $result ) ? (string) ( $result['message'] ?? '' ) : '' ),
			)
		);
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

				MailSurfaceMailer::$sent[] = array(
					'to'            => $this->getToAddresses(),
					'cc'            => $this->getCcAddresses(),
					'bcc'           => $this->getBccAddresses(),
					'replyTo'       => $this->getReplyToAddresses(),
					'attachments'   => $this->getAttachments(),
					'customHeaders' => $this->getCustomHeaders(),
					'from'          => $this->From,
					'fromName'      => $this->FromName,
					'subject'       => $this->Subject,
					'body'          => $this->Body,
					'contentType'   => $this->ContentType,
					'charset'       => $this->CharSet,
					'encoding'      => $this->Encoding,
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
