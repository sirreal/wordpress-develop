<?php

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl() {
		return false;
	}
}

if ( ! function_exists( 'wp_get_word_count_type' ) ) {
	function wp_get_word_count_type() {
		return 'words';
	}
}

if ( ! function_exists( 'user_can_richedit' ) ) {
	function user_can_richedit() {
		return false;
	}
}

if ( ! class_exists( 'Component_Fuzz_WPDB_Stub', false ) ) {
	class Component_Fuzz_WPDB_Stub {
		public $suppress_errors = false;
		public $posts = 'wp_posts';
		public $comments = 'wp_comments';
		public $terms = 'wp_terms';
		public $term_taxonomy = 'wp_term_taxonomy';
		public $term_relationships = 'wp_term_relationships';
		public $options = 'wp_options';
		public $postmeta = 'wp_postmeta';
		public $users = 'wp_users';
		public $usermeta = 'wp_usermeta';
		public $commentmeta = 'wp_commentmeta';
		public $prefix = 'wp_';

		public function _escape( $data ) {
			if ( is_array( $data ) ) {
				return array_map( array( $this, '_escape' ), $data );
			}

			return addslashes( (string) $data );
		}

		public function prepare( $query, ...$args ) {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sdFf]/', "'" . $this->_escape( $arg ) . "'", $query, 1 );
			}
			return $query;
		}

		public function suppress_errors( $suppress = true ) {
			$previous = $this->suppress_errors;
			$this->suppress_errors = (bool) $suppress;
			return $previous;
		}

		public function get_var( $query = null, $x = 0, $y = 0 ) {
			return null;
		}

		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			return null;
		}

		public function get_results( $query = null, $output = OBJECT ) {
			return array();
		}

		public function get_blog_prefix( $blog_id = null ) {
			return 'wp_';
		}
	}
}

$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new Component_Fuzz_WPDB_Stub();
