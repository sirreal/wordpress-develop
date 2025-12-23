<?php
/**
 * HTML API: WP_HTML_Template class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 7.0.0
 */

class WP_HTML_Template {
	/**
	 * The template string.
	 *
	 * @since 7.0.0
	 *
	 * @var string
	 */
	private string $template_string;

	private function __construct( string $template_string ) {
		$this->template_string = $template_string;
	}

	/**
	 * @todo remove type hint from argument.
	 * @todo obviously bad name…
	 */
	public static function sprintf( string $template_string, array $replacements = array() ): string {
		return self::from( $template_string )->render( $replacements );
	}

	/**
	 * @todo remove type hint from argument.
	 */
	public static function from( string $template_string ): static {
		if ( ! is_string( $template_string ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'The template string must be a string.' ),
				'7.0.0'
			);
			$template_string = '';
		}

		return new static( $template_string );
	}

	/**
	 * @todo remove type hint from argument.
	 */
	public function render( array $replacements ): string {
		if ( ! is_array( $replacements ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'The replacements must be an array.' ),
				'7.0.0'
			);
			$replacements = array();
		}

		return WP_HTML_Processor::normalize( $this->template_string );
	}
}
