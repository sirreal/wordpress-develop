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
		public $last_query = '';
		public $rows_affected = 0;
		public $insert_id = 0;
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
		public $charset = 'utf8mb4';

		private $component_fuzz_options = array();

		public function __construct( array $options = array() ) {
			$this->component_fuzz_reset_options( $options );
		}

		public function component_fuzz_reset_options( array $options = array() ) {
			$this->component_fuzz_options = array();

			foreach ( $options as $option => $entry ) {
				if ( is_array( $entry ) && array_key_exists( 'option_value', $entry ) ) {
					$value    = $entry['option_value'];
					$autoload = $entry['autoload'] ?? 'auto';
				} else {
					$value    = function_exists( 'maybe_serialize' ) ? maybe_serialize( $entry ) : $entry;
					$autoload = 'auto';
				}

				$this->component_fuzz_options[ (string) $option ] = array(
					'option_value' => $value,
					'autoload'     => (string) $autoload,
				);
			}
		}

		public function component_fuzz_get_options() {
			return $this->component_fuzz_options;
		}

		public function _escape( $data ) {
			if ( is_array( $data ) ) {
				return array_map( array( $this, '_escape' ), $data );
			}

			return addslashes( (string) $data );
		}

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sdFf]/', "'" . $this->_escape( $arg ) . "'", $query, 1 );
			}
			return $query;
		}

		public function placeholder_escape() {
			static $placeholder;

			if ( ! $placeholder ) {
				$placeholder = '{component-fuzz-placeholder-escape}';
			}

			if ( function_exists( 'has_filter' ) && function_exists( 'add_filter' ) && false === has_filter( 'query', array( $this, 'remove_placeholder_escape' ) ) ) {
				add_filter( 'query', array( $this, 'remove_placeholder_escape' ), 0 );
			}

			return $placeholder;
		}

		public function add_placeholder_escape( $query ) {
			return str_replace( '%', $this->placeholder_escape(), (string) $query );
		}

		public function remove_placeholder_escape( $query ) {
			return str_replace( $this->placeholder_escape(), '%', (string) $query );
		}

		public function suppress_errors( $suppress = true ) {
			$previous = $this->suppress_errors;
			$this->suppress_errors = (bool) $suppress;
			return $previous;
		}

		public function get_var( $query = null, $x = 0, $y = 0 ) {
			unset( $x, $y );

			$row = $this->get_row( $query, ARRAY_A );
			if ( ! is_array( $row ) || array() === $row ) {
				return null;
			}

			return reset( $row );
		}

		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			unset( $y );

			$this->last_query = (string) $query;
			$option           = $this->component_fuzz_extract_option_name( $this->last_query );

			if ( null === $option || ! isset( $this->component_fuzz_options[ $option ] ) ) {
				return null;
			}

			$entry = $this->component_fuzz_options[ $option ];
			$row   = array(
				'option_name'  => $option,
				'option_value' => $entry['option_value'],
				'autoload'     => $entry['autoload'],
			);

			if ( preg_match( '/SELECT\s+autoload\b/i', $this->last_query ) ) {
				$row = array( 'autoload' => $entry['autoload'] );
			} elseif ( preg_match( '/SELECT\s+option_value\b/i', $this->last_query ) ) {
				$row = array( 'option_value' => $entry['option_value'] );
			}

			return $this->component_fuzz_format_row( $row, $output );
		}

		public function get_results( $query = null, $output = OBJECT ) {
			$this->last_query = (string) $query;
			$rows             = array();

			if ( preg_match( '/\boption_name\s+IN\s*\(/i', $this->last_query ) ) {
				$names = array_fill_keys( $this->component_fuzz_quoted_values( $this->last_query ), true );
				foreach ( $this->component_fuzz_options as $option => $entry ) {
					if ( isset( $names[ $option ] ) ) {
						$rows[] = array(
							'option_name'  => $option,
							'option_value' => $entry['option_value'],
							'autoload'     => $entry['autoload'],
						);
					}
				}
			} elseif ( preg_match( '/\bautoload\s+IN\s*\(/i', $this->last_query ) ) {
				$autoload_values = array_fill_keys( $this->component_fuzz_quoted_values( $this->last_query ), true );
				foreach ( $this->component_fuzz_options as $option => $entry ) {
					if ( isset( $autoload_values[ $entry['autoload'] ] ) ) {
						$rows[] = array(
							'option_name'  => $option,
							'option_value' => $entry['option_value'],
							'autoload'     => $entry['autoload'],
						);
					}
				}
			} elseif ( preg_match( '/\bFROM\s+`?wp_options`?\b/i', $this->last_query ) ) {
				foreach ( $this->component_fuzz_options as $option => $entry ) {
					$rows[] = array(
						'option_name'  => $option,
						'option_value' => $entry['option_value'],
						'autoload'     => $entry['autoload'],
					);
				}
			}

			return $this->component_fuzz_format_results( $rows, $output );
		}

		public function query( $query ) {
			$this->last_query    = (string) $query;
			$this->rows_affected = 0;

			if ( ! preg_match( '/\bINSERT\s+INTO\s+`?wp_options`?\b/i', $this->last_query ) ) {
				return 0;
			}

			$values = $this->component_fuzz_quoted_values( $this->last_query );
			if ( count( $values ) < 3 ) {
				return false;
			}

			$option   = (string) $values[0];
			$exists   = isset( $this->component_fuzz_options[ $option ] );
			$autoload = (string) $values[2];

			$this->component_fuzz_options[ $option ] = array(
				'option_value' => $values[1],
				'autoload'     => $autoload,
			);
			$this->rows_affected                     = $exists ? 2 : 1;
			$this->insert_id++;

			return $this->rows_affected;
		}

		public function update( $table, $data, $where ) {
			unset( $table );

			$this->rows_affected = 0;
			$option              = isset( $where['option_name'] ) ? (string) $where['option_name'] : null;

			if ( null === $option || ! isset( $this->component_fuzz_options[ $option ] ) ) {
				return 0;
			}

			foreach ( array( 'option_value', 'autoload' ) as $column ) {
				if ( array_key_exists( $column, $data ) ) {
					$this->component_fuzz_options[ $option ][ $column ] = (string) $data[ $column ];
				}
			}

			$this->rows_affected = 1;
			return 1;
		}

		public function delete( $table, $where ) {
			unset( $table );

			$this->rows_affected = 0;
			$option              = isset( $where['option_name'] ) ? (string) $where['option_name'] : null;

			if ( null === $option || ! isset( $this->component_fuzz_options[ $option ] ) ) {
				return 0;
			}

			unset( $this->component_fuzz_options[ $option ] );
			$this->rows_affected = 1;
			return 1;
		}

		public function get_blog_prefix( $blog_id = null ) {
			return 'wp_';
		}

		private function component_fuzz_extract_option_name( $query ) {
			if ( preg_match( '/\boption_name\s*=\s*\'((?:\\\\.|[^\'\\\\])*)\'/i', (string) $query, $matches ) ) {
				return stripslashes( $matches[1] );
			}

			return null;
		}

		private function component_fuzz_quoted_values( $query ) {
			if ( ! preg_match_all( '/\'((?:\\\\.|[^\'\\\\])*)\'/s', (string) $query, $matches ) ) {
				return array();
			}

			return array_map( 'stripslashes', $matches[1] );
		}

		private function component_fuzz_format_row( array $row, $output ) {
			if ( ARRAY_A === $output ) {
				return $row;
			}

			if ( ARRAY_N === $output ) {
				return array_values( $row );
			}

			return (object) $row;
		}

		private function component_fuzz_format_results( array $rows, $output ) {
			if ( ARRAY_A === $output || ARRAY_N === $output ) {
				return array_map(
					function ( $row ) use ( $output ) {
						return $this->component_fuzz_format_row( $row, $output );
					},
					$rows
				);
			}

			$objects = array_map(
				function ( $row ) {
					return (object) $row;
				},
				$rows
			);

			if ( OBJECT_K === $output ) {
				$keyed = array();
				foreach ( $objects as $object ) {
					$values = get_object_vars( $object );
					$key    = reset( $values );
					if ( ! isset( $keyed[ $key ] ) ) {
						$keyed[ $key ] = $object;
					}
				}
				return $keyed;
			}

			return $objects;
		}
	}
}

$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new Component_Fuzz_WPDB_Stub();
